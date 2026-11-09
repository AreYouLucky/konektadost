import type { Feature, FeatureCollection, LineString, MultiLineString, MultiPolygon, Polygon } from 'geojson';
import type { ReactNode } from 'react';

export type GeographicLevel = 'province' | 'municipality' | 'barangay';
export type Bounds = [number, number, number, number];

export interface MapResourcePin {
    key: string;
    name: string;
    category: 'health' | 'education' | 'safety' | 'facility' | 'laboratory' | 'dost_tech' | 'telecom';
    barangay_id: number | null;
    /** Explicit source identity, used only for exact barangay-code matches when its polygon is absent. */
    barangay_psgc?: string | null;
    municipality_id: number | null;
    coordinates: [number, number];
    detail: string;
    municipality: string | null;
    address: string | null;
    source: string | null;
    /** Plain text popup details; source values are never interpreted as HTML. */
    details?: { label: string; value: string }[];
}

export interface MapLocation {
    id: number;
    key: string;
    level: GeographicLevel;
    name: string;
    code: string | null;
    center: [number, number] | null;
    bounds: Bounds | null;
}

export type Boundary = Feature<Polygon | MultiPolygon, MapLocation>;
export type Boundaries = FeatureCollection<Polygon | MultiPolygon, MapLocation>;
export interface GeographyResponse {
    locations: MapLocation[];
    boundaries: Boundaries;
}
export interface SelectionResponse {
    path: MapLocation[];
    boundary: Boundary | null;
}
export interface SearchResult extends MapLocation {
    path: MapLocation[];
}
export interface SatelliteConfig {
    url: string;
    attribution: string;
    maxzoom: number;
    tileSize: number;
}
export interface SearchMapProps {
    provinces: MapLocation[];
    geographyError: string | null;
    satellite: SatelliteConfig | null;
}

export interface GeographyApi {
    roads?: (level: GeographicLevel, id: number, cursor?: number) => string;
    geography: (level: GeographicLevel, parent?: number) => string;
    selection: (level: GeographicLevel, id: number) => string;
    search: (query: string) => string;
}

export type MapRoutePrefix = 'admin.search-map' | 'user.intelligence-map' | 'public-map';

export function geographyApi(routePrefix: MapRoutePrefix): GeographyApi {
    return {
        roads: (level, id, cursor = 0) => route(`${routePrefix}.roads`, { level, id, cursor }),
        geography: (level, parent) => route(`${routePrefix}.geography`, { level, ...(parent ? { parent } : {}) }),
        selection: (level, id) => route(`${routePrefix}.selection`, { level, id }),
        search: (q) => route(`${routePrefix}.search`, { q }),
    };
}

export const ADMIN_GEOGRAPHY_API: GeographyApi = {
    roads: (level, id, cursor = 0) => route('admin.search-map.roads', { level, id, cursor }),
    geography: (level, parent) => route('admin.search-map.geography', { level, ...(parent ? { parent } : {}) }),
    selection: (level, id) => route('admin.search-map.selection', { level, id }),
    search: (q) => route('admin.search-map.search', { q }),
};

export interface PhilippineMapProps extends Partial<SearchMapProps> {
    /** Tailwind classes for the containing box; defaults to a 640px-high embedded map. */
    className?: string;
    fullScreen?: boolean;
    title?: string;
    /** Defaults to false so embedded maps do not change their host page's URL/history. */
    syncUrl?: boolean;
    initialTerrain?: boolean;
    initialGlobe?: boolean;
    minZoom?: number;
    /** Use a stable object when connecting to other authorized endpoints with the same response shapes. */
    api?: GeographyApi;
    onSelectionChange?: (selected: MapLocation | null, path: MapLocation[]) => void;
    /** Page-specific content below the selected location in the left explorer/mobile sheet. */
    selectionContent?: ReactNode;
    /** Geocoded resources rendered as clustered custom pins; never changes the camera. */
    resourcePins?: MapResourcePin[];
    resourceLoading?: boolean;
    resourceError?: string | null;
    onRetryResources?: () => void;
    /** Desktop width reserved for a host page's right information panel; ignored below 1100px map width. */
    rightPanelWidth?: number;
    /** Optional overlays, positioned relative to the map. */
    children?: ReactNode;
}

export const EMPTY_BOUNDARIES: Boundaries = { type: 'FeatureCollection', features: [] };
export interface RoadProperties {
    barangay_id?: number | null;
    barangay_length_m?: number | null;
    id: number;
    name: string;
    road_code: string | null;
    road_class: string;
    surface_type: string;
    road_condition: string;
    length_m: number | null;
    width_m: number | null;
    municipality_id: number;
    source: string | null;
    reference_date: string | null;
}
export type RoadCollection = FeatureCollection<LineString | MultiLineString, RoadProperties>;
export interface RoadResponse extends RoadCollection {
    meta: {
        scope: { key: string; level: GeographicLevel; id: number };
        status: 'available' | 'empty' | 'unavailable';
        message: string | null;
        next_cursor: number | null;
        omitted_geometries: number;
    };
}
export const EMPTY_ROADS: RoadCollection = { type: 'FeatureCollection', features: [] };
export const LEVEL_LABELS: Record<GeographicLevel, string> = { province: 'Province', municipality: 'City / Municipality', barangay: 'Barangay' };

export async function getGeography<T>(url: string, signal: AbortSignal): Promise<T> {
    const response = await fetch(url, { signal, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    if (!response.ok) {
        if (response.status === 401 || response.status === 419 || response.redirected)
            throw new Error('Your session has expired. Please sign in again.');
        if (response.status === 422) throw new Error('This location has no verified administrative parent link.');
        if (response.status === 429) throw new Error('Please pause for a moment before searching again.');
        throw new Error('We could not load these locations. Check your connection and try again.');
    }
    if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Your session has expired. Please sign in again.');
    return response.json() as Promise<T>;
}

export function boundaryBounds(boundary: Boundary | null): Bounds | null {
    if (!boundary) return null;
    const polygons = boundary.geometry.type === 'Polygon' ? [boundary.geometry.coordinates] : boundary.geometry.coordinates;
    let west = Infinity;
    let south = Infinity;
    let east = -Infinity;
    let north = -Infinity;
    for (const polygon of polygons) {
        for (const ring of polygon) {
            for (const [lng, lat] of ring) {
                west = Math.min(west, lng);
                south = Math.min(south, lat);
                east = Math.max(east, lng);
                north = Math.max(north, lat);
            }
        }
    }
    return Number.isFinite(west) && west < east && south < north ? [west, south, east, north] : null;
}

/** Camera fallback for verified point records when the selected location has no polygon or center. */
export function resourcePinBounds(pins: MapResourcePin[]): Bounds | null {
    let bounds: Bounds | null = null;
    for (const {
        coordinates: [x, y],
    } of pins) {
        if (!Number.isFinite(x) || !Number.isFinite(y)) continue;
        bounds = bounds ? [Math.min(bounds[0], x), Math.min(bounds[1], y), Math.max(bounds[2], x), Math.max(bounds[3], y)] : [x, y, x, y];
    }
    return bounds;
}
