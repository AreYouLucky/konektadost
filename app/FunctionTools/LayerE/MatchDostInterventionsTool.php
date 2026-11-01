<?php

namespace App\FunctionTools\LayerE;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\LayerC\DetectDevelopmentGapsTool;
use App\FunctionTools\LayerD\SearchDostTechnologiesTool;
use App\FunctionTools\Support\LocationResolver;
use App\Models\AgricultureCountEstimate;
use App\Models\DostProject;
use App\Models\DostTech;
use App\Models\Facility;
use App\Models\Municipality;
use App\Models\Technology;
use App\Models\TelecomSite;
use Illuminate\Support\Facades\Schema;

/**
 * Layer E (E1) Function Tool: match_dost_interventions
 *
 * Deterministically matches identified municipal development gaps with potential
 * DOST technologies, programs, and STI interventions using rule-based criteria:
 * - Sector alignment
 * - Program objectives & technology applications
 * - Intended beneficiaries
 * - Geographic suitability & local assets
 * - Verified program availability
 *
 * Strict constraint: Does not force matches when no suitable intervention exists.
 * No AI integration.
 */
class MatchDostInterventionsTool implements FunctionToolInterface
{
    public function name(): string
    {
        return 'match_dost_interventions';
    }

    public function layer(): string
    {
        return 'E';
    }

    public function description(): string
    {
        return 'Match identified municipal development gaps with potential DOST technologies and STI interventions using deterministic sector alignment, beneficiary fit, local resource synergy, and verified catalog availability.';
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
                'sector' => [
                    'type' => 'string',
                    'description' => 'Optional sector filter (e.g. Health, Education, Agriculture, ICT, DRRM, Manufacturing, STI).',
                ],
                'barangay_id' => [
                    'type' => 'integer',
                    'description' => 'Optional specific barangay to focus intervention matching on.',
                ],
                'gaps' => [
                    'type' => 'array',
                    'description' => 'Optional pre-computed development gaps array from detect_development_gaps. If omitted, will be computed automatically.',
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
                'matches' => [],
            ];
        }

        // 1. Resolve gaps: use provided or run DetectDevelopmentGapsTool
        $gaps = $args['gaps'] ?? null;
        if (!is_array($gaps) || empty($gaps)) {
            $gapDetector = app(DetectDevelopmentGapsTool::class);
            $gapResult = $gapDetector->execute(['municipality_id' => $municipality->id]);
            $gaps = $gapResult['gaps'] ?? [];
        }

        // Sector filter if provided
        $filterSector = isset($args['sector']) && trim($args['sector']) !== '' ? strtolower(trim($args['sector'])) : null;

        // 2. Fetch local assets summary to check resource synergy
        $localContext = $this->gatherLocalContext($municipality);

        // 3. Match each gap against catalogs
        $gapMatches = [];
        $totalInterventionsMatched = 0;

        foreach ($gaps as $gap) {
            $domain = $gap['domain'] ?? 'general';
            if ($filterSector !== null && !str_contains(strtolower($domain), $filterSector) && !str_contains(strtolower($gap['gap_name'] ?? ''), $filterSector)) {
                continue;
            }

            $matchesForGap = $this->findInterventionsForGap($gap, $municipality, $localContext);
            $totalInterventionsMatched += count($matchesForGap);

            $gapMatches[] = [
                'gap_id' => $gap['gap_id'] ?? ($domain . '_gap'),
                'domain' => $domain,
                'gap_name' => $gap['gap_name'] ?? 'Identified Gap',
                'severity' => $gap['severity'] ?? 50,
                'affected_barangays_count' => count($gap['affected_barangays'] ?? []),
                'matched_interventions' => $matchesForGap,
                'has_suitable_intervention' => !empty($matchesForGap),
                'gap_unmatched_note' => empty($matchesForGap) 
                    ? 'No verified DOST catalog intervention currently recorded for this specific gap domain. Do not force arbitrary matches.' 
                    : null,
            ];
        }

        return [
            'municipality' => [
                'id' => $municipality->id,
                'psg_code' => $municipality->psg_code,
                'name' => $municipality->name,
                'province' => $municipality->province_name,
                'income_class' => $municipality->placeDescription?->income_class ?? 'Unclassified',
            ],
            'total_gaps_evaluated' => count($gaps),
            'total_gaps_with_matches' => count(array_filter($gapMatches, fn ($m) => !empty($m['matched_interventions']))),
            'total_interventions_matched' => $totalInterventionsMatched,
            'gap_interventions' => $gapMatches,
        ];
    }

    /**
     * Gathers quick local asset context (facility counts, agriculture commodities, telecom presence).
     */
    protected function gatherLocalContext(Municipality $municipality): array
    {
        $healthFacilityCount = Facility::query()
            ->where('municipality_id', $municipality->id)
            ->where('facility_type', 'health')
            ->count();

        $educationFacilityCount = Facility::query()
            ->where('municipality_id', $municipality->id)
            ->where('facility_type', 'education')
            ->count();

        $safetyFacilityCount = Facility::query()
            ->where('municipality_id', $municipality->id)
            ->where('facility_type', 'safety')
            ->count();

        $telecomCount = TelecomSite::query()
            ->where(function ($query) use ($municipality) {
                $query->where('municipality_id', $municipality->id);
                if ($municipality->psg_code) {
                    $prefix = substr($municipality->psg_code, 0, 6);
                    $query->orWhere('barangay_psgc', 'like', $prefix . '%');
                }
            })
            ->count();

        $agricultureCommodities = [];
        if (Schema::hasTable('agriculture_count_estimates')) {
            $munIds = [$municipality->id];
            $agricultureCommodities = AgricultureCountEstimate::query()
                ->whereIn('municipality_id', $munIds)
                ->distinct()
                ->pluck('commodity_name')
                ->take(10)
                ->toArray();
        }

        return [
            'health_facilities' => $healthFacilityCount,
            'education_facilities' => $educationFacilityCount,
            'safety_facilities' => $safetyFacilityCount,
            'telecom_towers' => $telecomCount,
            'agriculture_commodities' => $agricultureCommodities,
        ];
    }

    /**
     * Matches a single development gap against available DOST technologies.
     */
    protected function findInterventionsForGap(array $gap, Municipality $municipality, array $localContext): array
    {
        $domain = $gap['domain'] ?? '';
        $gapKeywords = $this->getDomainKeywords($domain, $gap['gap_name'] ?? '');
        $domainSectors = $this->getDomainSectors($domain);

        $candidateInterventions = [];

        // 1. Search Technology model (STI Program Catalog)
        $techQuery = Technology::query();
        $technologies = $techQuery->get();

        foreach ($technologies as $tech) {
            $score = $this->evaluateTechnologyRelevance($tech, $domain, $domainSectors, $gapKeywords, $localContext);
            if ($score['relevance_score'] >= 40) {
                $candidateInterventions[] = [
                    'source_catalog' => 'technologies',
                    'intervention_id' => $tech->code ?? ('TECH-' . $tech->id),
                    'code' => $tech->code ?? ('TECH-' . $tech->id),
                    'name' => $tech->name,
                    'agency' => $tech->agency ?? 'DOST',
                    'sector' => $tech->category ?? 'General STI',
                    'relevance_score' => $score['relevance_score'],
                    'matching_criteria' => $score['matching_criteria'],
                    'evidence' => [
                        'source' => 'technologies table',
                        'id' => $tech->id,
                        'source_reference' => $tech->source_reference ?? 'DOST STI Catalog',
                    ],
                    'intended_beneficiaries' => $tech->target_audience ?? 'General public and local institutions',
                    'availability_status' => in_array(strtolower($tech->active_status ?? ''), ['active', 'verified']) ? 'verified_available' : 'available',
                    'description' => $tech->description,
                ];
            }
        }

        // 2. Search DostTech model (NCR & Regional Masterlist Technologies)
        $dostTechQuery = DostTech::query();
        $dostTechs = $dostTechQuery->get();

        foreach ($dostTechs as $dtech) {
            $score = $this->evaluateDostTechRelevance($dtech, $domain, $domainSectors, $gapKeywords, $localContext);
            if ($score['relevance_score'] >= 40) {
                $candidateInterventions[] = [
                    'source_catalog' => 'dost_techs',
                    'intervention_id' => $dtech->code ?? ('DTECH-' . $dtech->id),
                    'code' => $dtech->code ?? ('DTECH-' . $dtech->id),
                    'name' => $dtech->name,
                    'agency' => $dtech->agency ?? 'DOST',
                    'sector' => $dtech->sector ?? 'DOST Program',
                    'relevance_score' => $score['relevance_score'],
                    'matching_criteria' => $score['matching_criteria'],
                    'evidence' => [
                        'source' => 'dost_techs table',
                        'id' => $dtech->id,
                        'mechanism' => $dtech->mechanism,
                    ],
                    'intended_beneficiaries' => 'Local MSMEs, cooperatives, and community organizations',
                    'availability_status' => strtolower($dtech->status ?? '') === 'active' ? 'verified_available' : 'available',
                    'description' => $dtech->outcome ?? $dtech->mechanism,
                ];
            }
        }

        // 3. Search DostProject model for high-relevance established programs (e.g. CEST, SETUP, ASTI-EWS)
        $projectQuery = DostProject::query();
        $matchingProjects = $projectQuery
            ->where(function ($q) use ($domainSectors, $gapKeywords) {
                foreach ($domainSectors as $sec) {
                    $q->orWhere('sector', 'like', '%' . $sec . '%');
                }
                foreach (array_slice($gapKeywords, 0, 3) as $kw) {
                    $q->orWhere('project_title', 'like', '%' . $kw . '%');
                }
            })
            ->whereNotNull('project_title')
            ->take(5)
            ->get();

        foreach ($matchingProjects as $proj) {
            $score = $this->evaluateProjectRelevance($proj, $domain, $domainSectors, $gapKeywords, $localContext);
            if ($score['relevance_score'] >= 45) {
                $candidateInterventions[] = [
                    'source_catalog' => 'dost_projects',
                    'intervention_id' => 'PROJ-' . $proj->id,
                    'code' => 'PROJ-' . $proj->id,
                    'name' => $proj->project_title,
                    'agency' => $proj->agency ?? 'DOST',
                    'sector' => $proj->sector ?? 'DOST Project',
                    'relevance_score' => $score['relevance_score'],
                    'matching_criteria' => $score['matching_criteria'],
                    'evidence' => [
                        'source' => 'dost_projects table',
                        'id' => $proj->id,
                        'program' => $proj->program,
                        'budget' => $proj->budget,
                    ],
                    'intended_beneficiaries' => 'LGU sectors and community beneficiaries',
                    'availability_status' => 'historical_benchmark',
                    'description' => $proj->description ?? $proj->objectives,
                ];
            }
        }

        // Sort candidates by relevance score descending
        usort($candidateInterventions, fn ($a, $b) => $b['relevance_score'] <=> $a['relevance_score']);

        // Return top 5 matches max per gap to maintain focus
        return array_slice($candidateInterventions, 0, 5);
    }

    protected function getDomainKeywords(string $domain, string $gapName): array
    {
        $domain = strtolower($domain);
        return match ($domain) {
            'health' => ['health', 'nutrition', 'clinic', 'hospital', 'medical', 'sanitation', 'water', 'telehealth', 'diagnostic'],
            'education' => ['learning', 'education', 'school', 'stem', 'starbooks', 'science', 'digital library', 'training'],
            'digital_connectivity' => ['ict', 'connectivity', 'telecom', 'cell', 'internet', 'smart', 'network', 'wireless'],
            'roads' => ['road', 'infrastructure', 'transportation', 'access', 'paving', 'monitoring', 'logistics'],
            'agriculture' => ['agriculture', 'crop', 'farming', 'post-harvest', 'food processing', 'fisheries', 'aquaculture', 'cold chain'],
            'sti_capacity' => ['laboratory', 'testing', 'calibration', 'onelab', 'quality assurance', 'analysis', 'metrology'],
            'safety_drrm' => ['disaster', 'drrm', 'hazard', 'flood', 'early warning', 'weather', 'monitoring', 'climate'],
            default => [strtolower($domain), strtolower($gapName)],
        };
    }

    protected function getDomainSectors(string $domain): array
    {
        $domain = strtolower($domain);
        return match ($domain) {
            'health' => ['Health & Nutrition', 'Health', 'Food processing', 'Food and Nutrition', 'Medicine'],
            'education' => ['Science Learning', 'Educational policies', 'Human Resource Development', 'ICT'],
            'digital_connectivity' => ['Smart Cities & ICT', 'ICT', 'Electronics', 'Information Networking'],
            'roads' => ['Metals & Engineering', 'Industrial Manufacturing', 'Construction', 'Metals and Engineering'],
            'agriculture' => ['Food Processing MSME', 'Food Manufacturing', 'Urban Agriculture', 'Agriculture', 'Fisheries', 'Aquaculture Safety'],
            'sti_capacity' => ['Testing & Technical', 'Testing laboratories', 'Science and Technology Infrastructure'],
            'safety_drrm' => ['Disaster Prevention (DRRM)', 'Disaster Mitigation', 'Environment', 'Climate & DRR'],
            default => ['General STI'],
        };
    }

    protected function evaluateTechnologyRelevance(Technology $tech, string $domain, array $domainSectors, array $keywords, array $localContext): array
    {
        $score = 20; // baseline
        $criteria = [];

        $category = $tech->category ?? '';
        $textToSearch = strtolower(($tech->name ?? '') . ' ' . ($tech->description ?? '') . ' ' . ($tech->tags ?? '') . ' ' . ($tech->target_audience ?? ''));

        // 1. Sector/Category match
        foreach ($domainSectors as $sec) {
            if (strcasecmp($category, $sec) === 0 || str_contains(strtolower($category), strtolower($sec))) {
                $score += 35;
                $criteria[] = "Direct category alignment: '{$category}' corresponds to {$domain} needs";
                break;
            }
        }

        // 2. Keyword matches
        $matchedKeywords = [];
        foreach ($keywords as $kw) {
            if (str_contains($textToSearch, $kw)) {
                $matchedKeywords[] = $kw;
            }
        }
        if (!empty($matchedKeywords)) {
            $keywordBonus = min(25, count($matchedKeywords) * 8);
            $score += $keywordBonus;
            $criteria[] = 'Keyword and concept overlap: ' . implode(', ', array_slice($matchedKeywords, 0, 4));
        }

        // 3. Local asset synergies
        if ($domain === 'education' && $localContext['education_facilities'] > 0) {
            $score += 15;
            $criteria[] = "Local facility readiness: {$localContext['education_facilities']} schools present for deployment";
        } elseif ($domain === 'health' && $localContext['health_facilities'] > 0) {
            $score += 15;
            $criteria[] = "Local health network: {$localContext['health_facilities']} health stations/clinics present";
        } elseif ($domain === 'agriculture' && !empty($localContext['agriculture_commodities'])) {
            $score += 15;
            $criteria[] = 'Local crop alignment: matches recorded commodities (' . implode(', ', array_slice($localContext['agriculture_commodities'], 0, 3)) . ')';
        }

        // 4. Active status check
        if (in_array(strtolower($tech->active_status ?? ''), ['active', 'verified'])) {
            $score += 10;
            $criteria[] = 'Verified active catalog status';
        }

        return [
            'relevance_score' => min(100, $score),
            'matching_criteria' => $criteria,
        ];
    }

    protected function evaluateDostTechRelevance(DostTech $tech, string $domain, array $domainSectors, array $keywords, array $localContext): array
    {
        $score = 20;
        $criteria = [];

        $sector = $tech->sector ?? '';
        $textToSearch = strtolower(($tech->name ?? '') . ' ' . ($tech->mechanism ?? '') . ' ' . ($tech->outcome ?? ''));

        // 1. Sector match
        foreach ($domainSectors as $sec) {
            if (strcasecmp($sector, $sec) === 0 || str_contains(strtolower($sector), strtolower($sec))) {
                $score += 35;
                $criteria[] = "Sector match: '{$sector}' aligns directly with {$domain}";
                break;
            }
        }

        // 2. Keyword overlap
        $matchedKeywords = [];
        foreach ($keywords as $kw) {
            if (str_contains($textToSearch, $kw)) {
                $matchedKeywords[] = $kw;
            }
        }
        if (!empty($matchedKeywords)) {
            $keywordBonus = min(25, count($matchedKeywords) * 8);
            $score += $keywordBonus;
            $criteria[] = 'Application overlap: ' . implode(', ', array_slice($matchedKeywords, 0, 4));
        }

        // 3. Local asset synergies
        if ($domain === 'agriculture' && !empty($localContext['agriculture_commodities'])) {
            $score += 15;
            $criteria[] = 'Value-addition alignment for local agricultural production';
        }

        // 4. Status
        if (strtolower($tech->status ?? '') === 'active') {
            $score += 10;
            $criteria[] = 'Active technology deployment status';
        }

        return [
            'relevance_score' => min(100, $score),
            'matching_criteria' => $criteria,
        ];
    }

    protected function evaluateProjectRelevance(DostProject $proj, string $domain, array $domainSectors, array $keywords, array $localContext): array
    {
        $score = 15;
        $criteria = [];

        $sector = $proj->sector ?? '';
        $textToSearch = strtolower(($proj->project_title ?? '') . ' ' . ($proj->description ?? '') . ' ' . ($proj->objectives ?? ''));

        foreach ($domainSectors as $sec) {
            if ($sector && str_contains(strtolower($sector), strtolower($sec))) {
                $score += 30;
                $criteria[] = "Historical project sector alignment: '{$sector}'";
                break;
            }
        }

        $matchedKeywords = [];
        foreach ($keywords as $kw) {
            if (str_contains($textToSearch, $kw)) {
                $matchedKeywords[] = $kw;
            }
        }
        if (!empty($matchedKeywords)) {
            $keywordBonus = min(20, count($matchedKeywords) * 6);
            $score += $keywordBonus;
            $criteria[] = 'Project scope alignment: ' . implode(', ', array_slice($matchedKeywords, 0, 3));
        }

        return [
            'relevance_score' => min(95, $score),
            'matching_criteria' => $criteria,
        ];
    }
}
