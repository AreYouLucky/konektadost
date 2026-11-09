<?php

namespace App\FunctionTools\Support;

use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\Province;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Finds provinces, cities/municipalities and barangays by name. Deterministic; no AI.
 *
 * Stored names differ from how people write them: "City of Iloilo" for "Iloilo City",
 * "IlocosNorte" for "Ilocos Norte", "Sta. Teresita" beside "Santa Cruz". Matching ignores
 * case, spaces, periods, hyphens and accents. A name shared by several places comes back
 * as a list to choose from and is never guessed.
 */
class LocationNameLookup
{
    private const MAX_MATCHES = 10;

    private const FETCH_LIMIT = 100;

    private const LABELS = ['province' => 'province', 'municipality' => 'city or municipality', 'barangay' => 'barangay'];

    /** Words around a place name that say what kind of place it is rather than which one. */
    private const DESIGNATORS = [
        'province' => '/^province\s+of\s+|\s+province$/iu',
        'municipality' => '/^(?:city|municipality)\s+of\s+|\s+(?:city|municipality)$/iu',
        'barangay' => '/^(?:barangay|brgy\.?|bgy\.?)\s+/iu',
    ];

    /** PSA publishes Metro Manila as a region, stored in `provinces` under this name. */
    private const NCR_KEY = 'nationalcapitalregion';

    private const NCR_ALIASES = ['ncr', 'metromanila', 'metropolitanmanila'];

    private const RELATIONS = [
        'province' => ['populationFigure', 'placeDescription'],
        'municipality' => ['populationFigure', 'provinceModel:id,name,psg_code', 'provinceModel.placeDescription'],
        'barangay' => ['populationFigure', 'municipality:id,name,province_id,psg_code', 'municipality.provinceModel:id,name,psg_code', 'municipality.provinceModel.placeDescription'],
    ];

    /** @return array<string, mixed> */
    public function province(string $name): array
    {
        return $this->respond('province', $name, $this->provinces($name));
    }

    /** @return array<string, mixed> */
    public function city(string $name, string $province = ''): array
    {
        $cities = $this->cityQuery($province);
        if ($cities === null) {
            return $this->noMatch('province', $province);
        }

        return $this->respond('municipality', $name, $this->matches($cities, 'name', 'municipality', $name));
    }

    /** @return array<string, mixed> */
    public function barangay(string $name, string $city = '', string $province = ''): array
    {
        $barangays = Barangay::query()->select(['id', 'municipality_id', 'brgy_name', 'psg_code', 'area_km2']);

        if ($city !== '' || $province !== '') {
            $cities = $this->cityQuery($province);
            if ($cities === null) {
                return $this->noMatch('province', $province);
            }
            $cityIds = ($city !== '' ? $this->matches($cities, 'name', 'municipality', $city) : $cities->get())->modelKeys();
            if ($cityIds === []) {
                return $this->noMatch('municipality', $city);
            }
            $barangays->whereIn('municipality_id', $cityIds);
        }

        return $this->respond('barangay', $name, $this->matches($barangays, 'brgy_name', 'barangay', $name));
    }

    /**
     * The public facts about a location, in the shape the lookup and comparison tools return.
     *
     * @return array{level: string, name: string, city: string|null, province: string|null, psg_code: string, population: int|null, area_km2: float|null}
     */
    public function describe(Model $location): array
    {
        $city = $location instanceof Barangay ? $location->municipality : null;
        $province = $location instanceof Municipality ? $location->provinceModel : $city?->provinceModel;
        $population = $location->populationFigure?->population;

        return [
            'level' => LocationResolver::levelOf($location),
            'name' => $location instanceof Province ? self::provinceName($location) : LocationResolver::nameOf($location),
            'city' => $city?->name,
            'province' => $province !== null ? self::provinceName($province) : null,
            'psg_code' => (string) $location->psg_code,
            'population' => $population !== null ? (int) $population : null,
            'area_km2' => $location->area_km2 !== null ? (float) $location->area_km2 : null,
        ];
    }

    private function provinces(string $name): Collection
    {
        return $this->matches(Province::query()->select(['id', 'name', 'psg_code', 'area_km2']), 'name', 'province', $name);
    }

    /** Returns null when a province filter was given and no province matches it. */
    private function cityQuery(string $province): ?Builder
    {
        $cities = Municipality::query()->select(['id', 'name', 'province_id', 'psg_code', 'area_km2']);
        if ($province === '') {
            return $cities;
        }
        $provinceIds = $this->provinces($province)->modelKeys();

        return $provinceIds === [] ? null : $cities->whereIn('province_id', $provinceIds);
    }

    /**
     * The narrowest set of rows that fits the name: the exact stored name when that is unique,
     * then the same place name with or without "City of", then anything containing the name.
     */
    private function matches(Builder $query, string $column, string $level, string $name): Collection
    {
        $literal = self::key($name);
        $names = self::nameKeys($level, $name);
        if ($names === []) {
            return new Collection;
        }
        $exact = array_values(array_unique([
            $literal,
            ...$names,
            ...($level === 'municipality' ? array_merge(...array_map(fn (string $key) => ['cityof'.$key, $key.'city'], $names)) : []),
        ]));
        $folded = "LOWER(REPLACE(REPLACE(REPLACE({$column}, ' ', ''), '.', ''), '-', ''))";

        // Exact names sort first so that a capped result never drops them.
        $rows = $query
            ->where(function (Builder $where) use ($folded, $names) {
                foreach ($names as $key) {
                    $where->orWhereRaw("{$folded} LIKE ?", ['%'.addcslashes($key, '\\%_').'%']);
                }
            })
            ->orderByRaw("CASE WHEN {$folded} IN (".implode(',', array_fill(0, count($exact), '?')).') THEN 0 ELSE 1 END', $exact)
            ->orderBy($column)
            ->limit(self::FETCH_LIMIT)
            ->get();

        $exactStoredName = $rows->filter(fn (Model $row) => self::key($row->{$column}) === $literal);
        if ($exactStoredName->count() === 1) {
            return $exactStoredName->values();
        }

        $samePlaceName = $rows->filter(fn (Model $row) => in_array(self::key(self::withoutDesignator($level, $row->{$column})), $names, true));
        if ($level === 'municipality' && self::saysCity($name)) {
            $cities = $samePlaceName->filter(fn (Model $row) => self::saysCity($row->{$column}));
            $samePlaceName = $cities->isNotEmpty() ? $cities : $samePlaceName;
        }

        return ($samePlaceName->isNotEmpty() ? $samePlaceName : $rows)->values();
    }

    /** @return array<string, mixed> */
    private function respond(string $level, string $name, Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return $this->noMatch($level, $name);
        }

        $shown = $rows->take(self::MAX_MATCHES)->load(self::RELATIONS[$level]);
        if ($rows->count() === 1) {
            return ['status' => 'found', 'location' => $this->describe($shown->first())];
        }

        return [
            'status' => 'multiple_matches',
            'matches' => $shown->map(fn (Model $row) => $this->describe($row))->values()->all(),
            'more_matches_exist' => $rows->count() > self::MAX_MATCHES,
            'message' => 'Several places match this name. Ask the user to choose one; do not guess.',
        ];
    }

    /** @return array{error: string} */
    private function noMatch(string $level, string $name): array
    {
        return ['error' => 'No '.self::LABELS[$level]." matches \"{$name}\". Ask the user to check the name."];
    }

    private static function provinceName(Province $province): string
    {
        return (string) ($province->placeDescription?->name ?: $province->name);
    }

    /**
     * Comparison keys for the place name, including Sta./Santa and Sto./Santo spellings.
     *
     * @return list<string>
     */
    private static function nameKeys(string $level, string $name): array
    {
        $place = self::withoutDesignator($level, $name);
        if ($level === 'province' && in_array(self::key($place), self::NCR_ALIASES, true)) {
            return [self::NCR_KEY];
        }

        $spellings = [
            $place,
            preg_replace(['/\bsta\.?\s+/iu', '/\bsto\.?\s+/iu'], ['santa ', 'santo '], $place),
            preg_replace(['/\bsanta\s+/iu', '/\bsanto\s+/iu'], ['sta ', 'sto '], $place),
        ];

        return array_values(array_filter(array_unique(array_map(self::key(...), $spellings))));
    }

    private static function withoutDesignator(string $level, string $name): string
    {
        return trim((string) preg_replace(self::DESIGNATORS[$level], '', trim($name)));
    }

    private static function saysCity(string $name): bool
    {
        return preg_match('/\bcity\b/iu', $name) === 1;
    }

    private static function key(string $text): string
    {
        return (string) preg_replace('/[\s.\-]+/u', '', mb_strtolower(Str::ascii($text)));
    }
}
