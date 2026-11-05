import type { AiResult } from '@/components/assessment-ai-analysis';
import { Button } from '@/components/ui/button';
import type { AssessmentLayer, AssessmentReportInput, LayerOverview } from '@/lib/assessment-report';
import { Download, LoaderCircle } from 'lucide-react';
import { useState } from 'react';

export default function AssessmentPdfExport({
    report,
    overviews,
    analysis,
}: {
    report: AssessmentReportInput;
    overviews: Record<AssessmentLayer, LayerOverview>;
    analysis?: AiResult;
}) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const download = async () => {
        setBusy(true);
        setError(null);
        try {
            const { downloadAssessmentPdf } = await import('@/lib/assessment-pdf');
            await downloadAssessmentPdf(report, overviews, analysis);
        } catch {
            setError('The PDF could not be created. Please retry.');
        } finally {
            setBusy(false);
        }
    };
    return (
        <div className="min-w-0 rounded-xl border border-sky-100 bg-white p-3 sm:flex sm:items-center sm:justify-between sm:gap-4 dark:border-slate-800 dark:bg-slate-950">
            <p className="text-muted-foreground text-xs leading-relaxed">Save all layers, matches and calculation notes.</p>
            <Button
                variant="outline"
                className="mt-2 w-full shrink-0 border-sky-200 text-[#087dcc] sm:mt-0 sm:w-auto"
                onClick={download}
                disabled={busy}
            >
                {busy ? (
                    <LoaderCircle className="mr-2 size-4 animate-spin" aria-hidden="true" />
                ) : (
                    <Download className="mr-2 size-4" aria-hidden="true" />
                )}
                {busy ? 'Preparing PDF…' : 'Export to PDF'}
            </Button>
            {error && (
                <p role="alert" className="mt-2 text-xs text-amber-800 dark:text-amber-300">
                    {error}
                </p>
            )}
        </div>
    );
}
