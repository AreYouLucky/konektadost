import { Head, useForm } from '@inertiajs/react';
import { ArrowRight, Cpu, Eye, EyeOff, FlaskConical, LoaderCircle, Lock, Mail, MapPin, Radio } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import { AuthBrandPanel, AuthMobileBrand } from '@/components/auth-brand-panel';
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
        },
        {
            icon: FlaskConical,
            title: 'DOST OneLab & Testing Directory',
            description: 'Locate accredited testing and calibration laboratories alongside their registered chemical and microbiological services.',
        },
        {
            icon: Cpu,
            title: 'Evidence-Based DOST Tech Matching',
            description: 'Match local service shortfalls to catalogued DOST technologies, lead agencies, and measurable community outcomes.',
        },
        {
            icon: Radio,
            title: 'Connectivity & DRR Readiness',
            description: 'Evaluate 4G/5G cell site density and emergency safety facilities per 100,000 residents across LGUs.',
        },
    ];

    return (
        <>
            <Head title="Sign in — KonektaDOST" />

            <div className="bg-background grid min-h-dvh lg:grid-cols-[1.1fr_0.9fr]">
                <AuthBrandPanel>
                    <div className="max-w-xl space-y-3">
                        <h1 className="text-3xl leading-tight font-bold tracking-tight text-white xl:text-4xl">
                            Connect every community to the right <span className="text-sky-200">DOST intervention.</span>
                        </h1>
                        <p className="text-sm leading-relaxed text-sky-50/85 xl:text-base">
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
                                    className="flex flex-col gap-2 rounded-xl border border-white/15 bg-white/7 p-4 backdrop-blur-xs transition-colors hover:bg-white/11"
                                >
                                    <div className="flex size-8 items-center justify-center rounded-lg border border-white/20 bg-white/15 text-sky-100">
                                        <Icon className="size-4" />
                                    </div>
                                    <h3 className="text-sm font-semibold text-white">{item.title}</h3>
                                    <p className="text-xs leading-relaxed text-sky-50/75">{item.description}</p>
                                </div>
                            );
                        })}
                    </div>
                </AuthBrandPanel>

                {/* Right Login Form Panel */}
                <div className="flex flex-col justify-center px-6 py-10 sm:px-12 lg:px-16">
                    <div className="mx-auto flex w-full max-w-md flex-col gap-7">
                        <AuthMobileBrand />

                        {/* Heading */}
                        <div className="flex flex-col gap-1.5">
                            <h2 className="text-2xl font-bold tracking-tight">Welcome back</h2>
                            <p className="text-muted-foreground text-sm">
                                Sign in with your account credentials to access the service area map and location assessments.
                            </p>
                        </div>

                        {status && (
                            <div className="rounded-lg border border-[#087dcc]/25 bg-blue-50 px-4 py-3 text-sm font-medium text-[#086bb0] dark:bg-[#087dcc]/15 dark:text-sky-200">
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
                                        className="h-10 pl-9 focus-visible:border-[#087dcc] focus-visible:ring-[#087dcc]/25"
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
                                        className="h-10 pr-10 pl-9 focus-visible:border-[#087dcc] focus-visible:ring-[#087dcc]/25"
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
                                        className="data-[state=checked]:border-[#087dcc] data-[state=checked]:bg-[#087dcc] data-[state=checked]:text-white"
                                    />
                                    <Label htmlFor="remember" className="cursor-pointer text-sm font-normal">
                                        Keep me signed in
                                    </Label>
                                </div>
                            </div>

                            <Button
                                type="submit"
                                className="h-10 w-full gap-2 bg-[#087dcc] text-white hover:bg-[#086bb0] focus-visible:ring-[#087dcc]/40"
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
                                <TextLink href={route('register')} tabIndex={6} className="font-medium text-[#087dcc] dark:text-sky-300">
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
