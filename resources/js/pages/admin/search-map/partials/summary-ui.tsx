import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

export function SummarySection({
    title,
    description,
    icon: Icon,
    tone = 'green',
    children,
}: {
    title: string;
    description?: string;
    icon?: LucideIcon;
    tone?: 'green' | 'blue';
    children: ReactNode;
}) {
    return (
        <section className={`rounded-xl border-b p-4 ${tone === 'blue' ? 'border-blue-100 bg-blue-50/50' : 'border-sky-50 bg-white'}`}>
            <div className="flex items-start gap-2.5">
                {Icon && (
                    <span
                        className={`grid size-9 shrink-0 place-items-center rounded-xl ${tone === 'blue' ? 'bg-blue-100 text-[#087dcc]' : 'bg-[#eaf3ed] text-[#138771]'}`}
                    >
                        <Icon size={19} aria-hidden="true" />
                    </span>
                )}
                <div className="min-w-0">
                    <h3 className={`text-sm font-semibold ${tone === 'blue' ? 'text-[#087dcc]' : 'text-[#153d38]'}`}>{title}</h3>
                    {description && <p className="mt-1 text-[11px] leading-relaxed text-[#738580]">{description}</p>}
                </div>
            </div>
            <div className="mt-3 space-y-2.5">{children}</div>
        </section>
    );
}

export function SummaryRow({ label, value, note }: { label: string; value: ReactNode; note?: string }) {
    return (
        <div>
            <div className="flex items-baseline justify-between gap-4 text-xs">
                <span className="min-w-0 text-[#738580]">{label}</span>
                <span className="text-right font-semibold break-words text-[#284d45] tabular-nums">{value}</span>
            </div>
            {note && <p className="mt-0.5 text-right text-[10px] leading-relaxed text-[#738580]">{note}</p>}
        </div>
    );
}
