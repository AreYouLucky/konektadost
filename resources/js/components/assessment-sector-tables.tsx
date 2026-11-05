import { assessmentSectors } from '@/components/assessment-ai-analysis';
import { GapSeverityDetails, IndicatorCalculationDetails, InterventionCalculationDetails } from '@/components/assessment-calculation-details';
import type { CalculationInputs, MatchScoreBreakdown } from '@/lib/assessment-explanations';
import SourceBasisModal from '@/pages/location-assessment/source-basis-modal';
import { useState, type ReactNode } from 'react';

const number = (value: number | null | undefined) => (value == null ? 'Unavailable' : value.toLocaleString('en-PH', { maximumFractionDigits: 2 }));
const label = (value?: string | null) => (value ? value.replaceAll('_', ' ') : 'Unknown');

export function SectorSelect({ value, onChange, name }: { value: string; onChange: (value: string) => void; name: string }) {
    return (
        <label className="flex flex-wrap items-center gap-2 text-xs">
            Sector
            <select
                aria-label={name}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="bg-background max-w-full rounded-md border px-2 py-1.5"
            >
                <option value="all">All sectors</option>
                {Object.entries(assessmentSectors).map(([key, title]) => (
                    <option key={key} value={key}>
                        {title}
                    </option>
                ))}
            </select>
        </label>
    );
}

export function indicatorSector(dimension: string, key = '') {
    return (
        (
            {
                population_and_social_needs: 'population',
                health_and_public_health: 'health',
                education_skills_and_scientific_talent: 'education',
                science_technology_and_innovation_capacity: 'sti_capacity',
                infrastructure_and_connectivity: key.includes('road') ? 'roads' : 'digital_connectivity',
                environment_climate_disaster: 'safety_drrm',
                agriculture_fisheries_food: 'agriculture',
            } as Record<string, string>
        )[dimension] ?? dimension
    );
}

function TableFrame({ children, name }: { children: ReactNode; name: string }) {
    return (
        <div className="max-h-[32rem] overflow-auto rounded-lg border bg-white dark:bg-slate-950" tabIndex={0} role="region" aria-label={name}>
            {children}
        </div>
    );
}

export interface DevelopmentGap {
    indicator_key: string;
    label: string;
    dimension: string;
    gap_type?: string;
    measured_value: number | null;
    benchmark_value: number | null;
    unit: string;
    percentage_gap: number | null;
    severity_score: number | null;
    severity_level: string;
    confidence: string;
    affected_population: number | null;
    affected_barangays_count: number;
    source?: string;
    reason?: string;
    calculation?: CalculationInputs | null;
}

export interface SectorGapDiagnostic {
    sector: string;
    status: 'gaps_detected' | 'no_shortfall' | 'insufficient_evidence' | 'not_assessed';
    message: string;
    gap_count: number;
    comparable_count: number;
    evidence_issues: { indicator_key: string; label: string; reason: string }[];
}

function SectorEvidenceNote({ diagnostic }: { diagnostic: SectorGapDiagnostic }) {
    return (
        <div>
            <p>{diagnostic.message}</p>
            {diagnostic.evidence_issues.length > 0 && (
                <SourceBasisModal title={<>Why this sector could not be fully assessed</>}>
                    <ul className="mt-2 space-y-2">
                        {diagnostic.evidence_issues.map((issue) => (
                            <li key={issue.indicator_key}>
                                <strong>{issue.label}: </strong>
                                {issue.reason || 'Required measurement or reference is unavailable.'}
                            </li>
                        ))}
                    </ul>
                </SourceBasisModal>
            )}
        </div>
    );
}

export function GapSectorTable({
    detectedGaps,
    cityName,
}: {
    detectedGaps: { gaps: DevelopmentGap[]; total_gaps_detected: number; sector_diagnostics?: SectorGapDiagnostic[] };
    cityName: string;
}) {
    const [sector, setSector] = useState('all');
    const rows = detectedGaps.gaps.filter((row) => sector === 'all' || indicatorSector(row.dimension, row.indicator_key) === sector);
    const diagnostics = (detectedGaps.sector_diagnostics ?? []).filter((row) => row.gap_count === 0 && (sector === 'all' || row.sector === sector));
    return (
        <section className="min-w-0 space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="text-lg font-semibold text-[#087dcc]">Development gaps ({detectedGaps.total_gaps_detected})</h2>
                <SectorSelect name="Development gaps sector" value={sector} onChange={setSector} />
            </div>
            <p className="text-muted-foreground text-xs">
                Computed comparisons for {cityName}. Severity is a score out of 100; low-confidence findings remain provisional.
            </p>
            <TableFrame name="Scrollable sector gaps">
                <table className="w-full min-w-[850px] text-left text-xs">
                    <caption className="sr-only">Development gaps by sector, including measured rates, references and confidence</caption>
                    <thead className="sticky top-0 bg-blue-50 dark:bg-slate-900">
                        <tr>
                            {[
                                'Sector / indicator',
                                'Local / national reference',
                                'Gap',
                                'Severity / confidence',
                                'Population context',
                                'Evidence',
                            ].map((title) => (
                                <th key={title} className="p-3">
                                    {title}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {rows.map((gap) => (
                            <tr key={gap.indicator_key} className="align-top">
                                <th scope="row" className="max-w-56 p-3 text-left font-medium">
                                    <span className="text-muted-foreground mb-1 block text-[10px]">
                                        {assessmentSectors[indicatorSector(gap.dimension, gap.indicator_key)] ?? label(gap.dimension)}
                                    </span>
                                    {gap.label}
                                </th>
                                <td className="p-3">
                                    <span className="font-mono">
                                        {number(gap.measured_value)} / {number(gap.benchmark_value)}
                                    </span>
                                    <p className="text-muted-foreground mt-1">{gap.unit}</p>
                                </td>
                                <td className="p-3 font-mono">
                                    {gap.percentage_gap == null
                                        ? 'Unavailable'
                                        : `${gap.percentage_gap > 0 ? '+' : ''}${number(gap.percentage_gap)}%`}
                                </td>
                                <td className="p-3">
                                    <span className="font-semibold text-amber-700 capitalize dark:text-amber-300">
                                        {label(gap.severity_level)} · {number(gap.severity_score)}/100
                                    </span>
                                    <p className="mt-1 capitalize">
                                        {gap.confidence === 'high' ? 'High confidence' : 'Provisional / low confidence'}
                                    </p>
                                </td>
                                <td className="p-3">
                                    {number(gap.affected_population)}
                                    <p className="text-muted-foreground mt-1">{number(gap.affected_barangays_count)} barangays identified</p>
                                </td>
                                <td className="max-w-xs p-3">
                                    <SourceBasisModal title={`Source & basis: ${gap.label}`}>
                                        <p className="mt-2">{gap.reason}</p>
                                        <p className="text-muted-foreground mt-1">{gap.source}</p>
                                        <p className="mt-1 capitalize">{label(gap.gap_type)}</p>
                                        <IndicatorCalculationDetails
                                            indicatorKey={gap.indicator_key}
                                            value={gap.measured_value}
                                            reference={gap.benchmark_value}
                                            gap={gap.percentage_gap}
                                            calculation={gap.calculation}
                                        />
                                        <GapSeverityDetails percentage={gap.percentage_gap} confidence={gap.confidence} score={gap.severity_score} />
                                    </SourceBasisModal>
                                </td>
                            </tr>
                        ))}
                        {diagnostics.map((diagnostic) => (
                            <tr key={diagnostic.sector} className="align-top">
                                <th scope="row" className="p-3 text-left font-medium">
                                    {assessmentSectors[diagnostic.sector] ?? label(diagnostic.sector)}
                                </th>
                                <td colSpan={5} className="text-muted-foreground p-3">
                                    <SectorEvidenceNote diagnostic={diagnostic} />
                                </td>
                            </tr>
                        ))}
                        {rows.length === 0 && diagnostics.length === 0 && (
                            <tr>
                                <td colSpan={6} className="text-muted-foreground p-5">
                                    No computed gaps for this selection. Missing evidence does not establish adequate services.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </TableFrame>
        </section>
    );
}

interface Intervention {
    intervention_id: string;
    source_catalog: string;
    name: string;
    agency?: string;
    availability_status?: string;
    relevance_score?: number | null;
    score_breakdown?: MatchScoreBreakdown;
    matching_criteria?: string[];
    intended_beneficiaries?: string;
    description?: string;
}
interface GapMatch {
    gap_id: string;
    domain: string;
    gap_name: string;
    severity: number | null;
    gap_unmatched_note?: string;
    matched_interventions: Intervention[];
}
interface Evaluation {
    intervention?: { name?: string; code?: string };
    feasibility_status?: string;
    feasibility_summary?: string;
    reason?: string;
    suitability_score?: number | null;
    suitability_level?: string;
    verified_prerequisites?: { prerequisite: string; status: string }[];
    dimension_scores?: Record<string, number>;
    constraints?: string[];
    enablers?: string[];
}

// Requirements are structured tool output with descriptive scalar leaves.
type RequirementValue = string | number | boolean | null | RequirementValue[] | { [key: string]: RequirementValue };
function RequirementDetails({ value }: { value: RequirementValue }) {
    if (value === null) return <span>Unavailable</span>;
    if (typeof value !== 'object') return <span>{typeof value === 'boolean' ? (value ? 'Required' : 'Not required') : String(value)}</span>;
    return (
        <dl className="space-y-1.5">
            {Object.entries(value).map(([key, item]) => (
                <div key={key}>
                    <dt className="font-medium capitalize">{label(key)}</dt>
                    <dd className="text-muted-foreground">
                        <RequirementDetails value={item} />
                    </dd>
                </div>
            ))}
        </dl>
    );
}

export function InterventionSectorTable({
    cityName,
    matchedInterventions,
    suitability,
    feasibility,
    partners,
    requirements,
    diagnostics = [],
}: {
    cityName: string;
    matchedInterventions?: { gap_interventions?: GapMatch[] };
    suitability?: Evaluation;
    feasibility?: Evaluation;
    partners?: { partners?: { partner_name: string; partner_type: string; suggested_role: string; jurisdiction?: string }[] };
    requirements?: { intervention?: { name?: string }; requirements?: Record<string, RequirementValue> };
    diagnostics?: SectorGapDiagnostic[];
}) {
    const [sector, setSector] = useState('all');
    const matches = (matchedInterventions?.gap_interventions ?? []).filter((row) => sector === 'all' || row.domain === sector);
    const unmatchedSectors = diagnostics.filter((row) => row.gap_count === 0 && (sector === 'all' || row.sector === sector));
    const grouped = new Map<string, { gap: GapMatch; item: Intervention; related: GapMatch[] }>();
    const unmatched: { gap: GapMatch; item: null; related: GapMatch[] }[] = [];
    for (const gap of matches) {
        if (!gap.matched_interventions.length) unmatched.push({ gap, item: null, related: [gap] });
        for (const item of gap.matched_interventions) {
            const key = `${item.source_catalog}:${item.intervention_id}`;
            const previous = grouped.get(key);
            const best = !previous || (item.relevance_score ?? -1) > (previous.item.relevance_score ?? -1);
            grouped.set(key, { gap: best ? gap : previous.gap, item: best ? item : previous.item, related: [...(previous?.related ?? []), gap] });
        }
    }
    const rows = [...grouped.values()].sort((a, b) => (b.item.relevance_score ?? -1) - (a.item.relevance_score ?? -1));
    const displayRows = [...rows, ...unmatched];
    return (
        <section className="min-w-0 space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="text-lg font-semibold text-[#087dcc]">STI interventions by sector</h2>
                <SectorSelect name="Intervention sector" value={sector} onChange={setSector} />
            </div>
            <p className="text-muted-foreground text-xs">
                Catalog screening for {cityName}. Relevance scores indicate matching strength; confirm availability and local suitability with DOST.
            </p>
            <TableFrame name="Scrollable sector interventions">
                <table className="w-full min-w-[850px] text-left text-xs">
                    <caption className="sr-only">Sector gaps and their matched DOST catalog interventions</caption>
                    <thead className="sticky top-0 bg-blue-50 dark:bg-slate-900">
                        <tr>
                            {['Needs addressed', 'Catalog option', 'Agency / status', 'Best relevance', 'Evidence'].map((title) => (
                                <th key={title} className="p-3">
                                    {title}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {displayRows.map(({ gap, item, related }, index) => (
                            <tr key={`${gap.gap_id}:${item?.source_catalog}:${item?.intervention_id}:${index}`} className="align-top">
                                <th scope="row" className="max-w-52 p-3 font-medium">
                                    <span className="text-muted-foreground mb-1 block text-[10px]">
                                        {[...new Set(related.map((row) => row.domain))]
                                            .map((domain) => assessmentSectors[domain] ?? label(domain))
                                            .join(' / ')}
                                    </span>
                                    {[...new Set(related.map((row) => row.gap_name))].join('; ')}
                                </th>
                                <td className="max-w-64 p-3 font-medium">
                                    {item?.name ?? 'No verified catalog match'}
                                    {item && (
                                        <p className="text-muted-foreground mt-1 text-[10px] font-normal">
                                            {item.source_catalog === 'dost_projects' ? 'Historical project lead' : 'Technology / program'} ·{' '}
                                            {item.intervention_id}
                                        </p>
                                    )}
                                </td>
                                <td className="p-3">
                                    {item?.agency ?? 'Unavailable'}
                                    <p className="text-muted-foreground mt-1 capitalize">{label(item?.availability_status)}</p>
                                </td>
                                <td className="p-3 font-mono">{item ? `${number(item.relevance_score)}/100` : '—'}</td>
                                <td className="max-w-sm p-3">
                                    {item ? (
                                        <SourceBasisModal title={`Source & basis: ${item.name}`}>
                                            <p>Best returned match: {gap.gap_name}. The relevance score below applies to this match.</p>

                                            <InterventionCalculationDetails
                                                catalog={item.source_catalog}
                                                score={item.relevance_score}
                                                breakdown={item.score_breakdown}
                                            />
                                            <ul className="list-disc space-y-1 pl-5">
                                                {related.map((row) => {
                                                    const match = row.matched_interventions.find(
                                                        (candidate) =>
                                                            candidate.source_catalog === item.source_catalog &&
                                                            candidate.intervention_id === item.intervention_id,
                                                    );
                                                    return (
                                                        <li key={row.gap_id}>
                                                            {row.gap_name}: {number(match?.relevance_score)}/100 relevance; gap severity{' '}
                                                            {number(row.severity)}/100.
                                                        </li>
                                                    );
                                                })}
                                            </ul>
                                            <ul className="mt-2 list-inside list-disc">
                                                {item.matching_criteria?.map((criterion) => (
                                                    <li key={criterion}>{criterion}</li>
                                                ))}
                                            </ul>
                                            <p className="mt-2">Beneficiaries: {item.intended_beneficiaries ?? 'Not recorded'}</p>
                                            {item.description && <p className="mt-2">{item.description}</p>}
                                        </SourceBasisModal>
                                    ) : (
                                        gap.gap_unmatched_note
                                    )}
                                </td>
                            </tr>
                        ))}
                        {unmatchedSectors.map((diagnostic) => (
                            <tr key={diagnostic.sector} className="align-top">
                                <th scope="row" className="p-3 text-left font-medium">
                                    {assessmentSectors[diagnostic.sector] ?? label(diagnostic.sector)}
                                </th>
                                <td colSpan={4} className="text-muted-foreground p-3">
                                    <SectorEvidenceNote diagnostic={diagnostic} />
                                </td>
                            </tr>
                        ))}
                        {displayRows.length === 0 && unmatchedSectors.length === 0 && (
                            <tr>
                                <td colSpan={5} className="text-muted-foreground p-5">
                                    No intervention matches for this selection.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </TableFrame>
            {(feasibility || suitability) && (
                <section className="min-w-0 space-y-3 rounded-xl border border-sky-100 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
                    <h3 className="text-sm font-semibold text-[#087dcc]">
                        Implementation evaluation · {feasibility?.intervention?.name ?? suitability?.intervention?.name ?? 'Selected candidate'}
                    </h3>

                    <p className="text-muted-foreground my-3 text-xs">
                        These evaluations apply to the candidate named in each result. They do not establish readiness for every catalog match.
                    </p>
                    <TableFrame name="Implementation evaluations">
                        <table className="w-full min-w-[600px] text-left text-xs">
                            <caption className="sr-only">Feasibility and suitability of the evaluated intervention</caption>
                            <thead className="bg-blue-50 dark:bg-slate-900">
                                <tr>
                                    <th className="p-3">Evaluation / candidate</th>
                                    <th className="p-3">Result</th>
                                    <th className="p-3">Evidence and conditions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {feasibility && (
                                    <tr className="align-top">
                                        <th className="p-3">
                                            Feasibility<p className="font-normal">{feasibility.intervention?.name ?? 'Candidate unspecified'}</p>
                                        </th>
                                        <td className="p-3 capitalize">{label(feasibility.feasibility_status)}</td>
                                        <td className="p-3">
                                            {feasibility.feasibility_summary ?? feasibility.reason}
                                            <SourceBasisModal title={<>How feasibility is decided</>}>
                                                <p className="mt-2">
                                                    This is a checklist, not an average score. Missing essential baseline information gives
                                                    “insufficient information”. A blocking issue gives “not yet feasible”. Required follow-up actions
                                                    give “conditionally feasible”. Otherwise, the tool returns “feasible”.
                                                </p>
                                                <p className="mt-1">
                                                    Read the prerequisites and conditions below for this candidate. Current availability, partner
                                                    commitments and site readiness still need local confirmation.
                                                </p>
                                            </SourceBasisModal>
                                            <ul className="mt-2 space-y-1">
                                                {feasibility.verified_prerequisites?.map((item, index) => (
                                                    <li key={index}>
                                                        {item.prerequisite}: {label(item.status)}
                                                    </li>
                                                ))}
                                            </ul>
                                        </td>
                                    </tr>
                                )}
                                {suitability && (
                                    <tr className="align-top">
                                        <th className="p-3">
                                            Suitability<p className="font-normal">{suitability.intervention?.name ?? 'Candidate unspecified'}</p>
                                        </th>
                                        <td className="p-3 capitalize">
                                            {number(suitability.suitability_score)}/100 · {label(suitability.suitability_level)}
                                        </td>
                                        <td className="p-3">
                                            <SourceBasisModal title={<>How suitability is calculated</>}>
                                                <p className="mt-2">
                                                    Multiply each dimension rating below by its weight, add the results, then round to the nearest
                                                    whole number.
                                                </p>
                                                <p className="mt-1 font-mono break-words">
                                                    Need relevance × 0.30 + beneficiary reach × 0.20 + resource readiness × 0.15 + infrastructure
                                                    readiness × 0.20 + institutional readiness × 0.15
                                                </p>
                                                <p className="mt-1">
                                                    Highly suitable: 80–100; suitable: 65–below 80; moderately suitable: 45–below 65; low suitability:
                                                    below 45.
                                                </p>
                                                <p className="mt-1">
                                                    These are rule-based screening ratings. Some dimensions use default starting ratings, so the total
                                                    is planning advice rather than independently verified implementation readiness.
                                                </p>
                                            </SourceBasisModal>
                                            {Object.entries(suitability.dimension_scores ?? {}).map(([key, value]) => (
                                                <p key={key} className="capitalize">
                                                    {label(key)}: {number(value)}/100
                                                </p>
                                            ))}
                                            {[...(suitability.enablers ?? []), ...(suitability.constraints ?? [])].map((text, index) => (
                                                <p key={index} className="mt-1">
                                                    {text}
                                                </p>
                                            ))}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </TableFrame>
                </section>
            )}
            {!!partners?.partners?.length && (
                <section className="min-w-0 space-y-3 rounded-xl border border-sky-100 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
                    <h3 className="text-sm font-semibold text-[#087dcc]">Implementation partners ({partners.partners.length})</h3>

                    <p className="text-muted-foreground mt-2 text-xs">Suggested roles require confirmation with the named organizations.</p>
                    <TableFrame name="Implementation partners">
                        <table className="mt-3 w-full min-w-[600px] text-left text-xs">
                            <caption className="sr-only">Potential implementation partners and suggested roles</caption>
                            <thead className="bg-blue-50 dark:bg-slate-900">
                                <tr>
                                    <th className="p-3">Partner</th>
                                    <th className="p-3">Type / jurisdiction</th>
                                    <th className="p-3">Suggested role</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {partners.partners.map((item, index) => (
                                    <tr key={index}>
                                        <th className="p-3 text-left font-medium">{item.partner_name}</th>
                                        <td className="p-3">
                                            {item.partner_type} · {item.jurisdiction}
                                        </td>
                                        <td className="p-3">{item.suggested_role}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </TableFrame>
                </section>
            )}
            {requirements?.requirements && (
                <section className="min-w-0 space-y-3 rounded-xl border border-sky-100 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
                    <h3 className="text-sm font-semibold text-[#087dcc]">
                        Resource &amp; operational requirements · {requirements.intervention?.name ?? 'Selected candidate'}
                    </h3>

                    <p className="text-muted-foreground my-3 text-xs">Tool-generated planning requirements need local confirmation.</p>
                    <TableFrame name="Resource requirements">
                        <table className="w-full min-w-[600px] text-left text-xs">
                            <caption className="sr-only">Planning requirements by category</caption>
                            <tbody className="divide-y">
                                {Object.entries(requirements.requirements).map(([key, value]) => (
                                    <tr key={key} className="align-top">
                                        <th className="w-48 p-3 text-left font-medium capitalize">{label(key)}</th>
                                        <td className="p-3">
                                            <RequirementDetails value={value} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </TableFrame>
                </section>
            )}
        </section>
    );
}
