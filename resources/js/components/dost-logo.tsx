import { DOST_LOGO_URL, DOST_MARK_RATIO } from '@/lib/dost-logo';
import { cn } from '@/lib/utils';

/**
 * The DOST logo. `full` is the horizontal lockup; `mark` crops it to the symbol.
 * Size it by height (e.g. `h-9`). The text is black, so dark mode gets a white backing.
 */
export function DostLogo({ variant = 'full', className }: { variant?: 'full' | 'mark'; className?: string }) {
    if (variant === 'mark') {
        return (
            <span className={cn('inline-block shrink-0 overflow-hidden', className)} style={{ aspectRatio: `${DOST_MARK_RATIO}` }}>
                <img src={DOST_LOGO_URL} alt="DOST" className="h-full w-auto max-w-none" draggable={false} />
            </span>
        );
    }
    return (
        <img
            src={DOST_LOGO_URL}
            alt="Department of Science and Technology"
            className={cn('w-auto shrink-0 dark:rounded-md dark:bg-white dark:p-1', className)}
            draggable={false}
        />
    );
}
