<?php

namespace App\Http\Controllers\Admin\Data;

use App\Http\Controllers\Controller;
use App\Models\DataImport;
use App\Models\DataVintage;
use App\Support\Import\PopulationCsvImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ImportController extends Controller
{
    /**
     * Import history and the upload form.
     */
    public function index(): Response
    {
        return Inertia::render('admin/data/import', [
            'imports' => DataImport::query()
                ->with('importer:id,name')
                ->latest()
                ->limit(25)
                ->get()
                ->map(fn (DataImport $import) => [
                    'id' => $import->id,
                    'filename' => $import->filename,
                    'vintage' => $import->vintage,
                    'status' => $import->status,
                    'rows_total' => $import->rows_total,
                    'rows_inserted' => $import->rows_inserted,
                    'rows_updated' => $import->rows_updated,
                    'messages' => $import->messages,
                    'imported_by' => $import->importer?->name,
                    'created_at' => $import->created_at?->toDateTimeString(),
                ]),
            'vintages' => DataVintage::query()->orderByDesc('census_year')->get()
                ->map(fn (DataVintage $record) => [
                    'vintage' => $record->vintage,
                    'label' => $record->label,
                    'census_year' => $record->census_year,
                    'is_current' => $record->is_current,
                ])->values(),
            'activeVintage' => DataVintage::activeVintage(),
            'expected' => [
                'municipalities' => 1628,
                'barangays' => 41929,
            ],
        ]);
    }

    /**
     * Validate an uploaded file and report what would change. Writes nothing.
     */
    public function preview(Request $request, PopulationCsvImporter $importer): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:csv,txt'],
        ]);

        $path = $request->file('file')->getRealPath();

        $result = $importer->preview($path, $request->file('file')->getClientOriginalName());

        DataImport::create([
            'filename' => $result['filename'],
            'vintage' => $result['vintage'] ?: 'unknown',
            'status' => $result['valid'] ? DataImport::STATUS_PREVIEW : DataImport::STATUS_FAILED,
            'rows_total' => $result['rows_total'],
            'messages' => ['errors' => $result['errors'], 'warnings' => $result['warnings']],
            'imported_by' => $request->user()?->id,
        ]);

        return response()->json($result);
    }

    /**
     * Apply a previously validated file.
     */
    public function commit(Request $request, PopulationCsvImporter $importer): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:csv,txt'],
            'vintage' => ['required', 'string', 'max:30'],
            'make_current' => ['nullable', 'boolean'],
        ]);

        $vintage = $request->string('vintage')->trim()->toString();

        // putFileAs, not putFile: the third argument of putFile() is the options
        // array, so passing a filename there is read as a visibility value.
        $path = Storage::disk('local')->putFileAs(
            'imports',
            $request->file('file'),
            now()->format('Ymd-His').'-'.$request->file('file')->getClientOriginalName()
        );

        $fullPath = Storage::disk('local')->path($path);

        $result = $importer->commit(
            path: $fullPath,
            vintage: $vintage,
            userId: $request->user()?->id,
            makeCurrent: $request->boolean('make_current', true),
        );

        Storage::disk('local')->delete($path);

        $record = DataImport::create([
            'filename' => $request->file('file')->getClientOriginalName(),
            'vintage' => $vintage,
            'status' => $result['committed'] ? DataImport::STATUS_COMMITTED : DataImport::STATUS_FAILED,
            'rows_total' => $result['rows_total'],
            'rows_inserted' => $result['inserted'],
            'rows_updated' => $result['updated'],
            'messages' => ['errors' => $result['errors'], 'warnings' => $result['warnings']],
            'imported_by' => $request->user()?->id,
        ]);

        if ($result['committed']) {
            $record->forceFill(['data_vintage_id' => DataVintage::where('vintage', $vintage)->value('id')])->save();
        }

        return response()->json($result + ['import_id' => $record->id]);
    }
}
