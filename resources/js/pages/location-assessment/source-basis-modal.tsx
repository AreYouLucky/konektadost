import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
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
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="link" size="sm" className="h-auto max-w-full justify-start p-0 text-left text-xs whitespace-normal text-[#087dcc]">
                    {triggerLabel}
                </Button>
            </DialogTrigger>
            <DialogContent style={{ translate: '-50% -50%' }} className="max-h-[85dvh] w-[92vw] max-w-3xl overflow-y-auto rounded-xl">
                <DialogHeader className="pr-5 text-left">
                    <DialogTitle className="leading-snug text-[#087dcc]">{title}</DialogTitle>
                    <DialogDescription>Recorded evidence and calculations for this result.</DialogDescription>
                </DialogHeader>
                <div className="min-w-0 space-y-3 text-sm leading-relaxed break-words">{children}</div>
            </DialogContent>
        </Dialog>
    );
}
