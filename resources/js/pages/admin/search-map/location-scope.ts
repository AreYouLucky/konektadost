import type { GeographicLevel } from '@/components/philippine-map';

export interface LocationScope {
    key: string;
    level: GeographicLevel;
    id: number;
    name: string;
    path: { id: number; level: GeographicLevel; name: string }[];
}

export const LOCATION_LABELS: Record<GeographicLevel, string> = {
    province: 'Province',
    municipality: 'City / Municipality',
    barangay: 'Barangay',
};
