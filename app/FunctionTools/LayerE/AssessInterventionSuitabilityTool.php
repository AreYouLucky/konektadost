<?php

namespace App\FunctionTools\LayerE;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\LayerC\DetectDevelopmentGapsTool;
use App\FunctionTools\Support\LocationResolver;
use App\Models\AgricultureCountEstimate;
use App\Models\DostProject;
use App\Models\DostTech;
use App\Models\Facility;
use App\Models\Municipality;
use App\Models\Technology;
use App\Models\TelecomSite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Layer E (E2) Function Tool: assess_intervention_suitability
 *
 * Deterministically evaluates the suitability of a specific DOST technology or
 * intervention for a target municipality and its barangays.
 *
 * Evaluates five distinct dimensions:
 * 1. Need Relevance: Alignment with detected gaps and severity.
 * 2. Beneficiary Reach: Target population coverage, rural/income demographics.
 * 3. Resource Readiness: Availability of local raw materials, crops, or local inputs.
 * 4. Infrastructure Readiness: Cellular/telecom coverage, paved road connectivity.
 * 5. Institutional Readiness: Local facilities, host venues, and LGU service capacity.
 */
class AssessInterventionSuitabilityTool implements FunctionToolInterface
{
    public function name(): string
    {
        return 'assess_intervention_suitability';
    }

    public function layer(): string
    {
        return 'E';
    }

    public function description(): string
    {
        return 'Assess the suitability of a candidate DOST intervention for a target municipality based on gap alignment, target beneficiaries, local resources, infrastructure readiness, and institutional capacity.';
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
                'suitability_score' => null,
            ];
        }

        $interventionId = $args['intervention_id'] ?? $args['code'] ?? null;
        $domain = $args['domain'] ?? null;

        // 1. Resolve Intervention Metadata
        $interventionMeta = $this->resolveIntervention($interventionId, $domain);

        // 2. Evaluate Dimension 1: Need Relevance
        $needRelevance = $this->evaluateNeedRelevance($municipality, $interventionMeta['domain']);

        // 3. Evaluate Dimension 2: Beneficiary Reach
        $beneficiaryReach = $this->evaluateBeneficiaryReach($municipality, $interventionMeta);

        // 4. Evaluate Dimension 3: Resource Readiness
        $resourceReadiness = $this->evaluateResourceReadiness($municipality, $interventionMeta);

        // 5. Evaluate Dimension 4: Infrastructure Readiness
        $infraReadiness = $this->evaluateInfrastructureReadiness($municipality, $interventionMeta);

        // 6. Evaluate Dimension 5: Institutional Readiness
        $institutionalReadiness = $this->evaluateInstitutionalReadiness($municipality, $interventionMeta);

        // Calculate Weighted Composite Suitability Score (0 - 100)
        // Weights: Need Relevance (30%), Beneficiary (20%), Resources (15%), Infrastructure (20%), Institutional (15%)
        $compositeScore = round(
            ($needRelevance['score'] * 0.30) +
            ($beneficiaryReach['score'] * 0.20) +
            ($resourceReadiness['score'] * 0.15) +
            ($infraReadiness['score'] * 0.20) +
            ($institutionalReadiness['score'] * 0.15)
        );

        $suitabilityLevel = match (true) {
            $compositeScore >= 80 => 'highly_suitable',
            $compositeScore >= 65 => 'suitable',
            $compositeScore >= 45 => 'moderately_suitable',
            default => 'low_suitability',
        };

        // Consolidate Enablers and Constraints
        $enablers = array_merge(
            $needRelevance['enablers'],
            $beneficiaryReach['enablers'],
            $resourceReadiness['enablers'],
            $infraReadiness['enablers'],
            $institutionalReadiness['enablers']
        );

        $constraints = array_merge(
            $needRelevance['constraints'],
            $beneficiaryReach['constraints'],
            $resourceReadiness['constraints'],
            $infraReadiness['constraints'],
            $institutionalReadiness['constraints']
        );

        return [
            'municipality' => [
                'id' => $municipality->id,
                'name' => $municipality->name,
                'province' => $municipality->province_name,
                'income_class' => $municipality->placeDescription?->income_class ?? 'Unclassified',
                'urban_rural' => $municipality->placeDescription?->urban_rural ?? 'Rural',
            ],
            'intervention' => [
                'id' => $interventionMeta['id'],
                'name' => $interventionMeta['name'],
                'agency' => $interventionMeta['agency'],
                'sector' => $interventionMeta['sector'],
                'domain' => $interventionMeta['domain'],
                'status' => $interventionMeta['status'],
            ],
            'suitability_score' => $compositeScore,
            'suitability_level' => $suitabilityLevel,
            'dimension_scores' => [
                'need_relevance' => $needRelevance['score'],
                'beneficiary_reach' => $beneficiaryReach['score'],
                'resource_readiness' => $resourceReadiness['score'],
                'infrastructure_readiness' => $infraReadiness['score'],
                'institutional_readiness' => $institutionalReadiness['score'],
            ],
            'enablers' => array_values(array_unique($enablers)),
            'constraints' => array_values(array_unique($constraints)),
        ];
    }

    protected function resolveIntervention(?string $id, ?string $domain): array
    {
        if ($id) {
            // Check Technology
            $tech = Technology::where('code', $id)->orWhere('id', is_numeric($id) ? (int)$id : 0)->first();
            if ($tech) {
                return [
                    'id' => $tech->code ?? ('TECH-' . $tech->id),
                    'name' => $tech->name,
                    'agency' => $tech->agency ?? 'DOST',
                    'sector' => $tech->category ?? 'General STI',
                    'domain' => $this->mapSectorToDomain($tech->category ?? ''),
                    'status' => $tech->active_status ?? 'Active',
                    'target_audience' => $tech->target_audience,
                ];
            }

            // Check DostTech
            $dtech = DostTech::where('code', $id)->orWhere('id', is_numeric($id) ? (int)$id : 0)->first();
            if ($dtech) {
                return [
                    'id' => $dtech->code ?? ('DTECH-' . $dtech->id),
                    'name' => $dtech->name,
                    'agency' => $dtech->agency ?? 'DOST',
                    'sector' => $dtech->sector ?? 'DOST Program',
                    'domain' => $this->mapSectorToDomain($dtech->sector ?? ''),
                    'status' => $dtech->status ?? 'Active',
                    'target_audience' => 'MSMEs and community enterprises',
                ];
            }

            // Check DostProject
            if (str_starts_with($id, 'PROJ-')) {
                $projId = (int)str_replace('PROJ-', '', $id);
                $proj = DostProject::find($projId);
                if ($proj) {
                    return [
                        'id' => 'PROJ-' . $proj->id,
                        'name' => $proj->project_title,
                        'agency' => $proj->agency ?? 'DOST',
                        'sector' => $proj->sector ?? 'DOST Project',
                        'domain' => $this->mapSectorToDomain($proj->sector ?? ''),
                        'status' => $proj->project_status ?? 'Completed',
                        'target_audience' => 'Community stakeholders',
                    ];
                }
            }
        }

        // Fallback default intervention if only domain given or generic
        $targetDomain = $domain ?: 'general';
        return [
            'id' => 'DOST-INTERVENTION-GENERIC',
            'name' => 'DOST STI Intervention Package (' . ucfirst(str_replace('_', ' ', $targetDomain)) . ')',
            'agency' => 'DOST',
            'sector' => ucfirst($targetDomain),
            'domain' => $targetDomain,
            'status' => 'Active',
            'target_audience' => 'Underserved communities and LGUs',
        ];
    }

    protected function mapSectorToDomain(string $sector): string
    {
        $sec = strtolower($sector);
        return match (true) {
            str_contains($sec, 'health') || str_contains($sec, 'nutrition') || str_contains($sec, 'medicine') => 'health',
            str_contains($sec, 'learn') || str_contains($sec, 'education') || str_contains($sec, 'school') => 'education',
            str_contains($sec, 'ict') || str_contains($sec, 'smart') || str_contains($sec, 'electronic') => 'digital_connectivity',
            str_contains($sec, 'manufacturing') || str_contains($sec, 'metal') || str_contains($sec, 'construction') => 'roads',
            str_contains($sec, 'agri') || str_contains($sec, 'food') || str_contains($sec, 'fisher') => 'agriculture',
            str_contains($sec, 'test') || str_contains($sec, 'laborat') || str_contains($sec, 'metrol') => 'sti_capacity',
            str_contains($sec, 'disaster') || str_contains($sec, 'drr') || str_contains($sec, 'climate') || str_contains($sec, 'hazard') => 'safety_drrm',
            default => 'general',
        };
    }

    protected function evaluateNeedRelevance(Municipality $municipality, string $domain): array
    {
        $gaps = app(DetectDevelopmentGapsTool::class)->execute(['municipality_id' => $municipality->id])['gaps'] ?? [];
        $matchingGap = null;

        foreach ($gaps as $g) {
            if (strcasecmp($g['domain'] ?? '', $domain) === 0) {
                $matchingGap = $g;
                break;
            }
        }

        $enablers = [];
        $constraints = [];

        if ($matchingGap) {
            $severity = (float)($matchingGap['severity'] ?? 50);
            $score = min(100, max(40, round($severity * 1.1)));
            $enablers[] = "Directly addresses verified development gap: {$matchingGap['gap_name']} (Severity: {$severity}%)";
        } else {
            $score = 50; // Moderate baseline need
            $constraints[] = "No critical gap detected for {$domain} domain; intervention addresses general enhancement";
        }

        return ['score' => $score, 'enablers' => $enablers, 'constraints' => $constraints];
    }

    protected function evaluateBeneficiaryReach(Municipality $municipality, array $intervention): array
    {
        $population = $municipality->populationFigure?->population ?? 25000;
        $incomeClass = $municipality->placeDescription?->income_class ?? '';
        $enablers = [];
        $constraints = [];

        $score = 60; // base score

        // 3rd to 6th class municipalities are prime DOST CEST/SETUP targets
        if (in_array($incomeClass, ['3rd', '4th', '5th', '6th'])) {
            $score += 25;
            $enablers[] = "Target LGU is a {$incomeClass} class municipality, prioritized for DOST CEST and inclusive STI interventions";
        } else {
            $score += 15;
            $enablers[] = "Municipality has active local population base of {$population} residents";
        }

        return ['score' => min(100, $score), 'enablers' => $enablers, 'constraints' => $constraints];
    }

    protected function evaluateResourceReadiness(Municipality $municipality, array $intervention): array
    {
        $domain = $intervention['domain'];
        $enablers = [];
        $constraints = [];
        $score = 65;

        if ($domain === 'agriculture') {
            $agriCount = 0;
            if (Schema::hasTable('agriculture_count_estimates')) {
                $agriCount = AgricultureCountEstimate::where('municipality_id', $municipality->id)->count();
            }
            if ($agriCount > 0) {
                $score = 90;
                $enablers[] = "Verified local agricultural production records ({$agriCount} commodity data entries) available for technology adoption";
            } else {
                $score = 45;
                $constraints[] = 'Limited verified agricultural yield records for targeted raw material integration';
            }
        }

        return ['score' => $score, 'enablers' => $enablers, 'constraints' => $constraints];
    }

    protected function evaluateInfrastructureReadiness(Municipality $municipality, array $intervention): array
    {
        $enablers = [];
        $constraints = [];

        $telecomCount = TelecomSite::where('municipality_id', $municipality->id)->count();
        $roadCount = 0;
        if (Schema::hasTable('roads')) {
            $roadCount = DB::table('roads')->where('municipality_id', $municipality->id)->count();
        }

        $score = 50;

        if ($telecomCount > 0) {
            $score += 25;
            $enablers[] = "Local telecom connectivity active with {$telecomCount} recorded cell site(s)";
        } else {
            $constraints[] = 'Zero local cellular towers recorded; digital tools may require satellite or offline mode';
        }

        if ($roadCount > 0) {
            $score += 25;
            $enablers[] = "Physical road connectivity established with {$roadCount} mapped road segments";
        } else {
            $constraints[] = 'Limited road infrastructure mapping for physical equipment transport';
        }

        return ['score' => min(100, $score), 'enablers' => $enablers, 'constraints' => $constraints];
    }

    protected function evaluateInstitutionalReadiness(Municipality $municipality, array $intervention): array
    {
        $domain = $intervention['domain'];
        $enablers = [];
        $constraints = [];

        $type = match ($domain) {
            'health' => 'health',
            'education' => 'education',
            'safety_drrm' => 'safety',
            default => null,
        };

        $facilityQuery = Facility::where('municipality_id', $municipality->id);
        if ($type) {
            $facilityQuery->where('facility_type', $type);
        }
        $facilityCount = $facilityQuery->count();

        $score = 55;
        if ($facilityCount > 0) {
            $score += 35;
            $enablers[] = "Institutional hosting capacity present with {$facilityCount} verified relevant local facility/facilities";
        } else {
            $score = 40;
            $constraints[] = "No dedicated local {$domain} facilities recorded; requires LGU municipal hall or community center hosting";
        }

        return ['score' => min(100, $score), 'enablers' => $enablers, 'constraints' => $constraints];
    }
}
