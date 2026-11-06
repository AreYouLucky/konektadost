/** Laravel refreshes this cookie when the session token changes during SPA navigation. */
export function csrfHeaders(): Record<string, string> {
    const cookie = document.cookie
        .split(';')
        .map((part) => part.trim())
        .find((part) => part.startsWith('XSRF-TOKEN='));
    if (cookie) {
        try {
            return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) };
        } catch {
            // A malformed cookie can fall back to the server-rendered token.
        }
    }
    return { 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '' };
}

/**
 * Fetches the session's current token and writes it into the page's meta tag. The meta tag is only
 * rendered on a full page load, so it goes stale after an Inertia login/logout or a login in another tab.
 */
export async function refreshCsrfToken(): Promise<string | null> {
    try {
        const response = await fetch(route('csrf-token'), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!response.ok || response.redirected) return null;
        const payload = await response.json();
        if (typeof payload?.token !== 'string' || !payload.token) return null;
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.setAttribute('content', payload.token);
        return payload.token;
    } catch {
        return null;
    }
}

/**
 * fetch() for state-changing requests. Sends the freshest CSRF token and, when the server answers 419
 * (token mismatch, not an ended login), refreshes the token and retries once. After that, a 419, 401 or
 * redirect to the login page means the session really ended.
 */
export async function fetchWithCsrf(url: string, init: RequestInit = {}): Promise<Response> {
    const send = (token: Record<string, string>) =>
        fetch(url, { credentials: 'same-origin', ...init, headers: { ...(init.headers as Record<string, string> | undefined), ...token } });
    const response = await send(csrfHeaders());
    if (response.status !== 419) return response;
    const token = await refreshCsrfToken();
    return token ? send({ 'X-CSRF-TOKEN': token }) : response;
}

/** True when a response means the user is no longer signed in (after any CSRF retry). */
export function isSessionEnded(response: Response) {
    return response.redirected || response.status === 401 || response.status === 419;
}
