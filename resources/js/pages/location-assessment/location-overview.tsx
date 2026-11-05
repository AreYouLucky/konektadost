import { Card, CardContent } from '@/components/ui/card';
import type { AssessmentPageProps } from './types';

export function LocationOverviewBar({ location, populationYear }: { location: AssessmentPageProps['location']; populationYear: number | null }) {
    const populationLabel = populationYear === null ? 'Population' : `Population (${populationYear})`;
    const popStats = location.population_statistics;
    const brgyCounts = location.barangay_counts;

    const classificationParts = [
        location.type,
        location.city_class ? `${location.city_class} City` : null,
        location.income_class ? `${location.income_class} Income Class` : null,
        location.urban_rural,
    ].filter(Boolean);

    const growthSummary = (() => {
        if (!popStats || popStats.population_baseline === null || popStats.baseline_year === null) {
            return 'No baseline census figure';
        }
        if (popStats.growth_rate_pct === null || popStats.population_change === null) {
            return `${popStats.population_baseline.toLocaleString()} (${popStats.baseline_year})`;
        }
        const sign = popStats.growth_rate_pct > 0 ? '+' : '';
        const diffSign = popStats.population_change > 0 ? '+' : '';
        return `${sign}${popStats.growth_rate_pct.toFixed(2)}% (${diffSign}${popStats.population_change.toLocaleString()} since ${popStats.baseline_year})`;
    })();

    const barangayBreakdownLabel = (() => {
        if (!brgyCounts || brgyCounts.total_barangays === 0) return '—';
        if (brgyCounts.urban_barangays > 0 || brgyCounts.rural_barangays > 0) {
            return `${brgyCounts.total_barangays.toLocaleString()} (${brgyCounts.urban_barangays} Urban · ${brgyCounts.rural_barangays} Rural)`;
        }
        return `${brgyCounts.total_barangays.toLocaleString()} barangays`;
    })();

    const items: Array<[string, string, string?]> = [
        ['Province / Region', location.province ?? '—', location.psg_code ? `PSGC ${location.psg_code}` : 'No verified PSGC'],
        ['Classification', classificationParts.length > 0 ? classificationParts.join(' · ') : '—'],
        [
            populationLabel,
            location.population?.toLocaleString() ?? 'No matched figure',
            popStats?.baseline_year && popStats?.population_baseline !== null
                ? `${popStats.baseline_year} Census: ${popStats.population_baseline.toLocaleString()}`
                : undefined,
        ],
        [
            'Population Trend',
            growthSummary,
            popStats?.annual_growth_rate_pct !== null && popStats?.annual_growth_rate_pct !== undefined
                ? `Annualized: ${popStats.annual_growth_rate_pct > 0 ? '+' : ''}${popStats.annual_growth_rate_pct.toFixed(2)}% / yr`
                : undefined,
        ],
        [
            'Land Area & Density',
            location.area_km2 ? `${location.area_km2.toLocaleString()} km²` : '—',
            location.population !== null && location.area_km2 !== null && location.area_km2 > 0
                ? `Density: ${(location.population / location.area_km2).toLocaleString(undefined, { maximumFractionDigits: 1 })} persons/km²`
                : undefined,
        ],
        ['Barangay Coverage', barangayBreakdownLabel],
    ];

    return (
        <Card className="rounded-2xl shadow-[0_4px_20px_-8px_#153d3824]">
            <CardContent className="p-4 sm:p-5">
                <div className="grid min-w-0 grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-6">
                    {items.map(([label, value, subtext]) => (
                        <div key={label} className="flex flex-col gap-0.5">
                            <span className="text-muted-foreground text-[11px] tracking-wide uppercase">{label}</span>
                            <span className="text-sm font-semibold break-words">{value}</span>
                            {subtext && <span className="text-muted-foreground text-[11px]">{subtext}</span>}
                        </div>
                    ))}
                </div>
            </CardContent>
        </Card>
    );
}
