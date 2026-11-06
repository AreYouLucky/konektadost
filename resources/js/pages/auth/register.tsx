import { Head, useForm } from '@inertiajs/react';
import { ArrowRight, Bot, ClipboardList, Eye, EyeOff, FileDown, Globe2, LoaderCircle, Lock, Mail, UserRound } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import { AuthBrandPanel, AuthMobileBrand } from '@/components/auth-brand-panel';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface RegisterForm {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
}

const BENEFITS = [
    {
        icon: Globe2,
        title: 'Explore any location',
        text: 'From the whole country down to a single barangay, with its facilities, labs and connectivity.',
    },
    {
        icon: ClipboardList,
        title: 'Run location assessments',
        text: 'See what an area needs, how severe each gap is and which DOST technology fits.',
    },
    { icon: Bot, title: 'Plan with GeoAgent', text: 'An AI assistant that runs the same analysis tools, explains the evidence and drafts the plan.' },
    { icon: FileDown, title: 'Keep and export plans', text: 'Every plan version is saved to your account and downloads as a PDF for LGU review.' },
];

const field = 'h-10 pl-9 focus-visible:border-[#087dcc] focus-visible:ring-[#087dcc]/25';

export default function Register() {
    const [showPassword, setShowPassword] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm<RegisterForm>({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('register'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <>
            <Head title="Create an account — KonektaDOST" />

            <div className="bg-background grid min-h-dvh lg:grid-cols-[1.1fr_0.9fr]">
                <AuthBrandPanel>
                    <div className="max-w-xl space-y-3">
                        <h1 className="text-3xl leading-tight font-bold tracking-tight text-white xl:text-4xl">
                            Create your workspace for <span className="text-sky-200">evidence-based STI planning.</span>
                        </h1>
                        <p className="text-sm leading-relaxed text-sky-50/85 xl:text-base">
                            One account gives you the map, the assessments and the AI planning assistant for any Philippine location.
                        </p>
                    </div>
                    <ul className="grid gap-3.5 sm:grid-cols-2">
                        {BENEFITS.map((item) => (
                            <li key={item.title} className="flex flex-col gap-2 rounded-xl border border-white/15 bg-white/7 p-4 backdrop-blur-xs">
                                <span className="flex size-8 items-center justify-center rounded-lg border border-white/20 bg-white/15 text-sky-100">
                                    <item.icon className="size-4" aria-hidden="true" />
                                </span>
                                <h3 className="text-sm font-semibold text-white">{item.title}</h3>
                                <p className="text-xs leading-relaxed text-sky-50/75">{item.text}</p>
                            </li>
                        ))}
                    </ul>
                </AuthBrandPanel>

                <div className="flex flex-col justify-center px-6 py-10 sm:px-12 lg:px-16">
                    <div className="mx-auto flex w-full max-w-md flex-col gap-7">
                        <AuthMobileBrand />

                        <div className="flex flex-col gap-1.5">
                            <h2 className="text-2xl font-bold tracking-tight">Create an account</h2>
                            <p className="text-muted-foreground text-sm">It takes a minute. You can start assessing a location right after.</p>
                        </div>

                        <form className="flex flex-col gap-5" onSubmit={submit}>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Full name</Label>
                                <div className="relative">
                                    <UserRound
                                        className="text-muted-foreground pointer-events-none absolute top-2.5 left-3 size-4"
                                        aria-hidden="true"
                                    />
                                    <Input
                                        id="name"
                                        type="text"
                                        required
                                        autoFocus
                                        tabIndex={1}
                                        autoComplete="name"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        disabled={processing}
                                        placeholder="Juan dela Cruz"
                                        className={field}
                                    />
                                </div>
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>
                                <div className="relative">
                                    <Mail className="text-muted-foreground pointer-events-none absolute top-2.5 left-3 size-4" aria-hidden="true" />
                                    <Input
                                        id="email"
                                        type="email"
                                        required
                                        tabIndex={2}
                                        autoComplete="email"
                                        value={data.email}
                                        onChange={(e) => setData('email', e.target.value)}
                                        disabled={processing}
                                        placeholder="name@agency.gov.ph"
                                        className={field}
                                    />
                                </div>
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="password">Password</Label>
                                    <div className="relative">
                                        <Lock
                                            className="text-muted-foreground pointer-events-none absolute top-2.5 left-3 size-4"
                                            aria-hidden="true"
                                        />
                                        <Input
                                            id="password"
                                            type={showPassword ? 'text' : 'password'}
                                            required
                                            tabIndex={3}
                                            autoComplete="new-password"
                                            value={data.password}
                                            onChange={(e) => setData('password', e.target.value)}
                                            disabled={processing}
                                            placeholder="At least 8 characters"
                                            aria-describedby="password-hint"
                                            className={`${field} pr-10`}
                                        />
                                        <button
                                            type="button"
                                            onClick={() => setShowPassword((value) => !value)}
                                            className="text-muted-foreground hover:text-foreground absolute top-2.5 right-3 transition-colors"
                                            aria-label={showPassword ? 'Hide passwords' : 'Show passwords'}
                                        >
                                            {showPassword ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                                        </button>
                                    </div>
                                    <InputError message={errors.password} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password_confirmation">Confirm password</Label>
                                    <div className="relative">
                                        <Lock
                                            className="text-muted-foreground pointer-events-none absolute top-2.5 left-3 size-4"
                                            aria-hidden="true"
                                        />
                                        <Input
                                            id="password_confirmation"
                                            type={showPassword ? 'text' : 'password'}
                                            required
                                            tabIndex={4}
                                            autoComplete="new-password"
                                            value={data.password_confirmation}
                                            onChange={(e) => setData('password_confirmation', e.target.value)}
                                            disabled={processing}
                                            placeholder="Repeat password"
                                            className={field}
                                        />
                                    </div>
                                    <InputError message={errors.password_confirmation} />
                                </div>
                            </div>
                            <p id="password-hint" className="-mt-2 text-xs text-slate-500">
                                Use at least 8 characters. A short phrase is easier to remember and harder to guess.
                            </p>

                            <Button
                                type="submit"
                                className="h-10 w-full gap-2 bg-[#087dcc] text-white hover:bg-[#086bb0] focus-visible:ring-[#087dcc]/40"
                                tabIndex={5}
                                disabled={processing}
                            >
                                {processing ? (
                                    <LoaderCircle className="size-4 animate-spin" />
                                ) : (
                                    <>
                                        <span>Create account</span>
                                        <ArrowRight className="size-4" />
                                    </>
                                )}
                            </Button>

                            <div className="text-muted-foreground border-t pt-4 text-center text-sm">
                                Already have an account?{' '}
                                <TextLink href={route('login')} tabIndex={6} className="font-medium text-[#087dcc] dark:text-sky-300">
                                    Log in
                                </TextLink>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </>
    );
}
