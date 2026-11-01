import { EvidenceVisuals, ReferenceIndexChart } from '@/components/assessment-visuals';
import DataBars from '@/components/data-bars';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EVIDENCE_STATES, evidenceState } from '@/lib/assessment-planning';

interface IndicatorFigure {
    key: string;
    dimension: string;
    label: string;
    value: number | null;
    benchmark: number | null;
    gap: number | null;
    status: string;
    confidence: string;
    is_shortfall: boolean;
    unit: string;
}
interface BarangayFigure {
    name: string;
    population: number | null;
    priority_level: string;
}

const chartCard = 'min-w-0 rounded-2xl border-blue-100 bg-white shadow-[0_4px_20px_-8px_#12357524] dark:bg-slate-950';

export default function AssessmentCharts({
    results,
    barangays = [],
    populationYear,
    dimensions = [],
    populationBreakdown,
}: {
    results: IndicatorFigure[];
    barangays?: BarangayFigure[];
    populationYear: number | null;
    dimensions?: { key: string; label: string }[];
    populationBreakdown?: { level: string; year: number | null; rows: { name: string; value: number | null }[] };
}) {
    const measured = results.filter((row) => evidenceState(row) !== 'missing');
    const compared = results.filter((row) => row.status === 'available' && row.value !== null && row.benchmark !== null);
    const shortfalls = compared.filter((row) => row.is_shortfall && row.confidence === 'high');
    const gaps = results
        .filter((row) => row.status === 'available' && row.value !== null && row.benchmark !== null && row.gap !== null)
        .sort((a, b) => Math.abs(b.gap!) - Math.abs(a.gap!));
    const populations = barangays.length ? barangays.map((row) => ({ name: row.name, value: row.population })) : (populationBreakdown?.rows ?? []);
    const priority = new Map<string, number>();
    for (const row of barangays) priority.set(row.priority_level, (priority.get(row.priority_level) ?? 0) + 1);
    const labels: Record<string, string> = {
        high: 'High review priority',
        moderate: 'No relevant facilities recorded',
        covered: 'Recorded facilities present',
        unmeasured: 'Population unavailable',
    };
    return (
        <section aria-label="Assessment charts" className="space-y-4">
            <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
                {[
                    ['Measured indicators', measured.length, `${results.length - measured.length} unavailable`],
                    ['With national comparisons', compared.length, 'Descriptive national rates'],
                    ['High-confidence flags', shortfalls.length, 'Comparison flags for local review'],
                    [
                        'Data follow-up',
                        results.filter((row) => ['review', 'reference', 'missing'].includes(evidenceState(row))).length,
                        'Low confidence, missing data or reference',
                    ],
                ].map(([label, amount, note]) => (
                    <Card key={label} className={chartCard}>
                        <CardContent className="p-4">
                            <p className="text-muted-foreground text-xs">{label}</p>
                            <p className="mt-2 text-3xl font-semibold tabular-nums">{amount}</p>
                            <p className="text-muted-foreground mt-1 text-xs">{note}</p>
                        </CardContent>
                    </Card>
                ))}
            </div>
            <EvidenceVisuals results={results} dimensions={dimensions} />
            <div className="grid items-start gap-4 xl:grid-cols-2">
                <ReferenceIndexChart results={results} />
                <Card className={chartCard}>
                    <CardContent className="p-5">
                        <DataBars
                            title="Difference from the national reference (%)"
                            description="Negative bars are below the national rate; positive bars are above. Density reverses the pressure direction. Amber marks high-confidence flags, slate marks low confidence and navy marks within-comparison values. National rates are descriptive references."
                            rows={gaps.map((row) => ({
                                label: row.label,
                                value: row.gap,
                                formatted: `${row.gap! > 0 ? '+' : ''}${row.gap!.toFixed(1)}%`,
                                color: EVIDENCE_STATES[evidenceState(row)].color,
                            }))}
                        />
                        {!gaps.length && (
                            <p className="text-muted-foreground mt-3 text-xs">
                                A comparison chart requires a measured value and a positive national reference. Recorded values remain visible below
                                when a benchmark is missing.
                            </p>
                        )}
                    </CardContent>
                </Card>
            </div>
            {populations.length > 0 && (
                <div className="grid gap-4 lg:grid-cols-2">
                    <Card className={chartCard}>
                        <CardContent className="p-5">
                            <DataBars
                                title={`Largest ${barangays.length || populationBreakdown?.level === 'barangay' ? 'barangay' : 'city / municipal'} populations${populationYear === null ? '' : ` · ${populationYear}`}`}
                                description="Top 10 available figures; a missing figure is not zero."
                                rows={[...populations]
                                    .filter((row) => row.value !== null)
                                    .sort((a, b) => b.value! - a.value!)
                                    .slice(0, 10)
                                    .map((row) => ({ label: row.name, value: row.value, color: '#00aeef' }))}
                            />
                        </CardContent>
                    </Card>
                    {barangays.length > 0 && (
                        <Card className={chartCard}>
                            <CardContent className="p-5">
                                <DataBars
                                    title="Barangay recorded-coverage priorities"
                                    description="Heuristic labels based on population and recorded facilities, not validated service adequacy."
                                    rows={[...priority].map(([key, value]) => ({
                                        label: labels[key] ?? key,
                                        value,
                                        color: key === 'high' ? '#d97706' : key === 'unmeasured' ? '#94a3b8' : '#123575',
                                    }))}
                                />
                            </CardContent>
                        </Card>
                    )}
                </div>
            )}
            <Card className={chartCard}>
                <CardHeader>
                    <CardTitle className="text-sm">Indicator comparison table</CardTitle>
                </CardHeader>
                <CardContent className="overflow-x-auto">
                    <table className="w-full min-w-[620px] text-left text-xs">
                        <caption className="sr-only">Measurements, national references and data confidence</caption>
                        <thead>
                            <tr className="border-b">
                                {['Indicator / unit', 'Measured', 'National rate', 'Difference', 'Confidence / availability'].map((label) => (
                                    <th key={label} scope="col" className="p-2">
                                        {label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {results.map((row) => (
                                <tr key={row.key} className="border-b align-top">
                                    <th scope="row" className="p-2 font-medium">
                                        {row.label}
                                        <span className="text-muted-foreground block text-[10px] font-normal">{row.unit}</span>
                                    </th>
                                    <td className="p-2 tabular-nums">
                                        {row.value === null ? 'Unavailable' : row.value.toLocaleString('en-PH', { maximumFractionDigits: 3 })}
                                    </td>
                                    <td className="p-2 tabular-nums">
                                        {row.benchmark === null
                                            ? 'No reference'
                                            : row.benchmark.toLocaleString('en-PH', { maximumFractionDigits: 3 })}
                                    </td>
                                    <td className="p-2 tabular-nums">{row.gap === null ? '—' : `${row.gap > 0 ? '+' : ''}${row.gap.toFixed(1)}%`}</td>
                                    <td className="p-2">
                                        {row.status === 'no_data'
                                            ? 'No data'
                                            : `${row.confidence} confidence${row.status === 'no_benchmark' ? ' · no reference' : ''}`}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </CardContent>
            </Card>
        </section>
    );
}
