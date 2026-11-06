import AssessmentProgress from '@/components/assessment-progress';
import { Button } from '@/components/ui/button';
import * as Dialog from '@radix-ui/react-dialog';
import { ClipboardList, LoaderCircle, MapPin } from 'lucide-react';

export default function AssessmentLoading({ open, onCancel }: { open: boolean; onCancel: () => void }) {
    return (
        <Dialog.Root
            open={open}
            onOpenChange={(nextOpen) => {
                if (!nextOpen) onCancel();
            }}
        >
            <Dialog.Portal>
                <Dialog.Overlay className="fixed inset-0 z-[10000] bg-slate-50/95 backdrop-blur-sm" />
                <Dialog.Content
                    className="fixed top-1/2 left-1/2 z-[10001] max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-3xl border border-blue-100 bg-white p-6 text-center shadow-[0_24px_80px_-24px_#087dcc30] outline-none sm:p-9"
                    onPointerDownOutside={(event) => event.preventDefault()}
                >
                    <div className="mb-7 flex items-center justify-center gap-2 text-xs font-semibold tracking-wide text-[#087dcc]">
                        <MapPin className="size-4" aria-hidden="true" />
                        KonektaDOST
                    </div>

                    <div className="relative mx-auto mb-6 flex size-24 items-center justify-center rounded-full bg-blue-50">
                        <LoaderCircle
                            className="absolute inset-0 size-24 animate-spin text-[#087dcc]/70 motion-reduce:animate-none"
                            strokeWidth={1}
                            aria-hidden="true"
                        />
                        <ClipboardList className="size-9 text-[#087dcc]" strokeWidth={1.5} aria-hidden="true" />
                    </div>

                    <Dialog.Title className="text-2xl font-semibold tracking-tight text-slate-900">Analyzing the selected area</Dialog.Title>
                    <Dialog.Description className="sr-only">
                        Generating the assessment: local profile, resource counts, national comparisons, gaps and DOST technology matches.
                    </Dialog.Description>

                    <AssessmentProgress active={open} className="mx-auto mt-6 max-w-sm" />

                    <p role="status" className="mt-6 text-xs leading-relaxed text-slate-500">
                        Your report will open automatically when it’s ready.
                    </p>
                    <Button
                        variant="ghost"
                        onClick={onCancel}
                        className="mt-3 text-slate-500 hover:bg-blue-50 hover:text-[#087dcc] focus-visible:ring-[#087dcc]"
                    >
                        Cancel request
                    </Button>
                </Dialog.Content>
            </Dialog.Portal>
        </Dialog.Root>
    );
}
