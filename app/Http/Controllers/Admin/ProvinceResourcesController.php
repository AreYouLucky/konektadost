<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Province;
use App\Support\Map\GeographicScope;
use App\Support\Map\GeographyExplorer;
use App\Support\Map\ProvinceResources;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProvinceResourcesController extends Controller
{
    public function __invoke(Request $request, Province $province, ProvinceResources $resources, GeographyExplorer $geography): JsonResponse
    {
        try {
            $scope = GeographicScope::fromRequest($request, $province, $geography);

            return response()->json($resources->summary($province, $geography, $scope))->header('Cache-Control', 'private, max-age=60');
        } catch (QueryException $exception) {
            report($exception);

            return response()->json(['message' => 'Population and service records could not be loaded. Please try again shortly.'], 503);
        }
    }
}
