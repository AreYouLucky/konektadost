import { DostLogo } from '@/components/dost-logo';
import type { IntelligenceMapProps } from '@/components/intelligence-map';
import type { SearchMapProps } from '@/components/philippine-map';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { lazy, Suspense, type ComponentType } from 'react';

function MapStatus({ message }: { message: string }) {
    return (
        <p role="status" className="m-auto px-6 text-center text-sm text-slate-500">
            {message}
        </p>
    );
}

function MapUnavailable() {
    return <MapStatus message="The map could not be loaded. Please refresh the page to try again." />;
}

// MapLibre is over 1 MB, so the map loads separately from the header. A failed download
// shows a notice here instead of taking the whole landing page down.
const IntelligenceMap = lazy<ComponentType<IntelligenceMapProps>>(() =>
    import('@/components/intelligence-map').catch(() => ({ default: MapUnavailable })),
);

/** Landing page: the header, then the intelligence map filling the rest of the screen. */
export default function Welcome({ map }: { map: SearchMapProps }) {
    const { auth } = usePage<SharedData>().props;
    const role = (auth.user as { role?: string } | null)?.role;
    const workspace = role === 'admin' ? route('admin.dashboard.index') : route('user.dashboard.index');

    return (
        <>
            <Head title="Welcome">
                <meta
                    name="description"
                    content="KonektaDOST helps DOST and local governments decide which science and technology programs fit each Philippine community, and why."
                />
            </Head>

            <div className="flex min-h-dvh flex-col bg-white text-slate-800 dark:bg-slate-950 dark:text-slate-200">
                <header className="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex h-16 items-center justify-between gap-4 px-3 sm:px-6">
                        <Link href={route('home')} className="flex min-w-0 items-center gap-3">
                            <DostLogo className="h-8 sm:h-10" />
                            <span className="hidden h-9 w-px bg-slate-200 sm:block dark:bg-slate-700" aria-hidden="true" />
                            <span className="hidden text-sm font-semibold tracking-tight text-[#087dcc] sm:block">KonektaDOST</span>
                        </Link>
                        <nav aria-label="Account" className="flex shrink-0 items-center gap-2">
                            {auth.user ? (
                                <Link
                                    href={workspace}
                                    className="rounded-lg bg-[#087dcc] px-4 py-2 text-sm font-semibold text-white hover:bg-[#086bb0]"
                                >
                                    Workspace
                                </Link>
                            ) : (
                                <>
                                    <Link
                                        href={route('login')}
                                        className="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-blue-50 hover:text-[#087dcc] dark:text-slate-300 dark:hover:bg-slate-800"
                                    >
                                        Log in
                                    </Link>
                                    <Link
                                        href={route('register')}
                                        className="rounded-lg bg-[#087dcc] px-4 py-2 text-sm font-semibold text-white hover:bg-[#086bb0]"
                                    >
                                        Register
                                    </Link>
                                </>
                            )}
                        </nav>
                    </div>
                </header>

                <main className="flex flex-1 flex-col">
                    <div className="landing-map flex min-h-130 flex-1 flex-col overflow-hidden bg-slate-50 dark:bg-slate-900">
                        <Suspense fallback={<MapStatus message="Loading the map…" />}>
                            <IntelligenceMap {...map} routePrefix={auth.user ? 'user.intelligence-map' : 'public-map'} syncUrl={false} />
                        </Suspense>
                    </div>
                </main>
            </div>
        </>
    );
}
