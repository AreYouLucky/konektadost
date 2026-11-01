<?php

namespace App\FunctionTools\LayerE;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\Support\LocationResolver;
use App\Models\DostProject;
use App\Models\DostTech;
use App\Models\Facility;
use App\Models\Municipality;
use App\Models\Technology;
use App\Models\TelecomSite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Layer E (E3) Function Tool: assess_implementation_feasibility
 *
 * Deterministically evaluates the implementation feasibility of a candidate
 * DOST intervention for a target municipality.
 *
 * Feasibility Classification:
 * - feasible: All essential prerequisites verified, active program status, adequate infrastructure.
 * - conditionally_feasible: High viability but subject to specific prerequisites (e.g. MOA, solar/power backup, offline pack).
 * - not_yet_feasible: Critical blocker present (e.g. program retired, zero road access, extreme facility deficit).
 * - insufficient_information: Essential requirements unknown due to unverified baseline data.
 *
 * Strict constraint: Unknown essential requirements must prevent declaring an intervention feasible.
 */
class AssessImplementationFeasibilityTool implements FunctionToolInterface
{
    public function name(): string
    {
        return 'assess_implementation_feasibility';
    }

    public function layer(): string
    {
        return 'E';
    }

    public function description(): string
    {
        return 'Evaluate implementation feasibility of a DOST intervention using deterministic criteria across infrastructure, utilities, institutional partners, funding eligibility, and catalog status.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'municipality_id' => [
                    'type' => 'integer',
                    'description' => 'Target municipality numeric ID.',
                ],
                'psg_code' => [
                    'type' => 'string',
                    'description' => 'Target municipality 10-digit PSGC code.',
                ],
                'intervention_id' => [
                    'type' => 'string',
                    'description' => 'Identifier or code of the intervention (e.g. STARBOOKS, SETUP-FOOD-01, or TECH code).',
                ],
                'barangay_id' => [
                    'type' => 'integer',
                    'description' => 'Optional specific barangay to assess implementation feasibility within.',
                ],
            ],
            'required' => [],
        ];
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'layer' => $this->layer(),
            'description' => $this->description(),
            'parameters' => $this->parameters(),
        ];
    }

    public function execute(array $args): array
    {
        $municipality = LocationResolver::resolve($args);
        if ($municipality === null) {
            return [
                'error' => 'Could not resolve municipality. Provide municipality_id or psg_code.',
                'feasibility_status' => 'insufficient_information',
            ];
        }

        $interventionId = $args['intervention_id'] ?? $args['code'] ?? null;
        $intervention = $this->resolveIntervention($interventionId);

        // 1. Check Baseline Information Completeness
        $baselineChecks = $this->verifyBaselineData($municipality);
        if ($baselineChecks['has_unknown_essential_requirements']) {
            return [
                'municipality' => [
                    'id' => $municipality->id,
                    'name' => $municipality->name,
                    'province' => $municipality->province_name,
                ],
                'intervention' => $intervention,
                'feasibility_status' => 'insufficient_information',
                'confidence_score' => 20,
                'reason' => 'Essential baseline data is missing: ' . implode(', ', $baselineChecks['missing_fields']) . '. Unknown essential requirements prevent declaring an intervention feasible.',
                'verified_prerequisites' => $baselineChecks['prerequisites'],
                'conditional_requirements' => ['Complete baseline spatial and demographic profiling before reassessing feasibility.'],
                'risks_and_bottlenecks' => ['Missing baseline infrastructure records.'],
            ];
        }

        // 2. Evaluate Individual Implementation Prerequisites
        $prerequisites = [];
        $blockers = [];
        $conditions = [];
        $risks = [];

        // Prerequisite A: Program Availability
        $programStatus = strtolower($intervention['status'] ?? 'active');
        $isProgramAvailable = in_array($programStatus, ['active', 'available', 'verified_available', 'verified', 'ongoing', 'comleted']);
        if ($programStatus === 'retired' || $programStatus === 'inactive' || $programStatus === 'cancelled') {
            $prerequisites[] = [
                'prerequisite' => 'Program Availability & Status',
                'status' => 'failed',
                'evidence' => "Technology status is recorded as '{$programStatus}'",
            ];
            $blockers[] = "The candidate program is {$programStatus} and no longer accepts new deployments.";
        } else {
            $prerequisites[] = [
                'prerequisite' => 'Program Availability & Status',
                'status' => 'passed',
                'evidence' => "Verified active/available in DOST catalog (Status: {$intervention['status']})",
            ];
        }

        // Prerequisite B: Road Accessibility & Physical Delivery
        $roadCount = Schema::hasTable('roads') ? DB::table('roads')->where('municipality_id', $municipality->id)->count() : 0;
        if ($roadCount > 0) {
            $pavedRoads = DB::table('roads')->where('municipality_id', $municipality->id)
                ->whereIn('surface_type', ['concrete', 'asphalt', 'paved'])
                ->count();
            $pavedRatio = round(($pavedRoads / max(1, $roadCount)) * 100);

            if ($pavedRatio >= 40) {
                $prerequisites[] = [
                    'prerequisite' => 'Road Accessibility & Equipment Transport',
                    'status' => 'passed',
                    'evidence' => "{$pavedRatio}% paved roads ({$pavedRoads}/{$roadCount} segments) allow regular logistics access",
                ];
            } else {
                $prerequisites[] = [
                    'prerequisite' => 'Road Accessibility & Equipment Transport',
                    'status' => 'conditional',
                    'evidence' => "Low paved road ratio ({$pavedRatio}%); transport may be constrained during adverse weather",
                ];
                $conditions[] = 'Schedule equipment hauling and deployment during dry weather or utilize rugged 4x4 transport.';
                $risks[] = 'Unpaved road degradation during rainy season may delay physical deployment.';
            }
        } else {
            $prerequisites[] = [
                'prerequisite' => 'Road Accessibility & Equipment Transport',
                'status' => 'conditional',
                'evidence' => 'No detailed road segment geometry recorded for this municipality',
            ];
            $conditions[] = 'Confirm physical road accessibility with Municipal Engineering Office prior to equipment dispatch.';
        }

        // Prerequisite C: Digital & Power Utilities
        $telecomCount = TelecomSite::where('municipality_id', $municipality->id)->count();
        $isDigitalTool = in_array($intervention['domain'], ['digital_connectivity', 'education', 'sti_capacity']);

        if ($isDigitalTool) {
            if ($telecomCount > 0) {
                $prerequisites[] = [
                    'prerequisite' => 'Digital Connectivity & Power Utilities',
                    'status' => 'passed',
                    'evidence' => "{$telecomCount} cellular tower(s) recorded in municipality",
                ];
            } else {
                $prerequisites[] = [
                    'prerequisite' => 'Digital Connectivity & Power Utilities',
                    'status' => 'conditional',
                    'evidence' => 'Zero cellular towers recorded; requires offline deployment or VSAT broadband',
                ];
                $conditions[] = 'Deploy standalone offline version (e.g. STARBOOKS Offline Pod) or provide DOST-ASTI VSAT satellite kit.';
                $risks[] = 'Intermittent cellular signal in remote barangays.';
            }
        } else {
            $prerequisites[] = [
                'prerequisite' => 'Utilities Readiness',
                'status' => 'passed',
                'evidence' => 'Standard municipal utility baseline sufficient for physical/production technology',
            ];
        }

        // Prerequisite D: Institutional Host & Facility
        $facilityCount = Facility::where('municipality_id', $municipality->id)->count();
        if ($facilityCount > 0) {
            $prerequisites[] = [
                'prerequisite' => 'Institutional Host Facility',
                'status' => 'passed',
                'evidence' => "{$facilityCount} verified public facilities present to serve as host venue",
            ];
        } else {
            $prerequisites[] = [
                'prerequisite' => 'Institutional Host Facility',
                'status' => 'conditional',
                'evidence' => 'No recorded public facilities; host venue must be designated by LGU',
            ];
            $conditions[] = 'Designate a secure municipal hall room, barangay hall, or covered court as project site.';
        }

        // Prerequisite E: LGU Partnership & Counterpart Capability
        $incomeClass = $municipality->placeDescription?->income_class ?? '';
        if (in_array($incomeClass, ['1st', '2nd', '3rd', '4th', '5th', '6th'])) {
            $prerequisites[] = [
                'prerequisite' => 'LGU Eligibility & Counterpart Capacity',
                'status' => 'passed',
                'evidence' => "LGU is classified as {$incomeClass} class; eligible for DOST grant-in-aid and technical assistance",
            ];
        } else {
            $prerequisites[] = [
                'prerequisite' => 'LGU Eligibility & Counterpart Capacity',
                'status' => 'conditional',
                'evidence' => 'Income classification not officially verified in records',
            ];
            $conditions[] = 'Secure formal LGU Sangguniang Bayan Resolution of Support and Counterpart Commitment.';
        }

        // 3. Determine Feasibility Classification
        if (!empty($blockers)) {
            $feasibilityStatus = 'not_yet_feasible';
            $confidence = 85;
        } elseif (!empty($conditions)) {
            $feasibilityStatus = 'conditionally_feasible';
            $confidence = 80;
        } else {
            $feasibilityStatus = 'feasible';
            $confidence = 90;
        }

        return [
            'municipality' => [
                'id' => $municipality->id,
                'name' => $municipality->name,
                'province' => $municipality->province_name,
                'income_class' => $incomeClass ?: 'Unclassified',
            ],
            'intervention' => $intervention,
            'feasibility_status' => $feasibilityStatus,
            'confidence_score' => $confidence,
            'feasibility_summary' => match ($feasibilityStatus) {
                'feasible' => 'All essential technical, infrastructural, and institutional prerequisites are verified and satisfied.',
                'conditionally_feasible' => 'Intervention is viable but requires specific counterpart actions or technical configurations.',
                'not_yet_feasible' => 'Critical blockers exist that currently prevent successful intervention execution.',
                default => 'Insufficient information to determine feasibility.',
            },
            'verified_prerequisites' => $prerequisites,
            'conditional_requirements' => array_values(array_unique($conditions)),
            'risks_and_bottlenecks' => array_values(array_unique($risks)),
        ];
    }

    protected function verifyBaselineData(Municipality $municipality): array
    {
        $missing = [];
        $prerequisites = [];

        if (!$municipality->name || !$municipality->psg_code) {
            $missing[] = 'Municipality geographic identity';
        }

        $hasPop = $municipality->populationFigure !== null || $municipality->populationFigures()->count() > 0;
        if (!$hasPop) {
            $missing[] = 'Verified population figures';
        }

        $prerequisites[] = [
            'prerequisite' => 'Verified Demographic Profile',
            'status' => $hasPop ? 'passed' : 'failed',
            'evidence' => $hasPop ? 'Active population figure verified' : 'No population figures found',
        ];

        return [
            'has_unknown_essential_requirements' => !empty($missing),
            'missing_fields' => $missing,
            'prerequisites' => $prerequisites,
        ];
    }

    protected function resolveIntervention(?string $id): array
    {
        if ($id) {
            $tech = Technology::where('code', $id)->orWhere('id', is_numeric($id) ? (int)$id : 0)->first();
            if ($tech) {
                return [
                    'id' => $tech->code ?? ('TECH-' . $tech->id),
                    'name' => $tech->name,
                    'agency' => $tech->agency ?? 'DOST',
                    'sector' => $tech->category ?? 'General STI',
                    'domain' => str_contains(strtolower($tech->category ?? ''), 'learn') ? 'education' : 'general',
                    'status' => $tech->active_status ?? 'Active',
                ];
            }

            $dtech = DostTech::where('code', $id)->orWhere('id', is_numeric($id) ? (int)$id : 0)->first();
            if ($dtech) {
                return [
                    'id' => $dtech->code ?? ('DTECH-' . $dtech->id),
                    'name' => $dtech->name,
                    'agency' => $dtech->agency ?? 'DOST',
                    'sector' => $dtech->sector ?? 'DOST Program',
                    'domain' => str_contains(strtolower($dtech->sector ?? ''), 'food') ? 'agriculture' : 'general',
                    'status' => $dtech->status ?? 'Active',
                ];
            }

            if (str_starts_with($id, 'PROJ-')) {
                $proj = DostProject::find((int)str_replace('PROJ-', '', $id));
                if ($proj) {
                    return [
                        'id' => 'PROJ-' . $proj->id,
                        'name' => $proj->project_title,
                        'agency' => $proj->agency ?? 'DOST',
                        'sector' => $proj->sector ?? 'DOST Project',
                        'domain' => 'general',
                        'status' => $proj->project_status ?? 'Completed',
                    ];
                }
            }
        }

        return [
            'id' => 'DOST-INTERVENTION-GENERAL',
            'name' => 'DOST Technology and Livelihood Program',
            'agency' => 'DOST',
            'sector' => 'Community STI',
            'domain' => 'general',
            'status' => 'Active',
        ];
    }
}
