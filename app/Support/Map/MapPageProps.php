<?php

namespace App\Support\Map;

use Illuminate\Database\QueryException;

/** The Inertia props every page that renders the intelligence map needs. */
class MapPageProps
{
    public function __construct(private readonly GeographyExplorer $geography) {}

    /** @return array{provinces: array<int, array<string, mixed>>, geographyError: string|null, satellite: array<string, mixed>|null} */
    public function toArray(): array
    {
        $error = null;
        try {
            $provinces = $this->geography->locations('province')['locations'];
        } catch (QueryException $exception) {
            report($exception);
            $provinces = [];
            $error = 'The geographic dataset is unavailable. Please check the database connection and imported geography tables.';
        }

        $satellite = config('map.satellite');
        $satellite = $satellite['enabled'] && $satellite['attribution'] !== ''
            && str_starts_with($satellite['url'], 'https://')
            && collect(['{z}', '{x}', '{y}'])->every(fn ($token) => str_contains($satellite['url'], $token))
                ? $satellite : null;

        return ['provinces' => $provinces, 'geographyError' => $error, 'satellite' => $satellite];
    }
}
