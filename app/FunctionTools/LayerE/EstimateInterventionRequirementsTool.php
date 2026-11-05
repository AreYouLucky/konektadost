<?php

namespace App\FunctionTools\LayerE;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\Support\LocationResolver;
use App\Models\DostProject;
use App\Models\DostTech;
use App\Models\Municipality;
use Illuminate\Support\Facades\Schema;
use App\Models\Technology;

/**
 * Layer E (E5) Function Tool: estimate_intervention_requirements
 *
 * Deterministically estimates equipment, facility, personnel, training,
 * utilities, and funding requirements for a DOST intervention.
 *
 * Funding Estimates:
 * Uses empirical historical benchmarks from `dost_projects` budget records.
 *
 * Strict constraint: Does not invent costs. If a verified historical cost is not
 * present, marks financial estimates as 'pending'.
 */
class EstimateInterventionRequirementsTool implements FunctionToolInterface
{
    public function name(): string
    {
        return 'estimate_intervention_requirements';
    }

    public function layer(): string
    {
        return 'E';
    }

    public function description(): string
    {
        return 'Estimate equipment, facility, personnel, training, utilities, and funding requirements for a DOST intervention using verified database records without inventing arbitrary costs.';
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
                    'description' => 'Identifier or code of the candidate intervention.',
                ],
                'sector' => [
                    'type' => 'string',
                    'description' => 'Optional sector or domain.',
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
                'requirements' => [],
            ];
        }

        $interventionId = $args['intervention_id'] ?? $args['code'] ?? null;
        $sector = $args['sector'] ?? null;
        $intervention = $this->resolveIntervention($interventionId, $sector);

        $domain = $intervention['domain'];

        // 1. Technical Equipment & Hardware
        $equipment = $this->determineEquipmentRequirements($domain, $intervention);

        // 2. Physical Space & Facility Requirements
        $facility = $this->determineFacilityRequirements($domain, $municipality);

        // 3. Human Resources & Capacity Building
        $humanResources = $this->determineHumanResourceRequirements($domain);

        // 4. Infrastructure & Utilities
        $utilities = $this->determineUtilityRequirements($domain);

        // 5. Funding Profile & Historical Benchmarks (No Hallucinated Costs!)
        $fundingProfile = $this->determineFundingProfile($domain, $intervention);

        // 6. Sustainability & Maintenance Requirements
        $sustainability = [
            'maintenance_protocol' => 'Annual preventive maintenance and recalibration scheduled via DOST Regional Office / OneLab',
            'lgu_counterpart_commitment' => 'LGU allocation for electricity, consumables, security, and designated operating personnel',
            'monitoring_frequency' => 'Quarterly progress reports submitted to DOST Provincial Science and Technology Office (PSTO)',
        ];

        return [
            'municipality' => [
                'id' => $municipality->id,
                'name' => $municipality->name,
                'province' => $municipality->province_name,
            ],
            'intervention' => $intervention,
            'requirements' => [
                'equipment_and_hardware' => $equipment,
                'facility_and_space' => $facility,
                'human_resources_and_training' => $humanResources,
                'infrastructure_and_utilities' => $utilities,
                'funding_profile' => $fundingProfile,
                'sustainability_and_maintenance' => $sustainability,
            ],
        ];
    }

    protected function determineEquipmentRequirements(string $domain, array $intervention): array
    {
        return match ($domain) {
            'education' => [
                'primary_hardware' => 'Digital Science Library Pod (STARBOOKS standalone kiosk or multi-user computer lab set)',
                'specifications' => 'Core i5/i3 processor, 8GB RAM, 1TB SSD preloaded with DOST-STII digital resources',
                'accessories' => 'Touchscreen kiosk enclosure, wireless router, surge protector, uninterruptible power supply (UPS)',
            ],
            'health' => [
                'primary_hardware' => 'Telehealth Diagnostic & Screening Kit (RxBox device or Point-of-Care Diagnostic Set)',
                'specifications' => 'Digital blood pressure monitor, pulse oximeter, ECG sensors, maternal/fetal Doppler, digital thermometer',
                'accessories' => 'Medical-grade tablets, Bluetooth synchronization modules, carrying cases for field deployment',
            ],
            'agriculture' => [
                'primary_hardware' => 'Post-Harvest Processing and Value-Addition Machinery (DOST-ITDI / MIRDC Food Processing Equipment)',
                'specifications' => 'Stainless steel food-grade dehydrator, vacuum packager, pulverizer, or retort cooker',
                'accessories' => 'Calibration gauges, food contact test strips, temperature loggers',
            ],
            'digital_connectivity' => [
                'primary_hardware' => 'Community Broadband / Mesh Network Equipment',
                'specifications' => 'Outdoor high-gain antennas, solar-ready PoE wireless access points, network routing switch',
                'accessories' => 'Weatherproof enclosure, grounding rod, battery backup',
            ],
            'safety_drrm' => [
                'primary_hardware' => 'Automated Weather / Hydromet Sensor Station (ARG/WLMS)',
                'specifications' => 'Tipping bucket rain gauge, ultrasonic water level sensor, solar PV power assembly',
                'accessories' => 'Cellular/satellite telemetry transmitter, heavy-duty mast assembly',
            ],
            'sti_capacity' => [
                'primary_hardware' => 'Laboratory Metrology & Testing Calibration Kit',
                'specifications' => 'Class E2/F1 analytical balance test weights, precision digital thermometer, pH/conductivity meter',
                'accessories' => 'ISO/IEC 17025 accredited calibration certificates, protective travel casing',
            ],
            default => [
                'primary_hardware' => 'DOST Standard Community STI Equipment Package',
                'specifications' => 'Standard technology transfer equipment calibrated to DOST specifications',
                'accessories' => 'Operational toolkit and spare components',
            ],
        };
    }

    protected function determineFacilityRequirements(string $domain, Municipality $municipality): array
    {
        return match ($domain) {
            'education' => [
                'facility_type' => 'Public School Library, Learning Resource Center, or Municipal Community Center',
                'minimum_area_sqm' => 20,
                'environmental_controls' => 'Well-ventilated, secure, dust-free space with protected power outlets',
            ],
            'health' => [
                'facility_type' => 'Rural Health Unit (RHU) Consultation Room or Barangay Health Station (BHS)',
                'minimum_area_sqm' => 15,
                'environmental_controls' => 'Sterile, clean clinical setting with secure storage for medical sensors',
            ],
            'agriculture' => [
                'facility_type' => 'LGU / Cooperative Agro-Processing Center or Demo Farm Facility',
                'minimum_area_sqm' => 50,
                'environmental_controls' => 'Food-grade washable walls/floors, potable running water, proper drainage (FDA-compliant)',
            ],
            'safety_drrm' => [
                'facility_type' => 'MDRRMO Emergency Operations Center (EOC) and elevated outdoor monitoring site',
                'minimum_area_sqm' => 25,
                'environmental_controls' => 'Unobstructed 360-degree exposure for rain gauge; secure indoor console',
            ],
            default => [
                'facility_type' => 'Municipal Government multi-purpose room or community facility',
                'minimum_area_sqm' => 20,
                'environmental_controls' => 'Standard municipal office amenities',
            ],
        };
    }

    protected function determineHumanResourceRequirements(string $domain): array
    {
        return [
            'focal_person' => 'Designated LGU Technical Coordinator (Project Lead)',
            'operators' => '2-3 trained municipal or barangay staff assigned for day-to-day operation',
            'required_training' => [
                'Technology Operation and Maintenance Protocol (DOST specialized training, 2-3 days)',
                'Data Logging, Troubleshooting, and Preventive Calibration',
                'User Safety, Hygiene, and Standards Compliance',
            ],
            'dost_technical_support' => 'Assigned DOST Provincial Science and Technology Office (PSTO) Technical Officer',
        ];
    }

    protected function determineUtilityRequirements(string $domain): array
    {
        return [
            'electrical_power' => 'Continuous 220V AC utility power (or dedicated 500W-1kW solar PV backup system for off-grid sites)',
            'connectivity' => 'Cellular 3G/4G/5G or satellite internet for telemetry/updates (offline mode supported if unserved)',
            'water_and_sanitation' => in_array($domain, ['health', 'agriculture']) ? 'Potable running water and clean drainage mandatory' : 'Standard facilities',
        ];
    }

    protected function determineFundingProfile(string $domain, array $intervention): array
    {
        // 1. Search actual historical project budgets from dost_projects table
        $budgetQuery = DostProject::query()
            ->whereNotNull('budget')
            ->where('budget', '!=', '')
            ->where('budget', '!=', '0');

        $matchingProjects = Schema::hasTable('dost_projects') ? $budgetQuery
            ->where(function ($q) use ($domain) {
                $q->where('sector', 'like', '%' . $domain . '%')
                    ->orWhere('project_title', 'like', '%' . $domain . '%');
            })
            ->take(20)
            ->get() : collect();

        $validBudgets = [];
        foreach ($matchingProjects as $p) {
            $parsed = $this->parseBudgetValue($p->budget);
            if ($parsed > 0) {
                $validBudgets[] = $parsed;
            }
        }

        if (!empty($validBudgets)) {
            sort($validBudgets);
            $count = count($validBudgets);
            $min = min($validBudgets);
            $max = max($validBudgets);
            $median = $validBudgets[(int)floor($count / 2)];

            return [
                'financial_status' => 'historical_benchmark_available',
                'historical_benchmark_currency' => 'PHP',
                'historical_benchmark_budget' => [
                    'min_php' => $min,
                    'median_php' => $median,
                    'max_php' => $max,
                    'sample_projects_count' => $count,
                ],
                'note' => 'Benchmark derived from real historical DOST project grants in this sector. Actual proposal budget will depend on detailed line-item costing.',
                'potential_funding_vehicles' => [
                    'DOST Community Empowerment through Science and Technology (CEST) Grant',
                    'DOST Small Enterprise Technology Upgrading Program (SETUP)',
                    'DOST Grants-in-Aid (GIA) Local Science & Technology Program',
                    'LGU 20% Local Development Fund counterpart',
                ],
            ];
        }

        // STRICT INSTRUCTION: "Do not invent costs. Mark missing financial estimates as pending."
        return [
            'financial_status' => 'pending',
            'historical_benchmark_currency' => 'PHP',
            'historical_benchmark_budget' => null,
            'note' => 'Financial estimate pending: No verified historical budget records found for this specific technology configuration. Costs must not be invented.',
            'potential_funding_vehicles' => [
                'DOST Grants-in-Aid (GIA)',
                'DOST Regional Office Technical Assistance Fund',
                'LGU Counterpart Budget Allocation',
            ],
        ];
    }

    protected function parseBudgetValue(?string $budgetStr): float
    {
        if (!$budgetStr) return 0.0;
        // Clean out currency symbols and whitespace
        $cleaned = trim(preg_replace('/[^0-9.]/', ' ', $budgetStr));
        $parts = array_filter(explode(' ', $cleaned));
        if (empty($parts)) return 0.0;

        // Take the first valid numeric segment
        $first = reset($parts);
        return is_numeric($first) ? (float)$first : 0.0;
    }

    protected function resolveIntervention(?string $id, ?string $sector): array
    {
        if ($id) {
            $tech = Schema::hasTable('technologies') ? Technology::where('code', $id)->orWhere('id', is_numeric($id) ? (int)$id : 0)->first() : null;
            if ($tech) {
                return [
                    'id' => $tech->code ?? ('TECH-' . $tech->id),
                    'name' => $tech->name,
                    'agency' => $tech->agency ?? 'DOST',
                    'sector' => $tech->category ?? 'General STI',
                    'domain' => str_contains(strtolower($tech->category ?? ''), 'learn') ? 'education' : 'general',
                ];
            }

            $dtech = Schema::hasTable('dost_techs') ? DostTech::where('code', $id)->orWhere('id', is_numeric($id) ? (int)$id : 0)->first() : null;
            if ($dtech) {
                return [
                    'id' => $dtech->code ?? ('DTECH-' . $dtech->id),
                    'name' => $dtech->name,
                    'agency' => $dtech->agency ?? 'DOST',
                    'sector' => $dtech->sector ?? 'DOST Program',
                    'domain' => str_contains(strtolower($dtech->sector ?? ''), 'food') ? 'agriculture' : 'general',
                ];
            }
        }

        $domain = $sector ? strtolower($sector) : 'education';
        return [
            'id' => 'DOST-TECH-PACKAGE',
            'name' => 'DOST Community STI Package',
            'agency' => 'DOST',
            'sector' => ucfirst($domain),
            'domain' => $domain,
        ];
    }
}
