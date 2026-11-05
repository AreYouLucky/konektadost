import type { AiResult } from '@/components/assessment-ai-analysis';
import { Button } from '@/components/ui/button';
import type { AssessmentReportInput } from '@/lib/assessment-report';
import { FileDown, LoaderCircle } from 'lucide-react';
import { useState } from 'react';

export default function ActionPlanExport({ report, analysis }: { report: AssessmentReportInput; analysis?: AiResult }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    async function download() {
        setBusy(true);
        setError(null);
        try {
            const { downloadActionPlanPdf } = await import('@/lib/assessment-action-plan-pdf');
            await downloadActionPlanPdf(report, analysis);
        } catch {
            setError('The action plan PDF could not be created. Please retry.');
        } finally {
            setBusy(false);
        }
    }
    return (
        <section
            aria-label="Action plan PDF"
            className="min-w-0 rounded-xl border border-sky-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950"
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="text-sm font-semibold text-[#087dcc]">DOST Action Plan</h2>
                    <p className="text-muted-foreground mt-1 text-xs">
                        Activities, objectives, results, timeline, resources and responsible offices, with a flowchart and evidence graphs.
                    </p>
                </div>
                <Button onClick={download} disabled={busy} className="w-full bg-[#087dcc] text-white hover:bg-[#086bb0] sm:w-auto">
                    {busy ? (
                        <LoaderCircle className="mr-2 size-4 animate-spin" aria-hidden="true" />
                    ) : (
                        <FileDown className="mr-2 size-4" aria-hidden="true" />
                    )}
                    {busy ? 'Preparing action plan…' : 'Generate Action Plan PDF'}
                </Button>
            </div>
            <p className="text-muted-foreground mt-2 text-[11px]">
                {analysis
                    ? 'Includes completed AI interpretation for matching options.'
                    : 'Uses computed assessment evidence; completed AI interpretation is included when available.'}{' '}
                Proposed schedule and costs require local confirmation.
            </p>
            {error && (
                <p role="alert" className="mt-2 text-xs text-amber-800 dark:text-amber-300">
                    {error}
                </p>
            )}
        </section>
    );
}
