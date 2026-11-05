import { usePlanningChat } from '@/components/planning-chat';
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem, useSidebar } from '@/components/ui/sidebar';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { FileText, LoaderCircle } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

interface PlanItem {
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

    return (
        <SidebarGroup className="px-3 py-0 group-data-[collapsible=icon]:px-2">
            <SidebarGroupLabel className="mb-1 px-3 text-[10px] font-medium tracking-[0.12em] text-slate-500 uppercase">
                Generated action plans
            </SidebarGroupLabel>
            <SidebarMenu className="max-h-72 gap-1 overflow-y-auto">
                {plans.map((plan) => (
                    <SidebarMenuItem key={plan.id}>
                        <SidebarMenuButton
                            tooltip={`${plan.title} · ${plan.municipality} · v${plan.version}`}
                            aria-label={`Open action plan: ${plan.title}, ${plan.municipality}, version ${plan.version}`}
                            onClick={() => {
                                setOpenMobile(false);
                                chat?.openPlan(plan.city, plan.id);
                            }}
                            className="h-auto min-h-11 cursor-pointer rounded-xl px-3 py-2 text-slate-600 hover:bg-blue-50 hover:text-[#087dcc] [&>svg]:size-4"
                        >
                            <FileText strokeWidth={1.7} aria-hidden="true" />
                            <span className="min-w-0 flex-1 group-data-[collapsible=icon]:hidden">
                                <span className="block truncate text-xs font-medium">{plan.title}</span>
                                <span className="mt-0.5 block truncate text-[10px] text-slate-500">
                                    {plan.municipality} · v{plan.version}
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
            {nextPage && !error && (
                <button
                    type="button"
                    disabled={loading}
                    onClick={() => void load(nextPage)}
                    className="px-3 py-2 text-left text-[11px] font-semibold text-[#087dcc] group-data-[collapsible=icon]:hidden"
                >
                    Show more plans
                </button>
            )}
        </SidebarGroup>
    );
}
