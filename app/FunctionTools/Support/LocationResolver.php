<?php

namespace App\FunctionTools\Support;

use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\PlaceDescription;
use App\Models\Province;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class LocationResolver
{
    /**
     * Resolve a location model (Municipality by default, or Barangay/Province) from tool arguments.
     *
     * Supported arguments:
     * - `location` (already an Eloquent Model instance)
     * - `municipality_id` (int)
     * - `psg_code` (10-digit PSGC string)
     * - `id` + optional `level` ('municipality'|'barangay'|'province')
     *
     * @param  array<string, mixed>  $args
     */
    public static function resolve(array $args): Model
    {
        if (isset($args['location']) && $args['location'] instanceof Model) {
            return $args['location'];
        }

        $level = strtolower(trim((string) ($args['level'] ?? 'municipality')));

        if (! empty($args['municipality_id'])) {
            $mun = Municipality::query()->find((int) $args['municipality_id']);
            if ($mun !== null) {
                return $mun;
            }
        }

        if (! empty($args['psg_code'])) {
            $code = trim((string) $args['psg_code']);

            if ($level === 'barangay') {
                $bgy = Barangay::query()->where('psg_code', $code)->first();
                if ($bgy !== null) {
                    return $bgy;
                }
            }

            if ($level === 'province') {
                $prov = Province::query()->where('psg_code', $code)->first();
                if ($prov !== null) {
                    return $prov;
                }
            }

            $mun = Municipality::query()->where('psg_code', $code)->first();
            if ($mun !== null) {
                return $mun;
            }

            $bgy = Barangay::query()->where('psg_code', $code)->first();
            if ($bgy !== null) {
                return $bgy;
            }

            $prov = Province::query()->where('psg_code', $code)->first();
            if ($prov !== null) {
                return $prov;
            }
        }

        if (! empty($args['id'])) {
            $id = (int) $args['id'];
            $model = match ($level) {
                'province' => Province::query()->find($id),
                'barangay' => Barangay::query()->find($id),
                default => Municipality::query()->find($id),
            };

            if ($model !== null) {
                return $model;
            }
        }

        throw new InvalidArgumentException(
            'Could not resolve location. Provide a valid `municipality_id`, `psg_code`, or `id`.'
        );
    }

    /**
     * Standardize the location level string ('municipality', 'barangay', or 'province').
     */
    public static function levelOf(Model $location): string
    {
        return match (true) {
            $location instanceof Province => 'province',
            $location instanceof Barangay => 'barangay',
            default => 'municipality',
        };
    }

    /**
     * Standardize the display name of a location model.
     */
    public static function nameOf(Model $location): string
    {
        if ($location instanceof Barangay) {
            return (string) ($location->brgy_name ?: $location->name ?: 'Barangay');
        }

        return (string) ($location->name ?? 'Location');
    }

    /**
     * Compute the PSGC prefix used to match child rows in PSGC-indexed tables.
     */
    public static function psgcPrefixFor(string $level, string $psgCode): string
    {
        if ($psgCode === '') {
            return '';
        }

        if ($level === 'province') {
            return str_starts_with($psgCode, '13') ? '13' : substr($psgCode, 0, 5);
        }

        if ($level === 'municipality') {
            return str_starts_with($psgCode, '13806')
                ? substr($psgCode, 0, 5)
                : substr($psgCode, 0, 7);
        }

        return $psgCode;
    }

    /**
     * Resolve the PlaceDescription record for a location in the latest PSGC release.
     */
    public static function resolvePlaceDescription(string $level, string $psgCode): ?PlaceDescription
    {
        if (! Schema::hasTable('place_descriptions') || $psgCode === '') {
            return null;
        }

        $placeLevels = match ($level) {
            'province' => [PlaceDescription::LEVEL_PROVINCE, PlaceDescription::LEVEL_REGION],
            'barangay' => [PlaceDescription::LEVEL_BARANGAY],
            default => PlaceDescription::LGU_LEVELS,
        };

        return PlaceDescription::query()
            ->where('psg_code', $psgCode)
            ->whereIn('level', $placeLevels)
            ->where('release_id', PlaceDescription::latestReleaseId())
            ->first();
    }

    /**
     * Resolve region PSGC and name from the first 2 digits of a 10-digit PSGC code.
     *
     * @return array{region_psg_code: string|null, region_name: string|null}
     */
    public static function resolveRegion(string $psgCode): array
    {
        if (strlen($psgCode) < 2 || ! Schema::hasTable('place_descriptions')) {
            return ['region_psg_code' => null, 'region_name' => null];
        }

        $regionCode = substr($psgCode, 0, 2).str_repeat('0', 8);

        $region = PlaceDescription::query()
            ->where('psg_code', $regionCode)
            ->where('level', PlaceDescription::LEVEL_REGION)
            ->where('release_id', PlaceDescription::latestReleaseId())
            ->first(['psg_code', 'name']);

        return [
            'region_psg_code' => $region?->psg_code ?? $regionCode,
            'region_name' => $region?->name ?? null,
        ];
    }

    /**
     * Standard JSON schema parameter block for location-scoped tools.
     *
     * @return array<string, mixed>
     */
    public static function locationParameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'municipality_id' => [
                    'type' => 'integer',
                    'description' => 'Database ID of the Municipality in `municipalities`.',
                ],
                'psg_code' => [
                    'type' => 'string',
                    'description' => '10-digit PSA PSGC code of the Municipality (or Barangay/Province).',
                ],
                'level' => [
                    'type' => 'string',
                    'enum' => ['municipality', 'barangay', 'province'],
                    'description' => 'Geographic scope level (defaults to "municipality").',
                ],
                'id' => [
                    'type' => 'integer',
                    'description' => 'Database ID when querying by a specific `level`.',
                ],
            ],
        ];
    }
}

