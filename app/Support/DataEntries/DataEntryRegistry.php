<?php

namespace App\Support\DataEntries;

use App\Models\AgricultureCountEstimate;
use App\Models\DostProject;
use App\Models\DostTech;
use App\Models\Facility;
use App\Models\Laboratory;
use App\Models\LabServices;
use App\Models\Technology;
use App\Models\TelecomSite;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Every admin data-entry section. Add a sector by adding one definition here. */
class DataEntryRegistry
{
    /** @var array<string, DataEntryResource>|null */
    private ?array $resources = null;

    /** @return array<string, DataEntryResource> */
    public function all(): array
    {
        if ($this->resources !== null) {
            return $this->resources;
        }

        $resources = [
            $this->facilities('health-facilities', 'health', 'Health facilities', 'Health facility', 'HeartPulse',
                'Hospitals, clinics, pharmacies, health posts and other recorded health services.'),
            $this->facilities('education-facilities', 'education', 'Schools', 'School', 'GraduationCap',
                'Elementary, secondary and other schools used for education access indicators.'),
            $this->facilities('safety-facilities', 'safety', 'Safety & DRRM facilities', 'Safety facility', 'ShieldAlert',
                'Police and fire stations and other emergency and disaster-response facilities.'),
            $this->laboratories(),
            $this->labServices(),
            $this->cellSites(),
            $this->dostTechs(),
            $this->stiPrograms(),
            $this->dostProjects(),
            $this->agriculture(),
            $this->users(),
        ];
        $this->resources = [];
        foreach ($resources as $resource) {
            $this->resources[$resource->key] = $resource;
        }

        return $this->resources;
    }

    public function find(string $key): DataEntryResource
    {
        $resource = $this->all()[$key] ?? null;
        abort_if($resource === null || ! $resource->available(), 404);

        return $resource;
    }

    private function facilities(string $key, string $sector, string $label, string $singular, string $icon, string $description): DataEntryResource
    {
        return new DataEntryResource(
            key: $key, label: $label, singular: $singular, group: 'Health, education & safety', icon: $icon, description: $description,
            model: Facility::class,
            fields: [
                ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'list' => true, 'span' => 2],
                ['name' => 'facility_type', 'label' => 'Facility type', 'type' => 'text', 'required' => true, 'list' => true, 'max' => 100,
                    'suggestions' => fn () => $this->distinct('facilities', 'facility_type', fn ($q) => $q->where('sector', $sector)),
                    'help' => 'Use an existing type (e.g. '.match ($sector) {
                        'health' => 'hospital, clinic, pharmacy', 'education' => 'school', default => 'police_station, fire_station'
                    }.') so indicators count it.'],
                ['name' => 'level', 'label' => $sector === 'education' ? 'School level' : 'Level', 'type' => 'text', 'max' => 50,
                    'list' => $sector === 'education', 'suggestions' => fn () => $this->distinct('facilities', 'level', fn ($q) => $q->where('sector', $sector))],
                ['name' => 'operator', 'label' => 'Operator', 'type' => 'text', 'list' => true, 'suggestions' => ['government', 'private', 'ngo', 'religious']],
                ['name' => 'capacity', 'label' => 'Capacity (description)', 'type' => 'text', 'max' => 255, 'placeholder' => 'e.g. 50 beds'],
                ['name' => 'capacity_no', 'label' => 'Capacity (number)', 'type' => 'integer', 'min' => 0, 'max' => 65535],
                ['name' => 'address', 'label' => 'Address', 'type' => 'text', 'span' => 2],
                ['name' => 'latitude', 'label' => 'Latitude', 'type' => 'decimal', 'required' => true, 'min' => 4, 'max' => 22, 'step' => 'any'],
                ['name' => 'longitude', 'label' => 'Longitude', 'type' => 'decimal', 'required' => true, 'min' => 114, 'max' => 128, 'step' => 'any'],
                ['name' => 'location', 'label' => 'City / municipality and barangay', 'type' => 'location', 'list' => true, 'span' => 2],
                ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'span' => 2],
            ],
            search: ['name', 'facility_type', 'address', 'operator'],
            scope: fn ($query) => $query->where('sector', $sector),
            defaults: ['sector' => $sector, 'source' => 'Admin data entry'],
            saving: function (Model $record) use ($sector) {
                $record->sector = $sector;
            },
        );
    }

    private function laboratories(): DataEntryResource
    {
        return new DataEntryResource(
            key: 'laboratories', label: 'Laboratories', singular: 'Laboratory', group: 'STI resources', icon: 'FlaskConical',
            description: 'DOST and OneLab-network laboratories used for STI laboratory capacity.',
            model: Laboratory::class, title: 'agency_name',
            fields: [
                ['name' => 'agency_name', 'label' => 'Laboratory name', 'type' => 'text', 'required' => true, 'list' => true, 'span' => 2],
                ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'required' => true, 'unique' => true, 'list' => true, 'max' => 100],
                ['name' => 'source', 'label' => 'Source', 'type' => 'text', 'max' => 100, 'placeholder' => 'Admin data entry'],
                ['name' => 'city', 'label' => 'City (as written by the source)', 'type' => 'text', 'list' => true],
                ['name' => 'province', 'label' => 'Province', 'type' => 'text'],
                ['name' => 'region', 'label' => 'Region', 'type' => 'text'],
                ['name' => 'country', 'label' => 'Country', 'type' => 'text', 'placeholder' => 'Philippines'],
                ['name' => 'website', 'label' => 'Website', 'type' => 'url'],
                ['name' => 'contact_information', 'label' => 'Contact information', 'type' => 'text'],
                ['name' => 'latitude', 'label' => 'Latitude', 'type' => 'decimal', 'min' => -90, 'max' => 90, 'step' => 'any'],
                ['name' => 'longitude', 'label' => 'Longitude', 'type' => 'decimal', 'min' => -180, 'max' => 180, 'step' => 'any'],
                ['name' => 'location', 'label' => 'City / municipality and barangay', 'type' => 'location', 'span' => 2],
                ['name' => 'is_outside_philippines', 'label' => 'Located outside the Philippines', 'type' => 'boolean',
                    'help' => 'Overseas laboratories are excluded from every local inventory.'],
            ],
            search: ['agency_name', 'code', 'city', 'province'],
            orderBy: 'agency_name', direction: 'asc',
            defaults: ['source' => 'Admin data entry', 'country' => 'Philippines', 'services_count' => 0],
            saving: function (Model $record) {
                $record->has_coordinates = $record->latitude !== null && $record->longitude !== null;
                $record->services_count ??= 0;
            },
            deleting: fn (Model $record) => $record->services()->exists()
                ? 'This laboratory still has recorded services. Delete its services first.' : null,
        );
    }

    private function labServices(): DataEntryResource
    {
        return new DataEntryResource(
            key: 'lab-services', label: 'Laboratory services', singular: 'Laboratory service', group: 'STI resources', icon: 'TestTube',
            description: 'Tests and methods each laboratory offers, used for laboratory tests per million residents.',
            model: LabServices::class, title: 'service_name',
            fields: [
                ['name' => 'laboratory_id', 'label' => 'Laboratory', 'type' => 'select', 'required' => true, 'list' => true, 'span' => 2,
                    'options' => fn () => Laboratory::query()->orderBy('agency_name')->get(['id', 'agency_name', 'code'])
                        ->map(fn ($lab) => ['value' => $lab->id, 'label' => "{$lab->agency_name} ({$lab->code})"])->all()],
                ['name' => 'service_name', 'label' => 'Test / service', 'type' => 'text', 'required' => true, 'list' => true, 'span' => 2],
                ['name' => 'description', 'label' => 'Method', 'type' => 'text', 'list' => true, 'span' => 2],
                ['name' => 'reference', 'label' => 'Reference / standard', 'type' => 'text'],
                ['name' => 'fee', 'label' => 'Fee (PHP)', 'type' => 'integer', 'min' => 0, 'list' => true],
                ['name' => 'source', 'label' => 'Source', 'type' => 'text', 'max' => 100, 'placeholder' => 'Admin data entry'],
            ],
            search: ['service_name', 'description', 'reference'],
            defaults: ['source' => 'Admin data entry'],
        );
    }

    private function cellSites(): DataEntryResource
    {
        return new DataEntryResource(
            key: 'cell-sites', label: 'Cell sites', singular: 'Cell site', group: 'STI resources', icon: 'RadioTower',
            description: 'Recorded telecom towers and their technologies, used for digital connectivity.',
            model: TelecomSite::class,
            fields: [
                ['name' => 'name', 'label' => 'Site name', 'type' => 'text', 'required' => true, 'list' => true, 'span' => 2],
                ['name' => 'operator', 'label' => 'Network operator', 'type' => 'text', 'list' => true, 'suggestions' => ['Smart', 'Globe', 'DITO']],
                ['name' => 'tower_company', 'label' => 'Tower company', 'type' => 'text'],
                ['name' => 'technologies', 'label' => 'Technologies', 'type' => 'list', 'list' => true, 'suggestions' => ['2G', '3G', '4G', '5G'],
                    'help' => 'Separate with commas, e.g. 4G, 5G.'],
                ['name' => 'bands', 'label' => 'Bands', 'type' => 'list', 'help' => 'Separate with commas.'],
                ['name' => 'structure_type', 'label' => 'Structure type', 'type' => 'text'],
                ['name' => 'is_colocated', 'label' => 'Co-located with another operator', 'type' => 'boolean'],
                ['name' => 'latitude', 'label' => 'Latitude', 'type' => 'decimal', 'required' => true, 'min' => 4, 'max' => 22, 'step' => 'any'],
                ['name' => 'longitude', 'label' => 'Longitude', 'type' => 'decimal', 'required' => true, 'min' => 114, 'max' => 128, 'step' => 'any'],
                ['name' => 'location', 'label' => 'City / municipality and barangay', 'type' => 'location', 'list' => true, 'span' => 2],
                ['name' => 'barangay_name', 'label' => 'Barangay name (as written by the source)', 'type' => 'text',
                    'help' => 'Filled from the chosen barangay when left blank.'],
                ['name' => 'district', 'label' => 'District', 'type' => 'text'],
                ['name' => 'source_checked', 'label' => 'Source checked on', 'type' => 'date'],
                ['name' => 'coverage_area', 'label' => 'Coverage notes', 'type' => 'textarea', 'span' => 2],
            ],
            search: ['name', 'operator', 'tower_company', 'barangay_name'],
            saving: function (Model $record) {
                $barangay = $record->barangay_id ? DB::table('barangays')->where('id', $record->barangay_id)->first(['psg_code', 'brgy_name']) : null;
                // The PSGC is the strongest location evidence for cell sites (see resources:relink-locations).
                $record->barangay_psgc = $barangay->psg_code ?? null;
                if (blank($record->barangay_name)) {
                    $record->barangay_name = $barangay->brgy_name
                        ?? throw ValidationException::withMessages(['barangay_name' => 'Enter the barangay name or choose a barangay location.']);
                }
            },
        );
    }

    private function dostTechs(): DataEntryResource
    {
        return new DataEntryResource(
            key: 'dost-technologies', label: 'DOST technologies', singular: 'DOST technology', group: 'DOST catalog & programs', icon: 'Cpu',
            description: 'The deployable technology catalog matched against local gaps (Layer E).',
            model: DostTech::class,
            fields: [
                ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'required' => true, 'unique' => true, 'list' => true, 'placeholder' => 'SETUP-FOOD-01'],
                ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'list' => true],
                ['name' => 'agency', 'label' => 'Agency', 'type' => 'text', 'list' => true, 'suggestions' => fn () => $this->distinct('dost_techs', 'agency')],
                ['name' => 'sector', 'label' => 'Sector', 'type' => 'text', 'list' => true, 'suggestions' => fn () => $this->distinct('dost_techs', 'sector'),
                    'help' => 'Matching gives a sector match +35 points, so use an existing sector where it fits.'],
                ['name' => 'status', 'label' => 'Status', 'type' => 'text', 'list' => true, 'suggestions' => fn () => $this->distinct('dost_techs', 'status')],
                ['name' => 'deployment_clusters', 'label' => 'Deployment clusters', 'type' => 'textarea', 'span' => 2,
                    'help' => 'Where it is deployed; place names here become map reference pins.'],
                ['name' => 'mechanism', 'label' => 'Mechanism', 'type' => 'textarea', 'span' => 2],
                ['name' => 'outcome', 'label' => 'Outcome', 'type' => 'textarea', 'span' => 2],
            ],
            search: ['code', 'name', 'agency', 'sector'],
            orderBy: 'code', direction: 'asc',
        );
    }

    private function stiPrograms(): DataEntryResource
    {
        return new DataEntryResource(
            key: 'sti-programs', label: 'STI programs', singular: 'STI program', group: 'DOST catalog & programs', icon: 'BookOpen',
            description: 'STI programs such as STARBOOKS and DOSTv, also matched against local gaps.',
            model: Technology::class,
            fields: [
                ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'required' => true, 'unique' => true, 'list' => true],
                ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'list' => true],
                ['name' => 'agency', 'label' => 'Agency', 'type' => 'text', 'list' => true, 'suggestions' => fn () => $this->distinct('technologies', 'agency')],
                ['name' => 'category', 'label' => 'Category', 'type' => 'text', 'list' => true, 'suggestions' => fn () => $this->distinct('technologies', 'category')],
                ['name' => 'active_status', 'label' => 'Status', 'type' => 'text', 'list' => true, 'suggestions' => fn () => $this->distinct('technologies', 'active_status')],
                ['name' => 'delivery_mode', 'label' => 'Delivery mode', 'type' => 'text', 'suggestions' => fn () => $this->distinct('technologies', 'delivery_mode')],
                ['name' => 'duration_date', 'label' => 'Duration', 'type' => 'text'],
                ['name' => 'url', 'label' => 'Website', 'type' => 'url'],
                ['name' => 'target_audience', 'label' => 'Target audience', 'type' => 'textarea', 'span' => 2],
                ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'span' => 2],
                ['name' => 'tags', 'label' => 'Keywords', 'type' => 'text', 'span' => 2, 'max' => 2000, 'help' => 'Comma-separated; used by keyword matching.'],
                ['name' => 'source_reference', 'label' => 'Source reference', 'type' => 'text', 'span' => 2],
            ],
            search: ['code', 'name', 'agency', 'category', 'tags'],
            orderBy: 'code', direction: 'asc',
        );
    }

    private function dostProjects(): DataEntryResource
    {
        return new DataEntryResource(
            key: 'dost-projects', label: 'DOST projects', singular: 'DOST project', group: 'DOST catalog & programs', icon: 'History',
            description: 'Historical DOST projects: background evidence of past local work, not available technologies.',
            model: DostProject::class, title: 'project_title',
            fields: [
                ['name' => 'project_title', 'label' => 'Project title', 'type' => 'textarea', 'required' => true, 'list' => true, 'span' => 2, 'max' => 2000],
                ['name' => 'agency', 'label' => 'Agency', 'type' => 'text', 'list' => true, 'suggestions' => fn () => $this->distinct('dost_projects', 'agency')],
                ['name' => 'project_status', 'label' => 'Status', 'type' => 'text', 'list' => true,
                    'suggestions' => fn () => array_values(array_unique(array_map(
                        fn ($status) => strtolower($status) === 'comleted' ? 'completed' : $status,
                        $this->distinct('dost_projects', 'project_status'),
                    )))],
                ['name' => 'program', 'label' => 'Program', 'type' => 'text', 'span' => 2, 'max' => 2000],
                ['name' => 'sector', 'label' => 'Sector', 'type' => 'text'],
                ['name' => 'project_type', 'label' => 'Project type', 'type' => 'text'],
                ['name' => 'location', 'label' => 'Location (as written)', 'type' => 'text', 'list' => true, 'max' => 2000],
                ['name' => 'budget', 'label' => 'Budget', 'type' => 'text'],
                ['name' => 'start_date', 'label' => 'Start', 'type' => 'text', 'placeholder' => 'e.g. 2023-01'],
                ['name' => 'end_date', 'label' => 'End', 'type' => 'text', 'placeholder' => 'e.g. 2024-12'],
                ['name' => 'project_leader', 'label' => 'Project leader', 'type' => 'text'],
                ['name' => 'implementing', 'label' => 'Implementing agency', 'type' => 'text'],
                ['name' => 'funding', 'label' => 'Funding agency', 'type' => 'text'],
                ['name' => 'source_url', 'label' => 'Source link', 'type' => 'url'],
                ['name' => 'objectives', 'label' => 'Objectives', 'type' => 'textarea', 'span' => 2],
                ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'span' => 2, 'max' => 60000],
            ],
            search: ['project_title', 'agency', 'program', 'location', 'sector'],
            note: 'Status "comleted" in imported rows is shown and filtered as completed elsewhere in the app.',
        );
    }

    private function agriculture(): DataEntryResource
    {
        return new DataEntryResource(
            key: 'agriculture', label: 'Agriculture estimates', singular: 'Agriculture estimate', group: 'Livelihood', icon: 'Wheat',
            description: 'Modeled crop, livestock and fisheries estimates (quantity, revenue, cost and net per year).',
            model: AgricultureCountEstimate::class, title: 'commodity_name',
            fields: [
                ['name' => 'commodity_name', 'label' => 'Commodity', 'type' => 'text', 'required' => true, 'list' => true],
                ['name' => 'commodity_variant', 'label' => 'Variant', 'type' => 'text'],
                ['name' => 'category_id', 'label' => 'Category', 'type' => 'select', 'required' => true, 'list' => true,
                    'options' => fn () => DB::table('agriculture_categories')->orderBy('id')->get(['id', 'category_name'])
                        ->map(fn ($row) => ['value' => $row->id, 'label' => $row->category_name])->all()],
                ['name' => 'agriculture_type_id', 'label' => 'Type', 'type' => 'select', 'required' => true,
                    'options' => fn () => DB::table('agriculture_types as t')->join('agriculture_categories as c', 'c.id', '=', 't.category_id')
                        ->orderBy('c.id')->orderBy('t.type_name')->get(['t.id', 't.type_name', 'c.category_name'])
                        ->map(fn ($row) => ['value' => $row->id, 'label' => "{$row->category_name} · {$row->type_name}"])->all()],
                ['name' => 'model_year', 'label' => 'Year', 'type' => 'integer', 'required' => true, 'list' => true, 'min' => 1990, 'max' => 2100],
                ['name' => 'province_id', 'label' => 'Province', 'type' => 'select', 'required' => true, 'list' => true,
                    'options' => fn () => DB::table('provinces')->orderBy('name')->get(['id', 'name'])
                        ->map(fn ($row) => ['value' => $row->id, 'label' => $row->name])->all(),
                    'help' => 'Set automatically from the city when one is chosen.'],
                ['name' => 'location', 'label' => 'City / municipality and barangay (optional)', 'type' => 'location', 'span' => 2],
                ['name' => 'activity_type', 'label' => 'Activity', 'type' => 'text', 'suggestions' => fn () => $this->distinct('agriculture_count_estimates', 'activity_type')],
                ['name' => 'production_method', 'label' => 'Production method', 'type' => 'text'],
                ['name' => 'estimated_quantity_year', 'label' => 'Quantity per year', 'type' => 'decimal', 'min' => 0, 'step' => 'any', 'list' => true],
                ['name' => 'quantity_unit', 'label' => 'Unit', 'type' => 'text', 'max' => 50, 'suggestions' => fn () => $this->distinct('agriculture_count_estimates', 'quantity_unit')],
                ['name' => 'quantity_basis', 'label' => 'Quantity basis', 'type' => 'text', 'max' => 100],
                ['name' => 'area_sqm', 'label' => 'Area (m²)', 'type' => 'decimal', 'min' => 0, 'step' => 'any'],
                ['name' => 'area_kind', 'label' => 'Area kind', 'type' => 'text', 'max' => 100],
                ['name' => 'average_headcount', 'label' => 'Average headcount', 'type' => 'integer', 'min' => 0],
                ['name' => 'estimated_unit_price_php', 'label' => 'Unit price (PHP)', 'type' => 'decimal', 'min' => 0, 'step' => 'any'],
                ['name' => 'estimated_revenue_php_year', 'label' => 'Revenue per year (PHP)', 'type' => 'decimal', 'step' => 'any'],
                ['name' => 'estimated_operating_cost_php_year', 'label' => 'Operating cost per year (PHP)', 'type' => 'decimal', 'step' => 'any'],
                ['name' => 'estimated_net_php_year', 'label' => 'Net per year (PHP)', 'type' => 'decimal', 'step' => 'any'],
                ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'span' => 2, 'max' => 255],
            ],
            search: ['commodity_name', 'commodity_variant', 'record_code', 'source_province_name'],
            note: 'Entries made here are tagged MANUAL_ENTRY so they stay distinguishable from the PSA-based model rows.',
            defaults: [
                'record_code' => fn () => 'MANUAL-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'location_match_status' => 'manual_entry',
                'data_source_type' => 'MANUAL_ENTRY',
                'source_table' => 'admin_data_entries',
                'source_record_id' => 0,
                'source_data_json' => [],
                'source_report_title' => 'Admin data entry',
                'source_url' => '',
                'notes' => '',
            ],
            saving: function (Model $record, array $input, bool $creating) {
                if ($record->municipality_id) {
                    $record->province_id = DB::table('municipalities')->where('id', $record->municipality_id)->value('province_id') ?? $record->province_id;
                }
                $record->geographic_level = $record->barangay_id ? 'barangay' : ($record->municipality_id ? 'municipality' : 'province');
                $record->source_province_name = (string) DB::table('provinces')->where('id', $record->province_id)->value('name');
                $record->notes ??= '';
                if ($creating) {
                    // This table's id is not auto-incrementing.
                    $record->id = (int) DB::table('agriculture_count_estimates')->lockForUpdate()->max('id') + 1;
                }
            },
        );
    }

    private function users(): DataEntryResource
    {
        return new DataEntryResource(
            key: 'users', label: 'Users', singular: 'User', group: 'Access', icon: 'Users',
            description: 'Accounts and roles. Administrators reach this area; technicians and users get the user workspace.',
            model: User::class, orderBy: 'name', direction: 'asc',
            fields: [
                ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'list' => true],
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'unique' => true, 'list' => true],
                ['name' => 'role', 'label' => 'Role', 'type' => 'select', 'required' => true, 'list' => true, 'options' => [
                    ['value' => User::ROLE_ADMIN, 'label' => 'Administrator'],
                    ['value' => User::ROLE_TECHNICIAN, 'label' => 'Technician'],
                    ['value' => User::ROLE_USER, 'label' => 'User'],
                ]],
                ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'help' => 'At least 8 characters. Leave blank to keep the current password.'],
            ],
            search: ['name', 'email', 'role'],
            saving: function (Model $record, array $input, bool $creating) {
                $self = Auth::id() !== null && (int) $record->getKey() === (int) Auth::id();
                if ($self && $record->isDirty('role') && $record->getOriginal('role') === User::ROLE_ADMIN) {
                    throw ValidationException::withMessages(['role' => 'You cannot remove your own administrator role.']);
                }
                if ($creating) {
                    // Created by an administrator, so the address is treated as confirmed.
                    $record->email_verified_at = now();
                }
            },
            deleting: function (Model $record) {
                if ((int) $record->getKey() === (int) Auth::id()) {
                    return 'You cannot delete your own account here.';
                }

                // Their planning conversations and plans are removed by the foreign-key cascade.
                return $record->role === User::ROLE_ADMIN && User::query()->where('role', User::ROLE_ADMIN)->count() <= 1
                    ? 'This is the last administrator account.' : null;
            },
        );
    }

    /** @return list<string> */
    private function distinct(string $table, string $column, ?callable $scope = null): array
    {
        if (! Schema::hasColumn($table, $column)) {
            return [];
        }
        $query = DB::table($table)->whereNotNull($column)->where($column, '<>', '');
        if ($scope) {
            $scope($query);
        }

        return $query->distinct()->orderBy($column)->limit(60)->pluck($column)->map(fn ($value) => (string) $value)->all();
    }
}
