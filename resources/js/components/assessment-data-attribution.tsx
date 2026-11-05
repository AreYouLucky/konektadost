import { assessmentDataSources } from '@/lib/assessment-data-sources';
import type { AssessmentReportInput } from '@/lib/assessment-report';

export default function AssessmentDataAttribution({ report }: { report: AssessmentReportInput }) {
    return (
        <section aria-labelledby="assessment-data-sources" className="min-w-0 space-y-3">
            <h2 id="assessment-data-sources" className="sr-only">
                Data sources &amp; attribution
            </h2>
            <p className="text-muted-foreground text-xs">
                Sources recorded by the system. Some imports have no source date or original provider; empty inventories remain incomplete evidence.
            </p>
            <div
                className="overflow-x-auto rounded-xl border border-sky-100 bg-white dark:border-slate-800 dark:bg-slate-950"
                role="region"
                aria-label="Scrollable data attribution table"
                tabIndex={0}
            >
                <table className="w-full min-w-[700px] text-left text-xs">
                    <caption className="sr-only">Data sources, recorded references and their use in this assessment</caption>
                    <thead className="bg-sky-50 dark:bg-slate-900">
                        <tr>
                            {['Data used', 'Source / provider', 'Year / reference', 'Used for'].map((title) => (
                                <th key={title} className="p-3 font-semibold">
                                    {title}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                        {assessmentDataSources(report).map((row) => (
                            <tr key={row.data} className="align-top">
                                <th scope="row" className="p-3 font-medium">
                                    {row.data}
                                </th>
                                <td className="p-3">{row.source}</td>
                                <td className="p-3">{row.reference}</td>
                                <td className="p-3">{row.use}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}
