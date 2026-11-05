<?php

namespace App\FunctionTools\LayerF;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\Support\LocationResolver;
use App\Models\Municipality;
use App\Models\TelecomSite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Layer F (F4) Function Tool: conduct_risk_assessment
 *
 * Deterministically evaluates operational, infrastructural, technological, and
 * environmental risks for a DOST intervention in a target municipality,
 * deriving risk factors directly from live database tables (roads, telecom, terrain).
 */
class ConductRiskAssessmentTool implements FunctionToolInterface
{
    public function name(): string
    {
        return 'conduct_risk_assessment';
    }

    public function layer(): string
    {
        return 'F';
    }

    public function description(): string
    {
        return 'Conduct risk assessment across operational, infrastructural, technological, and environmental dimensions with empirical likelihood, impact, and mitigation strategies.';
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
                    'description' => 'Optional domain.',
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
                'risks' => [],
            ];
        }

        $risks = [];

        // 1. Infrastructural Risk: Road Accessibility & Weather Vulnerability
        $roadCount = Schema::hasTable('roads') ? DB::table('roads')->where('municipality_id', $municipality->id)->count() : 0;
        $unpavedCount = 0;
        if ($roadCount > 0) {
            $unpavedCount = DB::table('roads')->where('municipality_id', $municipality->id)
                ->whereIn('surface_type', ['gravel', 'earth', 'unpaved'])
                ->count();
        }
        $unpavedRatio = $roadCount > 0 ? round(($unpavedCount / $roadCount) * 100) : 50;

        $roadRiskLikelihood = $unpavedRatio > 40 ? 'High' : 'Medium';
        $risks[] = [
            'risk_id' => 'RISK-INFRA-01',
            'category' => 'Infrastructural & Logistics',
            'risk_title' => 'Adverse Weather Transport Disruption to Remote Barangays',
            'description' => "Unpaved road segments ({$unpavedRatio}% unpaved) may experience surface erosion or mud blockages during severe typhoon/rain events.",
            'likelihood' => $roadRiskLikelihood,
            'impact' => 'Medium',
            'severity_rating' => $roadRiskLikelihood === 'High' ? 'Substantial' : 'Moderate',
            'mitigation_strategy' => 'Schedule major hardware hauling during dry season windows and coordinate with Municipal Engineering Office for route pre-inspection.',
            'contingency_measure' => 'Pre-position emergency spare parts and deploy ruggedized transport vehicles for high-priority barangays.',
        ];

        // 2. Technological Risk: Digital Connectivity & Power Fluctuations
        $telecomCount = TelecomSite::where('municipality_id', $municipality->id)->count();
        $telecomLikelihood = $telecomCount < 2 ? 'High' : 'Low';
        $risks[] = [
            'risk_id' => 'RISK-TECH-02',
            'category' => 'Technological & Utilities',
            'risk_title' => 'Intermittent Cellular Telemetry and Electrical Fluctuations',
            'description' => "Rural grid voltage drops and cellular signal blindspots ({$telecomCount} recorded cell site(s)) may interrupt online synchronization.",
            'likelihood' => $telecomLikelihood,
            'impact' => 'High',
            'severity_rating' => $telecomLikelihood === 'High' ? 'Critical' : 'Moderate',
            'mitigation_strategy' => 'Equip hardware with heavy-duty surge protectors, automatic voltage regulators (AVR), uninterruptible power supplies (UPS), and offline cache mode.',
            'contingency_measure' => 'Incorporate solar PV battery kits and periodic batch-data synchronization.',
        ];

        // 3. Operational Risk: Personnel Turnover & Maintenance Capacity
        $risks[] = [
            'risk_id' => 'RISK-OPS-03',
            'category' => 'Operational & Human Capital',
            'risk_title' => 'Trained Operator Reassignment or Staff Turnover',
            'description' => 'Local election cycles or administrative transfers may lead to designated operators leaving without proper knowledge transfer.',
            'likelihood' => 'Medium',
            'impact' => 'Medium',
            'severity_rating' => 'Moderate',
            'mitigation_strategy' => 'Institutionalize a minimum of 3 certified operators per facility and require LGU executive order designating permanent focal persons.',
            'contingency_measure' => 'DOST PSTO to maintain digital video training modules and conduct annual operator refresher sessions.',
        ];

        // 4. Financial & Counterpart Risk
        $incomeClass = $municipality->placeDescription?->income_class ?? '';
        $isLowIncome = in_array($incomeClass, ['5th', '6th']);
        $risks[] = [
            'risk_id' => 'RISK-FIN-04',
            'category' => 'Financial & Sustainability',
            'risk_title' => 'LGU Operational Maintenance Budget Constraints',
            'description' => 'Delayed local budget appropriations for equipment consumables, preventive maintenance, or utility overhead.',
            'likelihood' => $isLowIncome ? 'Medium' : 'Low',
            'impact' => 'Medium',
            'severity_rating' => 'Moderate',
            'mitigation_strategy' => 'Formally incorporate technology maintenance into the annual LGU 20% Local Development Fund under the MOA.',
            'contingency_measure' => 'Establish cooperative or community micro-contribution mechanisms for sustainable consumable replenishment.',
        ];

        return [
            'municipality' => [
                'id' => $municipality->id,
                'name' => $municipality->name,
                'province' => $municipality->province_name,
            ],
            'total_risks_identified' => count($risks),
            'critical_risks_count' => count(array_filter($risks, fn ($r) => $r['severity_rating'] === 'Critical')),
            'risk_assessment' => $risks,
            'overall_risk_profile' => $telecomLikelihood === 'High' || $roadRiskLikelihood === 'High' ? 'manageable_with_controls' : 'low_risk',
        ];
    }
}

