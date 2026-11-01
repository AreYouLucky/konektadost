<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Municipality;
use App\Support\Assessment\IndicatorCatalog;
use App\Support\Map\GeographyExplorer;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SearchMapController extends Controller
{
    public function show(GeographyExplorer $geography): Response
    {
        return $this->mapPage('admin/search-map/search-map', $geography);
    }

    public function assessment(GeographyExplorer $geography): Response
    {
        return $this->mapPage('admin/assessment', $geography, [
            'dimensions' => IndicatorCatalog::dimensions(),
        ]);
    }

    public function startAssessment(Request $request, GeographyExplorer $geography): RedirectResponse
    {
        $data = $request->validate([
            'municipality' => ['required', 'integer', 'min:1'],
            'focus' => ['required', Rule::in([IndicatorCatalog::DIMENSION_GENERAL, ...IndicatorCatalog::supportedDimensions()])],
        ]);

        // Resolve the actual municipality record; map IDs and display codes may be legacy GADM identities.
        $geography->selection('municipality', (int) $data['municipality']);
        $municipality = Municipality::query()->findOrFail((int) $data['municipality']);
        $code = (string) $municipality->getAttribute('psg_code');

        if (! preg_match('/^\d{10}$/D', $code)) {
            throw ValidationException::withMessages([
                'municipality' => 'Assessment is unavailable for this location because it has no linked 10-digit PSGC code.',
            ]);
        }

        return to_route('location.assess', ['city' => $code, 'focus' => $data['focus']]);
    }

    private function mapPage(string $component, GeographyExplorer $geography, array $props = []): Response
    {
        $error = null;
        try {
            $provinces = $geography->locations('province')['locations'];
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

        return Inertia::render($component, [
            ...$props,
            'provinces' => $provinces,
            'geographyError' => $error,
            'satellite' => $satellite,
        ]);
    }

    public function geography(Request $request, GeographyExplorer $geography): JsonResponse
    {
        $data = $request->validate([
            'level' => ['required', Rule::in(['province', 'municipality', 'barangay'])],
            'parent' => ['required_unless:level,province', 'nullable', 'integer', 'min:1'],
        ]);

        return $this->respond(fn () => $geography->locations($data['level'], isset($data['parent']) ? (int) $data['parent'] : null, true));
    }

    public function selection(string $level, int $id, GeographyExplorer $geography): JsonResponse
    {
        abort_unless(in_array($level, ['province', 'municipality', 'barangay'], true), 404);

        return $this->respond(fn () => $geography->selection($level, $id));
    }

    public function search(Request $request, GeographyExplorer $geography): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:80']]);

        return $this->respond(fn () => ['results' => $geography->search(trim($data['q']))]);
    }

    private function respond(callable $callback): JsonResponse
    {
        try {
            return response()->json($callback())->header('Cache-Control', 'private, max-age=60');
        } catch (QueryException $exception) {
            report($exception);

            return response()->json(['message' => 'Geographic records could not be loaded. Please try again shortly.'], 503);
        }
    }
}
