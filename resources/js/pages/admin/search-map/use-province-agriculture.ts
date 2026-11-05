import type { MapLocation } from '@/components/philippine-map';
import type { MapRoutePrefix } from '@/components/philippine-map/types';
import { useEffect, useState } from 'react';
import type { LocationScope } from './location-scope';

export interface AgricultureFinancials {
    revenue: { total: string | null; reported_count: number };
    operating_cost: { total: string | null; reported_count: number };
    net: { total: string | null; reported_count: number };
}
export interface AgricultureCommodity {
    name: string | null;
    variant: string | null;
    category_id: number | null;
    category_name: string;
    category_code: string | null;
    type_id: number | null;
    type_name: string;
    unit: string | null;
    basis: string | null;
    record_count: number;
    annual_quantity: string | null;
    marketable_quantity: string | null;
    sold_quantity: string | null;
    financials: AgricultureFinancials;
    unit_price: { min: string | null; max: string | null; reported_count: number };
}
export interface AgricultureSummary {
    province_id: number;
    scope: LocationScope;
    status: 'available' | 'no_data' | 'unavailable';
    model_year: number | null;
    record_count: number;
    commodity_count: number;
    municipality_count: number;
    data_source_types: string[];
    source_tables: string[];
    municipalities: { id: number; name: string }[];
    categories: {
        id: number | null;
        code: string | null;
        name: string;
        record_count: number;
        types: { id: number | null; name: string; record_count: number }[];
    }[];
    commodities: AgricultureCommodity[];
    financials: AgricultureFinancials | null;
    areas: { kind: string | null; area_sqm: string | null; reported_count: number }[];
}

export async function fetchAgriculture<T>(url: string, signal: AbortSignal): Promise<T> {
    const response = await fetch(url, { signal, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    if (response.redirected || response.status === 401 || response.status === 419) throw new Error('Your session has expired. Please sign in again.');
    if (response.status === 429) throw new Error('Please pause for a moment, then try again.');
    if (!response.ok) throw new Error('Agriculture records could not be loaded. Please try again.');
    return response.json() as Promise<T>;
}

export function useProvinceAgriculture(
    provinceId: number | null,
    selection: MapLocation | null = null,
    routePrefix: MapRoutePrefix = 'admin.search-map',
) {
    const level = selection?.level ?? 'province';
    const location = selection?.id ?? provinceId;
    const scopeKey = provinceId === null ? null : `${provinceId}:${level}:${location}`;
    const [attempt, setAttempt] = useState(0);
    const [state, setState] = useState<{ key: string | null; data: AgricultureSummary | null; loading: boolean; error: string | null }>({
        key: null,
        data: null,
        loading: false,
        error: null,
    });
    useEffect(() => {
        if (provinceId === null) return;
        const controller = new AbortController();
        setState({ key: scopeKey, data: null, loading: true, error: null });
        void fetchAgriculture<AgricultureSummary>(
            route(`${routePrefix}.agriculture`, { province: provinceId, level, location: location! }),
            controller.signal,
        )
            .then((data) => {
                if (!controller.signal.aborted) setState({ key: scopeKey, data, loading: false, error: null });
            })
            .catch((error: unknown) => {
                if (!controller.signal.aborted)
                    setState({
                        key: scopeKey,
                        data: null,
                        loading: false,
                        error: error instanceof Error ? error.message : 'Agriculture data is unavailable.',
                    });
            });
        return () => controller.abort();
    }, [provinceId, level, location, scopeKey, attempt, routePrefix]);
    return {
        data: scopeKey !== null && state.key === scopeKey ? state.data : null,
        loading: scopeKey !== null && (state.key !== scopeKey || state.loading),
        error: state.key === scopeKey ? state.error : null,
        retry: () => setAttempt((value) => value + 1),
    };
}
