import { usePlanningChat } from '@/components/planning-chat';
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem, useSidebar } from '@/components/ui/sidebar';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { ChevronDown, ChevronUp, FileText, LoaderCircle } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

/** Plans shown at once; the up/down buttons move through the rest. */
const VISIBLE_PLANS = 4;
const SOURCE_LABEL = { chat: 'GeoAgent', assessment: 'Assessment' } as const;

interface PlanItem {
    /** `chat` plans open in GeoAgent; `assessment` plans were generated on the location assessment page. */
    kind?: 'chat' | 'assessment';
    id: number;
    title: string;
    municipality: string;
    city: string;
    version: number;
}

/** All of the account's plans, across municipalities, with paginated access to older versions. */
export function PlanningSavedPlans() {
    const chat = usePlanningChat();
    const { props } = usePage<SharedData>();
    const { setOpenMobile } = useSidebar();
    const [plans, setPlans] = useState<PlanItem[]>([]);
    const [nextPage, setNextPage] = useState<number | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(false);
    // Index of the first plan in the visible window; the list pages instead of scrolling.
    const [offset, setOffset] = useState(0);
    const pending = useRef<AbortController | null>(null);
    const userId = props.auth.user.id;

    const load = useCallback(async (page: number) => {
        pending.current?.abort();
        const controller = new AbortController();
        pending.current = controller;
        setLoading(true);
        setError(false);
        try {
            const response = await fetch(`${route('planning-chat.saved-plans')}?page=${page}`, {
                credentials: 'same-origin',
                signal: controller.signal,
                headers: { Accept: 'application/json' },
            });
            if (!response.ok || response.redirected) throw new Error('Could not load saved plans.');
            const payload = await response.json();
            if (controller.signal.aborted) return;
            setPlans((current) => (page === 1 ? payload.items : [...current, ...payload.items]));
            if (page === 1) setOffset(0);
            setNextPage(payload.next_page);
        } catch {
            if (!controller.signal.aborted) setError(true);
        } finally {
            if (!controller.signal.aborted) setLoading(false);
        }
    }, []);

    useEffect(() => {
        setPlans([]);
        void load(1);
        const refresh = () => {
            void load(1);
        };
        window.addEventListener('planning-plans-updated', refresh);
        return () => {
            pending.current?.abort();
            window.removeEventListener('planning-plans-updated', refresh);
        };
    }, [load, userId]);

    const start = Math.max(0, Math.min(offset, plans.length - VISIBLE_PLANS));
    const visible = plans.slice(start, start + VISIBLE_PLANS);
    const canGoUp = start > 0;
    const canGoDown = start + VISIBLE_PLANS < plans.length || nextPage !== null;
    const goUp = () => setOffset(Math.max(0, start - VISIBLE_PLANS));
    const goDown = async () => {
        const target = start + VISIBLE_PLANS;
        // Fetch the next page first when the window would run past the plans already loaded.
        if (target + VISIBLE_PLANS > plans.length && nextPage !== null) await load(nextPage);
        setOffset(target);
    };
    const pagerButton =
        'grid size-7 shrink-0 cursor-pointer place-items-center rounded-lg border border-slate-200 bg-white text-slate-500 transition-colors hover:border-blue-200 hover:bg-blue-50 hover:text-[#087dcc] focus-visible:outline-2 focus-visible:outline-[#087dcc] disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:border-slate-200 disabled:hover:bg-white disabled:hover:text-slate-500';

    return (
        <SidebarGroup className="px-3 py-0 group-data-[collapsible=icon]:px-2">
            <SidebarGroupLabel className="mb-1 px-3 text-[10px] font-medium tracking-[0.12em] text-slate-500 uppercase">
                Generated action plans
            </SidebarGroupLabel>
            <SidebarMenu className="gap-1" aria-live="polite">
                {visible.map((plan) => (
                    <SidebarMenuItem key={`${plan.kind ?? 'chat'}:${plan.id}`}>
                        <SidebarMenuButton
                            tooltip={`${plan.title} · ${plan.municipality} · v${plan.version}`}
                            aria-label={`Open action plan: ${plan.title}, ${plan.municipality}, version ${plan.version}`}
                            onClick={() => {
                                setOpenMobile(false);
                                if (plan.kind === 'assessment') chat?.openAssessmentPlan(plan.id);
                                else chat?.openPlan(plan.city, plan.id);
                            }}
                            className="h-auto min-h-11 cursor-pointer rounded-xl px-3 py-2 text-slate-600 hover:bg-blue-50 hover:text-[#087dcc] [&>svg]:size-4"
                        >
                            <FileText strokeWidth={1.7} aria-hidden="true" />
                            <span className="min-w-0 flex-1 group-data-[collapsible=icon]:hidden">
                                <span className="block truncate text-xs font-medium">{plan.title}</span>
                                <span className="mt-0.5 block truncate text-[10px] text-slate-500">
                                    {SOURCE_LABEL[plan.kind ?? 'chat']} · {plan.municipality} · v{plan.version}
                                </span>
                            </span>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
            {!plans.length && !loading && !error && (
                <p className="px-3 py-2 text-[11px] text-slate-400 group-data-[collapsible=icon]:hidden">No generated plans yet.</p>
            )}
            {loading && (
                <p role="status" className="flex items-center gap-1.5 px-3 py-2 text-[11px] text-slate-400 group-data-[collapsible=icon]:hidden">
                    <LoaderCircle className="size-3 animate-spin" /> Loading plans…
                </p>
            )}
            {error && (
                <button
                    type="button"
                    onClick={() => void load(1)}
                    className="px-3 py-2 text-left text-[11px] text-amber-700 group-data-[collapsible=icon]:hidden"
                >
                    Could not load plans. Retry
                </button>
            )}
            {(canGoUp || canGoDown) && !error && (
                <nav
                    aria-label="Browse generated action plans"
                    className="mt-1 flex items-center justify-between gap-2 px-3 group-data-[collapsible=icon]:flex-col group-data-[collapsible=icon]:px-0"
                >
                    <span className="text-[10px] text-slate-400 tabular-nums group-data-[collapsible=icon]:hidden">
                        {start + 1}–{start + visible.length} of {plans.length}
                        {nextPage !== null ? '+' : ''}
                    </span>
                    <span className="flex items-center gap-1 group-data-[collapsible=icon]:flex-col">
                        <button type="button" onClick={goUp} disabled={!canGoUp || loading} aria-label="Show newer plans" className={pagerButton}>
                            <ChevronUp className="size-4" aria-hidden="true" />
                        </button>
                        <button
                            type="button"
                            onClick={() => void goDown()}
                            disabled={!canGoDown || loading}
                            aria-label="Show older plans"
                            className={pagerButton}
                        >
                            <ChevronDown className="size-4" aria-hidden="true" />
                        </button>
                    </span>
                </nav>
            )}
        </SidebarGroup>
    );
}
