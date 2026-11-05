<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Province;
use App\Support\Map\GeographicScope;
use App\Support\Map\GeographyExplorer;
use App\Support\Map\ProvinceAgriculture;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProvinceAgricultureController extends Controller
{
    public function records(Request $request, Province $province, ProvinceAgriculture $agriculture, GeographyExplorer $geography): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'commodity' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', 'integer', 'min:1'],
            'municipality' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        try {
            $scope = GeographicScope::fromRequest($request, $province, $geography);

            return response()->json($agriculture->records((int) $province->id, $filters, $scope))
                ->header('Cache-Control', 'private, max-age=60');
        } catch (QueryException $exception) {
            report($exception);

            return response()->json(['message' => 'Agriculture records could not be loaded. Please try again shortly.'], 503);
        }
    }

    public function __invoke(Request $request, Province $province, ProvinceAgriculture $agriculture, GeographyExplorer $geography): JsonResponse
    {
        try {
            $scope = GeographicScope::fromRequest($request, $province, $geography);

            return response()->json($agriculture->summary((int) $province->id, $scope))
                ->header('Cache-Control', 'private, max-age=60');
        } catch (QueryException $exception) {
            report($exception);

            return response()->json(['message' => 'Agriculture records could not be loaded. Please try again shortly.'], 503);
        }
    }
}
