import { cn } from '@/lib/utils';
import { Check, LoaderCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * What the server is doing while an assessment loads, in pipeline order (function tool layers A–E).
 * The request is a single fetch with no progress events, so steps advance on a timer; the last
 * step stays "in progress" until the report actually arrives and the bar never reaches 100%.
 */
const STEPS = [
    { label: 'Reading the local profile', detail: 'Population, land area and barangays' },
    { label: 'Counting local resources', detail: 'Health facilities, schools, safety, labs and cell sites' },
    { label: 'Comparing with national rates', detail: 'Services per 100,000 residents against reference rates' },
    { label: 'Finding development gaps', detail: 'Scoring gap severity and ranking barangays' },
    { label: 'Matching DOST technologies', detail: 'Screening the catalog against each gap' },
    { label: 'Checking feasibility', detail: 'Partners, requirements and the summary' },
] as const;

const STEP_SECONDS = 1.4;
const SLOW_SECONDS = 14;

export default function AssessmentProgress({ name, active = true, className }: { name?: string; active?: boolean; className?: string }) {
    const [elapsed, setElapsed] = useState(0);

    useEffect(() => {
        if (!active) return;
        const start = performance.now();
        setElapsed(0);
        const timer = window.setInterval(() => setElapsed((performance.now() - start) / 1000), 200);
        return () => window.clearInterval(timer);
    }, [active]);

    const current = Math.min(STEPS.length - 1, Math.floor(elapsed / STEP_SECONDS));
    // Eases toward 92% so a slow request never looks finished.
    const progress = Math.round(6 + 86 * (1 - Math.exp(-elapsed / 5)));

    return (
        <div className={cn('text-left', className)}>
            <p className="text-center text-sm font-semibold text-[#087dcc]" aria-live="polite">
                {STEPS[current].label}
                {name && current === 0 ? ` for ${name}` : ''}…
            </p>
            <p className="mt-1 text-center text-xs text-slate-500">{STEPS[current].detail}</p>

            <div className="mt-4 h-1.5 overflow-hidden rounded-full bg-blue-50" aria-hidden="true">
                <div
                    className="h-full rounded-full bg-[#087dcc] transition-[width] duration-500 ease-out motion-reduce:transition-none"
                    style={{ width: `${progress}%` }}
                />
            </div>

            <ol className="mt-5 space-y-2.5" aria-label="Analysis steps">
                {STEPS.map((step, index) => {
                    const state = index < current ? 'done' : index === current ? 'active' : 'pending';
                    return (
                        <li key={step.label} className="flex items-center gap-3 text-sm" aria-current={state === 'active' ? 'step' : undefined}>
                            <span
                                className={cn(
                                    'grid size-6 shrink-0 place-items-center rounded-full border transition-colors motion-reduce:transition-none',
                                    state === 'done' && 'border-[#087dcc] bg-[#087dcc] text-white',
                                    state === 'active' && 'border-[#087dcc] bg-blue-50 text-[#087dcc]',
                                    state === 'pending' && 'border-slate-200 bg-white text-slate-300',
                                )}
                                aria-hidden="true"
                            >
                                {state === 'done' ? (
                                    <Check className="size-3.5" strokeWidth={3} />
                                ) : state === 'active' ? (
                                    <LoaderCircle className="size-3.5 animate-spin motion-reduce:animate-none" strokeWidth={2.5} />
                                ) : (
                                    <span className="size-1.5 rounded-full bg-current" />
                                )}
                            </span>
                            <span className={cn(state === 'pending' ? 'text-slate-400' : 'text-slate-700', state === 'active' && 'font-medium')}>
                                {step.label}
                                <span className="sr-only">{state === 'done' ? ' (done)' : state === 'active' ? ' (in progress)' : ''}</span>
                            </span>
                        </li>
                    );
                })}
            </ol>

            {elapsed >= SLOW_SECONDS && (
                <p className="mt-5 rounded-xl bg-slate-50 px-3 py-2 text-center text-xs leading-relaxed text-slate-500">
                    Larger cities have more barangays to rank. Still working; the report opens as soon as it’s ready.
                </p>
            )}
        </div>
    );
}
