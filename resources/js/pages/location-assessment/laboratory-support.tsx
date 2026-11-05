import { explainNumber } from '@/lib/assessment-explanations';
import { reportLaboratoryOptions, reportNumber, reportText } from '@/lib/assessment-report';
import { LocalLaboratoriesSection } from './laboratory-services';
import type { AssessmentPageProps } from './types';

export default function LaboratorySupport({ report }: { report: AssessmentPageProps }) {
    const options = reportLaboratoryOptions(report);
    return (
        <section aria-label="Laboratory support" className="min-w-0 space-y-3">
            {options.conflict ? (
                <p className="text-sm">Validate conflicting laboratory locations before choosing a partner.</p>
            ) : options.local.length ? (
                <LocalLaboratoriesSection cityName={report.location.name} laboratories={report.localLaboratories} />
            ) : options.nearby ? (
                <p className="rounded-xl border border-sky-100 bg-white p-4 text-sm dark:border-slate-800 dark:bg-slate-950">
                    Nearest recorded referral: <strong>{reportText(options.nearby.agency_name)}</strong> (
                    {explainNumber(reportNumber(options.nearby.distance_km))} km straight-line). Confirm required tests and access.
                </p>
            ) : (
                <p className="text-muted-foreground text-sm">
                    No verified laboratory partner in the returned evidence. Confirm nearby services with DOST.
                </p>
            )}
            <p className="text-muted-foreground text-xs">A new laboratory requires verified testing demand, equipment, staff and budget.</p>
        </section>
    );
}
