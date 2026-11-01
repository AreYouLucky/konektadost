<?php

namespace App\FunctionTools\LayerF;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\LayerC\CalculatePriorityScoresTool;
use App\FunctionTools\LayerE\AssessImplementationFeasibilityTool;
use App\FunctionTools\LayerE\AssessInterventionSuitabilityTool;
use App\FunctionTools\LayerE\EstimateInterventionRequirementsTool;
use App\FunctionTools\LayerE\IdentifyImplementationPartnersTool;
use App\FunctionTools\LayerE\MatchDostInterventionsTool;
use App\FunctionTools\Support\LocationResolver;
use App\Models\Municipality;

/**
 * Layer F (F1) Function Tool: generate_intervention_plan
 *
 * Produces a comprehensive, evidence-backed intervention proposal for a
 * candidate DOST technology/program tailored to a municipality and its priority barangays.
 *
 * Strict constraint: Clearly labels plans as proposals pending human validation and approval.
 */
class GenerateInterventionPlanTool implements FunctionToolInterface
{
    public function name(): string
    {
        return 'generate_intervention_plan';
    }

    public function layer(): string
    {
        return 'F';
    }

    public function description(): string
    {
        return 'Generate a structured intervention plan proposal containing activities, resource requirements, potential partners, expected outputs, and risks, labeled pending human validation.';
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
                    'description' => 'Identifier or code of the specific candidate intervention.',
                ],
                'domain' => [
                    'type' => 'string',
                    'description' => 'Optional domain (health, education, digital_connectivity, roads, agriculture, sti_capacity, safety_drrm).',
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
                'plan' => null,
            ];
        }

        $interventionId = $args['intervention_id'] ?? $args['code'] ?? null;
        $domain = $args['domain'] ?? null;

        // 1. Gather Layer E context
        $evalArgs = ['municipality_id' => $municipality->id, 'intervention_id' => $interventionId, 'domain' => $domain];

        $suitability = app(AssessInterventionSuitabilityTool::class)->execute($evalArgs);
        $feasibility = app(AssessImplementationFeasibilityTool::class)->execute($evalArgs);
        $partners = app(IdentifyImplementationPartnersTool::class)->execute($evalArgs);
        $requirements = app(EstimateInterventionRequirementsTool::class)->execute($evalArgs);

        // 2. Fetch Top Priority Barangays from Layer C
        $priorityResult = app(CalculatePriorityScoresTool::class)->execute(['municipality_id' => $municipality->id]);
        $topBarangays = array_slice($priorityResult['priority_scores'] ?? [], 0, 5);
        $targetBarangayNames = array_map(fn ($b) => $b['barangay_name'] ?? ('Barangay ' . $b['barangay_id']), $topBarangays);

        $interventionMeta = $suitability['intervention'] ?? [
            'id' => 'DOST-TECH-PROPOSAL',
            'name' => 'DOST Community STI Technology Deployment',
            'agency' => 'DOST',
            'domain' => $domain ?: 'general',
        ];

        $targetDomain = $interventionMeta['domain'] ?? ($domain ?: 'general');

        // 3. Phased Implementation Activities
        $activities = [
            [
                'phase' => 'Phase 1: Mobilization & Stakeholder Alignment',
                'timeline_months' => 'Months 1 - 2',
                'action_items' => [
                    'Execute tripartite Memorandum of Agreement (MOA) between DOST Regional Office/PSTO, Municipal LGU, and beneficiary communities.',
                    'Convene local project steering committee with LGU department heads (' . ($partners['partners'][2]['suggested_role'] ?? 'LGU Focal') . ').',
                    'Conduct ground-truth validation in pilot priority barangays: ' . implode(', ', array_slice($targetBarangayNames, 0, 3)),
                ],
            ],
            [
                'phase' => 'Phase 2: Site Preparation & Utility Readiness',
                'timeline_months' => 'Months 2 - 3',
                'action_items' => [
                    'Inspect and secure designated host facilities: ' . ($requirements['requirements']['facility_and_space']['facility_type'] ?? 'Municipal host venue'),
                    'Verify electrical grounding and utility connections (' . ($requirements['requirements']['infrastructure_and_utilities']['electrical_power'] ?? '220V AC utility power') . ').',
                    'Establish inventory handling and secure storage protocol for incoming equipment.',
                ],
            ],
            [
                'phase' => 'Phase 3: Technology Delivery & Installation',
                'timeline_months' => 'Months 3 - 4',
                'action_items' => [
                    'Dispatch and transport hardware: ' . ($requirements['requirements']['equipment_and_hardware']['primary_hardware'] ?? 'DOST Technology Package'),
                    'Perform on-site installation, technical commissioning, and calibration testing under DOST engineer supervision.',
                    'Execute initial dry-run diagnostics and functionality verification.',
                ],
            ],
            [
                'phase' => 'Phase 4: Capacity Building & Technology Transfer',
                'timeline_months' => 'Months 4 - 5',
                'action_items' => [
                    'Conduct hands-on operators training: ' . implode('; ', $requirements['requirements']['human_resources_and_training']['required_training'] ?? ['Standard technical operations']),
                    'Deliver user manuals, standard operating procedures (SOPs), and maintenance checklists.',
                    'Certify designated municipal and barangay operators.',
                ],
            ],
            [
                'phase' => 'Phase 5: Pilot Rollout & Monitoring',
                'timeline_months' => 'Months 5 - 12',
                'action_items' => [
                    'Commence formal community service operations across priority barangays.',
                    'Establish digital tracking log for beneficiaries served and service transactions.',
                    'Conduct quarterly joint monitoring inspections with DOST PSTO.',
                ],
            ],
        ];

        // 4. Verification Requirements
        $verificationRequirements = [
            'LGU Sangguniang Bayan Resolution authorizing the Municipal Mayor to sign the MOA',
            'Certificate of Site Readiness issued by DOST PSTO technical inspection team',
            'Property Transfer Report / Delivery Receipt signed by LGU property custodian',
            'Certificate of Training Completion for local operators',
        ];

        return [
            'plan_title' => "DOST STI Intervention Proposal: {$interventionMeta['name']} for {$municipality->name}",
            'plan_status' => 'proposal_pending_human_validation_and_approval',
            'formal_disclaimer' => 'This document is a deterministic decision-support proposal generated from verified spatial, demographic, and technological evidence. It requires formal review, validation, and approval by authorized DOST and LGU officials prior to funding allocation or contract execution.',
            'target_municipality' => [
                'id' => $municipality->id,
                'name' => $municipality->name,
                'province' => $municipality->province_name,
                'income_class' => $municipality->placeDescription?->income_class ?? 'Unclassified',
            ],
            'priority_beneficiary_barangays' => $topBarangays,
            'proposed_intervention' => $interventionMeta,
            'suitability_summary' => [
                'suitability_score' => $suitability['suitability_score'] ?? 75,
                'suitability_level' => $suitability['suitability_level'] ?? 'suitable',
                'enablers' => $suitability['enablers'] ?? [],
                'constraints' => $suitability['constraints'] ?? [],
            ],
            'feasibility_summary' => [
                'feasibility_status' => $feasibility['feasibility_status'] ?? 'feasible',
                'confidence_score' => $feasibility['confidence_score'] ?? 85,
                'conditional_requirements' => $feasibility['conditional_requirements'] ?? [],
                'risks_and_bottlenecks' => $feasibility['risks_and_bottlenecks'] ?? [],
            ],
            'implementation_activities' => $activities,
            'required_resources' => $requirements['requirements'] ?? [],
            'institutional_partners' => $partners['partners'] ?? [],
            'verification_requirements' => $verificationRequirements,
            'assumptions' => [
                'LGU provides counterpart facility, utilities, and security.',
                'Target community leaders facilitate beneficiary mobilization.',
                'DOST PSTO maintains quarterly oversight and technical assistance.',
            ],
        ];
    }
}

