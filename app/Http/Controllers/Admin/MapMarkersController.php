<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Map\GeographyExplorer;
use App\Support\Map\MapMarkers;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MapMarkersController extends Controller
{
    public function __invoke(Request $request, MapMarkers $markers, GeographyExplorer $geography): JsonResponse
    {
        try {
            return response()->json($markers->all($geography, forGuest: $request->user() === null))->header('Cache-Control', 'private, max-age=60');
        } catch (QueryException $exception) {
            report($exception);

            return response()->json(['message' => 'Map markers could not be loaded. Please try again.'], 503);
        }
    }
}
