<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\DataEntries\DataEntryRegistry;
use App\Support\DataEntries\DataEntryResource;
use App\Support\DataEntries\LocationFromCoordinates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Admin CRUD for every reference-data sector, driven by DataEntryRegistry definitions. */
class DataEntryController extends Controller
{
    private const PER_PAGE = 25;

    public function index(DataEntryRegistry $registry): Response
    {
        $sections = [];
        foreach ($registry->all() as $resource) {
            $sections[] = [...$resource->meta(), 'available' => $resource->available(), 'count' => $resource->available() ? $resource->query()->count() : null];
        }

        return Inertia::render('admin/data-entries/index', ['sections' => $sections]);
    }

    public function show(Request $request, string $resource, DataEntryRegistry $registry): Response
    {
        $definition = $registry->find($resource);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in($definition->sortable())],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $sort = $filters['sort'] ?? $definition->orderBy;
        $direction = $filters['direction'] ?? ($sort === $definition->orderBy ? $definition->direction : 'asc');

        $query = $definition->query();
        if ($search !== '') {
            $query->where(function ($inner) use ($definition, $search) {
                foreach ($definition->search as $column) {
                    $inner->orWhere($column, 'like', '%'.addcslashes($search, '%_\\').'%');
                }
                if (ctype_digit($search)) {
                    $inner->orWhere($inner->getModel()->getQualifiedKeyName(), (int) $search);
                }
            });
        }
        $page = $query->orderBy($sort, $direction)->orderBy($query->getModel()->getKeyName(), 'desc')
            ->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('admin/data-entries/resource', [
            'resource' => $definition->meta(),
            'fields' => $definition->schema(),
            'rows' => $this->withLocationLabels($definition, array_map(fn ($record) => $definition->present($record), $page->items())),
            'pagination' => [
                'page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total(),
                'from' => $page->firstItem(), 'to' => $page->lastItem(),
            ],
            'filters' => ['search' => $search, 'sort' => $sort, 'direction' => $direction],
            'sortable' => $definition->sortable(),
        ]);
    }

    public function store(Request $request, string $resource, DataEntryRegistry $registry): RedirectResponse
    {
        $definition = $registry->find($resource);
        $input = $request->validate($definition->rules(null), [], $definition->attributes());
        $record = new $definition->model;
        DB::transaction(function () use ($definition, $record, $input) {
            $definition->apply($record, $input, true);
            $record->save();
        });

        return back()->with('success', "{$definition->singular} added.");
    }

    public function update(Request $request, string $resource, int $id, DataEntryRegistry $registry): RedirectResponse
    {
        $definition = $registry->find($resource);
        $record = $definition->query()->findOrFail($id);
        $input = $request->validate($definition->rules($record), [], $definition->attributes());
        DB::transaction(function () use ($definition, $record, $input) {
            $definition->apply($record, $input, false);
            $record->save();
        });

        return back()->with('success', "{$definition->singular} updated.");
    }

    public function destroy(string $resource, int $id, DataEntryRegistry $registry): RedirectResponse
    {
        $definition = $registry->find($resource);
        $record = $definition->query()->findOrFail($id);
        if ($definition->deleting && ($reason = ($definition->deleting)($record))) {
            return back()->withErrors(['record' => $reason]);
        }
        $record->delete();

        return back()->with('success', "{$definition->singular} deleted.");
    }

    /** The municipality and barangay containing a coordinate, for the form's "Detect" button. */
    public function locate(Request $request, LocationFromCoordinates $locator): JsonResponse
    {
        $data = $request->validate(['latitude' => ['required', 'numeric', 'between:-90,90'], 'longitude' => ['required', 'numeric', 'between:-180,180']]);
        $found = $locator->resolve((float) $data['latitude'], (float) $data['longitude']);
        $labels = $this->locationLabels([$found['municipality_id']], [$found['barangay_id']]);

        return response()->json([
            ...$found,
            'label' => $found['municipality_id'] ? $this->label($found['municipality_id'], $found['barangay_id'], $labels) : null,
        ])->header('Cache-Control', 'private, no-store');
    }

    /** @param list<array<string, mixed>> $rows */
    private function withLocationLabels(DataEntryResource $definition, array $rows): array
    {
        if (! $definition->hasLocation()) {
            return $rows;
        }
        $labels = $this->locationLabels(array_column($rows, 'municipality_id'), array_column($rows, 'barangay_id'));

        return array_map(fn ($row) => [...$row, 'location_label' => $this->label($row['municipality_id'], $row['barangay_id'], $labels)], $rows);
    }

    private function locationLabels(array $municipalityIds, array $barangayIds): array
    {
        $municipalityIds = array_values(array_filter(array_unique($municipalityIds)));
        $barangayIds = array_values(array_filter(array_unique($barangayIds)));

        return [
            'municipalities' => $municipalityIds ? DB::table('municipalities')->whereIn('id', $municipalityIds)->get(['id', 'name', 'province_name'])->keyBy('id') : collect(),
            'barangays' => $barangayIds ? DB::table('barangays')->whereIn('id', $barangayIds)->pluck('brgy_name', 'id') : collect(),
        ];
    }

    private function label(?int $municipalityId, ?int $barangayId, array $labels): ?string
    {
        $municipality = $municipalityId ? $labels['municipalities']->get($municipalityId) : null;
        if ($municipality === null) {
            return null;
        }
        $parts = [$barangayId ? $labels['barangays']->get($barangayId) : null, $municipality->name, $municipality->province_name];

        return implode(', ', array_filter($parts));
    }
}
