import type { MapResourcePin } from '@/components/philippine-map';
import { useEffect, useState } from 'react';

const EMPTY_PINS: MapResourcePin[] = [];

/** Fetch the coordinate inventory once per mounted map; selections filter locally. */
export function useMapMarkers() {
    const [attempt, setAttempt] = useState(0);
    const [state, setState] = useState<{ pins: MapResourcePin[]; loading: boolean; error: string | null }>({
        pins: EMPTY_PINS,
        loading: true,
        error: null,
    });
    useEffect(() => {
        const controller = new AbortController();
        setState({ pins: EMPTY_PINS, loading: true, error: null });
        void (async () => {
            try {
                const response = await fetch(`${route('admin.search-map.index').replace(/\/$/, '')}/markers`, {
                    signal: controller.signal,
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (response.redirected || response.status === 401 || response.status === 419)
                    throw new Error('Your session has expired. Please sign in again.');
                if (!response.ok) throw new Error('Map markers could not be loaded. Please try again.');
                const data: { pins: MapResourcePin[] } = await response.json();
                if (!controller.signal.aborted) setState({ pins: data.pins, loading: false, error: null });
            } catch (error) {
                if (!controller.signal.aborted)
                    setState({ pins: EMPTY_PINS, loading: false, error: error instanceof Error ? error.message : 'Map markers are unavailable.' });
            }
        })();
        return () => controller.abort();
    }, [attempt]);
    return { ...state, retry: () => setAttempt((value) => value + 1) };
}
