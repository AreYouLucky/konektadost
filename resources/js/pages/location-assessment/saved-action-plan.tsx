import WideModal from '@/components/assessment-method/wide-modal';
import { DostLogo } from '@/components/dost-logo';
import { Button } from '@/components/ui/button';
import { DialogClose } from '@/components/ui/dialog';
import { loadAssessmentPlan, type SavedAssessmentPlan } from '@/lib/assessment-plan-store';
import { Link } from '@inertiajs/react';
import { ExternalLink, FileDown, LoaderCircle, RotateCcw } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ActionPlanView, useActionPlanDownload } from './action-plan-export';

type LoadState = { status: 'loading' } | { status: 'ready'; plan: SavedAssessmentPlan } | { status: 'error'; message: string };

const formatSaved = (iso: string) => {
    const date = new Date(iso);
    return Number.isNaN(date.getTime())
        ? 'Time not recorded'
        : new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short' }).format(date);
};

function SavedPlanActions({ plan, onClose }: { plan: SavedAssessmentPlan; onClose: () => void }) {
    const { busy, error, download } = useActionPlanDownload(plan.snapshot);
    return (
        <>
            {error && (
                <p role="alert" className="mr-auto text-xs text-amber-800 dark:text-amber-300">
                    {error}
                </p>
            )}
            <Button asChild variant="outline" className="border-[#087dcc]/30 text-[#087dcc]">
                <Link href={plan.assessment_url} onClick={onClose}>
                    <ExternalLink className="size-4" aria-hidden="true" /> Open assessment
                </Link>
            </Button>
            <DialogClose asChild>
                <Button variant="ghost">Close</Button>
            </DialogClose>
            <Button onClick={download} disabled={busy} className="bg-[#087dcc] text-white hover:bg-[#086bb0]">
                {busy ? <LoaderCircle className="size-4 animate-spin" aria-hidden="true" /> : <FileDown className="size-4" aria-hidden="true" />}
                {busy ? 'Preparing PDF…' : 'Download PDF'}
            </Button>
        </>
    );
}

/** A saved assessment action plan, reopened exactly as it was generated. */
export default function SavedActionPlanDialog({ planId, onClose }: { planId: number; onClose: () => void }) {
    const [state, setState] = useState<LoadState>({ status: 'loading' });
    const [attempt, setAttempt] = useState(0);
    useEffect(() => {
        const controller = new AbortController();
        setState({ status: 'loading' });
        loadAssessmentPlan(planId, controller.signal)
            .then((plan) => setState({ status: 'ready', plan }))
            .catch((error) => {
                if (!controller.signal.aborted)
                    setState({ status: 'error', message: error instanceof Error ? error.message : 'The saved plan could not be loaded.' });
            });
        return () => controller.abort();
    }, [planId, attempt]);

    const plan = state.status === 'ready' ? state.plan : null;
    return (
        <WideModal
            size="xl"
            open
            onOpenChange={(open) => !open && onClose()}
            title={plan ? `${plan.location_name} · DOST Action Plan` : 'Saved action plan'}
            description={
                plan
                    ? `Saved version ${plan.version} · ${formatSaved(plan.snapshot.plan.generatedAt)}${plan.ai_assisted ? ' · AI-assisted wording' : ''} · Draft for LGU/DOST validation.`
                    : 'Draft for LGU/DOST validation.'
            }
            leading={<DostLogo className="h-9 sm:h-10" />}
            footer={
                plan ? (
                    <SavedPlanActions plan={plan} onClose={onClose} />
                ) : (
                    <DialogClose asChild>
                        <Button variant="ghost">Close</Button>
                    </DialogClose>
                )
            }
        >
            {state.status === 'loading' && (
                <p role="status" className="flex items-center gap-2 py-10 text-sm text-slate-500">
                    <LoaderCircle className="size-4 animate-spin" aria-hidden="true" /> Loading saved plan…
                </p>
            )}
            {state.status === 'error' && (
                <div role="alert" className="flex flex-wrap items-center gap-3 py-10 text-sm text-amber-800 dark:text-amber-300">
                    {state.message}
                    <Button variant="outline" size="sm" onClick={() => setAttempt((value) => value + 1)}>
                        <RotateCcw className="size-4" aria-hidden="true" /> Retry
                    </Button>
                </div>
            )}
            {plan && <ActionPlanView plan={plan.snapshot.plan} targets={plan.snapshot.targets} />}
        </WideModal>
    );
}
