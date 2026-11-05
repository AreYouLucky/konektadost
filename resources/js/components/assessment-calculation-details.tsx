import {
    comparisonMeaning,
    explainNumber,
    indicatorMethod,
    interventionMethod,
    workedRate,
    type CalculationInputs,
    type MatchScoreBreakdown,
} from '@/lib/assessment-explanations';

export function IndicatorCalculationDetails({
    indicatorKey,
    value,
    reference,
    gap,
    status,
    calculation,
}: {
    indicatorKey: string;
    value: number | null;
    reference: number | null;
    gap: number | null;
    status?: string;
    calculation?: CalculationInputs | null;
}) {
    const method = indicatorMethod(indicatorKey);
    const worked = status === 'no_data' ? null : workedRate(calculation, value);
    return (
        <div className="mt-3 max-w-sm space-y-3 text-xs leading-relaxed font-normal break-words whitespace-normal">
            <div>
                <p className="font-semibold">How the local result is calculated</p>
                <p>{method.formula}</p>
                {worked && <p className="mt-1 rounded bg-sky-50 p-2 font-mono text-slate-700 dark:bg-slate-900 dark:text-slate-200">{worked}</p>}
                {calculation?.population_year && indicatorKey !== 'telecom_5g_share' && (
                    <p className="mt-1">Population year: {calculation.population_year}.</p>
                )}
                <p className="mt-1">{method.meaning}</p>
            </div>
            <div>
                <p className="font-semibold">Where the national reference comes from</p>
                <p>{method.reference}.</p>
                {indicatorKey === 'laboratory_tests_per_million' && (
                    <p className="mt-1">
                        The local count removes repeated service/method pairs; the national reference currently counts recorded service rows.
                    </p>
                )}
                <p className="mt-1">This reference describes the records in the database. It is not an official minimum service standard.</p>
            </div>
            <div>
                <p className="font-semibold">How the percentage gap is calculated</p>
                <p>(Local value − national reference) ÷ national reference × 100</p>
                {value != null && reference != null && reference > 0 && gap != null && status !== 'no_data' && (
                    <p className="mt-1 rounded bg-sky-50 p-2 font-mono text-slate-700 dark:bg-slate-900 dark:text-slate-200">
                        ({explainNumber(value)} − {explainNumber(reference)}) ÷ {explainNumber(reference)} × 100 ≈ {explainNumber(gap)}%
                    </p>
                )}
                <p className="mt-1">{comparisonMeaning(indicatorKey, value, reference, gap, status)}</p>
            </div>
            <p className="text-muted-foreground">
                Figures shown here are rounded. The calculation uses the underlying recorded values. A recorded zero does not prove that no service
                exists.
            </p>
        </div>
    );
}

export function GapSeverityDetails({ percentage, confidence, score }: { percentage: number | null; confidence: string; score: number | null }) {
    const factor = confidence === 'high' ? 1 : 0.65;
    return (
        <div className="mt-3 max-w-sm space-y-2 text-xs leading-relaxed break-words whitespace-normal">
            <p className="font-semibold">How severity is calculated</p>
            <p>
                Take the size of the percentage gap, ignoring its +/− sign. Cap it at 100, then multiply by {factor} because this finding has{' '}
                {confidence === 'high' ? 'high' : 'provisional / low'} confidence.
            </p>
            {percentage == null ? (
                <p>
                    No percentage gap is available. The engine uses zero gap magnitude in its severity formula; a zero score here does not establish
                    zero need.
                </p>
            ) : (
                <p className="rounded bg-sky-50 p-2 font-mono text-slate-700 dark:bg-slate-900 dark:text-slate-200">
                    min(100, |{explainNumber(percentage)}|) × {factor} ≈ {explainNumber(score)}/100
                </p>
            )}
            <p>
                Critical: 75–100; high: 50–below 75; moderate: 25–below 50; low: below 25. The server uses the unrounded percentage and rounds
                severity to one decimal place.
            </p>
            <p>The 0.65 factor reduces the weight of uncertain evidence. It does not mean a 65% chance that the finding is correct.</p>
            <p>
                Population context uses the selected area’s population unless coverage analysis identifies a more specific group. It is not a verified
                count of people without service; zero identified barangays does not establish zero need.
            </p>
        </div>
    );
}

export function InterventionCalculationDetails({
    catalog,
    score,
    breakdown,
}: {
    catalog: string;
    score?: number | null;
    breakdown?: MatchScoreBreakdown;
}) {
    const method = interventionMethod(catalog);
    const components = breakdown
        ? ([
              ['Starting points', breakdown.base],
              ['Sector alignment', breakdown.sector],
              ['Keyword overlap', breakdown.keywords],
              ['Relevant local assets', breakdown.local_assets],
              ['Active catalog status', breakdown.status],
          ] as const)
        : [];
    return (
        <div className="mt-3 max-w-sm space-y-3 text-xs leading-relaxed break-words whitespace-normal">
            <div>
                <p className="font-semibold">How the relevance score is calculated</p>
                <p>{method.formula}</p>
                <p className="mt-1">{method.assets}</p>
            </div>
            {breakdown && (
                <div>
                    <p className="font-semibold">Points earned by this match</p>
                    <dl className="mt-1 space-y-1">
                        {components.map(([name, points]) => (
                            <div className="flex justify-between gap-3" key={name}>
                                <dt>{name}</dt>
                                <dd className="font-mono">+{points}</dd>
                            </div>
                        ))}
                    </dl>
                    <p className="mt-2 rounded bg-sky-50 p-2 font-mono text-slate-700 dark:bg-slate-900 dark:text-slate-200">
                        min({breakdown.cap}, {components.map(([, points]) => points).join(' + ')}) = {explainNumber(score)}/100
                    </p>
                </div>
            )}
            <p>
                A candidate needs at least {breakdown?.threshold ?? method.threshold} points to be listed. Candidates are sorted by relevance,
                duplicate names are combined, and up to four matches are shown for each gap.
            </p>
            <p>
                Gap severity is not added to this score. Relevance describes matching strength, not a success percentage, approved funding, or
                confirmed implementation readiness. Historical projects provide examples to investigate; their current availability must be checked
                with DOST.
            </p>
        </div>
    );
}
