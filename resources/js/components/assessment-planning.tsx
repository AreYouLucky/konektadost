import {
    evidenceState,
    matchProjects,
    planningTopics,
    rankedShortfalls,
    validationAction,
    type AgricultureContext,
    type IndicatorEvidence,
    type ProjectCatalogData,
} from '@/lib/assessment-planning';
import { ClipboardCheck, Cpu, Database, FlaskConical, Info, Target } from 'lucide-react';

const basisLabels = {
    comparison: 'High-confidence comparison',
    provisional: 'Validate low-confidence evidence',
    pressure: 'Population-pressure context',
    modeled: 'Modeled agriculture context',
};
const format = (value: number | null) => (value === null ? 'Unavailable' : value.toLocaleString('en-PH', { maximumFractionDigits: 3 }));
const surface = 'min-w-0 rounded-2xl border border-blue-100 bg-white p-5 shadow-[0_4px_20px_-8px_#12357524] dark:bg-slate-950';

export default function AssessmentPlanning({
    locationName,
    results,
    catalog,
    agriculture,
    locationMatches = [],
}: {
    locationName: string;
    results: IndicatorEvidence[];
    catalog: ProjectCatalogData;
    agriculture?: AgricultureContext;
    locationMatches?: { id: number; matches_city_cluster: boolean }[];
}) {
    const flags = rankedShortfalls(results);
    const confirmed = flags.filter((row) => row.confidence === 'high');
    const followUp = results.filter((row) => ['missing', 'reference', 'review'].includes(evidenceState(row)));
    const topics = planningTopics(results, agriculture);
    const projects =
        catalog.status === 'available'
            ? matchProjects(
                  catalog.records,
                  topics,
                  locationMatches.filter((row) => row.matches_city_cluster).map((row) => row.id),
              )
            : [];
    return (
        <section aria-labelledby="assessment-planning-heading" className="space-y-5">
            <header className="flex items-start gap-3">
                <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-[#123575] text-white">
                    <Target size={22} aria-hidden="true" />
                </span>
                <div>
                    <p className="text-[10px] font-semibold tracking-wider text-slate-500 uppercase">Planning brief</p>
                    <h2 id="assessment-planning-heading" className="mt-1 text-xl font-semibold tracking-tight text-[#123575] dark:text-blue-200">
                        Priority review &amp; DOST project options
                    </h2>
                    <p className="mt-1 max-w-4xl text-sm leading-relaxed text-slate-500">
                        Use the recorded evidence for {locationName} to start local validation and project scoping. Confirm local demand, source
                        completeness and suitability with the LGU and DOST.
                    </p>
                </div>
            </header>

            <div className={surface}>
                <h3 className="flex items-center gap-2 font-semibold text-[#123575] dark:text-blue-200">
                    <ClipboardCheck size={18} aria-hidden="true" /> What needs attention
                </h3>
                <p className="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-400">
                    {results.length === 0
                        ? 'No indicators are available for this focus. Choose a supported focus or establish the required local evidence before screening projects.'
                        : confirmed.length > 0
                          ? `${confirmed.length} high-confidence comparison ${confirmed.length === 1 ? 'flag requires' : 'flags require'} local review. Density flags indicate service pressure; other flags identify rates below their reference.`
                          : followUp.length > 0
                            ? 'Resolve the evidence gaps first. No high-confidence shortfall is established by the available comparisons; missing data do not mean the area has adequate services.'
                            : 'No high-confidence shortfall is flagged by the available comparisons. Check accessibility, capacity and actual local demand before drawing conclusions about service adequacy.'}
                </p>
                {flags.length > 0 && (
                    <div className="mt-4 overflow-x-auto rounded-xl border border-slate-100">
                        <table className="w-full min-w-[760px] text-left text-xs">
                            <caption className="sr-only">
                                Comparison flags and local validation actions, ordered by confidence and relative difference
                            </caption>
                            <thead className="bg-slate-50 text-slate-500">
                                <tr>
                                    {['Concern / confidence', 'Local / reference', 'Difference', 'First local check'].map((label) => (
                                        <th key={label} scope="col" className="p-3 font-medium">
                                            {label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {flags.map((row) => (
                                    <tr key={row.key} className="border-t border-slate-100 align-top">
                                        <th scope="row" className="p-3 font-medium">
                                            <span>{row.label}</span>
                                            <span
                                                className={`mt-1 block text-[10px] ${row.confidence === 'high' ? 'text-amber-700' : 'text-slate-500'}`}
                                            >
                                                {row.confidence === 'high' ? 'High confidence · review flag' : 'Low confidence · provisional'}
                                            </span>
                                            <span className="mt-1 block text-[10px] font-normal text-slate-500">{row.source}</span>
                                        </th>
                                        <td className="p-3 tabular-nums">
                                            {format(row.value)} / {format(row.benchmark)}
                                            <span className="mt-1 block text-[10px] text-slate-500">{row.unit}</span>
                                        </td>
                                        <td className="p-3 font-medium tabular-nums">
                                            {row.gap === null ? 'Not computed' : `${row.gap > 0 ? '+' : ''}${row.gap.toFixed(1)}%`}
                                        </td>
                                        <td className="max-w-sm p-3 leading-relaxed text-slate-600">{validationAction(row)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                {flags.length > 0 && (
                    <p className="mt-2 text-[10px] text-slate-500">
                        Ordered by confidence, then absolute percentage difference. This is a review order, not a resource-allocation ranking.
                    </p>
                )}
                {followUp.length > 0 && (
                    <details className="mt-4 rounded-xl border border-slate-200 bg-slate-50/60 p-3">
                        <summary className="cursor-pointer text-xs font-semibold text-[#123575]">
                            <span className="inline-flex items-center gap-2">
                                <Database size={15} aria-hidden="true" /> Evidence follow-up ({followUp.length} indicators)
                            </span>
                        </summary>
                        <ul className="mt-3 grid gap-3 lg:grid-cols-2">
                            {followUp.map((row) => (
                                <li key={row.key} className="rounded-lg border border-slate-100 bg-white p-3 text-xs dark:bg-slate-950">
                                    <p className="font-semibold">{row.label}</p>
                                    <p className="mt-1 leading-relaxed text-slate-500">{row.reason || validationAction(row)}</p>
                                    <p className="mt-2 text-[10px] text-slate-500">Source: {row.source || 'Not recorded'}</p>
                                </li>
                            ))}
                        </ul>
                    </details>
                )}
            </div>

            <div className={surface}>
                <header className="flex items-start gap-3">
                    <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-[#00aeef]/10 text-[#123575]">
                        <Cpu size={21} aria-hidden="true" />
                    </span>
                    <div>
                        <h3 className="font-semibold text-[#123575] dark:text-blue-200">DOST options for feasibility review</h3>
                        <p className="mt-1 text-xs leading-relaxed text-slate-500">
                            {catalog.status === 'available'
                                ? `${projects.length} catalog entries match evidence themes through words in their names, sectors or mechanisms. Each option requires local feasibility review.`
                                : 'Catalog screening requires an available reference source and local evidence themes.'}
                        </p>
                    </div>
                </header>
                <p className="mt-4 flex items-start gap-2 rounded-xl bg-blue-50 p-3 text-xs leading-relaxed text-[#123575]">
                    <Info size={15} aria-hidden="true" className="mt-0.5 shrink-0" />
                    <span>
                        {catalog.coverage} Source: {catalog.source}.
                    </span>
                </p>
                {catalog.status === 'unavailable' ? (
                    <p className="mt-4 text-sm text-slate-500">
                        The DOST reference catalog could not be loaded. Confirm the source before screening project options.
                    </p>
                ) : catalog.records.length === 0 ? (
                    <p className="mt-4 text-sm text-slate-500">The reference catalog is available but currently contains no entries.</p>
                ) : projects.length === 0 ? (
                    <p className="mt-4 text-sm leading-relaxed text-slate-500">
                        No catalog entry matched the current evidence themes. Verify the baseline and ask the local DOST office for options outside
                        this catalog. This does not establish that no suitable DOST projects exist.
                    </p>
                ) : (
                    <div className="mt-5 grid items-start gap-4 lg:grid-cols-2">
                        {projects.map(({ project, reasons, locationMatch }) => (
                            <article key={project.id} className="min-w-0 rounded-xl border border-slate-200 bg-white p-4 dark:bg-slate-950">
                                <p className="text-[10px] font-semibold tracking-wide text-slate-500">{project.code || `Catalog ID ${project.id}`}</p>
                                <h4 className="mt-1 text-base leading-snug font-semibold text-[#123575] dark:text-blue-200">{project.name}</h4>
                                <p className="mt-2 text-xs text-slate-500">
                                    {project.agency || 'Lead agency not recorded'} · {project.sector || 'Sector not recorded'}
                                </p>
                                <div className="mt-3 space-y-2">
                                    {reasons.map(({ topic, terms }) => (
                                        <div key={topic.id} className="rounded-lg bg-slate-50 p-3 text-xs dark:bg-slate-900">
                                            <p className="font-semibold">{topic.label}</p>
                                            <p className={`mt-1 text-[10px] ${topic.basis === 'comparison' ? 'text-amber-700' : 'text-slate-500'}`}>
                                                {basisLabels[topic.basis]}
                                            </p>
                                            <p className="mt-1 leading-relaxed text-slate-600 dark:text-slate-400">{topic.evidence.join('; ')}</p>
                                            <p className="mt-2 text-[10px] text-slate-500">Catalog text matched: {terms.join(', ')}</p>
                                        </div>
                                    ))}
                                </div>
                                <p className="mt-3 text-[10px] leading-relaxed text-slate-500">
                                    {locationMatch
                                        ? 'This entry also has a catalog location-text match; local deployment is unverified.'
                                        : 'No local deployment is established by this screening match.'}{' '}
                                    Catalog status: {project.status || 'not recorded'}.
                                </p>
                                <details className="mt-3 border-t border-slate-100 pt-3">
                                    <summary className="cursor-pointer text-xs font-semibold text-[#123575]">
                                        Mechanism, source outcome &amp; readiness checks
                                    </summary>
                                    <dl className="mt-3 space-y-3 text-xs leading-relaxed">
                                        <div>
                                            <dt className="font-semibold">Catalog mechanism</dt>
                                            <dd className="mt-1 text-slate-500">{project.mechanism || 'Not recorded'}</dd>
                                        </div>
                                        <div>
                                            <dt className="font-semibold">Catalog-stated outcome</dt>
                                            <dd className="mt-1 text-slate-500">{project.outcome || 'Not recorded'}</dd>
                                        </div>
                                    </dl>
                                    <h5 className="mt-3 text-xs font-semibold">Validate before project design</h5>
                                    <ul className="mt-2 list-disc space-y-2 pl-4 text-xs leading-relaxed text-slate-500">
                                        {[...new Set(reasons.map(({ topic }) => topic.check))].map((check) => (
                                            <li key={check}>{check}</li>
                                        ))}
                                    </ul>
                                </details>
                            </article>
                        ))}
                    </div>
                )}
            </div>

            <div className={surface}>
                <h3 className="flex items-center gap-2 font-semibold text-[#123575] dark:text-blue-200">
                    <FlaskConical size={18} aria-hidden="true" /> From evidence to a local project brief
                </h3>
                <ol className="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    {[
                        [
                            'Verify the baseline',
                            'Confirm the active population year, source completeness, classifications and local geographic assignments. Map positions and stored inventory links can differ.',
                        ],
                        [
                            'Confirm the problem',
                            'Ask the LGU and affected users about service access, capacity, demand and existing alternatives. Use hazard or commodity evidence where relevant.',
                        ],
                        [
                            'Scope with DOST',
                            'Confirm agency applicability and current availability. Establish the site, operator, staff, utilities, equipment, budget and recurring operating costs.',
                        ],
                        [
                            'Define a measurable pilot',
                            'Record a baseline and agree on outcome measures, responsibilities, maintenance and review dates before implementation. Do not assume catalog outcomes will recur locally.',
                        ],
                    ].map(([title, detail], index) => (
                        <li key={title} className="rounded-xl bg-slate-50 p-3 dark:bg-slate-900">
                            <span className="mb-3 grid size-7 place-items-center rounded-lg bg-[#123575] text-xs font-semibold text-white">
                                {index + 1}
                            </span>
                            <h4 className="text-sm font-semibold">{title}</h4>
                            <p className="mt-2 text-xs leading-relaxed text-slate-500">{detail}</p>
                        </li>
                    ))}
                </ol>
            </div>
        </section>
    );
}
