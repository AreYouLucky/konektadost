import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowRight, Cpu, Eye, EyeOff, FlaskConical, LoaderCircle, Lock, Mail, MapPin, Radio, ShieldCheck } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface LoginForm {
    email: string;
    password: string;
    remember: boolean;
}

interface LoginProps {
    status?: string;
    canResetPassword: boolean;
}

export default function Login({ status, canResetPassword }: LoginProps) {
    const [showPassword, setShowPassword] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm<LoginForm>({
        email: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    const features = [
        {
            icon: MapPin,
            title: 'Barangay-Level Spatial Gap Analysis',
            description: 'Pinpoint high-population, underserved barangays using PSA 2024 census figures and infrastructure layers.',
            color: 'text-teal-300 bg-teal-500/15 border-teal-400/20',
        },
        {
            icon: FlaskConical,
            title: 'DOST OneLab & Testing Directory',
            description: 'Locate accredited testing and calibration laboratories alongside their registered chemical and microbiological services.',
            color: 'text-emerald-300 bg-emerald-500/15 border-emerald-400/20',
        },
        {
            icon: Cpu,
            title: 'Evidence-Based DOST Tech Matching',
            description: 'Match local service shortfalls to catalogued DOST technologies, lead agencies, and measurable community outcomes.',
            color: 'text-violet-300 bg-violet-500/15 border-violet-400/20',
        },
        {
            icon: Radio,
            title: 'Connectivity & DRR Readiness',
            description: 'Evaluate 4G/5G cell site density and emergency safety facilities per 100,000 residents across LGUs.',
            color: 'text-amber-300 bg-amber-500/15 border-amber-400/20',
        },
    ];

    return (
        <>
            <Head title="Sign in — KonektaDOST" />

            <div className="bg-background grid min-h-dvh lg:grid-cols-[1.1fr_0.9fr]">
                {/* Left Hero Panel */}
                <div className="relative hidden flex-col justify-between overflow-hidden bg-slate-950 p-10 text-white lg:flex xl:p-14">
                    {/* Ambient radial gradients & grid */}
                    <div
                        className="pointer-events-none absolute inset-0 opacity-35"
                        style={{
                            backgroundImage:
                                'radial-gradient(circle at 18% 20%, rgba(13, 148, 136, 0.45), transparent 45%), radial-gradient(circle at 85% 80%, rgba(124, 58, 237, 0.35), transparent 50%)',
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

                    {/* Brand Header */}
                    <div className="relative z-10 flex items-center justify-between">
                        <Link href={route('home')} className="flex items-center gap-3">
                            <div className="flex size-10 items-center justify-center rounded-xl border border-teal-400/30 bg-teal-500/20 text-teal-300 shadow-inner">
                                <MapPin className="size-5" />
                            </div>
                            <div className="flex flex-col">
                                <span className="text-lg font-bold tracking-tight text-white">KonektaDOST</span>
                                <span className="text-[11px] font-medium tracking-wider text-teal-300/90 uppercase">
                                    Spatial STI Decision Support
                                </span>
                            </div>
                        </Link>

                        <span className="inline-flex items-center gap-1.5 rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs text-slate-300 backdrop-blur">
                            <ShieldCheck className="size-3.5 text-teal-400" />
                            DOST Service Area Intelligence
                        </span>
                    </div>

                    {/* Center Copy + Feature Cards */}
                    <div className="relative z-10 my-auto flex flex-col gap-8 py-8">
                        <div className="max-w-xl space-y-3">
                            <h1 className="text-3xl leading-tight font-bold tracking-tight text-white xl:text-4xl">
                                Connect every community to the right{' '}
                                <span className="bg-gradient-to-r from-teal-300 via-emerald-300 to-cyan-300 bg-clip-text text-transparent">
                                    DOST intervention.
                                </span>
                            </h1>
                            <p className="text-sm leading-relaxed text-slate-300/90 xl:text-base">
                                Analyze city and barangay baselines across population, healthcare, education, digital connectivity, and laboratory
                                capacity to guide science and technology deployment.
                            </p>
                        </div>

                        <div className="grid gap-3.5 sm:grid-cols-2">
                            {features.map((item) => {
                                const Icon = item.icon;
                                return (
                                    <div
                                        key={item.title}
                                        className="flex flex-col gap-2 rounded-xl border border-white/10 bg-white/[0.04] p-4 backdrop-blur-xs transition-colors hover:bg-white/[0.07]"
                                    >
                                        <div className={` flex size-8 items-center justify-center rounded-lg border ${item.color}`}>
                                            <Icon className="size-4" />
                                        </div>
                                        <h3 className="text-sm font-semibold text-white">{item.title}</h3>
                                        <p className="text-xs leading-relaxed text-slate-300/80">{item.description}</p>
                                    </div>
                                );
                            })}
                        </div>
                    </div>

                    {/* Footer */}
                    <div className="relative z-10 flex items-center justify-between border-t border-white/10 pt-5 text-xs text-slate-400">
                        <span>PSA 2024 Census · OpenStreetMap · DOST OneLab · NCR Tech Catalogue</span>
                        <span>v1.0</span>
                    </div>
                </div>

                {/* Right Login Form Panel */}
                <div className="flex flex-col justify-center px-6 py-10 sm:px-12 lg:px-16">
                    <div className="mx-auto flex w-full max-w-md flex-col gap-7">
                        {/* Mobile Brand Header */}
                        <div className="flex items-center gap-3 lg:hidden">
                            <div className="flex size-10 items-center justify-center rounded-xl bg-teal-600 text-white shadow-sm">
                                <MapPin className="size-5" />
                            </div>
                            <div className="flex flex-col">
                                <span className="text-lg font-bold tracking-tight">KonektaDOST</span>
                                <span className="text-muted-foreground text-xs">Spatial STI Decision Support</span>
                            </div>
                        </div>

                        {/* Heading */}
                        <div className="flex flex-col gap-1.5">
                            <h2 className="text-2xl font-bold tracking-tight">Welcome back</h2>
                            <p className="text-muted-foreground text-sm">
                                Sign in with your account credentials to access the service area map and location assessments.
                            </p>
                        </div>

                        {status && (
                            <div className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm font-medium text-emerald-700 dark:text-emerald-300">
                                {status}
                            </div>
                        )}

                        {/* Form Card */}
                        <form className="flex flex-col gap-5" onSubmit={submit}>
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>
                                <div className="relative">
                                    <Mail className="text-muted-foreground pointer-events-none absolute top-2.5 left-3 size-4" />
                                    <Input
                                        id="email"
                                        type="email"
                                        required
                                        autoFocus
                                        tabIndex={1}
                                        autoComplete="email"
                                        value={data.email}
                                        onChange={(e) => setData('email', e.target.value)}
                                        placeholder="name@mail.dev"
                                        className="h-10 pl-9"
                                    />
                                </div>
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center justify-between">
                                    <Label htmlFor="password">Password</Label>
                                    {canResetPassword && (
                                        <TextLink href={route('password.request')} className="text-xs font-medium" tabIndex={5}>
                                            Forgot password?
                                        </TextLink>
                                    )}
                                </div>
                                <div className="relative">
                                    <Lock className="text-muted-foreground pointer-events-none absolute top-2.5 left-3 size-4" />
                                    <Input
                                        id="password"
                                        type={showPassword ? 'text' : 'password'}
                                        required
                                        tabIndex={2}
                                        autoComplete="current-password"
                                        value={data.password}
                                        onChange={(e) => setData('password', e.target.value)}
                                        placeholder="Enter your password"
                                        className="h-10 pr-10 pl-9"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowPassword((prev) => !prev)}
                                        className="text-muted-foreground hover:text-foreground absolute top-2.5 right-3 transition-colors"
                                        aria-label={showPassword ? 'Hide password' : 'Show password'}
                                    >
                                        {showPassword ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                                    </button>
                                </div>
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center justify-between">
                                <div className="flex items-center space-x-2.5">
                                    <Checkbox
                                        id="remember"
                                        name="remember"
                                        checked={data.remember}
                                        onClick={() => setData('remember', !data.remember)}
                                        tabIndex={3}
                                    />
                                    <Label htmlFor="remember" className="cursor-pointer text-sm font-normal">
                                        Keep me signed in
                                    </Label>
                                </div>
                            </div>

                            <Button
                                type="submit"
                                className="h-10 w-full gap-2 bg-teal-600 text-white hover:bg-teal-700"
                                tabIndex={4}
                                disabled={processing}
                            >
                                {processing ? (
                                    <LoaderCircle className="size-4 animate-spin" />
                                ) : (
                                    <>
                                        <span>Sign in to KonektaDOST</span>
                                        <ArrowRight className="size-4" />
                                    </>
                                )}
                            </Button>

                            <div className="text-muted-foreground border-t pt-4 text-center text-sm">
                                Don&apos;t have an account yet?{' '}
                                <TextLink href={route('register')} tabIndex={6} className="font-medium text-teal-600 dark:text-teal-400">
                                    Create an account
                                </TextLink>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </>
    );
}
