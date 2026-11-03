import { Button } from '@/components/ui/button';
import * as Dialog from '@radix-ui/react-dialog';
import { ChartNoAxesCombined, ClipboardList, LoaderCircle, MapPin, Users } from 'lucide-react';

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

                    <Dialog.Title className="text-2xl font-semibold tracking-tight text-slate-900">Generating assessment…</Dialog.Title>
                    <Dialog.Description className="mx-auto mt-3 max-w-sm text-sm leading-relaxed text-slate-500">
                        Preparing the latest local profile, indicators, and assessment summary. Detailed locations may take a little longer.
                    </Dialog.Description>

                    <div
                        className="mt-7 grid grid-cols-3 gap-2 rounded-2xl bg-slate-50 p-3 text-[11px] leading-relaxed text-slate-600 sm:gap-4 sm:p-4"
                        aria-hidden="true"
                    >
                        <div className="flex flex-col items-center gap-2">
                            <Users className="size-5 text-[#087dcc]" strokeWidth={1.6} />
                            Local profile
                        </div>
                        <div className="flex flex-col items-center gap-2">
                            <ChartNoAxesCombined className="size-5 text-[#087dcc]" strokeWidth={1.6} />
                            Indicator comparison
                        </div>
                        <div className="flex flex-col items-center gap-2">
                            <ClipboardList className="size-5 text-[#087dcc]" strokeWidth={1.6} />
                            Assessment summary
                        </div>
                    </div>

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
