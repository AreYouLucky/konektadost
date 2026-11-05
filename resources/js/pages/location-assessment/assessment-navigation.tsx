import type { AssessmentLayer } from '@/lib/assessment-report';
import { Activity, Building2, FlaskConical, MapPin, Sparkles } from 'lucide-react';
import { forwardRef } from 'react';

export const ASSESSMENT_SECTIONS = [
    { key: 'profile_assets', label: 'Area & Assets', icon: Building2 },
    { key: 'sectors_roads', label: 'Sector Needs', icon: Activity },
    { key: 'gaps_barangays', label: 'Gaps & Priority', icon: MapPin },
    { key: 'dost_catalog', label: 'DOST Catalog', icon: FlaskConical },
    { key: 'matched_interventions', label: 'Technology & Lab Fit', icon: Sparkles },
] as const;

/** Sticky step tabs (A–E) with a small count per step. */
const AssessmentNavigation = forwardRef<
    HTMLElement,
    { active: AssessmentLayer; onChange: (key: AssessmentLayer) => void; counts?: Partial<Record<AssessmentLayer, { value: number; label: string }>> }
>(function AssessmentNavigation({ active, onChange, counts = {} }, ref) {
    return (
        <nav
            ref={ref}
            aria-label="Assessment workflow steps"
            className="sticky top-0 z-30 -mx-4 scroll-mt-2 border-b border-sky-100 bg-blue-50/90 px-4 py-2 backdrop-blur md:-mx-6 md:px-6 dark:border-slate-800 dark:bg-slate-950/90"
        >
            <ol className="flex gap-2 overflow-x-auto pb-0.5 lg:grid lg:grid-cols-5 lg:overflow-visible">
                {ASSESSMENT_SECTIONS.map(({ key, label, icon: Icon }, index) => {
                    const isActive = active === key;
                    const count = counts[key];
                    return (
                        <li key={key} className="min-w-44 shrink-0 lg:min-w-0">
                            <button
                                type="button"
                                aria-current={isActive ? 'step' : undefined}
                                onClick={() => onChange(key)}
                                className={`flex w-full items-center gap-2.5 rounded-xl border px-3 py-2 text-left transition-colors ${isActive ? 'border-[#087dcc] bg-[#087dcc] text-white shadow-sm' : 'border-sky-100 bg-white text-slate-700 hover:border-sky-300 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-200'}`}
                            >
                                <span
                                    className={`flex size-7 shrink-0 items-center justify-center rounded-lg ${isActive ? 'bg-white/20' : 'bg-blue-50 text-[#087dcc] dark:bg-slate-800'}`}
                                >
                                    <Icon className="size-4" aria-hidden="true" />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className={`block text-[10px] font-semibold uppercase ${isActive ? 'text-blue-100' : 'text-slate-400'}`}>
                                        Step {String.fromCharCode(65 + index)}
                                    </span>
                                    <span className="block truncate text-sm font-semibold">{label}</span>
                                </span>
                                {count && (
                                    <span
                                        title={count.label}
                                        className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums ${isActive ? 'bg-white text-[#087dcc]' : 'bg-sky-50 text-[#087dcc] dark:bg-slate-800 dark:text-sky-300'}`}
                                    >
                                        {count.value}
                                    </span>
                                )}
                            </button>
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
});

export default AssessmentNavigation;
