import type { MapResourcePin } from './types';

export type MarkerGroup = 'laboratories' | 'technologies' | 'telecom' | 'facilities';
export type MarkerVisibility = Record<MarkerGroup, boolean>;
export const ALL_MARKERS_VISIBLE: MarkerVisibility = { laboratories: true, technologies: true, telecom: true, facilities: true };

export function markerGroup(category: MapResourcePin['category']): MarkerGroup {
    if (category === 'laboratory') return 'laboratories';
    if (category === 'dost_tech') return 'technologies';
    if (category === 'telecom') return 'telecom';
    return 'facilities';
}

/** Shared by the canvas markers and the SVG legend so every symbol stays consistent. */
export const RESOURCE_MARKERS: Record<MapResourcePin['category'], { label: string; color: string; paths: string[] }> = {
    dost_tech: { label: 'DOST Technologies', color: '#00aeef', paths: ['M7 7h10v10H7ZM10 3v4m4-4v4m-4 10v4m4-4v4M3 10h4m-4 4h4m10-4h4m-4 4h4'] },
    laboratory: { label: 'DOST Laboratories', color: '#00aeef', paths: ['M9 3h6M10 3v7L4 20q-1 2 2 2h12q3 0 2-2l-6-10V3M7 16h10'] },
    telecom: {
        label: 'Cell sites',
        color: '#ea580c',
        paths: ['M12 9v13M7 22l5-9 5 9M9 18h6M8 6a6 6 0 0 0 0 8M16 6a6 6 0 0 1 0 8M5 3a10 10 0 0 0 0 14M19 3a10 10 0 0 1 0 14'],
    },
    health: { label: 'Health facilities', color: '#dc4660', paths: ['M10 3h4v7h7v4h-7v7h-4v-7H3v-4h7Z'] },
    education: {
        label: 'Education facilities',
        color: '#287ac5',
        paths: ['M2 5c4-2 7-2 10 0v16c-3-2-6-2-10 0Z', 'M22 5c-4-2-7-2-10 0v16c3-2 6-2 10 0Z'],
    },
    safety: { label: 'Safety facilities', color: '#c17a13', paths: ['M12 2 3 6v6c0 5 6 9 9 10 3-1 9-5 9-10V6Z', 'm8 12 3 3 5-6'] },
    facility: { label: 'Other facilities', color: '#1e3a8a', paths: ['M4 22V3h16v19M2 22h20M8 7h1m6 0h1M8 11h1m6 0h1M10 22v-6h4v6'] },
};

export function markerShape(category: MapResourcePin['category']): string {
    if (category === 'laboratory') return 'M20 4 36 13 36 33 20 42 4 33 4 13Z';
    if (category === 'dost_tech') return 'M20 4 39 23 20 42 1 23Z';
    if (category === 'telecom') return 'M20 3 39 43H1Z';
    return 'M20 46C17 40 3 30 3 20a17 17 0 0 1 34 0c0 10-14 20-17 26Z';
}

export function markerGlyphY(category: MapResourcePin['category']): number {
    return category === 'telecom' ? 17 : category === 'laboratory' || category === 'dost_tech' ? 13 : 9;
}
