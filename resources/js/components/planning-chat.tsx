import PlanningActionPlan from '@/components/planning-action-plan';
import PlanningVisuals, { type PlanningVisual } from '@/components/planning-visuals';
import { SidebarMenuButton, useSidebar } from '@/components/ui/sidebar';
import { fetchWithCsrf, isSessionEnded } from '@/lib/csrf';
import { isPlanningActionPlan, stripToolCitations } from '@/lib/planning-plan';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import * as Dialog from '@radix-ui/react-dialog';
import {
    ArrowLeft,
    Check,
    Copy,
    FileText,
    History,
    LoaderCircle,
    MapPin,
    MessageCircle,
    Minus,
    RotateCcw,
    Save,
    Search,
    Send,
    Sparkles,
    Square,
    X,
} from 'lucide-react';
import { createContext, lazy, Suspense, useContext, useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';

/** Saved assessment-page plans open in their own viewer, loaded only when one is opened. */
const SavedActionPlanDialog = lazy(() => import('@/pages/location-assessment/saved-action-plan'));

interface Municipality {
    id: number;
    psg_code: string;
    name: string;
    province_name: string | null;
}

interface Message {
    id?: number;
    role: 'user' | 'assistant';
    content: string;
    sources?: { name: string; location: string }[];
    generated_at?: string;
    saved_plan_id?: number | null;
    visuals?: PlanningVisual[];
}

interface Conversation {
    id: number;
    title: string;
    archived: boolean;
}

interface ChatPayload {
    conversation: Conversation | null;
    messages: Message[];
    has_more_messages: boolean;
}

interface SavedPlan {
    id: number;
    conversation_id: number;
    title: string;
    version: number;
    content: string;
    created_at: string;
    visuals?: PlanningVisual[];
    evidence_snapshot?: unknown;
}

function PlanningReply({ message, location }: { message: Message; location: Municipality | null }) {
    if (message.saved_plan_id || isPlanningActionPlan(message.content))
        return (
            <PlanningActionPlan
                snapshot={{
                    content: message.content,
                    location: location?.name ?? 'Location not recorded',
                    province: location?.province_name,
                    created_at: message.generated_at,
                    visuals: message.visuals,
                    sources: message.sources,
                }}
            />
        );
    return (
        <>
            <div className="break-words whitespace-pre-wrap">{stripToolCitations(message.content)}</div>
            <PlanningVisuals visuals={message.visuals} />
        </>
    );
}

interface HistoryItem {
    id: number;
    title: string;
    version?: number;
    created_at?: string;
    updated_at?: string;
    archived_at?: string | null;
}

interface Scope {
    city?: string;
    municipality_id?: number;
}

const ChatContext = createContext<{
    open: boolean;
    show: () => void;
    /** Opens a GeoAgent (chat) plan in the assistant window. */
    openPlan: (city: string, planId: number) => void;
    /** Opens a plan saved from the location assessment page. */
    openAssessmentPlan: (planId: number) => void;
} | null>(null);

export function usePlanningChat() {
    return useContext(ChatContext);
}

export function PlanningChatProvider({ children }: { children: ReactNode }) {
    const [open, setOpen] = useState(false);
    const [minimized, setMinimized] = useState(false);
    const [requestedPlan, setRequestedPlan] = useState<{ city: string; id: number; visit: number } | null>(null);
    const [assessmentPlanId, setAssessmentPlanId] = useState<number | null>(null);
    const { url, props } = usePage<SharedData>();
    const params = new URLSearchParams(url.split('?')[1] ?? '');
    const city = params.get('city') ?? (typeof props.selectedCity === 'string' ? props.selectedCity : null);
    const municipalityId = params.get('level') === 'municipality' ? Number(params.get('location')) : null;
    const scope: Scope | null =
        city && /^\d{10}$/.test(city)
            ? { city }
            : municipalityId && Number.isInteger(municipalityId) && municipalityId > 0
              ? { municipality_id: municipalityId }
              : null;
    const effectiveScope = requestedPlan ? { city: requestedPlan.city } : scope;
    const scopeKey = requestedPlan ? `plan:${requestedPlan.id}:${requestedPlan.visit}` : (scope?.city ?? String(scope?.municipality_id ?? 'choose'));
    useEffect(() => setRequestedPlan(null), [url, props.auth.user.id]);
    useEffect(() => setAssessmentPlanId(null), [props.auth.user.id]);
    const show = () => {
        setOpen(true);
        setMinimized(false);
    };

    return (
        <ChatContext.Provider
            value={{
                open,
                show,
                openPlan: (city, id) => {
                    setRequestedPlan({ city, id, visit: Date.now() });
                    show();
                },
                openAssessmentPlan: (id) => setAssessmentPlanId(id),
            }}
        >
            {children}
            {assessmentPlanId !== null && (
                <Suspense fallback={null}>
                    <SavedActionPlanDialog key={assessmentPlanId} planId={assessmentPlanId} onClose={() => setAssessmentPlanId(null)} />
                </Suspense>
            )}
            <PlanningChatWindow
                key={`${props.auth.user.id}:${scopeKey}`}
                initialScope={effectiveScope}
                initialPlanId={requestedPlan?.id}
                open={open}
                onClose={() => {
                    setOpen(false);
                    setMinimized(false);
                }}
                onMinimize={() => {
                    setOpen(false);
                    setMinimized(true);
                }}
            />
            {!open && (
                <button
                    type="button"
                    onClick={show}
                    className="fixed right-5 bottom-5 z-[60] flex items-center gap-2 rounded-full bg-[#087dcc] px-5 py-3 text-sm font-semibold text-white shadow-xl hover:bg-[#0666a8] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-blue-500"
                    aria-label={minimized ? 'Reopen GeoAgent' : 'Talk to GeoAgent'}
                    aria-haspopup="dialog"
                    data-planning-chat-launcher="floating"
                >
                    <MessageCircle className="size-5" aria-hidden="true" /> Talk to GeoAgent
                </button>
            )}
        </ChatContext.Provider>
    );
}

export function PlanningChatLauncher() {
    const chat = useContext(ChatContext);
    const { setOpenMobile } = useSidebar();
    if (!chat) return null;

    return (
        <SidebarMenuButton
            tooltip="Talk to GeoAgent"
            onClick={() => {
                setOpenMobile(false);
                chat.show();
            }}
            aria-label="Talk to GeoAgent, the AI planning assistant"
            aria-haspopup="dialog"
            aria-expanded={chat.open}
            className="h-11 cursor-pointer rounded-xl px-3 text-[13px] font-semibold text-[#087dcc] hover:bg-blue-50 hover:text-[#0666a8] [&>svg]:size-[18px]"
        >
            <MessageCircle strokeWidth={1.8} aria-hidden="true" />
            <span>Talk to GeoAgent</span>
            <Sparkles className="ml-auto size-3.5! group-data-[collapsible=icon]:hidden" aria-hidden="true" />
        </SidebarMenuButton>
    );
}

function queryString(scope: Scope) {
    return new URLSearchParams(Object.entries(scope).map(([key, value]) => [key, String(value)])).toString();
}

function requestUuid() {
    // randomUUID is unavailable on some HTTP LAN development URLs; getRandomValues still works.
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

async function requestJson(url: string, options: RequestInit = {}) {
    // The page's meta token goes stale after an Inertia login/logout; this sends the current token
    // and retries a 419 once, so only a truly ended session reaches the message below.
    const response = await fetchWithCsrf(url, {
        ...options,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(options.headers as Record<string, string> | undefined),
        },
    });
    if (isSessionEnded(response)) throw new Error('Your session has expired. Refresh and sign in again.');
    if (response.status === 429) throw new Error('Too many requests. Please wait a minute and try again.');
    if (response.status === 404) throw new Error('This municipality could not be found. Choose a location below.');
    // A server error page (e.g. a PHP time limit during a long reply) is HTML, not JSON.
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload) throw new Error(payload?.message || 'The assistant is unavailable. Please retry.');
    return payload;
}

function PlanningChatWindow({
    initialScope,
    initialPlanId,
    open,
    onClose,
    onMinimize,
}: {
    initialScope: Scope | null;
    initialPlanId?: number;
    open: boolean;
    onClose: () => void;
    onMinimize: () => void;
}) {
    const [scope, setScope] = useState(initialScope);
    const [location, setLocation] = useState<Municipality | null>(null);
    const [messages, setMessages] = useState<Message[]>([]);
    const [conversation, setConversation] = useState<Conversation | null>(null);
    const [hasMore, setHasMore] = useState(false);
    const [loadingEarlier, setLoadingEarlier] = useState(false);
    const [view, setView] = useState<'chat' | 'chats' | 'plans'>(initialPlanId ? 'plans' : 'chat');
    const [savingMessage, setSavingMessage] = useState<number | null>(null);
    const [savedNotice, setSavedNotice] = useState(false);
    const [draft, setDraft] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [busy, setBusy] = useState(false);
    const [configured, setConfigured] = useState(true);
    const [choosing, setChoosing] = useState(!initialScope);
    const [search, setSearch] = useState('');
    const [results, setResults] = useState<Municipality[]>([]);
    const [searching, setSearching] = useState(false);
    const [searchError, setSearchError] = useState<string | null>(null);
    const [copied, setCopied] = useState<number | null>(null);
    const [resetting, setResetting] = useState(false);
    const [confirmReset, setConfirmReset] = useState(false);
    const [historyVersion, setHistoryVersion] = useState(0);
    const loaded = useRef<string | null>(null);
    const composer = useRef<HTMLTextAreaElement>(null);
    const searchInput = useRef<HTMLInputElement>(null);
    const scrollArea = useRef<HTMLDivElement>(null);
    const prependScroll = useRef<{ height: number; top: number } | null>(null);
    const pending = useRef<AbortController | null>(null);
    const cancelled = useRef(false);
    const lastAttempt = useRef<{ message: string; id: string } | null>(null);
    const currentKey = scope ? queryString(scope) : null;

    useEffect(() => () => pending.current?.abort(), []);

    useEffect(() => {
        if (!open || !currentKey || loaded.current === currentKey) return;
        const controller = new AbortController();
        setLoading(true);
        setError(null);
        void requestJson(`${route('planning-chat.show')}?${currentKey}`, { signal: controller.signal })
            .then((payload) => {
                setLocation(payload.location);
                setMessages(payload.messages);
                setConversation(payload.conversation);
                setHasMore(payload.has_more_messages);
                setConfigured(payload.configured);
                if (payload.messages.some((item: Message) => item.saved_plan_id)) window.dispatchEvent(new Event('planning-plans-updated'));
                loaded.current = currentKey;
            })
            .catch((cause) => {
                if (!controller.signal.aborted) {
                    setError(cause.message);
                    setChoosing(true);
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });
        return () => controller.abort();
    }, [open, currentKey, historyVersion]);

    useEffect(() => {
        if (!open || !choosing || search.trim().length < 2) {
            setResults([]);
            setSearching(false);
            return;
        }
        const controller = new AbortController();
        setSearching(true);
        setSearchError(null);
        const timer = window.setTimeout(() => {
            void requestJson(`${route('planning-chat.locations')}?${new URLSearchParams({ q: search.trim() })}`, { signal: controller.signal })
                .then((payload) => setResults(payload.locations))
                .catch((cause) => {
                    if (!controller.signal.aborted) setSearchError(cause.message);
                })
                .finally(() => {
                    if (!controller.signal.aborted) setSearching(false);
                });
        }, 300);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [open, choosing, search]);

    useEffect(() => {
        if (!open || !scrollArea.current || view !== 'chat') return;
        if (prependScroll.current) {
            scrollArea.current.scrollTop = scrollArea.current.scrollHeight - prependScroll.current.height + prependScroll.current.top;
            prependScroll.current = null;
        } else {
            scrollArea.current.scrollTop = scrollArea.current.scrollHeight;
        }
    }, [open, messages, busy, view]);

    useEffect(() => {
        if (view !== 'chat' && scrollArea.current) scrollArea.current.scrollTop = 0;
    }, [view]);

    useEffect(() => {
        if (open && !loading && !busy) (choosing ? searchInput.current : composer.current)?.focus();
    }, [open, loading, busy, choosing]);

    function selectLocation(item: Municipality) {
        if (busy || resetting) return;
        setScope({ city: item.psg_code });
        setLocation(item);
        setMessages([]);
        setConversation(null);
        setHasMore(false);
        setSavedNotice(false);
        setView('chat');
        setDraft('');
        setError(null);
        setSearch('');
        setChoosing(false);
        setConfirmReset(false);
        loaded.current = null;
        lastAttempt.current = null;
        setHistoryVersion((value) => value + 1);
    }

    async function send(text = draft) {
        const message = text.trim();
        if (!message || !scope || !location || busy || loading || resetting || savingMessage !== null || !configured || view !== 'chat') return;
        const controller = new AbortController();
        if (lastAttempt.current?.message !== message) lastAttempt.current = { message, id: requestUuid() };
        const requestId = lastAttempt.current.id;
        pending.current = controller;
        cancelled.current = false;
        setBusy(true);
        setError(null);
        setDraft('');
        const before = messages;
        setMessages([...before, { role: 'user', content: message }]);
        const timer = window.setTimeout(() => controller.abort(), 210000);
        try {
            const payload = await requestJson(route('planning-chat.store'), {
                method: 'POST',
                body: JSON.stringify({ ...scope, message, request_id: requestId, conversation_id: conversation?.id }),
                signal: controller.signal,
            });
            const firstId = payload.messages[0]?.id ?? 0;
            setMessages([...before.filter((item) => item.id && item.id < firstId), ...payload.messages]);
            setConversation(payload.conversation);
            setHasMore(payload.has_more_messages);
            if (payload.saved_plan_id) {
                setSavedNotice(true);
                window.dispatchEvent(new Event('planning-plans-updated'));
            }
            lastAttempt.current = null;
        } catch (cause) {
            setMessages(before);
            setDraft(message);
            setError(
                cancelled.current
                    ? 'Stopped. Your message is back in the box. If GeoAgent had already finished, the reply appears when you reopen the chat; resending the same message reuses it.'
                    : controller.signal.aborted
                      ? 'The reply timed out. Reopen the chat to check whether it completed before retrying.'
                      : cause instanceof Error
                        ? cause.message
                        : 'Could not send this message. Please retry.',
            );
            // Refresh authoritative history after a network failure; the server may have completed the turn.
            loaded.current = null;
        } finally {
            window.clearTimeout(timer);
            setBusy(false);
            pending.current = null;
            composer.current?.focus();
        }
    }

    /** Stop waiting for the current reply (client-side abort). */
    function cancelSend() {
        if (!pending.current) return;
        cancelled.current = true;
        pending.current.abort();
    }

    async function resetConversation() {
        if (!scope || busy || resetting) return;
        setResetting(true);
        setError(null);
        try {
            await requestJson(route('planning-chat.destroy'), { method: 'DELETE', body: JSON.stringify(scope) });
            setMessages([]);
            setConversation(null);
            setHasMore(false);
            setSavedNotice(false);
            setView('chat');
            setDraft('');
            lastAttempt.current = null;
            setConfirmReset(false);
        } catch (cause) {
            setError(cause instanceof Error ? cause.message : 'Could not clear the conversation.');
        } finally {
            setResetting(false);
        }
    }

    async function copyReply(message: string, index: number) {
        try {
            await navigator.clipboard.writeText(message);
            setCopied(index);
        } catch {
            setError('Clipboard access is unavailable. You can select and copy the reply.');
        }
    }

    async function loadEarlier() {
        if (!scope || !conversation || loadingEarlier || !messages[0]?.id) return;
        setLoadingEarlier(true);
        try {
            const payload = await requestJson(
                `${route('planning-chat.show')}?${queryString(scope)}&conversation_id=${conversation.id}&before=${messages[0].id}`,
            );
            if (scrollArea.current) prependScroll.current = { height: scrollArea.current.scrollHeight, top: scrollArea.current.scrollTop };
            setMessages((current) => [...payload.messages, ...current]);
            setHasMore(payload.has_more_messages);
        } catch (cause) {
            setError(cause instanceof Error ? cause.message : 'Could not load earlier messages.');
        } finally {
            setLoadingEarlier(false);
        }
    }

    async function resumeConversation(id: number) {
        if (!scope || busy || resetting) return;
        setResetting(true);
        setError(null);
        try {
            const payload: ChatPayload = await requestJson(route('planning-chat.resume', { conversation: id }), {
                method: 'POST',
                body: JSON.stringify(scope),
            });
            setConversation(payload.conversation);
            setMessages(payload.messages);
            setHasMore(payload.has_more_messages);
            setView('chat');
            setDraft('');
            setSavedNotice(false);
            lastAttempt.current = null;
        } catch (cause) {
            setError(cause instanceof Error ? cause.message : 'Could not resume this conversation.');
        } finally {
            setResetting(false);
        }
    }

    async function saveReply(message: Message) {
        if (!scope || !message.id || busy || savingMessage !== null) return;
        setSavingMessage(message.id);
        setError(null);
        try {
            const payload = await requestJson(route('planning-chat.save-plan'), {
                method: 'POST',
                body: JSON.stringify({ ...scope, message_id: message.id }),
            });
            setMessages((current) => current.map((item) => (item.id === message.id ? { ...item, saved_plan_id: payload.saved_plan_id } : item)));
            setSavedNotice(true);
            window.dispatchEvent(new Event('planning-plans-updated'));
        } catch (cause) {
            setError(cause instanceof Error ? cause.message : 'Could not save this reply.');
        } finally {
            setSavingMessage(null);
        }
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        void send();
    }

    return (
        <Dialog.Root
            open={open}
            onOpenChange={(value) => {
                if (!value) onClose();
            }}
            modal={false}
        >
            <Dialog.Portal>
                <Dialog.Content
                    aria-describedby="planning-chat-description"
                    onInteractOutside={(event) => event.preventDefault()}
                    onOpenAutoFocus={(event) => {
                        event.preventDefault();
                        (choosing ? searchInput.current : composer.current)?.focus();
                    }}
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        document.querySelector<HTMLButtonElement>('[data-planning-chat-launcher="floating"]')?.focus();
                    }}
                    className="fixed right-3 bottom-3 z-[70] flex h-[min(720px,calc(100dvh-1.5rem))] w-[calc(100vw-1.5rem)] flex-col overflow-hidden rounded-2xl border border-blue-200 bg-white text-slate-900 shadow-2xl outline-none sm:right-5 sm:bottom-5 sm:w-[420px] dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                >
                    <header className="flex shrink-0 items-center gap-3 bg-[#087dcc] px-4 py-3 text-white">
                        <div className="flex size-10 shrink-0 items-center justify-center rounded-full bg-white/15">
                            <MessageCircle className="size-5" aria-hidden="true" />
                        </div>
                        <div className="min-w-0 flex-1">
                            <Dialog.Title className="text-base font-semibold">GeoAgent</Dialog.Title>
                            <Dialog.Description id="planning-chat-description" className="text-[11px] text-blue-100">
                                DOST technology-to-location planning
                            </Dialog.Description>
                        </div>
                        <button
                            type="button"
                            aria-label="Minimize assistant"
                            onClick={onMinimize}
                            className="rounded-lg p-2 hover:bg-white/15 focus-visible:outline-2"
                        >
                            <Minus className="size-4" />
                        </button>
                        <Dialog.Close aria-label="Close assistant" className="rounded-lg p-2 hover:bg-white/15 focus-visible:outline-2">
                            <X className="size-4" />
                        </Dialog.Close>
                    </header>

                    <div className="shrink-0 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
                        <div className="flex items-center gap-2">
                            <MapPin className="size-4 shrink-0 text-[#087dcc]" aria-hidden="true" />
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-xs font-semibold">{location ? location.name : 'Choose a municipality'}</p>
                                {location?.province_name && <p className="truncate text-[10px] text-slate-500">{location.province_name}</p>}
                            </div>
                            <button
                                type="button"
                                disabled={busy || resetting}
                                onClick={() => setChoosing(!choosing)}
                                className="rounded-md px-2 py-1 text-xs font-medium text-[#087dcc] hover:bg-blue-50 disabled:opacity-50"
                            >
                                {choosing && location ? 'Done' : 'Change'}
                            </button>
                            <button
                                type="button"
                                title="New conversation"
                                aria-label="Start a new conversation"
                                disabled={busy || loading || resetting || !messages.length}
                                onClick={() => setConfirmReset(!confirmReset)}
                                className="rounded-md p-1.5 text-slate-500 hover:bg-blue-50 disabled:opacity-40"
                            >
                                <RotateCcw className="size-4" />
                            </button>
                        </div>
                        {confirmReset && (
                            <div className="mt-2 rounded-lg bg-blue-50 p-2 text-xs dark:bg-slate-900">
                                Start a new chat? This conversation and its plans will remain in history.
                                <div className="mt-2 flex gap-3">
                                    <button
                                        type="button"
                                        disabled={resetting}
                                        onClick={() => void resetConversation()}
                                        className="font-semibold text-[#087dcc]"
                                    >
                                        {resetting ? 'Archiving…' : 'Archive & start new'}
                                    </button>
                                    <button type="button" onClick={() => setConfirmReset(false)}>
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        )}
                        {choosing && (
                            <div className="mt-3">
                                <label className="relative block">
                                    <span className="sr-only">Search municipalities or provinces</span>
                                    <Search className="absolute top-2.5 left-2.5 size-4 text-slate-400" aria-hidden="true" />
                                    <input
                                        ref={searchInput}
                                        value={search}
                                        maxLength={100}
                                        onChange={(event) => setSearch(event.target.value)}
                                        placeholder="Search municipality or province…"
                                        className="w-full rounded-lg border border-slate-200 bg-transparent py-2 pr-3 pl-8 text-xs focus:border-blue-400 focus:outline-none dark:border-slate-700"
                                    />
                                </label>
                                <div role="status" className="mt-1 text-[11px] text-slate-500">
                                    {searchError ??
                                        (searching
                                            ? 'Searching…'
                                            : search.trim().length < 2
                                              ? 'Enter at least 2 characters.'
                                              : results.length === 0
                                                ? 'No municipalities found.'
                                                : 'Select the correct municipality and province.')}
                                </div>
                                {results.length > 0 && (
                                    <ul
                                        aria-label="Municipality search results"
                                        className="mt-2 max-h-36 overflow-y-auto rounded-lg border border-slate-100 dark:border-slate-800"
                                    >
                                        {results.map((item) => (
                                            <li key={item.id}>
                                                <button
                                                    type="button"
                                                    disabled={busy || resetting}
                                                    onClick={() => selectLocation(item)}
                                                    className="w-full px-3 py-2 text-left text-xs hover:bg-blue-50 focus-visible:bg-blue-50 dark:hover:bg-slate-800"
                                                >
                                                    <span className="font-medium">{item.name}</span>
                                                    <span className="ml-2 text-slate-500">{item.province_name}</span>
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        )}
                    </div>

                    <nav aria-label="GeoAgent views" className="flex shrink-0 gap-1 border-b border-slate-100 px-3 py-2 dark:border-slate-800">
                        {(
                            [
                                { key: 'chat', label: 'Chat', icon: MessageCircle },
                                { key: 'chats', label: 'History', icon: History },
                                { key: 'plans', label: 'Saved plans', icon: FileText },
                            ] as const
                        ).map((tab) => (
                            <button
                                type="button"
                                key={tab.key}
                                disabled={busy || resetting || !location}
                                onClick={() => {
                                    setView(tab.key);
                                    setSavedNotice(false);
                                }}
                                aria-pressed={view === tab.key}
                                className={`flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium disabled:opacity-40 ${view === tab.key ? 'bg-blue-50 text-[#087dcc] dark:bg-slate-800' : 'text-slate-500 hover:bg-slate-50 dark:hover:bg-slate-900'}`}
                            >
                                <tab.icon className="size-3.5" aria-hidden="true" />
                                {tab.label}
                            </button>
                        ))}
                    </nav>

                    <div
                        ref={scrollArea}
                        className="min-h-0 flex-1 overflow-y-auto bg-slate-50/70 px-4 py-4 dark:bg-slate-900/40"
                        role={view === 'chat' ? 'log' : 'region'}
                        aria-label={view === 'chat' ? 'Conversation with GeoAgent' : 'Saved planning history'}
                        aria-live={view === 'chat' ? 'polite' : 'off'}
                        aria-relevant="additions text"
                        aria-busy={busy || loading}
                    >
                        {view !== 'chat' && scope && location ? (
                            <PlanningChatArchive
                                key={`${currentKey}:${view}`}
                                scope={scope}
                                location={location}
                                kind={view}
                                initialPlanId={
                                    view === 'plans' && initialScope && currentKey === queryString(initialScope) ? initialPlanId : undefined
                                }
                                onResume={(id) => void resumeConversation(id)}
                                disabled={busy || resetting}
                            />
                        ) : (
                            <>
                                {hasMore && (
                                    <button
                                        type="button"
                                        disabled={loadingEarlier || busy}
                                        onClick={() => void loadEarlier()}
                                        className="mb-4 w-full rounded-lg border border-blue-100 bg-white py-2 text-xs text-[#087dcc] dark:bg-slate-900"
                                    >
                                        {loadingEarlier ? 'Loading…' : 'Load earlier messages'}
                                    </button>
                                )}
                                <div className="mb-4 max-w-[95%] rounded-2xl rounded-bl-sm border border-blue-100 bg-white p-3 text-sm leading-relaxed dark:border-slate-700 dark:bg-slate-900">
                                    Hi, I'm GeoAgent! I match DOST technologies to a location, rank where to deploy them, compare locations and draft
                                    the action plan.{' '}
                                    {location
                                        ? `Which technology or local need should we explore for ${location.name}?`
                                        : 'Choose a municipality above to get started.'}
                                    <p className="mt-2 text-[11px] text-slate-500">
                                        I answer only from KonektaDOST's recorded data and tell you when something isn't recorded. Plans are drafts
                                        for LGU/DOST review, not approved projects.
                                    </p>
                                </div>
                                {loading && (
                                    <p role="status" className="flex items-center gap-2 text-xs text-slate-500">
                                        <LoaderCircle className="size-4 animate-spin" /> Loading conversation…
                                    </p>
                                )}
                                {messages.map((message, index) => (
                                    <div
                                        key={`${index}:${message.role}`}
                                        className={`mb-3 flex ${message.role === 'user' ? 'justify-end' : 'justify-start'}`}
                                    >
                                        <div
                                            className={`max-w-[95%] min-w-0 rounded-2xl px-3 py-2.5 text-sm leading-relaxed ${message.role === 'user' ? 'rounded-br-sm bg-[#087dcc] text-white' : 'rounded-bl-sm border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900'}`}
                                        >
                                            <span className="sr-only">{message.role === 'user' ? 'You: ' : 'Assistant: '}</span>
                                            {message.role === 'assistant' ? (
                                                <PlanningReply message={message} location={location} />
                                            ) : (
                                                <div className="break-words whitespace-pre-wrap">{message.content}</div>
                                            )}
                                            {message.sources && message.sources.length > 0 && (
                                                <details className="mt-2 border-t border-slate-100 pt-2 text-[10px] text-slate-500 dark:border-slate-700">
                                                    <summary className="cursor-pointer">Tools consulted ({message.sources.length})</summary>
                                                    <ul className="mt-1 space-y-1">
                                                        {message.sources.map((source) => (
                                                            <li key={source.name} className="break-words">
                                                                {source.name} · {source.location}
                                                            </li>
                                                        ))}
                                                    </ul>
                                                </details>
                                            )}
                                            {message.role === 'assistant' && (
                                                <div className="mt-2 flex flex-wrap gap-3">
                                                    <button
                                                        type="button"
                                                        aria-label="Copy assistant reply"
                                                        onClick={() => void copyReply(stripToolCitations(message.content), index)}
                                                        className="flex items-center gap-1 rounded text-[10px] text-slate-500 hover:text-[#087dcc]"
                                                    >
                                                        {copied === index ? <Check className="size-3" /> : <Copy className="size-3" />}
                                                        {copied === index ? 'Copied' : 'Copy reply'}
                                                    </button>
                                                    {message.id && (
                                                        <button
                                                            type="button"
                                                            disabled={busy || savingMessage !== null || !!message.saved_plan_id}
                                                            onClick={() => void saveReply(message)}
                                                            className="flex items-center gap-1 rounded text-[10px] text-[#087dcc] disabled:opacity-60"
                                                        >
                                                            {savingMessage === message.id ? (
                                                                <LoaderCircle className="size-3 animate-spin" />
                                                            ) : message.saved_plan_id ? (
                                                                <Check className="size-3" />
                                                            ) : (
                                                                <Save className="size-3" />
                                                            )}
                                                            {message.saved_plan_id ? 'Plan saved' : 'Save as plan'}
                                                        </button>
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                ))}
                                {busy && (
                                    <p role="status" className="flex items-center gap-2 text-xs text-slate-500">
                                        <LoaderCircle className="size-4 animate-spin text-[#087dcc]" /> Reviewing your request and local evidence…
                                        <button type="button" onClick={cancelSend} className="ml-auto font-semibold text-[#087dcc] hover:underline">
                                            Cancel
                                        </button>
                                    </p>
                                )}
                                {!messages.length && location && !loading && !busy && (
                                    <div className="flex flex-wrap gap-2">
                                        {[
                                            'Match DOST technologies to this location',
                                            'Which top 5 barangays should we target first, and with which technology?',
                                            'Create a technology assignment action plan',
                                            'Show technology match scores and deployment diagram',
                                        ].map((prompt) => (
                                            <button
                                                type="button"
                                                key={prompt}
                                                disabled={!configured || choosing}
                                                onClick={() => void send(prompt)}
                                                className="rounded-xl border border-blue-200 bg-white px-3 py-2 text-left text-xs text-[#087dcc] hover:bg-blue-50 disabled:opacity-50 dark:bg-slate-900"
                                            >
                                                {prompt}
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </>
                        )}
                    </div>

                    <div className="shrink-0 border-t border-slate-100 bg-white px-3 pt-2 pb-3 dark:border-slate-800 dark:bg-slate-950">
                        {savedNotice && (
                            <p role="status" className="mb-2 flex items-center gap-1 px-1 text-xs text-emerald-700 dark:text-emerald-300">
                                <Check className="size-3.5" /> Action plan saved. Previous versions are kept.
                            </p>
                        )}
                        {!configured && (
                            <p role="alert" className="mb-2 px-1 text-xs text-amber-700 dark:text-amber-300">
                                The AI assistant is not configured on the server. Computed assessments remain available.
                            </p>
                        )}
                        {error && (
                            <div
                                role="alert"
                                className="mb-2 flex items-start gap-2 rounded-lg bg-amber-50 p-2 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-200"
                            >
                                <span className="flex-1">{error}</span>
                                {!busy && (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            loaded.current = null;
                                            setHistoryVersion((value) => value + 1);
                                        }}
                                        className="font-semibold underline"
                                    >
                                        Refresh
                                    </button>
                                )}
                            </div>
                        )}
                        {location && view === 'chat' && configured && (
                            <div className="mb-2 flex flex-wrap gap-1.5" aria-label="Quick actions">
                                {[
                                    { label: 'Compare with…', text: `Compare ${location.name} with `, send: false },
                                    {
                                        label: 'Top 10 barangays',
                                        text: 'Rank the top 10 barangays to target, with the reason and best-fit DOST technology for each.',
                                        send: true,
                                    },
                                    { label: 'Draft action plan', text: 'Create a technology assignment action plan', send: true },
                                ].map((action) => (
                                    <button
                                        key={action.label}
                                        type="button"
                                        disabled={busy || loading || choosing}
                                        onClick={() => {
                                            if (action.send) void send(action.text);
                                            else {
                                                setDraft(action.text);
                                                composer.current?.focus();
                                            }
                                        }}
                                        className="rounded-full border border-blue-200 bg-blue-50/60 px-2.5 py-1 text-[11px] font-medium text-[#087dcc] hover:bg-blue-100 disabled:opacity-50 dark:border-slate-700 dark:bg-slate-900"
                                    >
                                        {action.label}
                                    </button>
                                ))}
                            </div>
                        )}
                        <form
                            onSubmit={submit}
                            className="flex items-end gap-2 rounded-xl border border-slate-200 p-2 focus-within:border-blue-400 dark:border-slate-700"
                        >
                            <textarea
                                ref={composer}
                                aria-label="Message GeoAgent"
                                value={draft}
                                onChange={(event) => setDraft(event.target.value)}
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) {
                                        event.preventDefault();
                                        void send();
                                    }
                                }}
                                placeholder={location ? 'Ask a question or describe your plan…' : 'Choose a municipality first…'}
                                rows={2}
                                maxLength={4000}
                                disabled={
                                    !location || loading || busy || resetting || savingMessage !== null || !configured || choosing || view !== 'chat'
                                }
                                className="max-h-28 min-h-11 flex-1 resize-none bg-transparent p-1 text-sm outline-none disabled:opacity-50"
                            />
                            {busy ? (
                                <button
                                    type="button"
                                    onClick={cancelSend}
                                    aria-label="Stop GeoAgent"
                                    title="Stop"
                                    className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-slate-700 text-white hover:bg-slate-900 dark:bg-slate-200 dark:text-slate-900"
                                >
                                    <Square className="size-3.5 fill-current" />
                                </button>
                            ) : (
                                <button
                                    type="submit"
                                    aria-label="Send message"
                                    disabled={
                                        !draft.trim() ||
                                        !location ||
                                        loading ||
                                        busy ||
                                        resetting ||
                                        savingMessage !== null ||
                                        !configured ||
                                        choosing ||
                                        view !== 'chat'
                                    }
                                    className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-[#087dcc] text-white hover:bg-[#0666a8] disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    <Send className="size-4" />
                                </button>
                            )}
                        </form>
                        <p className="mt-1.5 px-1 text-[10px] text-slate-400">
                            Chats and plan versions are saved to your account. AI uses recent exchanges and the latest saved plan.
                        </p>
                    </div>
                </Dialog.Content>
            </Dialog.Portal>
        </Dialog.Root>
    );
}

function PlanningChatArchive({
    scope,
    location,
    kind,
    initialPlanId,
    onResume,
    disabled,
}: {
    scope: Scope;
    location: Municipality;
    kind: 'chats' | 'plans';
    initialPlanId?: number;
    onResume: (id: number) => void;
    disabled: boolean;
}) {
    const [items, setItems] = useState<HistoryItem[]>([]);
    const [page, setPage] = useState(1);
    const [nextPage, setNextPage] = useState<number | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [plan, setPlan] = useState<SavedPlan | null>(null);
    const [openingPlanId, setOpeningPlanId] = useState(initialPlanId ?? null);
    const [retry, setRetry] = useState(0);
    const scopeQuery = queryString(scope);

    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setError(null);
        void requestJson(`${route('planning-chat.history')}?${scopeQuery}&kind=${kind}&page=${page}`, { signal: controller.signal })
            .then((payload) => {
                setItems((current) => [
                    ...new Map([...(page === 1 ? [] : current), ...(payload.items as HistoryItem[])].map((item) => [item.id, item])).values(),
                ]);
                setNextPage(payload.next_page);
            })
            .catch((cause) => {
                if (!controller.signal.aborted) setError(cause.message);
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });
        return () => controller.abort();
    }, [scopeQuery, kind, page, retry]);

    useEffect(() => {
        if (!openingPlanId) return;
        const controller = new AbortController();
        setLoading(true);
        setError(null);
        void requestJson(`${route('planning-chat.plan', { plan: openingPlanId })}?${scopeQuery}`, { signal: controller.signal })
            .then((payload) => setPlan(payload.plan))
            .catch((cause) => {
                if (!controller.signal.aborted) setError(cause.message);
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });
        return () => controller.abort();
    }, [scopeQuery, openingPlanId, retry]);

    if (plan)
        return (
            <article className="min-w-0 text-sm">
                <button
                    type="button"
                    onClick={() => {
                        setPlan(null);
                        setOpeningPlanId(null);
                    }}
                    className="mb-3 flex items-center gap-1 text-xs text-[#087dcc]"
                >
                    <ArrowLeft className="size-3.5" /> All saved plans
                </button>
                <PlanningActionPlan key={plan.id} initiallyOpen snapshot={{ ...plan, location: location.name, province: location.province_name }} />
                <button
                    type="button"
                    disabled={disabled}
                    onClick={() => onResume(plan.conversation_id)}
                    className="mt-4 rounded-lg bg-[#087dcc] px-3 py-2 text-xs font-medium text-white disabled:opacity-50"
                >
                    Open source conversation
                </button>
            </article>
        );

    return (
        <section aria-label={kind === 'chats' ? 'Previous conversations' : 'Saved action plans'}>
            <h3 className="mb-3 text-sm font-semibold">{kind === 'chats' ? 'Previous conversations' : 'Saved action plans'}</h3>
            <p className="mb-3 text-xs text-slate-500">
                {kind === 'chats'
                    ? 'Resume a previous chat. Starting a new conversation never deletes these records.'
                    : 'Complete action plans are saved automatically. Every revised plan keeps its own version.'}
            </p>
            {error && (
                <p role="alert" className="mb-3 text-xs text-amber-700">
                    {error}{' '}
                    <button type="button" onClick={() => setRetry((value) => value + 1)} className="font-semibold underline">
                        Retry
                    </button>
                </p>
            )}
            <ul className="space-y-2">
                {items.map((item) => (
                    <li key={item.id}>
                        <button
                            type="button"
                            disabled={disabled || loading}
                            onClick={() => (kind === 'plans' ? setOpeningPlanId(item.id) : onResume(item.id))}
                            className="w-full rounded-xl border border-blue-100 bg-white p-3 text-left hover:bg-blue-50 disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900"
                        >
                            <span className="block text-xs font-semibold break-words">{item.title}</span>
                            <span className="mt-1 block text-[10px] text-slate-500">
                                {kind === 'plans'
                                    ? `Version ${item.version} · ${item.created_at}`
                                    : `${item.archived_at ? 'Archived' : 'Active'} · ${item.updated_at}`}
                            </span>
                        </button>
                    </li>
                ))}
            </ul>
            {loading && (
                <p role="status" className="mt-3 flex items-center gap-2 text-xs text-slate-500">
                    <LoaderCircle className="size-4 animate-spin" /> Loading…
                </p>
            )}
            {!loading && !items.length && !error && (
                <p className="text-xs text-slate-500">
                    {kind === 'plans' ? 'No saved plans yet. Ask the assistant to create one.' : 'No previous conversations yet.'}
                </p>
            )}
            {nextPage && (
                <button type="button" disabled={loading} onClick={() => setPage(nextPage)} className="mt-3 text-xs font-medium text-[#087dcc]">
                    Load older {kind === 'plans' ? 'plans' : 'chats'}
                </button>
            )}
        </section>
    );
}
