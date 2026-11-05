<?php

namespace App\FunctionTools\LayerF;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\LayerC\CalculatePriorityScoresTool;
use App\FunctionTools\Support\LocationResolver;
use App\Models\Municipality;

/**
 * Layer F (F2) Function Tool: define_performance_indicators
 *
 * Deterministically generates Key Performance Indicators (KPIs) for an intervention
 * tailored to a specific municipality, calculating quantitative targets from local
 * demographics, priority barangays, and baseline indicators.
 */
class DefinePerformanceIndicatorsTool implements FunctionToolInterface
{
    public function name(): string
    {
        return 'define_performance_indicators';
    }

    public function layer(): string
    {
        return 'F';
    }

    public function description(): string
    {
        return 'Define measurable Key Performance Indicators (KPIs) with baselines, empirical targets, units, measurement methods, and reporting frequencies for an intervention.';
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
                'domain' => [
                    'type' => 'string',
                    'description' => 'Optional sector/domain (health, education, agriculture, digital_connectivity, sti_capacity, safety_drrm).',
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
                'indicators' => [],
            ];
        }

        $domain = strtolower((string) ($args['domain'] ?? 'general'));

        $priorityResult = app(CalculatePriorityScoresTool::class)->execute(['municipality_id' => $municipality->id]);
        $priorityCount = count($priorityResult['barangays'] ?? $priorityResult['priority_scores'] ?? []);
        $targetBarangaysCovered = max(3, min(10, (int) ceil($priorityCount * 0.5)));
        $population = $municipality->populationFigure?->population ?? 25000;

        $targetTrained = max(20, min(150, (int) round(($population / 1000) * 3)));
        $targetBeneficiaries = max(500, min(15000, (int) round($population * 0.15)));

        $kpis = [
            [
                'indicator_name' => 'Technologies and Equipment Packages Deployed',
                'category' => 'Output',
                'baseline' => 0,
                'target' => 1,
                'unit' => 'complete package',
                'measurement_method' => 'Physical inspection and Property Transfer Report (PTR) sign-off',
                'reporting_frequency' => 'Milestone / Upon Completion',
                'data_source' => 'DOST PSTO Technical Inspection Report',
                'actual_accomplishment' => null,
                'status' => 'target_defined',
            ],
            [
                'indicator_name' => 'Underserved Barangays Directly Covered',
                'category' => 'Reach',
                'baseline' => 0,
                'target' => $targetBarangaysCovered,
                'unit' => 'barangays',
                'measurement_method' => 'Barangay outreach service logs and beneficiary distribution records',
                'reporting_frequency' => 'Quarterly',
                'data_source' => 'Municipal LGU Project Management Office Logbook',
                'actual_accomplishment' => null,
                'status' => 'target_defined',
            ],
            [
                'indicator_name' => 'Local Personnel and Operators Certified',
                'category' => 'Capacity Building',
                'baseline' => 0,
                'target' => $targetTrained,
                'unit' => 'individuals trained',
                'measurement_method' => 'Training attendance rosters, post-training evaluation, and Certificates of Completion',
                'reporting_frequency' => 'Semi-annually',
                'data_source' => 'DOST Training Completion Records',
                'actual_accomplishment' => null,
                'status' => 'target_defined',
            ],
            [
                'indicator_name' => 'Community Residents / MSMEs Benefited',
                'category' => 'Outcome',
                'baseline' => 0,
                'target' => $targetBeneficiaries,
                'unit' => 'direct beneficiaries',
                'measurement_method' => 'Registered user logs, consultation registries, or sales/productivity logs',
                'reporting_frequency' => 'Quarterly',
                'data_source' => 'Municipal Department and Community Partner Service Registries',
                'actual_accomplishment' => null,
                'status' => 'target_defined',
            ],
        ];

        // Domain-specific KPI additions
        if ($domain === 'agriculture') {
            $kpis[] = [
                'indicator_name' => 'Post-Harvest Volume Processed / Value-Added',
                'category' => 'Productivity',
                'baseline' => 0,
                'target' => 25,
                'unit' => 'metric tons / year',
                'measurement_method' => 'Processing facility production logs',
                'reporting_frequency' => 'Annually',
                'data_source' => 'Municipal Agriculture Office / Coop Production Ledger',
                'actual_accomplishment' => null,
                'status' => 'target_defined',
            ];
        } elseif ($domain === 'health') {
            $kpis[] = [
                'indicator_name' => 'Telehealth & Diagnostic Screenings Conducted',
                'category' => 'Service Delivery',
                'baseline' => 0,
                'target' => max(300, min(5000, (int) round($population * 0.08))),
                'unit' => 'screenings / consultations',
                'measurement_method' => 'Rural Health Unit digital diagnostic log',
                'reporting_frequency' => 'Monthly',
                'data_source' => 'Municipal Health Office Consultation Records',
                'actual_accomplishment' => null,
                'status' => 'target_defined',
            ];
        } elseif ($domain === 'education') {
            $kpis[] = [
                'indicator_name' => 'Digital Learning Material Accesses / Research Sessions',
                'category' => 'Service Delivery',
                'baseline' => 0,
                'target' => max(1000, min(20000, (int) round($population * 0.25))),
                'unit' => 'user queries / student sessions',
                'measurement_method' => 'STARBOOKS automated digital access counter',
                'reporting_frequency' => 'Quarterly',
                'data_source' => 'DOST STARBOOKS Automated Audit Log',
                'actual_accomplishment' => null,
                'status' => 'target_defined',
            ];
        }

        return [
            'municipality' => [
                'id' => $municipality->id,
                'name' => $municipality->name,
                'province' => $municipality->province_name,
                'population' => $population,
            ],
            'total_kpis' => count($kpis),
            'performance_indicators' => $kpis,
            'monitoring_framework' => [
                'lead_agency' => 'DOST Provincial Science and Technology Office (PSTO)',
                'lgu_counterpart_lead' => 'Municipal Planning and Development Office (MPDO)',
                'evaluation_milestones' => ['Month 3 Inception Review', 'Month 6 Mid-term Audit', 'Month 12 Annual Accomplishment Evaluation'],
            ],
        ];
    }
}
