import type { PlanningVisual } from '@/components/planning-visuals';
import { actionPlanColumns } from './assessment-action-plan';

export interface PlanningPlanSnapshot {
    content: string;
    title?: string;
    location: string;
    province?: string | null;
    version?: number;
    created_at?: string;
    visuals?: PlanningVisual[];
    sources?: { name: string; location: string }[];
    evidence_snapshot?: unknown;
}
export type PlanningPlanPart = { type: 'text'; content: string } | { type: 'matrix'; rows: string[][] };
export interface PlanningPlanDocument {
    title: string;
    parts: PlanningPlanPart[];
    hasMatrix: boolean;
}

const plain = (value: string) =>
    value
        .trim()
        .replace(/^#{1,6}\s+/, '')
        .replace(/\*\*([^*]+)\*\*/g, '$1');
const normalize = (value: string) =>
    plain(value)
        .toLowerCase()
        .replace(/[^a-z]/g, '');

/** Parse only the requested six-column matrix. All other text stays in its original order. */
export function splitPlanCells(line: string): string[] | null {
    if (!line.includes('|')) return null;
    let value = line.trim();
    if (value.startsWith('|')) value = value.slice(1);
    if (value.endsWith('|') && !value.endsWith('\\|')) value = value.slice(0, -1);
    const cells: string[] = [];
    let current = '';
    for (let index = 0; index < value.length; index++) {
        const character = value[index];
        if (character === '\\' && value[index + 1] === '|') {
            current += '|';
            index++;
        } else if (character === '|') {
            cells.push(plain(current));
            current = '';
        } else current += character;
    }
    cells.push(plain(current));
    return cells;
}

function isHeader(cells: string[] | null): boolean {
    if (cells?.length !== 6) return false;
    const names = cells.map(normalize);
    return (
        names[0] === 'activities' &&
        names[1] === 'objectives' &&
        ['results', 'expectedresults'].includes(names[2]) &&
        names[3] === 'timeline' &&
        names[4] === 'budgetresources' &&
        ['responsiblepersonoffice', 'responsiblepersonoroffice', 'responsibleoffice'].includes(names[5])
    );
}

const TOOL_ID = String.raw`[a-z][a-z0-9]*(?:_[a-z0-9]+)+`;
const TOOL_LIST = String.raw`(?:(?:tools?|sources?|via|from)\s*:?\s*)?${TOOL_ID}(?:\s*(?:,|;|\/|&|\band\b)\s*${TOOL_ID})*`;
const TOOL_CITATION = new RegExp(String.raw`[ \t]*(?:\[\s*${TOOL_LIST}\s*\]|\(\s*${TOOL_LIST}\s*\))`, 'gi');

/** Remove bracketed internal tool references such as "[generate_action_plan]" (older saved replies still contain them). */
export function stripToolCitations(text: string) {
    return text.replace(TOOL_CITATION, '');
}

export function parsePlanningPlan(snapshot: PlanningPlanSnapshot): PlanningPlanDocument {
    const lines = stripToolCitations(snapshot.content).replaceAll('\r\n', '\n').split('\n');
    const named = lines.find((line) => /^\s*(?:plan title|action plan title):/i.test(line));
    const title =
        snapshot.title || (named ? named.replace(/^\s*(?:plan title|action plan title):\s*/i, '').trim() : `Action Plan for ${snapshot.location}`);
    const parts: PlanningPlanPart[] = [];
    let text: string[] = [];
    const flush = () => {
        if (text.some((line) => line.trim())) parts.push({ type: 'text', content: text.join('\n').trim() });
        text = [];
    };
    for (let index = 0; index < lines.length; index++) {
        if (lines[index] === named) continue;
        const header = splitPlanCells(lines[index]),
            divider = splitPlanCells(lines[index + 1] ?? '');
        if (isHeader(header) && divider?.length === 6 && divider.every((cell) => /^:?-{3,}:?$/.test(cell))) {
            const rows: string[][] = [];
            let end = index + 2;
            for (; end < lines.length; end++) {
                const row = splitPlanCells(lines[end]);
                if (row?.length !== 6) break;
                rows.push(row);
            }
            if (rows.length) {
                flush();
                parts.push({ type: 'matrix', rows });
                index = end - 1;
                continue;
            }
        }
        text.push(lines[index]);
    }
    flush();
    return { title, parts, hasMatrix: parts.some((part) => part.type === 'matrix') };
}

export function isPlanningActionPlan(content: string) {
    const headings = ['Priority Areas', 'Critical Needs', 'Recommended STI Interventions', 'Investment and Resource', 'Expected Outcomes'];
    return (
        headings.every((heading, index) => new RegExp(`(?:^|\\n)\\s*[#* ]*${index + 1}[.)]\\s*${heading}`, 'i').test(content)) ||
        parsePlanningPlan({ content, location: '' }).hasMatrix
    );
}

export function planningPlanSources(snapshot: PlanningPlanSnapshot) {
    const sources = [...(snapshot.sources ?? [])];
    let evidence = snapshot.evidence_snapshot;
    if (typeof evidence === 'string') {
        try {
            evidence = JSON.parse(evidence);
        } catch {
            evidence = null;
        }
    }
    if (evidence && typeof evidence === 'object' && 'tools' in evidence && Array.isArray(evidence.tools)) {
        for (const item of evidence.tools) if (item && typeof item.tool === 'string') sources.push({ name: item.tool, location: snapshot.location });
    }
    for (const visual of snapshot.visuals ?? []) if (visual.source_tool) sources.push({ name: visual.source_tool, location: snapshot.location });
    return [...new Map(sources.map((source) => [`${source.name}:${source.location}`, source])).values()];
}

export function planningPlanFilename(snapshot: PlanningPlanSnapshot) {
    const name =
        parsePlanningPlan(snapshot)
            .title.normalize('NFKD')
            .replace(/[^\p{L}\p{N}]+/gu, '-')
            .replace(/^-|-$/g, '')
            .slice(0, 100) || 'action-plan';
    return `konektadost-${name}${snapshot.version ? `-v${snapshot.version}` : ''}.pdf`;
}

export { actionPlanColumns };
