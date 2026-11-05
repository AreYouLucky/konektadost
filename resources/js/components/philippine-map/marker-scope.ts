import type { MultiPolygon, Polygon, Position } from 'geojson';
import { boundaryBounds, type Boundary, type MapLocation, type MapResourcePin } from './types';

/** Boundary points are included; polygon holes stay outside the selected area. */
function ringContains(point: [number, number], ring: Position[]): 'inside' | 'outside' | 'boundary' {
    const [x, y] = point;
    let inside = false;
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
        const [xi, yi] = ring[i];
        const [xj, yj] = ring[j];
        const cross = (x - xi) * (yj - yi) - (y - yi) * (xj - xi);
        if (
            Math.abs(cross) < 1e-10 &&
            x >= Math.min(xi, xj) - 1e-10 &&
            x <= Math.max(xi, xj) + 1e-10 &&
            y >= Math.min(yi, yj) - 1e-10 &&
            y <= Math.max(yi, yj) + 1e-10
        )
            return 'boundary';
        if (yi > y !== yj > y && x < ((xj - xi) * (y - yi)) / (yj - yi) + xi) inside = !inside;
    }
    return inside ? 'inside' : 'outside';
}

export function geometryContainsPoint(geometry: Polygon | MultiPolygon, point: [number, number]): boolean {
    const polygons = geometry.type === 'Polygon' ? [geometry.coordinates] : geometry.coordinates;
    return polygons.some((polygon) => {
        if (!polygon.length) return false;
        const outer = ringContains(point, polygon[0]);
        if (outer === 'outside') return false;
        if (outer === 'boundary') return true;
        return !polygon.slice(1).some((hole) => ringContains(point, hole) !== 'outside');
    });
}

export function scopeMapPins(pins: MapResourcePin[], selected: MapLocation | null, boundary: Boundary | null): MapResourcePin[] {
    if (!selected) return pins;
    const bounds = boundaryBounds(boundary);
    if (!boundary || !bounds) {
        return selected.level === 'barangay' && /^\d{10}$/.test(selected.code ?? '') ? pins.filter((pin) => pin.barangay_psgc === selected.code) : [];
    }
    return pins.filter((pin) => {
        const [x, y] = pin.coordinates;
        return x >= bounds[0] && y >= bounds[1] && x <= bounds[2] && y <= bounds[3] && geometryContainsPoint(boundary.geometry, pin.coordinates);
    });
}
