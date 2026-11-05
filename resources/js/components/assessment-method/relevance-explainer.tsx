import { explainNumber } from '@/lib/assessment-explanations';
import { reportLabel, reportNumber, reportRecord, reportText } from '@/lib/assessment-report';
import { useState } from 'react';
import { ComputeStep, Waterfall, type StackKey } from './charts';

export const MATCH_COMPONENTS: StackKey[] = [
    { key: 'base', label: 'Starting points' },
    { key: 'sector', label: 'Sector match' },
    { key: 'keywords', label: 'Keyword match' },
    { key: 'local_assets', label: 'Local assets' },
    { key: 'status', label: 'Active status' },
];

/** Rule values per source catalog (MatchDostInterventionsTool). */
const CATALOG_RULES = [
    {
        key: 'technologies',
        label: 'STI programs',
        base: 20,
        sector: 35,
        keywords: 'up to 25 (8 per keyword)',
        assets: '15 (health, education or agriculture assets)',
        status: 10,
        cap: 100,
        threshold: 40,
    },
    {
        key: 'dost_techs',
        label: 'DOST technologies',
        base: 20,
        sector: 35,
        keywords: 'up to 25 (8 per keyword)',
        assets: '15 (agriculture commodities)',
        status: 10,
        cap: 100,
        threshold: 40,
    },
    {
        key: 'dost_projects',
        label: 'Past projects',
        base: 15,
        sector: 30,
        keywords: 'up to 20 (6 per keyword)',
        assets: '—',
        status: '—',
        cap: 95,
        threshold: 45,
    },
] as const;

const RULE_ROWS: { label: string; hint: string; get: (rule: (typeof CATALOG_RULES)[number]) => string | number }[] = [
    { label: 'Starting points', hint: 'Every catalog entry starts here.', get: (rule) => rule.base },
    { label: 'Sector match', hint: 'The entry’s sector/category is the same as the need’s sector.', get: (rule) => `+${rule.sector}` },
    { label: 'Keyword match', hint: 'Words from the need found in the entry’s name, description or mechanism.', get: (rule) => `+${rule.keywords}` },
    {
        label: 'Local assets',
        hint: 'The location has assets the entry can build on.',
        get: (rule) => (rule.assets === '—' ? '—' : `+${rule.assets}`),
    },
    { label: 'Active status', hint: 'Catalog status is active or verified.', get: (rule) => (rule.status === '—' ? '—' : `+${rule.status}`) },
    { label: 'Maximum score', hint: 'Points above this are cut off.', get: (rule) => rule.cap },
    { label: 'Listed when', hint: 'Below this the entry is not shown.', get: (rule) => `≥ ${rule.threshold}` },
];

/** Popup body for the relevance-score chart: rules, then a worked breakdown for one technology. */
export function RelevanceExplanation({ options }: { options: Record<string, unknown>[] }) {
    const [index, setIndex] = useState(0);
    const option = options[Math.min(index, options.length - 1)];
    if (!option) return null;
    const points = reportRecord(option.score_breakdown);
    const values = MATCH_COMPONENTS.map((key) => reportNumber(points[key.key]) ?? 0);
    const cap = reportNumber(points.cap) ?? (option.source_catalog === 'dost_projects' ? 95 : 100);
    const threshold = reportNumber(points.threshold) ?? (option.source_catalog === 'dost_projects' ? 45 : 40);
    const score = reportNumber(option.relevance_score);
    const criteria = Array.isArray(option.matching_criteria)
        ? option.matching_criteria.filter((item): item is string => typeof item === 'string')
        : [];

    return (
        <>
            <div className="min-w-0 space-y-6">
                <p className="text-sm text-slate-700 dark:text-slate-200">
                    A relevance score shows how strongly a DOST catalog entry matches one detected need. It is a fixed, rule-based count of points,
                    not an AI guess.
                </p>
                <section>
                    <p className="mb-3 text-xs font-semibold tracking-wide text-slate-500 uppercase">The rules</p>
                    <div className="overflow-x-auto rounded-xl border border-sky-100 dark:border-slate-800">
                        <table className="w-full min-w-[560px] text-left text-xs">
                            <thead className="bg-blue-50 dark:bg-slate-900">
                                <tr>
                                    <th className="p-2.5">Rule</th>
                                    {CATALOG_RULES.map((rule) => (
                                        <th key={rule.key} className={`p-2.5 ${rule.key === option.source_catalog ? 'bg-[#087dcc] text-white' : ''}`}>
                                            {rule.label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {RULE_ROWS.map((row) => (
                                    <tr key={row.label} className="align-top">
                                        <th className="p-2.5 font-medium">
                                            {row.label}
                                            <span className="text-muted-foreground block font-normal">{row.hint}</span>
                                        </th>
                                        {CATALOG_RULES.map((rule) => (
                                            <td
                                                key={rule.key}
                                                className={`p-2.5 font-mono ${rule.key === option.source_catalog ? 'bg-sky-50 font-semibold dark:bg-slate-900' : ''}`}
                                            >
                                                {row.get(rule)}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <p className="text-muted-foreground mt-2 text-[11px]">The highlighted column is the catalog of the technology selected below.</p>
                </section>

                <section className="space-y-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="text-xs font-semibold tracking-wide text-slate-500 uppercase">Worked example</p>
                        {options.length > 1 && (
                            <label className="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                                Technology
                                <select
                                    value={index}
                                    onChange={(event) => setIndex(Number(event.target.value))}
                                    className="bg-background max-w-64 rounded-md border px-2 py-1"
                                >
                                    {options.map((item, i) => (
                                        <option key={`${item.source_catalog}:${item.intervention_id}`} value={i}>
                                            {reportText(item.name)}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                    </div>
                    <div className="grid gap-4 lg:grid-cols-2">
                        <ol className="rounded-xl border border-sky-100 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
                            <ComputeStep index={1} title="The need it was matched to" worked={reportText(option.gap_name)} />
                            <ComputeStep
                                index={2}
                                title="The catalog entry"
                                worked={`${reportText(option.name)} · ${reportText(option.agency, 'Agency not recorded')}`}
                                note={`${reportLabel(option.source_catalog)} · status ${reportLabel(option.availability_status)}`}
                            />
                            <ComputeStep
                                index={3}
                                title="Add the points"
                                formula={`min(${cap}, start + sector + keywords + assets + status)`}
                                worked={`min(${cap}, ${values.join(' + ')}) = ${explainNumber(score)}`}
                            />
                            <ComputeStep
                                index={4}
                                title="Listed?"
                                worked={
                                    score != null && score >= threshold ? `Yes: ${explainNumber(score)} ≥ ${threshold}` : `No: below ${threshold}`
                                }
                                note="Listed options are sorted by score; up to 4 per need. Need severity is not added."
                            />
                        </ol>
                        <div className="viz-palette rounded-xl border border-sky-100 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
                            <p className="mb-3 text-sm font-semibold text-slate-800 dark:text-slate-100">Points added by each rule</p>
                            <Waterfall
                                steps={MATCH_COMPONENTS.map((key, i) => ({ label: key.label, value: values[i] }))}
                                finalValue={score}
                                finalLabel="Relevance"
                                max={100}
                                threshold={threshold}
                                thresholdLabel={`Listed when ≥ ${threshold}`}
                            />
                        </div>
                    </div>
                    {criteria.length > 0 && (
                        <div className="rounded-xl bg-sky-50 p-4 dark:bg-slate-900">
                            <p className="mb-2 text-xs font-semibold text-slate-700 dark:text-slate-200">Why these points were given</p>
                            <ul className="flex flex-wrap gap-1.5">
                                {criteria.map((text) => (
                                    <li
                                        key={text}
                                        className="rounded-full bg-white px-2.5 py-1 text-[11px] text-slate-700 ring-1 ring-sky-200 dark:bg-slate-950 dark:text-slate-200 dark:ring-slate-700"
                                    >
                                        {text}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </section>

                <section className="grid gap-3 sm:grid-cols-3">
                    {[
                        ['It is', 'How well the catalog entry fits one detected need.'],
                        ['It is not', 'A chance of success, approved funding, or proof the technology is available locally.'],
                        ['Next step', 'Confirm availability, cost and site readiness with DOST before deployment.'],
                    ].map(([title, text]) => (
                        <div key={title} className="rounded-xl border border-slate-200 p-3 dark:border-slate-800">
                            <p className="text-xs font-semibold text-slate-800 dark:text-slate-100">{title}</p>
                            <p className="text-muted-foreground mt-1 text-[11px]">{text}</p>
                        </div>
                    ))}
                </section>
            </div>
        </>
    );
}
