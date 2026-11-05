<?php

namespace App\Http\Controllers\Admin\Data;

use App\Http\Controllers\Controller;
use App\Models\DataVintage;
use App\Models\PlaceDescription;
use App\Models\PopulationFigure;
use App\Support\Assessment\IndicatorCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class PopulationController extends Controller
{
    /**
     * Every vintage the administrator can filter by.
     *
     * Union of registered vintages and any vintage actually present in
     * population_figures, so pre-existing data is selectable before anyone has
     * imported or registered anything.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function vintages(): Collection
    {
        $registered = DataVintage::query()
            ->orderByDesc('census_year')
            ->get()
            ->keyBy('vintage');

        $present = PopulationFigure::query()
            ->distinct()
            ->orderByDesc('vintage')
            ->pluck('vintage')
            ->reject(fn (string $vintage) => $registered->has($vintage));

        return $registered->values()
            ->map(fn (DataVintage $record) => [
                'vintage' => $record->vintage,
                'label' => $record->label,
                'census_year' => $record->census_year,
                'is_current' => $record->is_current,
                'registered' => true,
            ])
            ->concat($present->map(fn (string $vintage) => [
                'vintage' => $vintage,
                'label' => null,
                'census_year' => $this->censusYearFor($vintage),
                'is_current' => false,
                'registered' => false,
            ]))
            ->values();
    }

    /**
     * Census year implied by a vintage label such as "2024_cph".
     */
    private function censusYearFor(string $vintage): ?int
    {
        return preg_match('/^(\d{4})/', $vintage, $matches) ? (int) $matches[1] : null;
    }

    /**
     * Browse population figures, filtered by vintage, level and match status.
     */
    public function index(Request $request): Response
    {
        $vintage = $request->string('vintage')->trim()->toString();
        $level = $request->string('level')->trim()->toString();
        $status = $request->string('status')->trim()->toString();
        $search = $request->string('q')->trim()->toString();

        $query = PopulationFigure::query()
            ->with('editor:id,name')
            ->orderBy('area_level')
            ->orderBy('area_name');

        if ($vintage !== '') {
            $query->ofVintage($vintage);
        }

        if (in_array($level, [PlaceDescription::LEVEL_MUNICIPALITY, PlaceDescription::LEVEL_BARANGAY], true)) {
            $query->ofLevel($level);
        }

        if ($status !== '') {
            $query->where('match_status', $status);
        }

        if ($status === 'corrected') {
            $query->corrected();
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('area_name', 'like', "%{$search}%")
                    ->orWhere('area_ref', 'like', "%{$search}%");
            });
        }

        return Inertia::render('admin/data/population', [
            'figures' => $query->paginate(50)->withQueryString()->through(fn (PopulationFigure $figure) => [
                'id' => $figure->id,
                'area_level' => $figure->area_level,
                'area_ref' => $figure->area_ref,
                'area_name' => $figure->area_name,
                'province' => $figure->province,
                'population' => $figure->population,
                'original_population' => $figure->original_population,
                'match_status' => $figure->match_status,
                'census_year' => $figure->census_year,
                'vintage' => $figure->vintage,
                'is_corrected' => $figure->isCorrected(),
                'edited_at' => $figure->edited_at?->toDateString(),
                'editor' => $figure->editor?->name,
            ]),
            'filters' => [
                'vintage' => $vintage,
                'level' => $level,
                'status' => $status,
                'q' => $search,
            ],
            'vintages' => $this->vintages(),
            'activeVintage' => DataVintage::activeVintage(),
            'levels' => [
                ['value' => PlaceDescription::LEVEL_MUNICIPALITY, 'label' => 'Municipality'],
                ['value' => PlaceDescription::LEVEL_BARANGAY, 'label' => 'Barangay'],
            ],
            'statuses' => [
                PlaceDescription::MATCH_MATCHED,
                PlaceDescription::MATCH_UNMATCHED_NAME,
                PlaceDescription::MATCH_UNMATCHED_PARENT,
                PlaceDescription::MATCH_AMBIGUOUS,
                'corrected',
            ],
            'summary' => [
                'total' => PopulationFigure::count(),
                'matched' => PopulationFigure::matched()->count(),
                'withoutPopulation' => PopulationFigure::whereNull('population')->count(),
                'corrected' => PopulationFigure::corrected()->count(),
            ],
            'dimensions' => IndicatorCatalog::dimensions(),
        ]);
    }

    /**
     * Correct a single figure, retaining the value it replaces.
     */
    public function update(Request $request, PopulationFigure $populationFigure): RedirectResponse
    {
        $validated = $request->validate([
            'population' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $populationFigure->applyPopulationCorrection(
            population: $validated['population'] ?? null,
            editorId: $request->user()?->id,
        );

        return back()->with('success', sprintf(
            'Updated %s. Previous value %s is retained for audit.',
            $populationFigure->area_name ?? $populationFigure->area_ref,
            $populationFigure->original_population === null ? 'none' : number_format($populationFigure->original_population)
        ));
    }

    /**
     * Mark a vintage as the one assessments read.
     */
    public function makeCurrent(DataVintage $dataVintage): RedirectResponse
    {
        $dataVintage->markCurrent();

        return back()->with('success', "Assessments will now read the {$dataVintage->vintage} vintage.");
    }
}
