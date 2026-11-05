import type { AiResult } from '@/components/assessment-ai-analysis';
import type { AssessmentLayer, AssessmentReportInput, LayerOverview } from '@/lib/assessment-report';
import { useState } from 'react';

/** Download the full assessment report PDF (all layers, matches and calculation notes). */
export function useAssessmentPdfDownload(report: AssessmentReportInput, overviews: Record<AssessmentLayer, LayerOverview>, analysis?: AiResult) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const download = async () => {
        setBusy(true);
        setError(null);
        try {
            const { downloadAssessmentPdf } = await import('@/lib/assessment-pdf');
            await downloadAssessmentPdf(report, overviews, analysis);
        } catch {
            setError('The report PDF could not be created. Please retry.');
        } finally {
            setBusy(false);
        }
    };
    return { busy, error, download };
}
