import { Popup, type GeoJSONSource, type Map as LibreMap, type MapLayerMouseEvent } from 'maplibre-gl';
import { useEffect, type RefObject } from 'react';
import type { RoadCollection } from './types';

export function useRoadLines(mapRef: RefObject<LibreMap | null>, ready: boolean, data: RoadCollection) {
    useEffect(() => {
        const map = mapRef.current;
        if (!map || !ready) return;
        const source = map.getSource('selected-roads') as GeoJSONSource | undefined;
        if (source) source.setData(data);
        else {
            map.addSource('selected-roads', { type: 'geojson', data, tolerance: 0.5 });
            const before = map.getLayer('explorer-selected-glow') ? 'explorer-selected-glow' : undefined;
            map.addLayer(
                {
                    id: 'selected-road-halo',
                    type: 'line',
                    source: 'selected-roads',
                    layout: { 'line-join': 'round', 'line-cap': 'round' },
                    paint: {
                        'line-color': '#ffffff',
                        'line-opacity': 0.85,
                        'line-width': ['interpolate', ['linear'], ['zoom'], 5, 2.5, 12, 5, 17, 8],
                    },
                },
                before,
            );
            map.addLayer(
                {
                    id: 'selected-road-lines',
                    type: 'line',
                    source: 'selected-roads',
                    layout: { 'line-join': 'round', 'line-cap': 'round' },
                    paint: {
                        'line-color': '#f59e0b',
                        'line-opacity': 0.95,
                        'line-width': ['interpolate', ['linear'], ['zoom'], 5, 1, 12, 2.5, 17, 5],
                    },
                },
                before,
            );
        }
        let popup: Popup | null = null;
        const click = (event: MapLayerMouseEvent) => {
            const road = event.features?.[0]?.properties;
            if (!road) return;
            popup?.remove();
            const body = document.createElement('div');
            body.className = 'text-xs text-slate-700';
            const title = document.createElement('strong');
            title.textContent = String(road.name || road.road_code || 'Unnamed road');
            body.append(title);
            for (const [label, value] of [
                ['Class', road.road_class],
                ['Surface', road.surface_type],
                ['Condition', road.road_condition],
                ...(road.barangay_id != null
                    ? [
                          [
                              'Length in selected barangay',
                              road.barangay_length_m == null ? 'Unknown' : `${Number(road.barangay_length_m).toLocaleString()} m`,
                          ],
                      ]
                    : []),
                ['Full road segment length', road.length_m == null ? 'Unknown' : `${Number(road.length_m).toLocaleString()} m`],
                ['Width', road.width_m == null ? 'Unknown' : `${road.width_m} m`],
                ['Source', road.source],
                ['Reference date', road.reference_date],
            ]) {
                if (value == null || value === '') continue;
                const row = document.createElement('p');
                row.className = 'mt-1';
                row.textContent = `${label}: ${value}`;
                body.append(row);
            }
            popup = new Popup({ maxWidth: 'min(300px, calc(100vw - 32px))', closeButton: true })
                .setLngLat(event.lngLat)
                .setDOMContent(body)
                .addTo(map);
        };
        const enter = () => {
            map.getCanvas().style.cursor = 'pointer';
        };
        const leave = () => {
            map.getCanvas().style.cursor = '';
        };
        map.on('click', 'selected-road-lines', click);
        map.on('mouseenter', 'selected-road-lines', enter);
        map.on('mouseleave', 'selected-road-lines', leave);
        const dismiss = () => popup?.remove();
        map.on('movestart', dismiss);
        return () => {
            popup?.remove();
            map.off('click', 'selected-road-lines', click);
            map.off('mouseenter', 'selected-road-lines', enter);
            map.off('mouseleave', 'selected-road-lines', leave);
            map.off('movestart', dismiss);
        };
    }, [mapRef, ready, data]);
}
