import { cn } from '@/lib/utils';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

/** Title block for admin pages: a white card with icon, title, description and optional actions. */
export function AdminPageHeader({
    icon: Icon,
    eyebrow,
    title,
    description,
    actions,
    className,
}: {
    icon?: LucideIcon;
    eyebrow?: ReactNode;
    title: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    className?: string;
}) {
    return (
        <header
            className={cn(
                'flex flex-wrap items-start justify-between gap-4 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs sm:p-6 dark:border-slate-800 dark:bg-slate-900',
                className,
            )}
        >
            <div className="flex min-w-0 items-start gap-3.5">
                {Icon && (
                    <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-blue-50 text-[#087dcc] dark:bg-slate-800">
                        <Icon className="size-5" aria-hidden="true" />
                    </span>
                )}
                <div className="min-w-0">
                    {eyebrow && <div className="mb-1 text-xs text-slate-500">{eyebrow}</div>}
                    <h1 className="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-50">{title}</h1>
                    {description && <p className="mt-1 max-w-2xl text-sm leading-relaxed text-slate-500">{description}</p>}
                </div>
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </header>
    );
}
