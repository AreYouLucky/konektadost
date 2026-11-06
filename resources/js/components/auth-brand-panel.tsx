import { DostLogo } from '@/components/dost-logo';
import { Link } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import type { ReactNode } from 'react';

/** The brand-blue gradient and faint grid behind the sign-in, registration and welcome panels. */
export function BrandBackdrop() {
    return (
        <>
            <div
                className="pointer-events-none absolute inset-0"
                style={{
                    backgroundImage:
                        'radial-gradient(circle at 15% 12%, rgba(56, 189, 248, 0.35), transparent 45%), radial-gradient(circle at 90% 90%, rgba(3, 50, 92, 0.65), transparent 55%), linear-gradient(155deg, #087dcc 0%, #06589a 55%, #053f70 100%)',
                }}
            />
            <div
                className="pointer-events-none absolute inset-0 opacity-10"
                style={{
                    backgroundImage:
                        'linear-gradient(to right, rgba(255,255,255,0.12) 1px, transparent 1px), linear-gradient(to bottom, rgba(255,255,255,0.12) 1px, transparent 1px)',
                    backgroundSize: '36px 36px',
                }}
            />
        </>
    );
}

/** Left half of the auth pages on large screens: logo header, page-specific content, data-source footer. */
export function AuthBrandPanel({ children }: { children: ReactNode }) {
    return (
        <div className="relative hidden flex-col justify-between overflow-hidden bg-[#075d99] p-10 text-white lg:flex xl:p-14">
            <BrandBackdrop />

            <div className="relative z-10 flex items-center justify-between gap-4">
                <Link href={route('home')} className="flex items-center gap-4">
                    <span className="rounded-xl bg-white px-3 py-2 shadow-lg shadow-black/10">
                        <DostLogo className="h-7 xl:h-8" />
                    </span>
                    <span className="flex flex-col">
                        <span className="text-lg font-bold tracking-tight text-white">KonektaDOST</span>
                        <span className="text-[11px] font-medium tracking-wider text-sky-100/90 uppercase">Spatial STI Decision Support</span>
                    </span>
                </Link>
                <span className="hidden items-center gap-1.5 rounded-full border border-white/20 bg-white/10 px-3 py-1 text-xs text-sky-50 backdrop-blur xl:inline-flex">
                    <ShieldCheck className="size-3.5 text-sky-200" />
                    DOST Service Area Intelligence
                </span>
            </div>

            <div className="relative z-10 my-auto flex flex-col gap-8 py-8">{children}</div>

            <div className="relative z-10 flex items-center justify-between border-t border-white/15 pt-5 text-xs text-sky-100/70">
                <span>PSA 2024 Census · OpenStreetMap · DOST OneLab · DOST Technology Catalog</span>
                <span>v1.0</span>
            </div>
        </div>
    );
}

/** Logo + product name shown above the form on small screens, where the brand panel is hidden. */
export function AuthMobileBrand() {
    return (
        <Link href={route('home')} className="flex items-center gap-3 lg:hidden">
            <DostLogo className="h-9" />
            <span className="h-8 w-px bg-slate-200" aria-hidden="true" />
            <span className="flex flex-col">
                <span className="text-lg font-bold tracking-tight text-[#087dcc]">KonektaDOST</span>
                <span className="text-muted-foreground text-xs">Spatial STI Decision Support</span>
            </span>
        </Link>
    );
}
