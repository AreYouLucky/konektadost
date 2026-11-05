import type { MapLocation, MapResourcePin } from '@/components/philippine-map';
import type { MapRoutePrefix } from '@/components/philippine-map/types';
import { useEffect, useMemo, useState } from 'react';
import type { LocationScope } from './location-scope';

export interface ProvinceResourceRecord extends Omit<MapResourcePin, 'coordinates'> {
    coordinates: [number, number] | null;
    metadata: {
        operator: string | null;
        level: string | null;
        capacity: string | null;
        capacity_no: number | null;
        description: string | null;
        code: string | null;
        website: string | null;
        contact: string | null;
        source_url: string | null;
        source_record_id: string | null;
        record_updated_at: string | null;
        catalog_service_count: number | null;
        source_listed_service_count: number | null;
    };
}
export interface ProvinceResourceData {
    province_id: number;
    scope: LocationScope;
    population: { status: 'available' | 'no_data' | 'unavailable'; value: number | null; year: number | null; reason: string | null };
    facilities: { status: 'available' | 'unavailable'; count: number | null; pinned_count: number; by_category: Record<string, number> };
    laboratories: { status: 'available' | 'unavailable'; count: number | null; pinned_count: number; by_category: Record<string, number> };
    telecom_sites: { status: 'available' | 'unavailable'; count: number | null; pinned_count: number; pins: MapResourcePin[] };
    records: ProvinceResourceRecord[];
    assessment: { area_km2: number | null; results: MapAssessmentResult[] };
    population_breakdown: { level: 'municipality' | 'barangay'; year: number | null; rows: { id: number; name: string; value: number | null }[] };
    technologies: {
        status: 'available' | 'unavailable';
        count: number | null;
        pinned_count: number;
        records: TechnologyLocation[];
        pins: MapResourcePin[];
    };
}

export interface TechnologyLocation {
    id: number;
    code: string;
    name: string;
    agency: string | null;
    sector: string | null;
    deployment_clusters: string | null;
    mechanism: string | null;
    status: string | null;
    outcome: string | null;
    locations: string[];
    location_basis: string;
}

export interface MapAssessmentResult {
    key: string;
    label: string;
    description: string;
    unit: string;
    dimension: string;
    source: string;
    caveat: string | null;
    value: number | null;
    benchmark: number | null;
    median: number | null;
    gap: number | null;
    status: 'available' | 'no_data' | 'no_benchmark';
    confidence: 'high' | 'low';
    reason: string;
    is_shortfall: boolean;
    basis_note: string | null;
}

export function useProvinceResources(
    provinceId: number | null,
    selection: MapLocation | null = null,
    routePrefix: MapRoutePrefix = 'admin.search-map',
) {
    const level = selection?.level ?? 'province';
    const location = selection?.id ?? provinceId;
    const scopeKey = provinceId === null ? null : `${provinceId}:${level}:${location}`;
    const [attempt, setAttempt] = useState(0);
    const [state, setState] = useState<{ scopeKey: string | null; data: ProvinceResourceData | null; loading: boolean; error: string | null }>({
        scopeKey: null,
        data: null,
        loading: false,
        error: null,
    });
    useEffect(() => {
        if (provinceId === null) return;
        const controller = new AbortController();
        setState({ scopeKey, data: null, loading: true, error: null });
        async function load() {
            try {
                const response = await fetch(route(`${routePrefix}.resources`, { province: provinceId!, level, location: location! }), {
                    signal: controller.signal,
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (response.redirected || response.status === 401 || response.status === 419)
                    throw new Error('Your session has expired. Please sign in again.');
                if (response.status === 429) throw new Error('Please pause for a moment, then try again.');
                if (!response.ok) throw new Error('Population and services could not be loaded. Please try again.');
                const data: ProvinceResourceData = await response.json();
                if (!controller.signal.aborted) setState({ scopeKey, data, loading: false, error: null });
            } catch (error) {
                if (!controller.signal.aborted)
                    setState({ scopeKey, data: null, loading: false, error: error instanceof Error ? error.message : 'Records are unavailable.' });
            }
        }
        void load();
        return () => controller.abort();
    }, [provinceId, level, location, scopeKey, attempt, routePrefix]);
    const data = scopeKey !== null && state.scopeKey === scopeKey ? state.data : null;
    const pins = useMemo<MapResourcePin[]>(
        () => [
            ...(data?.records.flatMap((record) =>
                record.coordinates
                    ? [
                          {
                              key: record.key,
                              name: record.name,
                              category: record.category,
                              barangay_id: record.barangay_id,
                              municipality_id: record.municipality_id,
                              coordinates: record.coordinates,
                              detail: record.detail,
                              municipality: record.municipality,
                              address: record.address,
                              source: record.source,
                              details:
                                  record.category === 'laboratory'
                                      ? [
                                            { label: 'Laboratory code', value: record.metadata.code },
                                            {
                                                label: 'Normalized service entries',
                                                value:
                                                    record.metadata.catalog_service_count === null
                                                        ? null
                                                        : String(record.metadata.catalog_service_count),
                                            },
                                            {
                                                label: 'Source-listed services',
                                                value:
                                                    record.metadata.source_listed_service_count === null
                                                        ? null
                                                        : String(record.metadata.source_listed_service_count),
                                            },
                                            { label: 'Contact', value: record.metadata.contact },
                                            { label: 'Website', value: record.metadata.website },
                                        ].filter((item): item is { label: string; value: string } => item.value !== null && item.value !== '')
                                      : undefined,
                          },
                      ]
                    : [],
            ) ?? []),
            ...(data?.technologies.pins ?? []),
            ...(data?.telecom_sites.pins ?? []),
        ],
        [data],
    );
    return {
        data,
        pins,
        loading: scopeKey !== null && (state.scopeKey !== scopeKey || state.loading),
        error: state.scopeKey === scopeKey ? state.error : null,
        retry: () => setAttempt((value) => value + 1),
    };
}
