import { reportRecord, reportText, type AssessmentReportInput } from './assessment-report';

export interface AssessmentDataSource {
    data: string;
    source: string;
    reference: string;
    use: string;
}

/** Source names follow the imported dataset audit and indicator catalog, not AI output. */
export function assessmentDataSources(report: AssessmentReportInput): AssessmentDataSource[] {
    const datasets = reportRecord(reportRecord(reportRecord(report.functionToolsBundle).dataset_audit).datasets);
    const reference = (key: string, fallback: string) => {
        const value = reportRecord(datasets[key]).reference_year;
        const text = typeof value === 'number' ? String(value) : reportText(value, '');
        // Labels such as "Workbook Import" are not an actual source date/release.
        return /\b\d{4}\b/.test(text) ? text : fallback;
    };
    return [
        {
            data: 'Population',
            source: 'Philippine Statistics Authority (PSA), imported population figures',
            reference: reference('population_figures', report.populationYear ? String(report.populationYear) : 'Census year not recorded'),
            use: 'Population, growth and service rates',
        },
        {
            data: 'Administrative areas & classifications',
            source: 'PSA Philippine Standard Geographic Code (PSGC), imported records',
            reference: reference('place_descriptions', 'Release date not recorded'),
            use: 'Area identity, parent locations and classifications',
        },
        {
            data: 'Boundaries & land area',
            source: 'Imported GADM boundary inventory',
            reference: 'Boundary version not recorded',
            use: 'Area, population density and geographic scope',
        },
        {
            data: 'Health, education & safety facilities',
            source: 'OpenStreetMap contributors (osm_hot import)',
            reference: reference('facilities', 'Import date not recorded'),
            use: 'Facility counts and service comparisons',
        },
        {
            data: 'Laboratories & testing services',
            source: 'DOST / OneLab registry, imported laboratory and service records',
            reference: reference('laboratories', 'Registry date not recorded'),
            use: 'Laboratory capacity and referral options',
        },
        {
            data: 'Cell sites & 5G inventory',
            source: 'Imported cell-site workbooks; original provider not recorded in this report',
            reference: reference('telecom_sites', 'Workbook date not recorded'),
            use: 'Recorded sites and 5G share; not signal coverage',
        },
        {
            data: 'Agriculture, fisheries & livestock',
            source: 'Imported modeled estimates; original provider not recorded in this report',
            reference: reference('agriculture_count_estimates', 'Model year not recorded'),
            use: 'Production and financial planning estimates',
        },
        {
            data: 'DOST technologies, programs & projects',
            source: 'Imported DOST agency project masterlist and technology/program catalogs',
            reference: reference('dost_catalog', 'Catalog date not recorded'),
            use: 'Technology matching and historical project leads',
        },
        {
            data: 'Road network & barangay links',
            source: 'Imported road inventory; original provider not recorded in this report',
            reference: reference('roads', 'Reference date not recorded'),
            use: 'Road access, length and density where available',
        },
        {
            data: 'References, gaps & match scores',
            source: 'KonektaDOST calculations from the recorded datasets above',
            reference: 'Computed for this assessment',
            use: 'Dynamic comparisons, priorities and candidate screening',
        },
    ];
}
