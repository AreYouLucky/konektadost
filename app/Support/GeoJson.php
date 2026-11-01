<?php

namespace App\Support;

/**
 * Turns a model query into a GeoJSON FeatureCollection using the rows'
 * `geometry` column.
 *
 * The `geometry` column already stores valid GeoJSON as text, so it only needs
 * decoding into a nested array. Passing the raw column through as a string
 * would make Leaflet render nothing at all, silently.
 */
trait GeoJson
{
    /**
     * Build a GeoJSON FeatureCollection from the query.
     *
     * @param  array<int, string>  $columns  Columns to select (geometry is always added).
     * @return array<string, mixed>
     */
    public static function toGeoJsonCollection(array $columns = []): array
    {
        $columns = array_values(array_unique([...$columns, 'geometry']));

        return [
            'type' => 'FeatureCollection',
            'features' => static::query()
                ->get($columns)
                ->map(fn ($row) => static::rowToFeature($row))
                ->values()
                ->all(),
        ];
    }

    /**
     * Limit the collection to a single province, matched on province id.
     *
     * Matched on the province_id foreign key rather than a psg_code prefix:
     * PSA codes the 37 independent/HUC cities outside their province's code
     * sequence (City of Cebu is 0730600000 while Cebu is 0702200000), so a
     * prefix match silently drops them. province_id is populated on every row.
     *
     * @param  int  $provinceId
     * @param  array<int, string>  $columns
     * @return array<string, mixed>
     */
    public static function toGeoJsonCollectionForProvince(int $provinceId, array $columns = []): array
    {
        $columns = array_values(array_unique([...$columns, 'geometry']));

        return [
            'type' => 'FeatureCollection',
            'features' => static::query()
                ->where('province_id', $provinceId)
                ->get($columns)
                ->map(fn ($row) => static::rowToFeature($row))
                ->values()
                ->all(),
        ];
    }

    /**
     * Convert a single row into a GeoJSON Feature.
     *
     * @return array<string, mixed>
     */
    protected static function rowToFeature(mixed $row): array
    {
        $properties = collect($row->getAttributes())
            ->except(['geometry', 'bounds'])
            ->map(function ($value, $key) {
                return match ($key) {
                    'name' => static::geoJsonLabel($value),
                    // A raw query bypasses the model's casts, so send real
                    // numbers rather than decimal strings.
                    'center_lat', 'center_lng' => $value === null ? null : (float) $value,
                    default => $value,
                };
            })
            ->all();

        return [
            'type' => 'Feature',
            'geometry' => json_decode($row->geometry),
            'properties' => $properties,
        ];
    }

    /**
     * Human readable name. The source data strips spaces ("QuezonCity") and
     * `variant_name` is inconsistently populated ("Parañaque,Cityof" on some
     * rows, NULL on others), so build a display label with a fallback chain.
     */
    protected static function geoJsonLabel(?string $name): string
    {
        $name = trim((string) $name);

        // Re-split words that were concatenated by the source data. Only breaks
        // on a lower->upper transition so "Hinunangan" and "SouthernLeyte"
        // style names in the original casing are left alone.
        $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $name);

        return $spaced ?: $name;
    }
}
