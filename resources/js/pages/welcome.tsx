import { BrandBackdrop } from '@/components/auth-brand-panel';
import { DostLogo } from '@/components/dost-logo';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, Bot, MapPin, Scale, ShieldCheck, Sparkles, Target, type LucideIcon } from 'lucide-react';

interface Stats {
    provinces: number | null;
    municipalities: number | null;
    barangays: number | null;
    facilities: number | null;
    laboratories: number | null;
    catalog: number | null;
}

const STEPS: { icon: LucideIcon; title: string; text: string }[] = [
    { icon: MapPin, title: 'Pick a place', text: 'A province, city, municipality or barangay.' },
    { icon: Scale, title: 'Measure local conditions', text: 'Services per 100,000 residents against national rates.' },
    { icon: Target, title: 'Find gaps and priorities', text: 'Severity scores and the barangays to start with.' },
    { icon: Sparkles, title: 'Match DOST technologies', text: 'Catalog fit, feasibility and a draft action plan.' },
];

const format = (value: number | null) => (value === null ? '—' : value.toLocaleString('en-PH'));

/** Single-section landing page: the pitch on the left, how it works on a brand-blue panel on the right. */
export default function Welcome({ stats }: { stats: Stats }) {
    const { auth } = usePage<SharedData>().props;
    const role = (auth.user as { role?: string } | null)?.role;
    const workspace = role === 'admin' ? route('admin.dashboard.index') : route('user.dashboard.index');
    const coverage = [
        { label: 'Cities & municipalities', value: stats.municipalities },
        { label: 'Barangays', value: stats.barangays },
        { label: 'Facilities mapped', value: stats.facilities },
        { label: 'DOST technologies & programs', value: stats.catalog },
    ];

    /** Sign-in links, styled for the white (`light`) or blue (`dark`) side. */
    const accountLinks = (tone: 'light' | 'dark') =>
        auth.user ? (
            <Link
                href={workspace}
                className={cn(
                    'rounded-lg px-4 py-2 text-sm font-semibold',
                    tone === 'light' ? 'bg-[#087dcc] text-white hover:bg-[#086bb0]' : 'bg-white text-[#087dcc] hover:bg-sky-50',
                )}
            >
                Workspace
            </Link>
        ) : (
            <>
                <Link
                    href={route('login')}
                    className={cn(
                        'rounded-lg px-4 py-2 text-sm font-medium',
                        tone === 'light' ? 'text-slate-600 hover:bg-blue-50 hover:text-[#087dcc]' : 'text-white hover:bg-white/10',
                    )}
                >
                    Log in
                </Link>
                <Link
                    href={route('register')}
                    className={cn(
                        'rounded-lg px-4 py-2 text-sm font-semibold',
                        tone === 'light' ? 'bg-[#087dcc] text-white hover:bg-[#086bb0]' : 'bg-white text-[#087dcc] hover:bg-sky-50',
                    )}
                >
                    Register
                </Link>
            </>
        );

    return (
        <>
            <Head title="Welcome">
                <meta
                    name="description"
                    content="KonektaDOST helps DOST and local governments decide which science and technology programs fit each Philippine community, and why."
                />
            </Head>

            <main className="grid min-h-dvh w-full bg-white text-slate-800 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)] dark:bg-slate-950 dark:text-slate-200">
                <section aria-labelledby="welcome-title" className="flex min-w-0 flex-col px-6 py-6 sm:px-10 lg:px-14 xl:px-20 2xl:px-28">
                    <header className="flex items-center justify-between gap-4">
                        <Link href={route('home')} className="flex min-w-0 items-center gap-3">
                            <DostLogo className="h-8 sm:h-10" />
                            <span className="hidden h-9 w-px bg-slate-200 sm:block dark:bg-slate-700" aria-hidden="true" />
                            <span className="hidden text-sm font-semibold tracking-tight text-[#087dcc] sm:block">KonektaDOST</span>
                        </Link>
                        <nav aria-label="Account" className="flex shrink-0 items-center gap-2 lg:hidden">
                            {accountLinks('light')}
                        </nav>
                    </header>

                    <div className="my-auto py-12 lg:py-16">
                        <p className="inline-flex items-center gap-2 rounded-full border border-blue-100 bg-blue-50 px-3 py-1 text-xs font-medium text-[#087dcc] dark:border-slate-700 dark:bg-slate-900">
                            <Sparkles className="size-3.5" aria-hidden="true" /> AI-assisted decision support for DOST
                        </p>
                        <h1
                            id="welcome-title"
                            className="mt-6 max-w-3xl text-4xl leading-[1.1] font-semibold tracking-tight text-slate-900 sm:text-5xl xl:text-6xl dark:text-white"
                        >
                            The right DOST technology for every Philippine community
                        </h1>
                        <p className="mt-6 max-w-2xl text-base leading-relaxed text-slate-600 sm:text-lg dark:text-slate-300">
                            KonektaDOST measures a location’s local conditions, finds its largest gaps, ranks the barangays to start with and matches
                            DOST technologies and STI programs, then drafts an action plan for the LGU and DOST to validate.
                        </p>
                        <div className="mt-9 flex flex-wrap items-center gap-3">
                            <Link
                                href={auth.user ? workspace : route('register')}
                                className="inline-flex items-center gap-2 rounded-xl bg-[#087dcc] px-6 py-3 text-sm font-semibold text-white shadow-sm hover:bg-[#086bb0] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#087dcc]"
                            >
                                {auth.user ? 'Open your workspace' : 'Get started'} <ArrowRight className="size-4" aria-hidden="true" />
                            </Link>
                            {!auth.user && (
                                <Link
                                    href={route('login')}
                                    className="inline-flex items-center rounded-xl border border-slate-200 px-6 py-3 text-sm font-semibold text-slate-700 hover:border-blue-200 hover:text-[#087dcc] dark:border-slate-700 dark:text-slate-200"
                                >
                                    Log in
                                </Link>
                            )}
                        </div>

                        <dl className="mt-14 grid max-w-3xl grid-cols-2 gap-x-8 gap-y-6 border-t border-slate-100 pt-8 sm:grid-cols-4 dark:border-slate-800">
                            {coverage.map((item) => (
                                <div key={item.label} className="flex flex-col">
                                    <dt className="text-xs leading-snug text-slate-500">{item.label}</dt>
                                    <dd className="order-first text-2xl font-semibold tracking-tight text-[#087dcc] tabular-nums xl:text-3xl">
                                        {format(item.value)}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </div>

                    <footer className="flex flex-col gap-1 text-xs text-slate-500 sm:flex-row sm:flex-wrap sm:gap-x-6">
                        <span>© {new Date().getFullYear()} Department of Science and Technology · KonektaDOST</span>
                        <span>Recommendations are advisory drafts until validated by the LGU and DOST.</span>
                    </footer>
                </section>

                <aside
                    aria-label="How KonektaDOST works"
                    className="relative hidden min-w-0 flex-col overflow-hidden px-12 py-6 text-white lg:flex xl:px-16 2xl:px-24"
                >
                    <BrandBackdrop />

                    <nav aria-label="Account" className="relative z-10 flex justify-end gap-2">
                        {accountLinks('dark')}
                    </nav>

                    <div className="relative z-10 my-auto py-10">
                        <p className="text-xs font-semibold tracking-[0.16em] text-sky-100/80 uppercase">The question it answers</p>
                        <blockquote className="mt-3 max-w-xl text-2xl leading-snug font-semibold">
                            “For this location, which DOST technologies and STI programs should be integrated, why, and how?”
                        </blockquote>
                        <ol className="mt-7 grid max-w-xl gap-2.5">
                            {STEPS.map((step, index) => (
                                <li
                                    key={step.title}
                                    className="flex items-center gap-3.5 rounded-2xl border border-white/15 bg-white/8 px-4 py-3 backdrop-blur-xs"
                                >
                                    <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-white/15 text-sky-100">
                                        <step.icon className="size-4.5" aria-hidden="true" />
                                    </span>
                                    <span className="min-w-0">
                                        <span className="block text-sm font-semibold">
                                            <span className="text-sky-200/80 tabular-nums">{index + 1}.</span> {step.title}
                                        </span>
                                        <span className="mt-0.5 block text-xs text-sky-50/75">{step.text}</span>
                                    </span>
                                </li>
                            ))}
                        </ol>

                        <figure className="mt-5 max-w-xl rounded-2xl bg-white p-4 text-slate-800 shadow-xl shadow-black/15">
                            <div className="flex items-center gap-2.5">
                                <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-[#087dcc] text-white">
                                    <Bot className="size-4.5" aria-hidden="true" />
                                </span>
                                <span className="min-w-0">
                                    <span className="block text-sm font-semibold text-slate-900">GeoAgent</span>
                                    <span className="block text-[11px] text-slate-500">AI-assisted planning, grounded in the analysis tools</span>
                                </span>
                                <span className="ml-auto inline-flex items-center gap-1 rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-semibold text-[#087dcc]">
                                    <Sparkles className="size-3" aria-hidden="true" /> AI
                                </span>
                            </div>
                            <p className="mt-3 text-[10px] font-semibold tracking-wide text-slate-400 uppercase">Example question</p>
                            <blockquote className="mt-1 rounded-xl rounded-tl-sm bg-slate-100 px-3 py-2 text-sm text-slate-700">
                                “Which DOST technologies fit Quezon City, and which barangays should we start with?”
                            </blockquote>
                            <figcaption className="mt-2.5 text-xs leading-relaxed text-slate-500">
                                It runs the same analysis tools, explains the evidence behind each number and drafts an action plan you can refine and
                                export.
                            </figcaption>
                        </figure>
                    </div>

                    <p className="relative z-10 flex items-center gap-2 text-xs text-sky-100/75">
                        <ShieldCheck className="size-4 shrink-0" aria-hidden="true" /> Every figure comes from recorded data. The AI only explains and
                        drafts, and missing data is never treated as zero.
                    </p>
                </aside>
            </main>
        </>
    );
}
