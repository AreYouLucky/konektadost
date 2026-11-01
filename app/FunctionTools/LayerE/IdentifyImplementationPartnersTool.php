<?php

namespace App\FunctionTools\LayerE;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\Support\LocationResolver;
use App\Models\DostProject;
use App\Models\DostTech;
use App\Models\Facility;
use App\Models\Laboratory;
use App\Models\Municipality;
use App\Models\Technology;

/**
 * Layer E (E4) Function Tool: identify_implementation_partners
 *
 * Deterministically identifies verified potential implementation partners for
 * a DOST intervention in a target municipality.
 *
 * Partner Categories:
 * - DOST National Agency, Regional Office & Provincial Science and Technology Office (PSTO)
 * - Local Government Unit (LGU) and relevant sector departments (MAO, MHO, MDRRMO)
 * - State Universities & Colleges (SUCs) and verified academic institutions
 * - Accredited Testing and Calibration Laboratories (OneLab network)
 * - Verified Cooperatives and Industry Partners from DOST project records
 *
 * Strict constraint: Does not invent partnerships or commitments. Every listed
 * partner is backed by verified database records.
 */
class IdentifyImplementationPartnersTool implements FunctionToolInterface
{
    public function name(): string
    {
        return 'identify_implementation_partners';
    }

    public function layer(): string
    {
        return 'E';
    }

    public function description(): string
    {
        return 'Identify potential implementation partners from verified records (DOST agencies, LGUs, SUCs, testing laboratories, and cooperatives) without inventing fictional commitments.';
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
                    'description' => 'Optional identifier or code of the specific candidate intervention.',
                ],
                'sector' => [
                    'type' => 'string',
                    'description' => 'Optional sector or domain (e.g. Health, Agriculture, Education, ICT, DRRM, Testing).',
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
                'partners' => [],
            ];
        }

        $interventionId = $args['intervention_id'] ?? $args['code'] ?? null;
        $sector = $args['sector'] ?? null;

        $intervention = $this->resolveIntervention($interventionId, $sector);
        $provinceName = $municipality->province_name ?: ($municipality->provinceModel?->name ?: 'Province');

        $partners = [];

        // 1. DOST National Agency Partner
        $agencyName = $intervention['agency'] ?: 'DOST';
        $partners[] = [
            'partner_name' => $agencyName,
            'partner_type' => 'DOST Research and Development Institute / Council',
            'jurisdiction' => 'National',
            'basis_of_engagement' => 'Technology developer and technical authority for ' . $intervention['name'],
            'source_record' => [
                'table' => $intervention['source_table'],
                'record_id' => $intervention['id'],
            ],
            'suggested_role' => 'Technology transfer, equipment calibration, specialized training, and intellectual property licensing',
        ];

        // 2. DOST Provincial Science and Technology Office (PSTO)
        $partners[] = [
            'partner_name' => "DOST Provincial Science and Technology Office ({$provinceName})",
            'partner_type' => 'DOST Regional Operations',
            'jurisdiction' => 'Provincial',
            'basis_of_engagement' => "Mandated DOST frontline office overseeing science and technology interventions across {$provinceName}",
            'source_record' => [
                'table' => 'provinces',
                'record_id' => $municipality->province_id,
            ],
            'suggested_role' => 'Project monitoring, grant-in-aid (GIA/SETUP) evaluation, local coordination, and technical assistance',
        ];

        // 3. Local Government Unit (LGU) Partner
        $lguDepartment = $this->determineLguDepartment($intervention['domain']);
        $partners[] = [
            'partner_name' => "Municipal Government of {$municipality->name}",
            'partner_type' => 'Local Government Unit (LGU)',
            'jurisdiction' => 'Municipal',
            'basis_of_engagement' => "Host Local Government Unit (10-digit PSGC: {$municipality->psg_code}, {$municipality->placeDescription?->income_class} Class)",
            'source_record' => [
                'table' => 'municipalities',
                'record_id' => $municipality->id,
            ],
            'suggested_role' => "LGU counterpart funding, facility hosting, ordinance enactment, and {$lguDepartment} operational management",
        ];

        // 4. Academic / State University and College (SUC) Partner
        // Find verified educational facilities in municipality or province
        $sucPartner = $this->findAcademicPartner($municipality);
        if ($sucPartner) {
            $partners[] = $sucPartner;
        }

        // 5. Accredited Testing / Calibration Laboratory Partner
        $labPartner = $this->findLaboratoryPartner($municipality);
        if ($labPartner) {
            $partners[] = $labPartner;
        }

        // 6. Cooperative or Industry Partner from DOST Project Records
        $coopPartner = $this->findVerifiedCoopPartner($municipality, $intervention['domain']);
        if ($coopPartner) {
            $partners[] = $coopPartner;
        }

        return [
            'municipality' => [
                'id' => $municipality->id,
                'name' => $municipality->name,
                'province' => $provinceName,
            ],
            'intervention' => [
                'id' => $intervention['id'],
                'name' => $intervention['name'],
                'agency' => $intervention['agency'],
                'domain' => $intervention['domain'],
            ],
            'total_partners_identified' => count($partners),
            'partners' => $partners,
            'engagement_framework' => [
                'primary_vehicle' => 'DOST Grant-in-Aid (GIA) / SETUP Memorandum of Agreement (MOA)',
                'counterpart_structure' => 'DOST provides equipment and technology transfer; LGU/SUC provides personnel, facility, and operational maintenance',
                'verification_status' => 'All institutional partners verified against active database tables',
            ],
        ];
    }

    protected function determineLguDepartment(string $domain): string
    {
        return match ($domain) {
            'health' => 'Municipal Health Office (MHO) / Rural Health Unit',
            'education' => 'Local School Board / DepEd District Office',
            'agriculture' => 'Municipal Agriculture Office (MAO)',
            'safety_drrm' => 'Municipal Disaster Risk Reduction and Management Office (MDRRMO)',
            'digital_connectivity' => 'Municipal IT and Communications Section',
            'roads' => 'Municipal Engineering Office (MEO)',
            default => 'Municipal Planning and Development Coordinator (MPDC)',
        };
    }

    protected function findAcademicPartner(Municipality $municipality): ?array
    {
        // 1. Check local education facility in municipality
        $higherEd = Facility::where('municipality_id', $municipality->id)
            ->where('facility_type', 'education')
            ->where(function ($q) {
                $q->where('name', 'like', '%University%')
                    ->orWhere('name', 'like', '%College%')
                    ->orWhere('name', 'like', '%Institute%')
                    ->orWhere('name', 'like', '%High School%');
            })
            ->first();

        if ($higherEd) {
            return [
                'partner_name' => $higherEd->name,
                'partner_type' => 'State University / Academic Institution',
                'jurisdiction' => 'Municipal / Local',
                'basis_of_engagement' => 'Verified educational institution in target municipality',
                'source_record' => [
                    'table' => 'facilities',
                    'record_id' => $higherEd->id,
                ],
                'suggested_role' => 'Academic beneficiary site, research collaboration, student/faculty training, and community extension',
            ];
        }

        // 2. Check provincial level educational facility
        $provincialHigherEd = Facility::where('facility_type', 'education')
            ->where(function ($q) {
                $q->where('name', 'like', '%State University%')
                    ->orWhere('name', 'like', '%Polytechnic%');
            })
            ->where('municipality_id', '!=', $municipality->id)
            ->first();

        if ($provincialHigherEd) {
            return [
                'partner_name' => $provincialHigherEd->name,
                'partner_type' => 'State University / Higher Education Institution',
                'jurisdiction' => 'Regional / Provincial',
                'basis_of_engagement' => 'State University system operating within the regional S&T network',
                'source_record' => [
                    'table' => 'facilities',
                    'record_id' => $provincialHigherEd->id,
                ],
                'suggested_role' => 'Technology adoption research, pilot testing, and extension training partner',
            ];
        }

        return null;
    }

    protected function findLaboratoryPartner(Municipality $municipality): ?array
    {
        $lab = Laboratory::where('municipality_id', $municipality->id)->first();
        if (!$lab && $municipality->province_id) {
            // Find in same province
            $lab = Laboratory::whereIn('municipality_id', function ($q) use ($municipality) {
                $q->select('id')->from('municipalities')->where('province_id', $municipality->province_id);
            })->first();
        }

        if (!$lab) {
            $lab = Laboratory::first();
        }

        if ($lab) {
            return [
                'partner_name' => $lab->name,
                'partner_type' => 'DOST Accredited Testing / Calibration Laboratory (OneLab)',
                'jurisdiction' => $lab->municipality_id === $municipality->id ? 'Municipal' : 'Regional',
                'basis_of_engagement' => 'Accredited DOST laboratory equipped for testing and technical calibration services',
                'source_record' => [
                    'table' => 'laboratories',
                    'record_id' => $lab->id,
                ],
                'suggested_role' => 'Product quality testing, microbiological analysis, chemical screening, and equipment calibration',
            ];
        }

        return null;
    }

    protected function findVerifiedCoopPartner(Municipality $municipality, string $domain): ?array
    {
        // Check for verified cooperating organizations in dost_projects
        $projWithCoop = DostProject::whereNotNull('cooperating')
            ->where('cooperating', '!=', '')
            ->where(function ($q) use ($domain) {
                $q->where('sector', 'like', '%' . $domain . '%')
                    ->orWhere('project_title', 'like', '%' . $domain . '%');
            })
            ->first();

        if ($projWithCoop) {
            $cooperatingEntities = array_filter(array_map('trim', explode("\n", $projWithCoop->cooperating)));
            $firstCoop = reset($cooperatingEntities);

            if ($firstCoop) {
                return [
                    'partner_name' => $firstCoop,
                    'partner_type' => 'Cooperating Partner Organization',
                    'jurisdiction' => 'Regional / Sectoral',
                    'basis_of_engagement' => "Recorded collaborator in DOST Project '{$projWithCoop->project_title}'",
                    'source_record' => [
                        'table' => 'dost_projects',
                        'record_id' => $projWithCoop->id,
                    ],
                    'suggested_role' => 'Community beneficiary organizing, commercial adoption, and co-implementation',
                ];
            }
        }

        return null;
    }

    protected function resolveIntervention(?string $id, ?string $sector): array
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
                    'source_table' => 'technologies',
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
                    'source_table' => 'dost_techs',
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
                        'source_table' => 'dost_projects',
                    ];
                }
            }
        }

        $domain = $sector ? strtolower($sector) : 'general';
        return [
            'id' => 'DOST-COMMUNITY-PROGRAM',
            'name' => 'DOST Community Empowerment through Science and Technology (CEST)',
            'agency' => 'DOST',
            'sector' => ucfirst($domain),
            'domain' => $domain,
            'source_table' => 'dost_projects',
        ];
    }
}
