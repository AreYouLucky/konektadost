import type { GeoFeatureCollection, MapMarkerItem, MunicipalityProperties, ProvinceProperties } from '@/types';
import L from 'leaflet';
import { useEffect, useRef } from 'react';
import { MapContainer, TileLayer, useMap } from 'react-leaflet';
import { RESOURCE_MARKERS } from './philippine-map/resource-marker-designs';

const TILE_URL = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
const ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

/** Initial view: the whole archipelago. */
const DEFAULT_CENTER: [number, number] = [12.8797, 121.774];

const PROVINCE_STYLE: L.PathOptions = {
    color: '#64748b',
    weight: 1,
    fillColor: '#cbd5e1',
    fillOpacity: 0.25,
};

const PROVINCE_SELECTED_STYLE: L.PathOptions = {
    color: '#0d9488',
    weight: 2.5,
    fillColor: '#14b8a6',
    fillOpacity: 0.4,
};

const CITY_STYLE: L.PathOptions = {
    color: '#0f766e',
    weight: 1,
    fillColor: '#5eead4',
    fillOpacity: 0.35,
};

const CITY_SELECTED_STYLE: L.PathOptions = {
    color: '#6d28d9',
    weight: 3,
    fillColor: '#a78bfa',
    fillOpacity: 0.75,
};

const HOVER_OVERRIDE: L.PathOptions = { weight: 3, fillOpacity: 0.6 };

type AnyFeatureCollection = GeoFeatureCollection<ProvinceProperties> | GeoFeatureCollection<MunicipalityProperties>;

export interface ServiceAreaMapProps {
    provinces: GeoFeatureCollection<ProvinceProperties>;
    selectedProvince: string | null;
    municipalities: GeoFeatureCollection<MunicipalityProperties> | null;
    selectedCity: string | null;
    markers?: MapMarkerItem[];
    onProvinceClick: (psgCode: string) => void;
    onCityClick: (psgCode: string) => void;
}

/**
 * Renders selectable province and municipality polygons.
 *
 * This module statically imports Leaflet, which requires a DOM. It must only
 * ever be loaded on the client, so the page lazy-loads it rather than
 * importing it directly.
 */
export default function ServiceAreaMap({
    provinces,
    selectedProvince,
    municipalities,
    selectedCity,
    markers = [],
    onProvinceClick,
    onCityClick,
}: ServiceAreaMapProps) {
    return (
        <MapContainer center={DEFAULT_CENTER} zoom={6} className="h-full w-full" scrollWheelZoom>
            <TileLayer url={TILE_URL} attribution={ATTRIBUTION} />

            {/* Provinces stay on the map as context while drilled into a province. */}
            <GeoJsonLayer
                data={provinces}
                styleFor={(p) => (p.psg_code === selectedProvince ? PROVINCE_SELECTED_STYLE : PROVINCE_STYLE)}
                onFeatureClick={(p) => onProvinceClick(p.psg_code)}
            />

            {municipalities && (
                <GeoJsonLayer
                    data={municipalities}
                    styleFor={(p) => (p.psg_code === selectedCity ? CITY_SELECTED_STYLE : CITY_STYLE)}
                    onFeatureClick={(p) => onCityClick(p.psg_code)}
                />
            )}

            <MarkersLayer markers={markers} />

            <FitBounds data={municipalities ?? provinces} maxZoom={11} />
            <InvalidateSizeOnMount />
        </MapContainer>
    );
}

/**
 * Adds a GeoJSON layer imperatively.
 *
 * react-leaflet removed its GeoJSON component in v4, and styling has to stay
 * in sync with the current selection, so the layer is rebuilt whenever the
 * data changes and mutated in place for hover.
 */
function GeoJsonLayer<P extends { psg_code: string }>({
    data,
    styleFor,
    onFeatureClick,
}: {
    data: GeoFeatureCollection<P>;
    styleFor: (properties: P) => L.PathOptions;
    onFeatureClick: (properties: P) => void;
}) {
    const map = useMap();

    // Held in refs so hover restyling always reads current callbacks without
    // forcing the whole layer to be torn down and rebuilt.
    const styleForRef = useRef(styleFor);
    const onFeatureClickRef = useRef(onFeatureClick);
    styleForRef.current = styleFor;
    onFeatureClickRef.current = onFeatureClick;

    useEffect(() => {
        const layer = L.geoJSON(data as unknown as GeoJSON.GeoJsonObject, {
            // MultiPolygon geometry (islands, exclaves) is handled natively:
            // every part of a feature is styled and clicked as one unit.
            style: (feature) => styleForRef.current(feature?.properties as P),
            onEachFeature: (feature, lyr) => {
                // onEachFeature receives the base Layer; polygon features
                // produce Path layers, which is what carries setStyle.
                const path = lyr as L.Path;

                path.on('mouseover', () => {
                    path.setStyle({ ...styleForRef.current(feature.properties as P), ...HOVER_OVERRIDE });
                });
                path.on('mouseout', () => {
                    path.setStyle(styleForRef.current(feature.properties as P));
                });
                lyr.on('click', (event) => {
                    L.DomEvent.stopPropagation(event);
                    onFeatureClickRef.current(feature.properties as P);
                });
            },
        });

        layer.addTo(map);

        return () => {
            layer.remove();
        };
    }, [data, map]);

    return null;
}

/** Frames the given collection in the viewport. */
function FitBounds({ data, maxZoom }: { data: AnyFeatureCollection; maxZoom: number }) {
    const map = useMap();

    useEffect(() => {
        // Bounds come from the layer's own geometry. The `bounds` column is
        // deliberately unused because it stores
        // [minLat, maxLat, minLng, maxLng] rather than the GeoJSON
        // [west, south, east, north] order Leaflet expects.
        const bounds = L.geoJSON(data as unknown as GeoJSON.GeoJsonObject).getBounds();

        if (bounds.isValid()) {
            map.flyToBounds(bounds, { padding: [30, 30], maxZoom, duration: 0.6 });
        }
    }, [data, map, maxZoom]);

    return null;
}

/** Leaflet mis-sizes itself when its container mounts inside a flex/grid box. */
function InvalidateSizeOnMount() {
    const map = useMap();

    useEffect(() => {
        const id = window.setTimeout(() => map.invalidateSize(), 150);
        return () => window.clearTimeout(id);
    }, [map]);

    return null;
}

function escapeHtml(value: string | null | undefined): string {
    if (!value) return '';
    return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function buildMarkerIcon(item: MapMarkerItem): L.DivIcon {
    if (item.category === 'dost_lab') {
        // DOST cyan hexagon with a Flask/Beaker icon.
        return L.divIcon({
            className: 'bg-transparent border-0',
            iconSize: [38, 40],
            iconAnchor: [19, 20],
            popupAnchor: [0, -20],
            html: `
                <div style="width:38px;height:40px;display:flex;align-items:center;justify-content:center;">
                    <svg viewBox="0 0 32 34" width="38" height="40" xmlns="http://www.w3.org/2000/svg">
                        <polygon points="16,1 30,9 30,25 16,33 2,25 2,9" fill="${RESOURCE_MARKERS.laboratory.color}" stroke="#ffffff" stroke-width="2" stroke-linejoin="round" />
                        <path d="M13 9h6M14 9v5l-4.5 7.5A1.5 1.5 0 0 0 10.8 24h10.4a1.5 1.5 0 0 0 1.3-2.5L18 14V9" fill="none" stroke="#ffffff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M11.5 20h9" stroke="#ffffff" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </div>
            `,
        });
    }

    if (item.category === 'dost_tech') {
        // DOST cyan diamond with a CPU / Gear icon.
        return L.divIcon({
            className: 'bg-transparent border-0',
            iconSize: [40, 40],
            iconAnchor: [20, 20],
            popupAnchor: [0, -20],
            html: `
                <div style="width:40px;height:40px;display:flex;align-items:center;justify-content:center;">
                    <svg viewBox="0 0 32 32" width="40" height="40" xmlns="http://www.w3.org/2000/svg">
                        <polygon points="16,1 31,16 16,31 1,16" fill="${RESOURCE_MARKERS.dost_tech.color}" stroke="#ffffff" stroke-width="2" stroke-linejoin="round" />
                        <rect x="11" y="11" width="10" height="10" rx="1.8" fill="none" stroke="#ffffff" stroke-width="1.8"/>
                        <rect x="14" y="14" width="4" height="4" fill="#bfdbfe"/>
                        <path d="M14 8v3M18 8v3M14 21v3M18 21v3M8 14h3M8 18h3M21 14h3M21 18h3" stroke="#ffffff" stroke-width="1.6" stroke-linecap="round"/>
                    </svg>
                </div>
            `,
        });
    }

    if (item.category === 'telecom') {
        // Triangle / Signal Tower badge in Orange with Radio waves
        return L.divIcon({
            className: 'bg-transparent border-0',
            iconSize: [32, 32],
            iconAnchor: [16, 16],
            popupAnchor: [0, -17],
            html: `
                <div style="width:32px;height:32px;display:flex;align-items:center;justify-content:center;">
                    <svg viewBox="0 0 28 28" width="32" height="32" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="14" cy="14" r="12" fill="#ea580c" stroke="#ffffff" stroke-width="2"/>
                        <circle cx="14" cy="11" r="1.8" fill="#ffffff"/>
                        <path d="M10.5 8.5a5 5 0 0 0 0 5M17.5 8.5a5 5 0 0 1 0 5M14 13v8M11 21h6" fill="none" stroke="#ffffff" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
            `,
        });
    }

    // Facilities: distinct shapes & icons by sector (health, safety, education)
    if (item.sector === 'health') {
        return L.divIcon({
            className: 'bg-transparent border-0',
            iconSize: [32, 32],
            iconAnchor: [16, 16],
            popupAnchor: [0, -17],
            html: `
                <div style="width:32px;height:32px;display:flex;align-items:center;justify-content:center;">
                    <svg viewBox="0 0 28 28" width="32" height="32" xmlns="http://www.w3.org/2000/svg">
                        <rect x="3" y="3" width="22" height="22" rx="6" fill="${RESOURCE_MARKERS.health.color}" stroke="#ffffff" stroke-width="2"/>
                        <path d="M14 8v12M8 14h12" stroke="#ffffff" stroke-width="2.6" stroke-linecap="round"/>
                    </svg>
                </div>
            `,
        });
    }

    if (item.sector === 'safety') {
        return L.divIcon({
            className: 'bg-transparent border-0',
            iconSize: [32, 35],
            iconAnchor: [16, 17.5],
            popupAnchor: [0, -17],
            html: `
                <div style="width:32px;height:35px;display:flex;align-items:center;justify-content:center;">
                    <svg viewBox="0 0 28 30" width="32" height="35" xmlns="http://www.w3.org/2000/svg">
                        <path d="M14 2L25 6.5v7.5c0 6.8-4.7 12.6-11 14.5C7.7 26.6 3 20.8 3 14V6.5L14 2z" fill="${RESOURCE_MARKERS.safety.color}" stroke="#ffffff" stroke-width="2" stroke-linejoin="round"/>
                        <path d="M14 9v6M14 19h.01" stroke="#ffffff" stroke-width="2.4" stroke-linecap="round"/>
                    </svg>
                </div>
            `,
        });
    }

    const education = item.sector === 'education';
    // Unknown/other facilities use the navy building icon.
    return L.divIcon({
        className: 'bg-transparent border-0',
        iconSize: [32, 32],
        iconAnchor: [16, 16],
        popupAnchor: [0, -17],
        html: `
            <div style="width:32px;height:32px;display:flex;align-items:center;justify-content:center;">
                <svg viewBox="0 0 28 28" width="32" height="32" xmlns="http://www.w3.org/2000/svg">
                    <rect x="3" y="3" width="22" height="22" rx="6" fill="${RESOURCE_MARKERS[education ? 'education' : 'facility'].color}" stroke="#ffffff" stroke-width="2"/>
                    ${education ? '<path d="M6 12l8-4 8 4-8 4-8-4z" fill="#ffffff"/><path d="M9 14.5v4c0 1.2 2.2 2.2 5 2.2s5-1 5-2.2v-4" fill="none" stroke="#ffffff" stroke-width="1.6" stroke-linecap="round"/>' : '<path d="M8 22V7h12v15M6 22h16M11 11h1m3 0h1m-5 4h1m3 0h1m-4 7v-4h4v4" fill="none" stroke="#ffffff" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>'}
                </svg>
            </div>
        `,
    });
}

function buildPopupHtml(item: MapMarkerItem): string {
    if (item.category === 'dost_lab') {
        const badge = item.code
            ? `<span style="background:#d1fae5;color:#065f46;padding:1px 6px;border-radius:4px;font-size:11px;font-family:monospace;">${escapeHtml(item.code)}</span>`
            : '';
        const services =
            item.services_count !== undefined
                ? `<div style="margin-top:4px;font-size:12px;color:#0f766e;font-weight:600;">${item.services_count} registered testing service(s)</div>`
                : '';
        const contact = item.contact ? `<div style="margin-top:4px;font-size:11px;color:#475569;">${escapeHtml(item.contact)}</div>` : '';

        return `
            <div style="min-width:200px;max-width:260px;line-height:1.35;">
                <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">
                    <span style="background:${RESOURCE_MARKERS.laboratory.color};color:#0b2454;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:600;text-transform:uppercase;">DOST Lab</span>
                    ${badge}
                </div>
                <div style="font-weight:600;font-size:13px;color:#0f172a;">${escapeHtml(item.title)}</div>
                ${item.subtitle ? `<div style="font-size:11px;color:#64748b;margin-top:2px;">${escapeHtml(item.subtitle)}</div>` : ''}
                ${services}
                ${contact}
            </div>
        `;
    }

    if (item.category === 'dost_tech') {
        const codeBadge = item.code
            ? `<span style="background:#dbeafe;color:#1d4ed8;padding:1px 6px;border-radius:4px;font-size:11px;font-family:monospace;">${escapeHtml(item.code)}</span>`
            : '';
        const meta = [item.agency, item.sector].filter(Boolean).map(escapeHtml).join(' · ');
        const cityLine = item.city
            ? `<div style="margin-top:4px;font-size:11px;color:#1e3a8a;font-weight:600;">Cluster: ${escapeHtml(item.city)}</div>`
            : '';
        const outcome = item.outcome
            ? `<div style="margin-top:4px;font-size:11px;color:#334155;"><strong>Outcome:</strong> ${escapeHtml(item.outcome)}</div>`
            : '';

        return `
            <div style="min-width:210px;max-width:270px;line-height:1.35;">
                <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">
                    <span style="background:${RESOURCE_MARKERS.dost_tech.color};color:#0b2454;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:600;text-transform:uppercase;">DOST Tech</span>
                    ${codeBadge}
                </div>
                <div style="font-weight:600;font-size:13px;color:#0f172a;">${escapeHtml(item.title)}</div>
                ${meta ? `<div style="font-size:11px;color:#64748b;margin-top:2px;">${meta}</div>` : ''}
                ${cityLine}
                ${outcome}
            </div>
        `;
    }

    if (item.category === 'telecom') {
        const meta = [item.operator, item.tower_company].filter(Boolean).map(escapeHtml).join(' · ');
        const tech = item.technologies
            ? `<div style="margin-top:4px;font-size:11px;color:#9a3412;font-weight:600;">${escapeHtml(item.technologies)}</div>`
            : '';

        return `
            <div style="min-width:180px;max-width:240px;line-height:1.35;">
                <div style="margin-bottom:4px;">
                    <span style="background:#ea580c;color:#fff;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:600;text-transform:uppercase;">Cell Site</span>
                </div>
                <div style="font-weight:600;font-size:13px;color:#0f172a;">${escapeHtml(item.title)}</div>
                ${meta ? `<div style="font-size:11px;color:#64748b;margin-top:2px;">${meta}</div>` : ''}
                ${item.subtitle ? `<div style="font-size:11px;color:#475569;margin-top:2px;">Brgy. ${escapeHtml(item.subtitle)}</div>` : ''}
                ${tech}
            </div>
        `;
    }

    const category = item.sector === 'health' || item.sector === 'education' || item.sector === 'safety' ? item.sector : 'facility';
    const sectorLabel = [item.sector, item.facility_type, item.level].filter(Boolean).map(escapeHtml).join(' · ');
    return `
        <div style="min-width:180px;max-width:240px;line-height:1.35;">
            <div style="margin-bottom:4px;">
                <span style="background:${RESOURCE_MARKERS[category].color};color:#fff;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:600;text-transform:uppercase;">Facility</span>
            </div>
            <div style="font-weight:600;font-size:13px;color:#0f172a;">${escapeHtml(item.title)}</div>
            ${sectorLabel ? `<div style="font-size:11px;color:#64748b;margin-top:2px;">${sectorLabel}</div>` : ''}
            ${item.subtitle ? `<div style="font-size:11px;color:#475569;margin-top:2px;">${escapeHtml(item.subtitle)}</div>` : ''}
        </div>
    `;
}

function MarkersLayer({ markers }: { markers: MapMarkerItem[] }) {
    const map = useMap();

    useEffect(() => {
        if (!markers.length) return;

        const group = L.layerGroup();

        markers.forEach((item) => {
            if (!Number.isFinite(item.lat) || !Number.isFinite(item.lng)) {
                return;
            }

            const marker = L.marker([item.lat, item.lng], {
                icon: buildMarkerIcon(item),
            });
            marker.bindPopup(buildPopupHtml(item), { maxWidth: 280 });
            marker.bindTooltip(`${item.code ? `[${item.code}] ` : ''}${item.title}`, {
                direction: 'top',
                offset: [0, item.category === 'dost_lab' || item.category === 'dost_tech' ? -22 : -18],
            });
            marker.addTo(group);
        });

        group.addTo(map);

        return () => {
            group.remove();
        };
    }, [markers, map]);

    return null;
}
