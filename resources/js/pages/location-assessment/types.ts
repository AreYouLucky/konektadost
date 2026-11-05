import { type SectorGapDiagnostic } from '@/components/assessment-sector-tables';
import type { CalculationInputs } from '@/lib/assessment-explanations';
import { type ProjectCatalogData } from '@/lib/assessment-planning';
import type { AgricultureSummary } from '@/pages/admin/search-map/use-province-agriculture';
import type { ProvinceResourceData } from '@/pages/admin/search-map/use-province-resources';

export interface AssessmentDimension {
    key: string;
    label: string;
    supported: boolean;
}

export interface AssessmentResult {
    key: string;
    label: string;
    description: string;
    unit: string;
    dimension: string;
    source: string;
    caveat: string | null;
    value: number | null;
    benchmark: number | null;
    median: number | null;
    gap: number | null;
    status: 'available' | 'no_data' | 'no_benchmark';
    confidence: 'high' | 'low';
    reason: string;
    is_shortfall: boolean;
    basis_note?: string | null;
    calculation?: CalculationInputs | null;
}

export interface BarangayItem {
    psg_code: string;
    name: string;
    population: number | null;
    population_share: number | null;
    area_km2: number | null;
    density: number | null;
    urban_rural: 'Urban' | 'Rural' | null;
    health_facilities_count: number;
    schools_count: number;
    safety_facilities_count: number;
    telecom_sites_count: number;
    total_facilities_count: number;
    priority_level: 'high' | 'moderate' | 'covered' | 'unmeasured';
    priority_reason: string;
    priority_score?: number | null;
    nearest_facility_km?: number | null;
    score_components?: { normalized_components?: Record<string, number> };
}

export interface BarangayAssessmentSummary {
    total_barangays: number;
    total_population: number;
    average_population: number | null;
    urban_count: number;
    rural_count: number;
    high_priority_count: number;
    underserved_barangays_count: number;
    underserved_population: number;
    underserved_population_share: number | null;
    most_populous: {
        name: string;
        population: number;
        population_share: number | null;
    } | null;
    highest_density: {
        name: string;
        density: number;
        population: number | null;
    } | null;
}

export interface LabServiceItem {
    test_name: string;
    method: string | null;
    reference: string | null;
    fee: number | null;
}

export interface LocalLaboratoryItem {
    id: number;
    agency_name: string | null;
    code: string | null;
    city: string | null;
    website: string | null;
    contact_information: string | null;
    total_services: number | null;
    services_status?: 'available' | 'unavailable';
    services: LabServiceItem[];
}

export interface DostInterventionItem {
    id: number;
    code: string;
    name: string;
    agency: string | null;
    sector: string | null;
    deployment_clusters: string | null;
    mechanism: string | null;
    status: string | null;
    outcome: string | null;
    matches_city_cluster: boolean;
    matches_focus: boolean;
}

export interface PopulationStatistics {
    active_year: number | null;
    population_current: number | null;
    baseline_year: number | null;
    population_baseline: number | null;
    population_change: number | null;
    growth_rate_pct: number | null;
    annual_growth_rate_pct: number | null;
    history: { year: number; population: number }[];
}

export interface BarangayClassificationCounts {
    total_barangays: number;
    urban_barangays: number;
    rural_barangays: number;
}

export interface DatasetAuditItem {
    key: string;
    label: string;
    table: string;
    status: 'available' | 'partial' | 'empty' | 'unavailable';
    local_records: number | null;
    national_records: number | null;
    barangays_represented?: number | null;
    total_barangays?: number | null;
    unmapped_local_records?: number | null;
    reference_year: string | number | null;
    notes: string;
}

export interface LocalDostProjectItem {
    id: number;
    agency: string | null;
    program: string | null;
    project_title: string | null;
    sector: string | null;
    location: string | null;
    raw_status: string | null;
    normalized_status: string;
    budget_php: number | null;
}

export interface FunctionToolsBundle {
    dataset_audit?: {
        summary: {
            available_count: number;
            partial_count: number;
            empty_count?: number;
            unavailable_count: number;
            total_datasets: number;
        };
        datasets: Record<string, DatasetAuditItem>;
    };
    local_assets?: {
        facility_counts?: { health?: number; education?: number; safety?: number; total?: number };
        facilities?: { total?: number; by_sector?: { health?: number; education?: number; safety?: number } };
        laboratory_count?: number;
        telecom_site_count?: number;
        telecom_sites?: { total_sites?: number };
    };
    telecom_infrastructure?: {
        total_sites: number;
        technologies_present?: string[];
        operators?: string[];
    };
    geographic_data_availability?: {
        has_geometry: boolean;
        area_km2?: number | null;
        local_road_records?: number;
        road_network?: { local_road_records?: number | null };
    };
    sector_analysis?: {
        health?: any;
        education?: any;
        connectivity?: any;
        road_accessibility?: {
            total_segments: number;
            total_length_km: number;
            road_density_km_per_km2: number | null;
            paved_ratio_pct: number | null;
            surface_distribution?: Record<string, number>;
            condition_distribution?: Record<string, number>;
        };
        agriculture?: any;
        sti_laboratory?: any;
        safety_and_drrm?: any;
    };
    detected_gaps?: {
        sector_diagnostics?: SectorGapDiagnostic[];
        total_gaps_detected: number;
        critical_severity_count?: number;
        critical_gaps_count?: number;
        gaps: any[];
    };
    underserved_areas?: any;
    priority_scores?: any;
    local_dost_projects?: {
        total_matched_projects: number;
        total_recorded_budget_php: number;
        by_agency: Record<string, number>;
        projects: LocalDostProjectItem[];
    };
    dost_technologies?: {
        total_matches?: number;
        results?: any[];
    };
    matched_interventions?: any;
}

export interface AssessmentPageProps {
    inventoryIntegrity?: {
        has_conflicts: boolean;
        inventories: {
            dataset: string;
            linked_records: number;
            outside_bounds_count: number;
            barangay_parent_conflicts: number;
            has_conflicts: boolean;
            reason: string | null;
        }[];
    };
    location: {
        psg_code: string | null;
        province_psg_code?: string | null;
        name: string;
        type: string | null;
        province: string | null;
        population: number | null;
        area_km2: number | null;
        income_class?: string | null;
        city_class?: string | null;
        urban_rural?: string | null;
        barangay_counts?: BarangayClassificationCounts | null;
        population_statistics?: PopulationStatistics | null;
    };
    focus: string;
    dimensions: AssessmentDimension[];
    results: AssessmentResult[];
    barangayAssessment?: {
        summary: BarangayAssessmentSummary;
        barangays: BarangayItem[];
    };
    localLaboratories?: LocalLaboratoryItem[];
    dostInterventions?: DostInterventionItem[];
    functionToolsBundle?: FunctionToolsBundle | null;
    layerEResult?: any;
    /** Collection year every population figure on this page belongs to. */
    populationYear?: number | null;
    populationReason?: string | null;
    assessmentParams?: Record<string, string | number>;
    mapReturnUrl?: string;
    assessmentLayout?: 'user' | 'admin';
    analysisScopeNotice?: string;
    resourceSummary?: ProvinceResourceData;
    agricultureSummary?: AgricultureSummary;
    projectCatalog?: ProjectCatalogData;
}
