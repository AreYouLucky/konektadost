import type { AiResult } from '@/components/assessment-ai-analysis';
import { explainNumber } from './assessment-explanations';
import {
    reportGaps,
    reportLabel,
    reportLaboratoryOptions,
    reportList,
    reportNumber,
    reportRecord,
    reportTechnologyOptions,
    reportText,
    type AssessmentReportInput,
    type OverviewChart,
} from './assessment-report';

export const actionPlanColumns = ['ACTIVITIES', 'OBJECTIVES', 'RESULTS', 'TIMELINE', 'BUDGET/RESOURCES', 'RESPONSIBLE (PERSON/OFFICE)'] as const;
export interface ActionPlanActivity {
    activity: string;
    objective: string;
    result: string;
    startWeek: number;
    endWeek: number;
    resources: string;
    responsible: string;
    phase: 'Validation' | 'Preparation' | 'Pilot' | 'Review';
}
export interface ActionPlanOption {
    ref: string;
    name: string;
    agency: string;
    need: string;
    relevance: number | null;
    status: string;
    why: string;
    aiNote: string | null;
}
export interface AssessmentActionPlan {
    location: string;
    level: string;
    scopeNote: string | null;
    populationYear: number | null;
    generatedAt: string;
    aiGeneratedAt: string | null;
    options: ActionPlanOption[];
    priorities: { area: string; basis: string }[];
    needs: { name: string; baseline: string; severity: number | null; confidence: string; source: string }[];
    activities: ActionPlanActivity[];
    laboratory: string;
    limitations: string[];
    charts: OverviewChart[];
}

const concise = (value: string, length = 250) => (value.length <= length ? value : value.slice(0, length).replace(/\s+\S*$/, '') + '…');

/** Draft actions use computed records; completed, reference-checked AI can add wording only. */
export function buildAssessmentActionPlan(report: AssessmentReportInput, analysis?: AiResult, date = new Date()): AssessmentActionPlan {
    const gaps = reportGaps(report);
    const datasets = Object.values(reportRecord(reportRecord(reportRecord(report.functionToolsBundle).dataset_audit).datasets)).map(reportRecord);
    const conflicts = reportList(reportRecord(report.inventoryIntegrity).inventories).filter((row) => row.has_conflicts === true);
    const options = reportTechnologyOptions(report)
        .slice(0, 4)
        .map((row): ActionPlanOption => {
            const ref = `${row.source_catalog}:${row.intervention_id}`;
            const named = analysis?.interventions.some((item) => item.ref === ref && item.name === row.name);
            const ai = named
                ? analysis?.analysis.sectors.find((item) => item.sector === row.domain && item.intervention_refs.includes(ref))
                : undefined;
            const criteria = Array.isArray(row.matching_criteria)
                ? row.matching_criteria.filter((item): item is string => typeof item === 'string').slice(0, 2)
                : [];
            return {
                ref,
                name: reportText(row.name),
                agency: reportText(row.agency, 'DOST focal office to be confirmed'),
                need: reportText(row.gap_name),
                relevance: reportNumber(row.relevance_score),
                status: reportLabel(row.availability_status),
                why: concise(
                    criteria.join('; ') || `Catalog screening links this option to ${reportText(row.gap_name)}. Validate the mechanism locally.`,
                ),
                aiNote: ai ? concise(ai.intervention_analysis) : null,
            };
        });
    const needs = [...gaps]
        .sort((a, b) => (reportNumber(b.severity_score) ?? -1) - (reportNumber(a.severity_score) ?? -1))
        .slice(0, 6)
        .map((row) => ({
            name: reportText(row.label),
            baseline: `${explainNumber(reportNumber(row.measured_value))} ${reportText(row.unit, '')}; national reference ${explainNumber(reportNumber(row.benchmark_value))}`,
            severity: reportNumber(row.severity_score),
            confidence: reportText(row.confidence, 'unrecorded'),
            source: reportText(row.source),
        }));
    const ranked = [...(report.barangayAssessment?.barangays ?? [])]
        .filter((row) => row.priority_score != null && row.population != null)
        .sort((a, b) => b.priority_score! - a.priority_score!)
        .slice(0, 5);
    const priorities = ranked.length
        ? ranked.map((row) => ({
              area: row.name,
              basis: `Review priority ${explainNumber(row.priority_score)}/100; validate need and site readiness.`,
          }))
        : [{ area: report.location.name, basis: 'Selected area only. Individual deployment sites require local validation.' }];
    const labs = reportLaboratoryOptions(report);
    const localLab = labs.local[0];
    const referral = localLab ?? labs.nearby;
    const laboratory = labs.conflict
        ? 'Laboratory locations conflict; validate the inventory before naming a service partner.'
        : localLab
          ? `Recorded local service option: ${reportText(localLab.agency_name)}. Confirm required tests, capacity and fees.`
          : labs.nearby
            ? `Nearest recorded referral: ${reportText(labs.nearby.agency_name)} (${explainNumber(reportNumber(labs.nearby.distance_km))} km straight-line). Confirm tests and actual access.`
            : 'No verified service partner in the returned evidence. Establish testing demand and map existing services before considering a new laboratory.';
    const lead = 'Proposed: selected LGU planning team and DOST focal office';
    const activities: ActionPlanActivity[] = [
        {
            phase: 'Validation',
            activity: `Validate local needs and priority sites in ${report.location.name}`,
            objective: 'Check inventory locations, service demand, baseline indicators and potential beneficiaries.',
            result: 'Verified baseline, documented needs and a locally reviewed site shortlist.',
            startWeek: 1,
            endWeek: 2,
            resources: 'To be costed: staff time, site survey, consultations and transport.',
            responsible: lead,
        },
    ];
    options.forEach((option) =>
        activities.push({
            phase: 'Preparation',
            activity: `Assess local adoption of ${option.name}`,
            objective: `Confirm how the option could support ${option.need}; verify eligibility and complementary service requirements.`,
            result: `Documented fit, current availability and an adoption or referral proposal for ${option.name}.`,
            startWeek: 3,
            endWeek: 4,
            resources: 'To be costed: technical assessment, supplier/service quotation and operator training needs.',
            responsible: `Proposed: selected LGU focal team; ${option.agency}`,
        }),
    );
    activities.push(
        referral && !labs.conflict
            ? {
                  phase: 'Preparation',
                  activity: `Confirm a laboratory service pathway with ${reportText(referral.agency_name)}`,
                  objective: 'Verify the required tests, sample handling, access, turnaround time and service fees.',
                  result: 'Documented referral process, current quotation and agreed service requirements.',
                  startWeek: 3,
                  endWeek: 4,
                  resources: 'To be costed: testing fees, sampling supplies, packaging and transport.',
                  responsible: `Proposed: LGU focal team; ${reportText(referral.agency_name)}`,
              }
            : {
                  phase: 'Validation',
                  activity: 'Assess testing demand and available laboratory services',
                  objective: 'Validate laboratory locations and determine whether existing laboratories can serve the area.',
                  result: 'Testing-demand record and a referral or feasibility-study decision.',
                  startWeek: 1,
                  endWeek: 2,
                  resources: 'To be costed: staff time, demand survey and service mapping.',
                  responsible: lead,
              },
    );
    if (options.length) {
        activities.push(
            {
                phase: 'Preparation',
                activity: 'Confirm resources, site and operating responsibilities',
                objective: 'Review current quotations, operator capacity, maintenance, connectivity and partner commitments.',
                result: 'Costed implementation proposal and a documented proceed/revise decision.',
                startWeek: 3,
                endWeek: 4,
                resources: 'Amounts to be established from current quotations; identify funding and recurrent operating costs.',
                responsible: lead,
            },
            {
                phase: 'Pilot',
                activity: 'Pilot the locally validated option or laboratory referral pathway',
                objective: 'Test the agreed delivery method after need, fit, resources and responsibilities are confirmed.',
                result: 'Pilot usage/service log, trained operator record and acceptance findings.',
                startWeek: 5,
                endWeek: 8,
                resources: 'To be costed: approved equipment/service scope, training, supplies and maintenance.',
                responsible: 'Proposed: selected LGU operator and confirmed technology/laboratory partner',
            },
        );
    } else
        activities.push({
            phase: 'Preparation',
            activity: 'Request DOST review of unmet needs and catalog coverage',
            objective: 'Investigate suitable options without assigning an unsupported technology.',
            result: 'Reviewed options or a documented evidence gap; no deployment assignment inferred.',
            startWeek: 3,
            endWeek: 4,
            resources: 'To be costed: technical consultations and additional evidence gathering.',
            responsible: lead,
        });
    activities.push({
        phase: 'Review',
        activity: options.length ? 'Review the pilot and update the action plan' : 'Review validated needs and update the action plan',
        objective: 'Compare verified baselines with usage, service performance, costs and user feedback where available.',
        result: 'Review report, agreed next steps and performance targets for any continuation.',
        startWeek: 9,
        endWeek: 12,
        resources: 'To be costed: monitoring, data collection and review meetings.',
        responsible: lead,
    });
    activities.sort((a, b) => a.startWeek - b.startWeek);
    const limitations = [
        'Draft for LGU/DOST review. The 12-week schedule, roles and deliverables are proposed; current costs and funding are unconfirmed.',
        'Catalog matches are screening leads. A technology complements services; a low facility count alone does not establish technology suitability.',
    ];
    if (conflicts.length)
        limitations.push(
            `${conflicts.length} inventory dataset(s) have conflicting locations. Resolve them before using affected counts or choosing a deployment site.`,
        );
    const incomplete = datasets.filter((row) => ['partial', 'empty', 'unavailable'].includes(String(row.status))).length;
    if (incomplete)
        limitations.push(`${incomplete} dataset(s) are partial, empty or unavailable. No recorded service does not prove service absence.`);
    if (!gaps.length) limitations.push('No computed gap was returned. This does not establish that all local needs are met.');
    return {
        location: report.location.name,
        level: report.location.type ?? 'Selected area',
        scopeNote: report.analysisScopeNotice ?? null,
        populationYear: report.populationYear ?? null,
        generatedAt: date.toISOString(),
        aiGeneratedAt: options.some((row) => row.aiNote) ? analysis!.generated_at : null,
        options,
        priorities,
        needs,
        activities,
        laboratory,
        limitations,
        charts: [
            {
                title: 'Needs to validate first',
                description:
                    'Severity /100 from recorded gaps; gray = provisional. National references describe the imported data, not minimum service standards.',
                maximum: 100,
                rows: needs.map((row) => ({ label: row.name, value: row.severity, color: row.confidence === 'high' ? '#d97706' : '#94a3b8' })),
            },
            {
                title: 'Shortlisted technology / program relevance',
                description: 'Best returned match /100 for each option. Relevance is matching strength, not probability of success.',
                maximum: 100,
                rows: options.map((row) => ({ label: row.name, value: row.relevance })),
            },
        ],
    };
}

export function actionPlanFilename(report: AssessmentReportInput, date = new Date()) {
    const name =
        report.location.name
            .normalize('NFKD')
            .replace(/[^\p{L}\p{N}]+/gu, '-')
            .replace(/^-|-$/g, '')
            .slice(0, 80) || 'location';
    const day = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Singapore', year: 'numeric', month: '2-digit', day: '2-digit' }).format(date);
    return `konektadost-action-plan-${name}-${day}.pdf`;
}
