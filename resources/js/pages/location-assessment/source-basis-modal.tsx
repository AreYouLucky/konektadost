import WideModal from '@/components/assessment-method/wide-modal';
import { Button } from '@/components/ui/button';
import type { ReactNode } from 'react';

export default function SourceBasisModal({
    title = 'Source & basis',
    children,
    triggerLabel = 'Source & basis',
}: {
    title?: ReactNode;
    children: ReactNode;
    triggerLabel?: string;
}) {
    return (
        <WideModal
            size="md"
            title={title}
            description="Recorded evidence and calculations for this result."
            trigger={
                <Button variant="link" size="sm" className="h-auto max-w-full justify-start p-0 text-left text-xs whitespace-normal text-[#087dcc]">
                    {triggerLabel}
                </Button>
            }
        >
            <div className="min-w-0 space-y-3 text-sm leading-relaxed break-words">{children}</div>
        </WideModal>
    );
}
