import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

/** Properties attached to every province feature. */
export interface ProvinceProperties {
    psg_code: string;
    name: string;
    variant_name: string | null;
    english_type: string | null;
    cc_code: string | null;
    center_lat: number | null;
    center_lng: number | null;
}

/** Properties attached to every municipality feature. */
export interface MunicipalityProperties {
    psg_code: string;
    name: string;
    type: string | null;
    province_name: string | null;
    center_lat: number | null;
    center_lng: number | null;
}

export interface GeoFeature<P> {
    type: 'Feature';
    geometry: GeoGeometry;
    properties: P;
}

export interface GeoGeometry {
    type: 'Polygon' | 'MultiPolygon';
    coordinates: number[][][][] | number[][][] | unknown;
}

export interface GeoFeatureCollection<P> {
    type: 'FeatureCollection';
    features: GeoFeature<P>[];
}

export interface MapMarkerItem {
    id: string;
    category: 'dost_lab' | 'dost_tech' | 'facility' | 'telecom';
    title: string;
    code?: string | null;
    subtitle?: string | null;
    agency?: string | null;
    sector?: string | null;
    status?: string | null;
    city?: string | null;
    deployment_clusters?: string | null;
    mechanism?: string | null;
    outcome?: string | null;
    services_count?: number;
    website?: string | null;
    contact?: string | null;
    facility_type?: string | null;
    level?: string | null;
    operator?: string | null;
    tower_company?: string | null;
    technologies?: string | null;
    lat: number;
    lng: number;
}

export interface MapMarkersPayload {
    dost_labs: MapMarkerItem[];
    dost_techs: MapMarkerItem[];
    facilities: MapMarkerItem[];
    telecom_sites: MapMarkerItem[];
}

export interface LocationMapProps {
    provinces: GeoFeatureCollection<ProvinceProperties>;
    selectedProvince: string | null;
    municipalities: GeoFeatureCollection<MunicipalityProperties> | null;
    selectedCity: string | null;
    mapMarkers?: MapMarkersPayload;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}
