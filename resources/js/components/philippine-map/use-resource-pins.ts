import { Popup, type GeoJSONSource, type Map as LibreMap, type MapLayerMouseEvent } from 'maplibre-gl';
import { useEffect, type RefObject } from 'react';
import { markerGlyphY, markerShape, RESOURCE_MARKERS } from './resource-marker-designs';
import type { MapResourcePin } from './types';

const PIN_SCALE = 0.85;

function pinImage(category: MapResourcePin['category']): ImageData | null {
    const canvas = document.createElement('canvas');
    canvas.width = 80;
    canvas.height = 96;
    const context = canvas.getContext('2d');
    if (!context) return null;
    context.scale(2, 2);
    context.fillStyle = RESOURCE_MARKERS[category].color;
    context.strokeStyle = '#fff';
    context.lineWidth = 2;
    const shape = markerShape(category);
    context.fill(new Path2D(shape));
    context.stroke(new Path2D(shape));
    context.translate(10, markerGlyphY(category));
    context.scale(0.85, 0.85);
    context.lineWidth = 1.8;
    context.lineCap = 'round';
    context.lineJoin = 'round';
    for (const path of RESOURCE_MARKERS[category].paths) context.stroke(new Path2D(path));
    return context.getImageData(0, 0, canvas.width, canvas.height);
}

export function useResourcePins(mapRef: RefObject<LibreMap | null>, ready: boolean, pins: MapResourcePin[]) {
    useEffect(() => {
        const map = mapRef.current;
        if (!ready || !map) return;
        let disposed = false;
        let popup: Popup | null = null;
        for (const category of Object.keys(RESOURCE_MARKERS) as MapResourcePin['category'][]) {
            const image = pinImage(category);
            if (!image) continue;
            if (map.hasImage(`resource-${category}`)) map.updateImage(`resource-${category}`, image);
            else map.addImage(`resource-${category}`, image, { pixelRatio: 2 });
        }
        const dataFor = (group: 'facility' | 'telecom' | 'dost') => ({
            type: 'FeatureCollection' as const,
            features: pins
                .filter(
                    (pin) =>
                        (pin.category === 'laboratory' || pin.category === 'dost_tech'
                            ? 'dost'
                            : pin.category === 'telecom'
                              ? 'telecom'
                              : 'facility') === group,
                )
                .map((pin) => ({
                    type: 'Feature' as const,
                    id: pin.key,
                    properties: { ...pin, coordinates: undefined },
                    geometry: { type: 'Point' as const, coordinates: pin.coordinates },
                })),
        });
        for (const group of [
            { name: 'facility' as const, source: 'province-resources', prefix: 'resource', icon: 'facility', color: RESOURCE_MARKERS.facility.color },
            { name: 'telecom' as const, source: 'telecom-resources', prefix: 'telecom', icon: 'telecom', color: RESOURCE_MARKERS.telecom.color },
        ]) {
            const data = dataFor(group.name);
            const source = map.getSource(group.source) as GeoJSONSource | undefined;
            if (source) {
                source.setData(data);
                map.setPaintProperty(`${group.prefix}-clusters`, 'circle-color', group.color);
                continue;
            }
            map.addSource(group.source, { type: 'geojson', data, cluster: true, clusterRadius: 42, clusterMaxZoom: 13 });
            map.addLayer({
                id: `${group.prefix}-clusters`,
                type: 'circle',
                source: group.source,
                filter: ['has', 'point_count'],
                paint: {
                    'circle-color': group.color,
                    'circle-radius': ['step', ['get', 'point_count'], 22, 100, 25, 1000, 29],
                    'circle-stroke-color': '#fff',
                    'circle-stroke-width': 2,
                },
            });
            map.addLayer({
                id: `${group.prefix}-cluster-count`,
                type: 'symbol',
                source: group.source,
                filter: ['has', 'point_count'],
                layout: {
                    'icon-image': `resource-${group.icon}`,
                    'icon-size': 0.5,
                    'icon-offset': [0, -16],
                    'icon-allow-overlap': true,
                    'text-field': ['get', 'point_count_abbreviated'],
                    'text-font': ['Noto Sans Regular'],
                    'text-size': 12,
                    'text-offset': [0, 0.8],
                    'text-allow-overlap': true,
                },
                paint: { 'text-color': '#fff' },
            });
            map.addLayer({
                id: `${group.prefix}-points`,
                type: 'symbol',
                source: group.source,
                filter: ['!', ['has', 'point_count']],
                layout: {
                    'icon-image': ['concat', 'resource-', ['get', 'category']],
                    'icon-size': PIN_SCALE,
                    'icon-anchor': group.name === 'telecom' ? 'center' : 'bottom',
                    'icon-allow-overlap': true,
                },
            });
        }
        // Keep the small DOST inventories visible above facility clusters at province/city scales.
        const dostSource = map.getSource('dost-resources') as GeoJSONSource | undefined;
        if (dostSource) dostSource.setData(dataFor('dost'));
        else {
            map.addSource('dost-resources', { type: 'geojson', data: dataFor('dost') });
            map.addLayer({
                id: 'dost-resource-points',
                type: 'symbol',
                source: 'dost-resources',
                layout: {
                    'icon-image': ['concat', 'resource-', ['get', 'category']],
                    'icon-size': PIN_SCALE,
                    'icon-anchor': 'center',
                    'icon-allow-overlap': true,
                    'icon-ignore-placement': true,
                    'text-field': ['get', 'name'],
                    'text-font': ['Noto Sans Regular'],
                    'text-size': 11,
                    'text-anchor': 'top',
                    'text-offset': [0, 1.8],
                    'text-max-width': 18,
                    'text-optional': true,
                },
                paint: { 'text-color': '#163e32', 'text-halo-color': '#ffffff', 'text-halo-width': 2 },
            });
        }
        const show = (event: MapLayerMouseEvent) => {
            const feature = event.features?.[0];
            const pin = pins.find((item) => item.key === feature?.properties?.key);
            if (!pin) return;
            popup?.remove();
            const content = document.createElement('div');
            content.className = 'resource-popup';
            const isDost = pin.category === 'laboratory' || pin.category === 'dost_tech';
            const collocated = pins.filter(
                (item) => Math.abs(item.coordinates[0] - pin.coordinates[0]) < 1e-7 && Math.abs(item.coordinates[1] - pin.coordinates[1]) < 1e-7,
            );
            if (collocated.length > 1) {
                const heading = document.createElement('h3');
                heading.textContent = `${collocated.length} records at this map location`;
                content.append(heading);
            }
            for (const item of collocated) {
                const section = document.createElement('section');
                section.className = 'resource-popup-record';
                for (const [tag, text] of [
                    ['h3', item.name],
                    [
                        'p',
                        item.category === 'laboratory'
                            ? 'DOST / OneLab Laboratory · Recorded coordinates'
                            : item.category === 'dost_tech'
                              ? 'DOST Technology · Catalog location match'
                              : `${RESOURCE_MARKERS[item.category].label} · Recorded coordinates`,
                    ],
                    ['p', item.detail],
                    ['p', item.municipality],
                    ['p', item.address],
                    ...(item.details ?? []).map((detail) => ['p', `${detail.label}: ${detail.value}`]),
                    ['small', item.source ? `Source: ${item.source}` : null],
                ]) {
                    if (!text) continue;
                    const element = document.createElement(tag!);
                    element.textContent = text;
                    section.append(element);
                }
                content.append(section);
            }
            popup = new Popup({ offset: [0, Math.round((isDost || pin.category === 'telecom' ? -22 : -36) * PIN_SCALE)], maxWidth: '320px' })
                .setLngLat(pin.coordinates)
                .setDOMContent(content)
                .addTo(map);
        };
        const expand = (event: MapLayerMouseEvent) => {
            const feature = event.features?.[0];
            if (!feature || feature.geometry.type !== 'Point') return;
            // Only the top visible resource handles the click when clusters and pins overlap.
            const top = map.queryRenderedFeatures(event.point, {
                layers: ['dost-resource-points', 'telecom-points', 'resource-points', 'telecom-clusters', 'resource-clusters'],
            })[0];
            if (!top || top.layer.id !== feature.layer.id || top.properties.cluster_id !== feature.properties.cluster_id) return;
            const center = feature.geometry.coordinates as [number, number];
            void (map.getSource(feature.source) as GeoJSONSource)
                .getClusterExpansionZoom(Number(feature.properties.cluster_id))
                .then((zoom) => {
                    if (!disposed && mapRef.current === map) map.easeTo({ center, zoom });
                })
                .catch(() => {
                    /* A selection change may replace the source while expansion resolves. */
                });
        };
        const pointer = () => {
            map.getCanvas().style.cursor = 'pointer';
        };
        const leave = () => {
            map.getCanvas().style.cursor = '';
        };
        map.on('click', 'resource-points', show);
        map.on('click', 'telecom-points', show);
        map.on('click', 'dost-resource-points', show);
        map.on('click', 'resource-clusters', expand);
        map.on('click', 'telecom-clusters', expand);
        for (const layer of ['resource-points', 'resource-clusters', 'telecom-points', 'telecom-clusters', 'dost-resource-points']) {
            map.on('mouseenter', layer, pointer);
            map.on('mouseleave', layer, leave);
        }
        return () => {
            disposed = true;
            popup?.remove();
            map.off('click', 'resource-points', show);
            map.off('click', 'telecom-points', show);
            map.off('click', 'dost-resource-points', show);
            map.off('click', 'resource-clusters', expand);
            map.off('click', 'telecom-clusters', expand);
            for (const layer of ['resource-points', 'resource-clusters', 'telecom-points', 'telecom-clusters', 'dost-resource-points']) {
                map.off('mouseenter', layer, pointer);
                map.off('mouseleave', layer, leave);
            }
        };
    }, [mapRef, pins, ready]);
}
