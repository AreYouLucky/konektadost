<?php

namespace App\Http\Controllers;

use App\Support\Map\GeographyExplorer;
use App\Support\Map\LocationRoads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocationRoadsController extends Controller
{
    public function __invoke(Request $request, string $level, int $id, GeographyExplorer $geography, LocationRoads $roads): JsonResponse
    {
        $data = validator(['level' => $level, ...$request->only(['cursor', 'limit'])], [
            'level' => ['required', Rule::in(['province', 'municipality', 'barangay'])],
            'cursor' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:2000'],
        ])->validate();

        return response()->json($roads->page($geography, $level, $id, (int) ($data['cursor'] ?? 0), (int) ($data['limit'] ?? 2000)))
            ->header('Cache-Control', 'private, no-store');
    }
}
