/** Laravel refreshes this cookie when the session token changes during SPA navigation. */
export function csrfHeaders(): Record<string, string> {
    const cookie = document.cookie.split(';').map((part) => part.trim()).find((part) => part.startsWith('XSRF-TOKEN='));
    if (cookie) {
        try {
            return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) };
        } catch {
            // A malformed cookie can fall back to the server-rendered token.
        }
    }
    return { 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '' };
}
