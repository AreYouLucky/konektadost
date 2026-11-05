import { useEffect, useState } from 'react';
import { EMPTY_ROADS, getGeography, type GeographyApi, type MapLocation, type RoadCollection, type RoadResponse } from './types';

export function useSelectedRoads(selected: MapLocation | null, api: GeographyApi) {
    const key = selected ? `${selected.level}:${selected.id}` : null;
    const level = selected?.level;
    const id = selected?.id;
    const [attempt, setAttempt] = useState(0);
    const [state, setState] = useState<{
        key: string | null;
        data: RoadCollection;
        loading: boolean;
        error: string | null;
        message: string | null;
        omitted: number;
    }>({ key: null, data: EMPTY_ROADS, loading: false, error: null, message: null, omitted: 0 });
    useEffect(() => {
        if (!key || !level || !id || !api.roads) return;
        const controller = new AbortController();
        setState({ key, data: EMPTY_ROADS, loading: true, error: null, message: null, omitted: 0 });
        const url = api.roads;
        void (async () => {
            let cursor = 0;
            let data = EMPTY_ROADS;
            let omitted = 0;
            try {
                while (!controller.signal.aborted) {
                    const page = await getGeography<RoadResponse>(url(level, id, cursor), controller.signal);
                    if (controller.signal.aborted) return;
                    if (page.type !== 'FeatureCollection' || !Array.isArray(page.features) || page.meta?.scope?.key !== key)
                        throw new Error('Road data did not match the selected location. Please retry.');
                    data = { type: 'FeatureCollection', features: [...data.features, ...page.features] };
                    omitted += page.meta.omitted_geometries;
                    const next = page.meta.next_cursor;
                    if (next !== null && (!Number.isInteger(next) || next <= cursor))
                        throw new Error('The road response was incomplete. Please retry.');
                    setState({ key, data, loading: next !== null, error: null, message: page.meta.message, omitted });
                    if (next === null) break;
                    cursor = next;
                }
            } catch (error) {
                if (!controller.signal.aborted)
                    setState({
                        key,
                        data,
                        loading: false,
                        error: error instanceof Error ? error.message : 'Road data could not be loaded.',
                        message: null,
                        omitted,
                    });
            }
        })();
        return () => controller.abort();
    }, [key, level, id, api, attempt]);

    // Clear old lines in the same render as a location change or reset.
    return {
        ...(state.key === key && key && api.roads ? state : { data: EMPTY_ROADS, loading: false, error: null, message: null, omitted: 0 }),
        retry: () => setAttempt((value) => value + 1),
    };
}
