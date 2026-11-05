import { explainNumber } from '@/lib/assessment-explanations';
import type { ReactNode } from 'react';
import { SeverityScale } from './charts';

/** Standard three-part explanation used by every chart's "Explain" popup. */
export function ExplainBlocks({ what, how, read, children }: { what: ReactNode; how: ReactNode; read: ReactNode; children?: ReactNode }) {
    const blocks = [
        ['What it shows', what],
        ['How it is computed', how],
        ['How to read it', read],
    ] as const;
    return (
        <div className="space-y-4 text-sm">
            <div className="grid gap-3 md:grid-cols-3">
                {blocks.map(([title, body]) => (
                    <section key={title} className="rounded-xl border border-sky-100 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
                        <p className="mb-1.5 text-[11px] font-semibold tracking-wide text-[#087dcc] uppercase">{title}</p>
                        <div className="space-y-1.5 text-xs leading-relaxed text-slate-700 dark:text-slate-200">{body}</div>
                    </section>
                ))}
            </div>
            {children}
            <p className="text-muted-foreground text-[11px]">
                Missing data is never counted as zero. National references are averages of the recorded data, not official standards.
            </p>
        </div>
    );
}

export function Formula({ children }: { children: ReactNode }) {
    return (
        <code className="block rounded-md bg-sky-50 px-2 py-1 font-mono text-[11px] text-slate-800 dark:bg-slate-900 dark:text-slate-100">
            {children}
        </code>
    );
}

export const SectorScorecardExplain = () => (
    <ExplainBlocks
        what="How many indicators in each sector are flagged as a possible need, within the national reference, uncertain or missing."
        how={
            <>
                <p>Each indicator is measured, then compared with its national reference:</p>
                <Formula>gap % = (local − reference) ÷ reference × 100</Formula>
                <p>It is then counted in one color group.</p>
            </>
        }
        read={
            <ul className="list-disc space-y-1 pl-4">
                <li>
                    <strong>Flagged need:</strong> below the reference (density: above), high confidence.
                </li>
                <li>
                    <strong>Within reference:</strong> no shortfall found.
                </li>
                <li>
                    <strong>Low confidence:</strong> data may be incomplete.
                </li>
                <li>
                    <strong>No reference / No data:</strong> cannot be compared yet.
                </li>
            </ul>
        }
    />
);

export const GapSeverityExplain = () => (
    <ExplainBlocks
        what="How many detected gaps fall into each severity band, split by how reliable the evidence is."
        how={
            <>
                <Formula>severity = min(100, |gap %|) × weight</Formula>
                <p>Weight is 1 for high-confidence evidence and 0.65 for provisional evidence.</p>
            </>
        }
        read="More amber toward Critical means larger shortfalls. Provisional gaps need checking before they guide a plan."
    >
        <div className="rounded-xl border border-sky-100 p-4 dark:border-slate-800">
            <p className="mb-1 text-xs font-semibold text-slate-700 dark:text-slate-200">Severity bands</p>
            <SeverityScale value={null} />
        </div>
    </ExplainBlocks>
);

export const ScatterExplain = () => (
    <ExplainBlocks
        what="Every barangay as a dot: how many people live there (left → right) and its priority score (bottom → top)."
        how={
            <>
                <p>The priority score adds points for population need, missing facilities, roads, connectivity and isolation (0–100).</p>
                <p>Colors come from recorded facilities and population.</p>
            </>
        }
        read={
            <ul className="list-disc space-y-1 pl-4">
                <li>
                    <strong>Top-right:</strong> many people and high priority. Check these first.
                </li>
                <li>
                    <strong>Top-left:</strong> high priority but fewer people.
                </li>
                <li>Hover a dot for its name and score.</li>
            </ul>
        }
    />
);

const PRIORITY_FACTOR_TEXT: Record<string, string> = {
    population_need: '70% relative population + 30% relative density',
    health_access_gap: 'Full points if no health facility, 30% if one, none if two or more',
    education_access_gap: 'Full points if no school, 30% if one, none if two or more',
    road_accessibility_gap: 'Few recorded road segments',
    digital_connectivity_gap: 'No recorded cell site',
    safety_and_drrm_gap: 'No recorded safety facility',
    geographic_isolation: 'Distance to nearest facility; full points at 6 km or more',
};

export function DriversExplain({ weights }: { weights?: Record<string, unknown> }) {
    const entries = Object.entries(PRIORITY_FACTOR_TEXT);
    return (
        <ExplainBlocks
            what="For the 8 highest-scoring barangays, how many points each factor adds to the priority score."
            how={
                <>
                    <Formula>score = Σ (factor weight × factor deficit)</Formula>
                    <p>Factors without data in this city are switched off and their weight is shared among the rest.</p>
                </>
            }
            read="The longest color in a bar is the main reason that barangay ranks high. Match the technology to that reason."
        >
            <div className="overflow-x-auto rounded-xl border border-sky-100 dark:border-slate-800">
                <table className="w-full min-w-[480px] text-left text-xs">
                    <thead className="bg-blue-50 dark:bg-slate-900">
                        <tr>
                            <th className="p-2.5">Factor</th>
                            <th className="p-2.5">Gives points when</th>
                            <th className="p-2.5 text-right">Max points</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {entries.map(([key, text]) => (
                            <tr key={key}>
                                <td className="p-2.5 font-medium capitalize">{key.replaceAll('_', ' ').replace(' gap', '')}</td>
                                <td className="text-muted-foreground p-2.5">{text}</td>
                                <td className="p-2.5 text-right font-mono">
                                    {weights && key in weights ? explainNumber(Number(weights[key])) : '—'}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </ExplainBlocks>
    );
}

export const FlowExplain = () => (
    <ExplainBlocks
        what="Which DOST technology or program was matched to which detected need."
        how={
            <>
                <p>Each need is scored against every catalog entry (start + sector + keywords + assets + status).</p>
                <p>A line is drawn when the entry scores 40 or more.</p>
            </>
        }
        read={
            <ul className="list-disc space-y-1 pl-4">
                <li>Needs on the left, ordered by severity.</li>
                <li>Thicker line = higher relevance score.</li>
                <li>One technology can answer several needs. Hover to isolate its links.</li>
            </ul>
        }
    />
);

export const TimelineExplain = () => (
    <ExplainBlocks
        what="The proposed 12-week schedule of the action plan activities."
        how="Activities are grouped into four phases: Validate (weeks 1–2), Prepare (3–4), Pilot (5–8) and Review (9–12)."
        read="A pilot only starts once the need, site, cost and partners are confirmed. Dates are proposals, not commitments."
    />
);

export const NeedsChartExplain = () => (
    <ExplainBlocks
        what="The detected needs with the highest severity, to validate first."
        how={<Formula>severity = min(100, |gap %|) × confidence weight</Formula>}
        read="Amber = high-confidence evidence; gray = provisional. Higher bars should be checked first on site."
    />
);

export const OptionsChartExplain = () => (
    <ExplainBlocks
        what="The shortlisted DOST technologies and programs, by their best relevance score."
        how={<Formula>relevance = min(100, start + sector + keywords + assets + status)</Formula>}
        read="Higher means a stronger match to a local need, not a higher chance of success. Confirm availability with DOST."
    />
);

/** Explanations for the layer-overview charts, keyed by chart title. */
export const OVERVIEW_EXPLAIN: Record<string, { what: string; how: ReactNode; read: string }> = {
    'Population across census years': {
        what: 'The recorded population for each census year.',
        how: <Formula>growth % = (current − baseline) ÷ baseline × 100</Formula>,
        read: 'A rising bar means growing demand for services. Missing years stay blank.',
    },
    'Recorded local assets': {
        what: 'Facilities, laboratories and cell sites linked to this location.',
        how: 'Records are counted when their location links to this area.',
        read: 'These are assets a technology can build on. No record does not prove no service.',
    },
    'Dataset availability': {
        what: 'How complete each data source is for this location.',
        how: 'Each dataset is checked for local records and national coverage.',
        read: 'Partial or empty datasets make related findings less certain.',
    },
    'Local value relative to its reference': {
        what: 'Each service indicator as a percentage of the national reference.',
        how: <Formula>index = local value ÷ national reference × 100</Formula>,
        read: '100% = same as the reference. Below 100% suggests a shortfall (for density, above 100% means pressure). Amber = flagged.',
    },
    'Indicator evidence': {
        what: 'How many indicators could be compared reliably.',
        how: 'Indicators are grouped by confidence and whether a reference exists.',
        read: 'More high-confidence comparisons mean stronger conclusions.',
    },
    'Largest comparison gaps': {
        what: 'The six gaps with the highest severity.',
        how: <Formula>severity = min(100, |gap %|) × confidence weight</Formula>,
        read: 'Longer bars are larger shortfalls. Gray bars are provisional.',
    },
    'Gaps by sector': {
        what: 'How many gaps were found in each sector.',
        how: 'Each gap is assigned to the sector of its indicator.',
        read: 'Blank means that sector could not be assessed with the current data.',
    },
    'Highest barangay review priorities': {
        what: 'The six barangays with the highest priority score.',
        how: <Formula>score = population need + facility, road, connectivity and isolation deficits</Formula>,
        read: 'Review these first, then confirm the need and site on the ground.',
    },
    'Returned local projects by agency': {
        what: 'Past DOST projects whose location text mentions this place, by agency.',
        how: 'Project location text is matched with the city/municipality and province names.',
        read: 'Shows which agencies already worked here. Past projects are not current offers.',
    },
    'Catalog status of returned entries': {
        what: 'The recorded status of the catalog entries shown.',
        how: 'Statuses are normalized (e.g. “comleted” is read as completed).',
        read: 'Active entries are more likely available, but always confirm with DOST.',
    },
    'Highest candidate relevance': {
        what: 'The six best-matching technologies or programs.',
        how: <Formula>relevance = min(100, start + sector + keywords + assets + status)</Formula>,
        read: 'Higher = stronger fit to a need. It is not a success rate.',
    },
    'Needs with a catalog option': {
        what: 'Per sector, how many needs have at least one matched technology.',
        how: <Formula>covered = needs with a match ÷ all needs in the sector</Formula>,
        read: 'Low bars show needs with no DOST option yet; ask DOST to review them.',
    },
    'Recorded local laboratory capabilities': {
        what: 'The local lab services, grouped by type of test.',
        how: 'Service descriptions are grouped by keywords (food, water, calibration…).',
        read: 'Shows which tests can be done locally. Confirm the exact test with the lab.',
    },
};
