import type { AssessmentLayer } from '@/lib/assessment-report';
import { Activity, Building2, FlaskConical, MapPin, Sparkles } from 'lucide-react';

const sections = [
    { key: 'profile_assets', label: 'Area & Assets', icon: Building2 },
    { key: 'sectors_roads', label: 'Sector Needs', icon: Activity },
    { key: 'gaps_barangays', label: 'Gaps & Priority', icon: MapPin },
    { key: 'dost_catalog', label: 'DOST Catalog', icon: FlaskConical },
    { key: 'matched_interventions', label: 'Technology & Lab Fit', icon: Sparkles },
] as const;

export default function AssessmentNavigation({ active, onChange }: { active: AssessmentLayer; onChange: (key: AssessmentLayer) => void }) {
    return (
        <nav aria-label="Assessment workflow steps" className="grid grid-cols-2 gap-2.5 sm:grid-cols-5">
            {sections.map(({ key, label, icon: Icon }, index) => (
                <button
                    key={key}
                    type="button"
                    aria-current={active === key ? 'step' : undefined}
                    onClick={() => onChange(key)}
                    className={`min-w-0 rounded-xl border p-3 text-left transition-colors ${active === key ? 'border-[#087dcc] bg-[#087dcc] text-white' : 'border-blue-100 bg-white text-slate-800 hover:border-blue-300 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-200'}`}
                >
                    <span className="flex items-center justify-between text-[10px] font-semibold uppercase opacity-80">
                        Layer {String.fromCharCode(65 + index)}
                        <Icon className="size-3.5" aria-hidden="true" />
                    </span>
                    <span className="mt-1 block text-sm font-semibold">{label}</span>
                </button>
            ))}
        </nav>
    );
}
