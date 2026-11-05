import { Dialog, DialogContent, DialogDescription, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import type { ReactNode } from 'react';

const SIZES = { md: 'max-w-2xl', lg: 'max-w-5xl', xl: 'max-w-6xl' } as const;

/**
 * Shared shell for large modals: fixed header (title, actions, close) and footer,
 * with only the body scrolling, so the close button and actions never scroll away.
 */
export default function WideModal({
    trigger,
    title,
    description,
    actions,
    footer,
    size = 'lg',
    children,
}: {
    trigger: ReactNode;
    title: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    footer?: ReactNode;
    size?: keyof typeof SIZES;
    children: ReactNode;
}) {
    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent
                style={{ translate: '-50% -50%' }}
                className={`flex max-h-[90dvh] w-[calc(100vw-2rem)] flex-col gap-0 overflow-hidden rounded-2xl p-0 ${SIZES[size]}`}
            >
                <header className="flex shrink-0 flex-wrap items-center gap-x-4 gap-y-2 border-b border-slate-200 py-4 pr-12 pl-5 sm:pl-6 dark:border-slate-800">
                    <div className="min-w-0 flex-1">
                        <DialogTitle className="text-base leading-snug font-semibold text-[#087dcc] sm:text-lg">{title}</DialogTitle>
                        {description && <DialogDescription className="mt-1 text-xs sm:text-sm">{description}</DialogDescription>}
                    </div>
                    {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
                </header>
                <div className="viz-palette min-h-0 flex-1 overflow-y-auto px-5 py-5 sm:px-6">{children}</div>
                {footer && (
                    <footer className="flex shrink-0 flex-wrap items-center justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3 sm:px-6 dark:border-slate-800 dark:bg-slate-900">
                        {footer}
                    </footer>
                )}
            </DialogContent>
        </Dialog>
    );
}
