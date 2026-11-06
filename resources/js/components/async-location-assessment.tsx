import AssessmentProgress from '@/components/assessment-progress';
import { Button } from '@/components/ui/button';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import AppSidebarLayoutAdmin from '@/layouts/app/app-sidebar-layout-admin';
import type { AssessmentPageProps } from '@/pages/location-assessment';
import { Head, Link, router } from '@inertiajs/react';
import { AlertCircle, ArrowLeft, ClipboardList, LoaderCircle, RotateCcw } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';

export interface PendingAssessment {
    url: string;
    name: string;
    province: string | null;
    backUrl: string;
    layout: 'user' | 'admin';
}

export default function AsyncLocationAssessment({
    pending,
    renderReport,
}: {
    pending: PendingAssessment;
    renderReport: (report: AssessmentPageProps) => ReactNode;
}) {
    const [attempt, setAttempt] = useState(0);
    const [state, setState] = useState<{ url: string; report: AssessmentPageProps | null; error: string | null }>({
        url: pending.url,
        report: null,
        error: null,
    });
    const request = useRef<AbortController | null>(null);

    useEffect(() => {
        const controller = new AbortController();
        request.current = controller;
        setState({ url: pending.url, report: null, error: null });

        void (async () => {
            try {
                const response = await fetch(pending.url, {
                    signal: controller.signal,
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (response.redirected || response.status === 401 || response.status === 419)
                    throw new Error('Your session has expired. Please sign in again.');
                if (response.status === 404 || response.status === 422)
                    throw new Error('This assessment selection is no longer available. Return to the map and select the location again.');
                if (response.status === 429) throw new Error('Please wait a moment before trying again.');
                if (!response.ok) throw new Error('We could not generate this assessment. Please try again.');

                const report: AssessmentPageProps = await response.json();
                if (!report || !report.location || !Array.isArray(report.results))
                    throw new Error('The assessment response was incomplete. Please try again.');
                if (!controller.signal.aborted) setState({ url: pending.url, report, error: null });
            } catch (error) {
                if (!controller.signal.aborted)
                    setState({
                        url: pending.url,
                        report: null,
                        error: error instanceof Error ? error.message : 'The assessment could not be loaded. Please try again.',
                    });
            }
        })();

        return () => controller.abort();
    }, [pending.url, attempt]);

    // Never show the previous city's result while a new URL is loading.
    const current = state.url === pending.url ? state : null;
    if (current?.report) return renderReport(current.report);

    const Layout = pending.layout === 'admin' ? AppSidebarLayoutAdmin : AppSidebarLayout;
    const cancel = () => {
        request.current?.abort();
        setState({ url: pending.url, report: null, error: 'Assessment request cancelled.' });
        router.visit(pending.backUrl);
    };

    return (
        <Layout>
            <Head title={`${pending.name} Assessment`} />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <Button variant="ghost" size="sm" asChild className="mb-3 -ml-2 w-fit">
                        <Link href={pending.backUrl}>
                            <ArrowLeft className="size-4" /> Back to map
                        </Link>
                    </Button>
                    <h1 className="text-2xl font-semibold tracking-tight text-[#087dcc] sm:text-3xl">{pending.name} Assessment</h1>
                    {pending.province && <p className="mt-1 text-sm text-slate-500">{pending.province}</p>}
                </div>

                <section
                    aria-busy={!current?.error}
                    className="mx-auto my-auto w-full max-w-xl rounded-3xl border border-blue-100 bg-white px-6 py-10 text-center shadow-sm sm:px-10"
                >
                    {current?.error ? (
                        <>
                            <AlertCircle className="mx-auto mb-5 size-10 text-amber-600" aria-hidden="true" />
                            <h2 className="text-xl font-semibold text-slate-900">Assessment not ready</h2>
                            <p role="alert" className="mt-3 text-sm leading-relaxed text-slate-500">
                                {current.error}
                            </p>
                            <Button onClick={() => setAttempt((value) => value + 1)} className="mt-6 bg-[#087dcc] text-white hover:bg-[#087dcc]/90">
                                <RotateCcw className="size-4" /> Try again
                            </Button>
                        </>
                    ) : (
                        <>
                            <div className="relative mx-auto mb-5 flex size-16 items-center justify-center rounded-full bg-blue-50">
                                <LoaderCircle
                                    className="absolute inset-0 size-16 animate-spin text-[#087dcc]/70 motion-reduce:animate-none"
                                    strokeWidth={1}
                                    aria-hidden="true"
                                />
                                <ClipboardList className="size-7 text-[#087dcc]" strokeWidth={1.5} aria-hidden="true" />
                            </div>
                            <h2 className="text-2xl font-semibold tracking-tight text-slate-900">Analyzing {pending.name}</h2>
                            <p role="status" className="sr-only">
                                Generating the assessment. The report will appear here when it’s ready.
                            </p>
                            <AssessmentProgress name={pending.name} className="mx-auto mt-6 max-w-sm" />
                            <Button variant="ghost" onClick={cancel} className="mt-6 text-slate-500 hover:bg-blue-50 hover:text-[#087dcc]">
                                Cancel request
                            </Button>
                        </>
                    )}
                </section>
            </div>
        </Layout>
    );
}
