<?php

namespace App\Support\Psgc;

use App\Models\PsgcRelease;
use App\Models\PsgcUnit;
use App\Support\Geo\PsgcMatcher;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reads an imported PSA release for matching and for the sync command.
 *
 * PsgcMatcher stays free of database access so its rules can be tested on their
 * own; this is the part that knows where the release lives.
 */
class PsgcUnitIndex
{
    public function __construct(private readonly PsgcRelease $release) {}

    /**
     * The most recently imported release.
     */
    public static function latest(): self
    {
        $release = PsgcRelease::query()->orderByDesc('id')->first();

        if ($release === null) {
            throw new RuntimeException(
                'No PSGC release has been imported. Run: php artisan geo:import-psgc'
            );
        }

        return self::forRelease($release);
    }

    public static function forRelease(PsgcRelease $release): self
    {
        // A release row with no units would otherwise match nothing while
        // reporting success, which reads as "no barangays matched" rather than
        // "nothing was imported".
        $hasUnits = PsgcUnit::query()
            ->where('release_id', $release->id)
            ->exists();

        if (! $hasUnits) {
            throw new RuntimeException(sprintf(
                'PSGC release "%s" has no imported units. Run: php artisan geo:import-psgc',
                $release->label
            ));
        }

        return new self($release);
    }

    public function release(): PsgcRelease
    {
        return $this->release;
    }

    public function label(): string
    {
        return $this->release->label;
    }

    /**
     * A matcher over this release's barangays.
     *
     * Each barangay is indexed under the six digit code of its published parent.
     * The sub-municipal districts of the City of Manila are also indexed under
     * the city they belong to, because PSA places their barangays under the
     * district rather than the city, while the geography tables only carry the
     * city. PSA records the relationship itself: every Manila district has the
     * city's code as its parent, so the alias is read from the release rather
     * than hard-coded.
     *
     * A barangay whose parent is not published cannot be matched in any case and
     * is left out rather than filed under a guess.
     */
    public function matcher(): PsgcMatcher
    {
        $districtParent = $this->districtParents();

        $rows = DB::table('place_descriptions')
            ->where('release_id', $this->release->id)
            ->where('level', 'Bgy')
            ->whereNotNull('parent_correspondence_code')
            ->get(['correspondence_code', 'parent_correspondence_code', 'name', 'old_name']);

        $expanded = [];

        foreach ($rows as $row) {
            $parent = substr((string) $row->parent_correspondence_code, 0, 6);

            $entry = [
                'code' => (string) $row->correspondence_code,
                'parent' => $parent,
                'name' => (string) $row->name,
                'oldName' => $row->old_name === null ? null : (string) $row->old_name,
            ];

            $expanded[] = $entry;

            $city = $districtParent[$parent] ?? null;

            if ($city !== null && $city !== $parent) {
                $expanded[] = ['parent' => $city] + $entry;
            }
        }

        return PsgcMatcher::fromRows($expanded);
    }

    /**
     * Every barangay in the release, keyed by its nine digit correspondence
     * code, so a resolved code can be turned into its attributes.
     *
     * @return \Illuminate\Support\Collection<string, PsgcUnit>
     */
    public function barangaysByCode(): \Illuminate\Support\Collection
    {
        return $this->unitsByCorrespondenceCode('Bgy');
    }

    /**
     * Six digit sub-municipal code => the six digit code of its parent city.
     *
     * @return array<string, string>
     */
    private function districtParents(): array
    {
        $map = [];

        foreach (DB::table('place_descriptions')
            ->where('release_id', $this->release->id)
            ->where('level', 'SubMun')
            ->whereNotNull('parent_correspondence_code')
            ->get(['correspondence_code', 'parent_correspondence_code']) as $unit) {
            $map[substr((string) $unit->correspondence_code, 0, 6)] = substr(
                (string) $unit->parent_correspondence_code,
                0,
                6
            );
        }

        return $map;
    }

    /**
     * Units at one level, keyed by correspondence code.
     *
     * @return \Illuminate\Support\Collection<string, PsgcUnit>
     */
    public function unitsByCorrespondenceCode(string $level): \Illuminate\Support\Collection
    {
        return PsgcUnit::query()
            ->where('release_id', $this->release->id)
            ->where('level', $level)
            ->whereNotNull('correspondence_code')
            ->get()
            ->keyBy('correspondence_code');
    }

    public function unitsByName(string $level, bool $compact = false): \Illuminate\Support\Collection
    {
        return PsgcUnit::query()
            ->where('release_id', $this->release->id)
            ->where('level', $level)
            ->get()
            ->groupBy(fn (PsgcUnit $unit) => $compact
                ? PsgcMatcher::compactKey(PsgcMatcher::comparisonKey($unit->name))
                : PsgcMatcher::comparisonKey($unit->name));
    }

    /**
     * Units at one level, grouped by every name form that should resolve to
     * them: the current PSA name, PSA's former name, and both with spaces
     * removed.
     *
     * The former name matters because GADM tends to carry the older spelling.
     * PSA records "Davao de Oro (formerly Compostela Valley)" and "Cotabato
     * (formerly North Cotabato)", which is precisely how a GADM province row
     * can be recognised rather than abandoned.
     *
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, PsgcUnit>>
     */
    public function unitsByAnyName(string $level): \Illuminate\Support\Collection
    {
        $grouped = collect();

        foreach ([$this->unitsByName($level), $this->unitsByName($level, compact: true)] as $byKey) {
            foreach ($byKey as $key => $units) {
                $grouped->put($key, $units);
            }
        }

        $former = PsgcUnit::query()
            ->where('release_id', $this->release->id)
            ->where('level', $level)
            ->whereNotNull('old_name')
            ->get();

        foreach ($former as $unit) {
            $key = PsgcMatcher::comparisonKey((string) $unit->old_name);

            foreach ([$key, PsgcMatcher::compactKey($key)] as $form) {
                if ($form === '') {
                    continue;
                }

                // A former name that already collides with something else is
                // not used, rather than being allowed to create a false
                // ambiguity or a false match.
                if (! isset($grouped[$form])) {
                    $grouped[$form] = collect([$unit]);
                }
            }
        }

        return $grouped;
    }
}
