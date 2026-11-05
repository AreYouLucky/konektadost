import { Button } from '@/components/ui/button';
import { csrfHeaders } from '@/lib/csrf';
import { LoaderCircle, Sparkles } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

export const assessmentSectors: Record<string, string> = {
    population: 'Population',
    health: 'Health',
    education: 'Education',
    digital_connectivity: 'Digital connectivity',
    roads: 'Road access',
    agriculture: 'Agriculture & fisheries',
    sti_capacity: 'STI & laboratories',
    safety_drrm: 'Safety & DRRM',
};

export interface AiSectorAnalysis {
    sector: string;
    gap_explanation: string;
    intervention_analysis: string;
    next_action: string;
    indicator_keys: string[];
    intervention_refs: string[];
}

export interface AiResult {
    status: 'ready';
    generated_at: string;
    analysis: { summary: string; sectors: AiSectorAnalysis[] };
    interventions: { ref: string; name: string; sector: string; indicator_key: string }[];
}

type AiState = { key: string; status: 'idle' | 'loading' | 'error' | 'ready'; result?: AiResult; message?: string };

export function useAssessmentAi(city: string | null, focus: string, active: boolean, enabled: boolean, scope?: Record<string, string | number>) {
    const level = scope?.level;
    const location = scope?.location;
    const map = scope?.map;
    const key = `${level ?? 'municipality'}:${location ?? city}:${focus}:${map ?? ''}`;
    const [state, setState] = useState<AiState>({ key, status: 'idle' });
    const started = useRef<string | null>(null);
    const request = useRef<AbortController | null>(null);

    const generate = useCallback(async () => {
        if (!city || !enabled) return;
        request.current?.abort();
        const controller = new AbortController();
        request.current = controller;
        started.current = key;
        setState({ key, status: 'loading' });
        const timer = window.setTimeout(() => controller.abort(), 90000);
        try {
            const response = await fetch(route('location.assess.ai'), {
                method: 'POST',
                credentials: 'same-origin',
                signal: controller.signal,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...csrfHeaders(),
                },
                body: JSON.stringify(level && location ? { level, location, map, focus } : { city, focus }),
            });
            if (response.redirected || [401, 419].includes(response.status))
                throw new Error('Your session has expired. Refresh the page and sign in again.');
            if (response.status === 429) throw new Error('Please wait a moment before requesting another AI analysis.');
            const payload = await response.json().catch(() => null);
            if (!payload) throw new Error('The AI request did not finish successfully. Please retry.');
            if (!response.ok || payload.status !== 'ready')
                throw new Error(payload.message || 'AI analysis is temporarily unavailable. Please retry.');
            if (!Array.isArray(payload.analysis?.sectors)) throw new Error('The AI response was incomplete. Please retry.');
            if (request.current === controller) setState({ key, status: 'ready', result: payload });
        } catch (error) {
            if (request.current === controller)
                setState({
                    key,
                    status: 'error',
                    message: controller.signal.aborted
                        ? 'AI analysis timed out. Please retry.'
                        : error instanceof Error
                          ? error.message
                          : 'AI analysis could not be loaded.',
                });
        } finally {
            window.clearTimeout(timer);
        }
    }, [city, focus, key, enabled, level, location, map]);

    useEffect(() => {
        if (active && enabled && started.current !== key) void generate();
    }, [active, enabled, key, generate]);

    useEffect(
        () => () => {
            const pending = request.current;
            request.current = null;
            pending?.abort();
            started.current = null;
        },
        [key],
    );

    return { state: state.key === key ? state : { key, status: 'idle' as const }, retry: generate };
}

export default function AssessmentAiAnalysis({ state, retry, mode }: { state: AiState; retry: () => void; mode: 'gaps' | 'interventions' }) {
    const [sector, setSector] = useState('all');
    const result = state.result;
    const rows = result?.analysis.sectors.filter((row) => sector === 'all' || row.sector === sector) ?? [];
    return (
        <section
            aria-label={`AI ${mode} analysis`}
            className="min-w-0 rounded-xl border border-blue-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950"
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="flex items-center gap-2 text-base font-semibold text-[#087dcc] dark:text-blue-200">
                    <Sparkles className="size-4" />
                    AI {mode === 'gaps' ? 'gap explanation' : 'intervention analysis'}
                </h2>
                {result && (
                    <label className="flex items-center gap-2 text-xs">
                        Sector
                        <select
                            aria-label={`AI ${mode} sector`}
                            value={sector}
                            onChange={(event) => setSector(event.target.value)}
                            className="bg-background max-w-full rounded-md border px-2 py-1.5"
                        >
                            <option value="all">All sectors</option>
                            {Object.entries(assessmentSectors).map(([key, label]) => (
                                <option key={key} value={key}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </label>
                )}
            </div>
            <p className="text-muted-foreground mt-1 text-xs">
                AI interpretation of computed evidence. Recommendations need LGU and DOST validation.
            </p>
            {(state.status === 'loading' || state.status === 'idle') && (
                <p role="status" className="text-muted-foreground mt-4 flex items-center gap-2 text-sm">
                    <LoaderCircle className="size-4 animate-spin" />
                    Explaining sector gaps and catalog matches… Computed results are available below.
                </p>
            )}
            {state.status === 'error' && (
                <div className="mt-3 flex flex-wrap items-center gap-3">
                    <p role="alert" className="text-sm text-amber-800 dark:text-amber-300">
                        {state.message}
                    </p>
                    <Button size="sm" variant="outline" onClick={retry}>
                        Retry AI analysis
                    </Button>
                </div>
            )}
            {result && (
                <>
                    <p className="mt-3 text-sm text-slate-700 dark:text-slate-300">{result.analysis.summary}</p>
                    <div
                        className="mt-3 max-h-[32rem] overflow-auto rounded-lg border"
                        tabIndex={0}
                        role="region"
                        aria-label="Scrollable AI sector analysis"
                    >
                        <table className="w-full min-w-[680px] text-left text-xs">
                            <caption className="sr-only">AI explanations and suggested next actions by sector</caption>
                            <thead className="sticky top-0 bg-blue-50 text-slate-600 dark:bg-slate-900 dark:text-slate-300">
                                <tr>
                                    <th className="p-3">Sector</th>
                                    <th className="p-3">
                                        {mode === 'gaps' ? 'Gap explanation & evidence' : 'Intervention analysis & catalog basis'}
                                    </th>
                                    <th className="p-3">Suggested next action</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {rows.map((row) => (
                                    <tr key={row.sector} className="align-top">
                                        <th scope="row" className="w-36 p-3 font-medium">
                                            {assessmentSectors[row.sector] ?? row.sector}
                                        </th>
                                        <td className="max-w-xl p-3 leading-relaxed">
                                            {mode === 'gaps' ? row.gap_explanation : row.intervention_analysis}
                                            {mode === 'gaps' && row.indicator_keys.length > 0 && (
                                                <p className="text-muted-foreground mt-2 text-[11px]">Indicators: {row.indicator_keys.join(', ')}</p>
                                            )}
                                            {mode === 'interventions' && row.intervention_refs.length > 0 && (
                                                <ul className="text-muted-foreground mt-2 list-inside list-disc text-[11px]">
                                                    {row.intervention_refs.map((ref) => (
                                                        <li key={ref}>
                                                            {result.interventions.find((item) => item.ref === ref)?.name} ({ref})
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                        </td>
                                        <td className="max-w-sm p-3 leading-relaxed">{row.next_action}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <p className="text-muted-foreground mt-2 text-[10px]">
                        Generated {new Date(result.generated_at).toLocaleString()}. Analysis is reused for unchanged evidence for up to 6 hours.
                    </p>
                </>
            )}
        </section>
    );
}
