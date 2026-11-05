import { useCallback, useEffect, useRef, useState } from 'react';
import {
    EMPTY_BOUNDARIES,
    getGeography,
    type Boundaries,
    type Boundary,
    type GeographicLevel,
    type GeographyApi,
    type GeographyResponse,
    type MapLocation,
    type SelectionResponse,
} from './types';

export function useGeography(provinces: MapLocation[], initialError: string | null, api: GeographyApi, syncUrl: boolean) {
    const [path, setPath] = useState<MapLocation[]>([]);
    const [locations, setLocations] = useState(provinces);
    const [boundaries, setBoundaries] = useState<Boundaries>(EMPTY_BOUNDARIES);
    const [boundary, setBoundary] = useState<Boundary | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(initialError);
    const [revision, setRevision] = useState(0);
    const cache = useRef(new Map<string, GeographyResponse>());
    const selections = useRef(new Map<string, SelectionResponse>());
    const request = useRef<AbortController | null>(null);
    const sequence = useRef(0);
    const lastTarget = useRef<MapLocation | null>(null);
    const countryRequest = useRef<{ controller: AbortController; promise: Promise<GeographyResponse> } | null>(null);

    const loadCountry = useCallback(() => {
        if (!countryRequest.current || countryRequest.current.controller.signal.aborted) {
            const controller = new AbortController();
            const promise = getGeography<GeographyResponse>(api.geography('province'), controller.signal).catch((error: unknown) => {
                if (countryRequest.current?.controller === controller) countryRequest.current = null;
                throw error;
            });
            countryRequest.current = { controller, promise };
        }
        return countryRequest.current.promise;
    }, [api]);

    const navigate = useCallback(
        async (target: MapLocation | null, updateHistory = true) => {
            request.current?.abort();
            const controller = new AbortController();
            request.current = controller;
            const version = ++sequence.current;
            lastTarget.current = target;
            setLoading(true);
            setError(null);
            try {
                const country = await loadCountry();
                let selection: SelectionResponse = { path: [], boundary: null };
                if (target) {
                    selection =
                        selections.current.get(target.key) ??
                        (await getGeography<SelectionResponse>(api.selection(target.level, target.id), controller.signal));
                    selections.current.set(target.key, selection);
                }
                const parent = selection.path.findLast((location) => location.level !== 'barangay');
                const level: GeographicLevel = !parent ? 'province' : parent.level === 'province' ? 'municipality' : 'barangay';
                const cacheKey = `${level}:${parent?.id ?? ''}`;
                const data =
                    (level === 'province' ? country : cache.current.get(cacheKey)) ??
                    (await getGeography<GeographyResponse>(api.geography(level, parent?.id), controller.signal));
                if (version !== sequence.current || controller.signal.aborted) return;
                // Bound the cache to recent selections; never retain the whole country's barangay geometry.
                if (cache.current.size >= 8) cache.current.delete(cache.current.keys().next().value!);
                cache.current.set(cacheKey, data);
                if (selections.current.size > 20) selections.current.delete(selections.current.keys().next().value!);
                setPath(selection.path);
                setBoundary(selection.boundary);
                setLocations(data.locations);
                setBoundaries(data.boundaries);
                setRevision((value) => value + 1);
                if (syncUrl && updateHistory) {
                    const url = new URL(window.location.href);
                    url.searchParams.delete('level');
                    url.searchParams.delete('location');
                    if (target) {
                        url.searchParams.set('level', target.level);
                        url.searchParams.set('location', String(target.id));
                    }
                    // Inertia may replace the initial entry after this hook mounts. Mark
                    // the settled entry immediately before pushing so Back stays local.
                    window.history.replaceState({ ...window.history.state, konektaExplorer: true }, '', window.location.href);
                    window.history.pushState({ ...window.history.state, konektaExplorer: true }, '', url);
                }
            } catch (failure) {
                if (!controller.signal.aborted && version === sequence.current)
                    setError(failure instanceof Error ? failure.message : 'Locations are unavailable.');
            } finally {
                if (version === sequence.current) setLoading(false);
            }
        },
        [loadCountry, api, syncUrl],
    );

    useEffect(() => {
        const geographyCache = cache.current;
        const selectionCache = selections.current;
        if (!syncUrl) {
            void navigate(null, false);
            return () => {
                request.current?.abort();
                countryRequest.current?.controller.abort();
                geographyCache.clear();
                selectionCache.clear();
            };
        }
        const pathname = window.location.pathname;
        const restore = (event?: PopStateEvent) => {
            if (event) {
                if (window.location.pathname !== pathname || !event.state?.konektaExplorer) return;
                // These entries change only map selection. Keep Inertia from remounting the
                // page/map for a local Back/Forward; ordinary page navigation stays with Inertia.
                event.stopImmediatePropagation();
            }
            const params = new URLSearchParams(window.location.search);
            const level = params.get('level');
            const id = Number(params.get('location'));
            const target =
                ['province', 'municipality', 'barangay'].includes(level ?? '') && Number.isSafeInteger(id) && id > 0
                    ? { id, level: level as GeographicLevel, key: `${level}:${id}`, name: '', code: null, center: null, bounds: null }
                    : null;
            void navigate(target, false);
        };
        window.history.replaceState({ ...window.history.state, konektaExplorer: true }, '', window.location.href);
        restore();
        window.addEventListener('popstate', restore, true);
        return () => {
            request.current?.abort();
            countryRequest.current?.controller.abort();
            geographyCache.clear();
            selectionCache.clear();
            window.removeEventListener('popstate', restore, true);
        };
    }, [navigate, syncUrl]);

    return {
        path,
        locations,
        boundaries,
        boundary,

        loading,
        error,
        revision,
        navigate,
        retry: () => navigate(lastTarget.current, false),
    };
}
