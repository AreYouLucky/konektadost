<?php

namespace App\Http\Controllers;

use App\Support\Assessment\AssessmentActionPlans;
use App\Support\Assessment\IndicatorCatalog;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Saves and reopens the action plans generated on the location assessment page. */
class AssessmentActionPlanController extends Controller
{
    public function __construct(private readonly AssessmentActionPlans $plans) {}

    public function store(Request $request): JsonResponse
    {
        abort_unless($this->plans->available(), 503, 'Saving action plans needs the latest database migration.');
        $data = $request->validate([
            'level' => ['nullable', 'required_without:city', Rule::in(['province', 'municipality', 'barangay'])],
            'location' => ['nullable', 'required_with:level', 'integer', 'min:1'],
            'city' => ['nullable', 'required_without:level', 'string', 'regex:/^\d{10}$/'],
            'focus' => ['nullable', Rule::in(['general', ...IndicatorCatalog::supportedDimensions()])],
            'map' => ['nullable', Rule::in(['user', 'admin'])],
            'snapshot' => ['required', 'array'],
            'snapshot.schema' => ['required', 'integer', Rule::in([1])],
            'snapshot.province' => ['nullable', 'string', 'max:255'],
            'snapshot.sources' => ['present', 'array', 'max:40'],
            'snapshot.targets' => ['nullable', 'array'],
            'snapshot.plan' => ['required', 'array'],
            'snapshot.plan.location' => ['required', 'string', 'max:255'],
            'snapshot.plan.generatedAt' => ['required', 'date'],
            'snapshot.plan.aiGeneratedAt' => ['nullable', 'string', 'max:64'],
            'snapshot.plan.laboratory' => ['required', 'string', 'max:2000'],
            'snapshot.plan.activities' => ['required', 'array', 'min:1', 'max:40'],
            'snapshot.plan.activities.*.activity' => ['required', 'string', 'max:2000'],
            'snapshot.plan.activities.*.startWeek' => ['required', 'integer', 'between:1,52'],
            'snapshot.plan.activities.*.endWeek' => ['required', 'integer', 'between:1,52'],
            'snapshot.plan.options' => ['present', 'array', 'max:20'],
            'snapshot.plan.targets' => ['present', 'array', 'max:25'],
            'snapshot.plan.priorities' => ['present', 'array', 'max:25'],
            'snapshot.plan.needs' => ['present', 'array', 'max:25'],
            'snapshot.plan.charts' => ['present', 'array', 'max:10'],
            'snapshot.plan.limitations' => ['present', 'array', 'max:20'],
        ]);
        // validated() drops nested keys without their own rule, so keep the full snapshot as sent.
        $snapshot = $request->input('snapshot');
        if (strlen(json_encode($snapshot) ?: '') > AssessmentActionPlans::MAX_SNAPSHOT_BYTES) {
            throw ValidationException::withMessages(['snapshot' => 'This action plan is too large to save.']);
        }
        $location = $this->plans->resolveLocation($data, (bool) $request->user()->isAdmin());

        try {
            $result = $this->plans->save($request->user()->id, $location, $snapshot);
        } catch (LockTimeoutException) {
            return $this->json(['message' => 'This plan is already being saved. Please retry in a moment.'], 409);
        }

        return $this->json($result, $result['created'] ? 201 : 200);
    }

    public function show(Request $request, int $plan): JsonResponse
    {
        abort_unless($this->plans->available(), 404);

        return $this->json(['plan' => $this->plans->find($request->user()->id, $plan)]);
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }
}
