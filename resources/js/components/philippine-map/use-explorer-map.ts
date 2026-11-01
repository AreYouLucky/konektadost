import {
    AttributionControl,
    Map as LibreMap,
    ScaleControl,
    setWorkerUrl,
    type GeoJSONSource,
    type LayerSpecification,
    type MapLayerMouseEvent,
} from 'maplibre-gl';
import workerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?url';
import { useCallback, useEffect, useRef, useState } from 'react';

import {
    boundaryBounds,
    EMPTY_BOUNDARIES,
    resourcePinBounds,
    type Boundaries,
    type Boundary,
    type MapLocation,
    type MapResourcePin,
    type SatelliteConfig,
} from './types';
import { useResourcePins } from './use-resource-pins';

const BRIGHT = 'https://tiles.openfreemap.org/styles/bright';
const DEM = 'https://tiles.mapterhorn.com/tilejson.json';
// MapLibre 6 ships a separate module worker. Let Vite publish it with a hashed URL.
setWorkerUrl(workerUrl);
const COUNTRY: [[number, number], [number, number]] = [
    [114.0, 4.1],
    [127.5, 21.5],
];
export type Basemap = 'streets' | 'satellite' | 'hybrid';
type LayerVisibility = NonNullable<NonNullable<LayerSpecification['layout']>['visibility']>;

interface BasemapLayer {
    original: LayerVisibility;
    hybridOverlay: boolean;
    mapLine: boolean;
}

function layerVisibility(layer: BasemapLayer, basemap: Basemap, showMapLines: boolean): LayerVisibility {
    if (layer.mapLine) return showMapLines ? layer.original : 'none';
    return basemap === 'streets' || (basemap === 'hybrid' && layer.hybridOverlay) ? layer.original : 'none';
}

interface ExplorerMapOptions {
    resourcePins: MapResourcePin[];
    rightPanelWidth: number;
    boundaries: Boundaries;

    boundary: Boundary | null;
    selected: MapLocation | null;
    hovered: string | null;
    expanded: boolean;
    revision: number;
    satellite: SatelliteConfig | null;
    initialTerrain: boolean;
    initialGlobe: boolean;
    minZoom: number;
    onSelect: (location: MapLocation) => void;
    onHover: (key: string | null) => void;
}

export function useExplorerMap(options: ExplorerMapOptions) {
    const container = useRef<HTMLDivElement>(null);
    const mapRef = useRef<LibreMap | null>(null);
    const current = useRef(options);
    current.current = options;
    const [ready, setReady] = useState(false);
    useResourcePins(mapRef, ready, options.resourcePins);
    const [terrain, setTerrain] = useState(options.initialTerrain);
    const [globe, setGlobe] = useState(options.initialGlobe);
    const [basemap, setBasemap] = useState<Basemap>(options.satellite ? 'streets' : 'hybrid');
    const [showMapLines, setShowMapLines] = useState(false);
    const [bearing, setBearing] = useState(0);
    const [warning, setWarning] = useState<string | null>(null);
    const [fatal, setFatal] = useState<string | null>(null);
    const preferences = useRef({ terrain, globe, basemap, showMapLines });
    preferences.current = { terrain, globe, basemap, showMapLines };
    const visibility = useRef(new globalThis.Map<string, BasemapLayer>());
    const reducedMotion = useRef(false);
    const fallbackFramed = useRef<string | null>(null);
    const padding = useCallback(() => {
        const element = container.current;
        const width = element?.clientWidth ?? window.innerWidth;
        const height = element?.clientHeight ?? window.innerHeight;
        return width < 768
            ? { top: 90, right: 28, bottom: current.current.expanded ? Math.min(height * 0.55 + 120, height - 200) : 250, left: 28 }
            : {
                  top: 100,
                  right: width >= 1100 && current.current.rightPanelWidth > 0 ? current.current.rightPanelWidth + 48 : 64,
                  bottom: 128,
                  left: width < 1024 ? 370 : 422,
              };
    }, []);

    const countryPadding = useCallback(() => {
        const width = container.current?.clientWidth ?? window.innerWidth;
        // Center the national view horizontally; only drilldowns avoid the left explorer.
        return width < 768 ? { top: 90, right: 20, bottom: 190, left: 20 } : { top: 100, right: 64, bottom: 128, left: 64 };
    }, []);

    const frameSelection = useCallback(
        (immediate = false) => {
            const map = mapRef.current;
            if (!map || !map.getLayer('explorer-fill')) return;
            const { selected, boundary, resourcePins } = current.current;
            const areaBounds = boundaryBounds(boundary) ?? selected?.bounds;
            const fallbackBounds = selected && !areaBounds && !selected.center ? resourcePinBounds(resourcePins) : null;
            const bounds = areaBounds ?? fallbackBounds;
            if (fallbackBounds && selected) fallbackFramed.current = selected.key;
            const pitch =
                preferences.current.terrain && selected ? (selected.level === 'province' ? 30 : selected.level === 'municipality' ? 43 : 52) : 0;
            const duration = immediate || reducedMotion.current ? 0 : 1100;
            const camera = { padding: padding(), pitch, bearing: 0, duration, essential: false, maxZoom: selected?.level === 'barangay' ? 16 : 13 };
            map.stop();
            if (bounds)
                map.fitBounds(
                    [
                        [bounds[0], bounds[1]],
                        [bounds[2], bounds[3]],
                    ],
                    camera,
                );
            else if (selected?.center) map.flyTo({ ...camera, center: selected.center, zoom: selected.level === 'barangay' ? 14 : 10 });
            else if (!selected) map.fitBounds(COUNTRY, { ...camera, padding: countryPadding(), pitch: 0, maxZoom: 6 });
        },
        [padding, countryPadding],
    );

    useEffect(() => {
        if (!container.current) return;
        const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
        reducedMotion.current = motion.matches;
        const onMotion = () => {
            reducedMotion.current = motion.matches;
        };
        motion.addEventListener('change', onMotion);
        let map: LibreMap;
        try {
            map = new LibreMap({
                container: container.current,
                style: BRIGHT,
                center: [121, 12.5],
                zoom: 4.7,
                bounds: COUNTRY,
                fitBoundsOptions: { padding: countryPadding(), maxZoom: 6 },
                pitch: 0,
                maxPitch: 70,
                minZoom: current.current.minZoom,
                maxZoom: 19,
                maxBounds: [
                    [90, -15],
                    [150, 35],
                ],
                renderWorldCopies: false,
                attributionControl: false,
                canvasContextAttributes: { antialias: true },
            });
            mapRef.current = map;
        } catch {
            setFatal('This browser could not start the 3D map. Please enable hardware acceleration or try a WebGL-capable browser.');
            motion.removeEventListener('change', onMotion);
            return;
        }

        map.addControl(new AttributionControl({ compact: true }), 'bottom-right');
        map.addControl(new ScaleControl({ maxWidth: 100, unit: 'metric' }), 'bottom-left');

        const install = () => {
            const { satellite } = current.current;
            map.setProjection({ type: preferences.current.globe ? 'globe' : 'mercator' });
            map.setSky({
                'atmosphere-blend': ['interpolate', ['linear'], ['zoom'], 0, 1, 3, 0],
            });
            if (!map.getSource('explorer-dem')) {
                map.addSource('explorer-dem', {
                    type: 'raster-dem',
                    url: DEM,
                    tileSize: 512,
                });
                map.addSource('explorer-hillshade-dem', { type: 'raster-dem', url: DEM, tileSize: 512 });
            }
            map.setTerrain(preferences.current.terrain ? { source: 'explorer-dem', exaggeration: 1 } : null);
            const layers = map.getStyle().layers ?? [];
            const firstRoad = layers.find((layer) => 'source-layer' in layer && layer['source-layer'] === 'transportation')?.id;
            const beforeLabels = layers.find((layer) => layer.type === 'symbol')?.id;
            if (!map.getLayer('explorer-hillshade'))
                map.addLayer(
                    {
                        id: 'explorer-hillshade',
                        type: 'hillshade',
                        source: 'explorer-hillshade-dem',
                        paint: { 'hillshade-exaggeration': 0.22 },
                    },
                    firstRoad ?? beforeLabels,
                );

            // Same composition as the official hybrid example: raster below transport and labels.
            if (satellite && !map.getSource('explorer-satellite')) {
                map.addSource('explorer-satellite', {
                    type: 'raster',
                    tiles: [satellite.url],
                    tileSize: satellite.tileSize,
                    maxzoom: satellite.maxzoom,
                    attribution: satellite.attribution,
                });
                map.addLayer(
                    {
                        id: 'explorer-satellite',
                        type: 'raster',
                        source: 'explorer-satellite',
                        layout: { visibility: preferences.current.basemap === 'streets' ? 'none' : 'visible' },
                        paint: { 'raster-fade-duration': 350 },
                    },
                    firstRoad ?? beforeLabels,
                );
            }
            visibility.current.clear();
            for (const layer of layers) {
                const hybridOverlay =
                    layer.type === 'symbol' || ('source-layer' in layer && String(layer['source-layer']).startsWith('transportation'));
                const settings: BasemapLayer = {
                    original: layer.layout?.visibility ?? 'visible',
                    hybridOverlay,
                    mapLine: layer.id === 'ferry' || ('source-layer' in layer && layer['source-layer'] === 'boundary'),
                };
                visibility.current.set(layer.id, settings);
                map.setLayoutProperty(
                    layer.id,
                    'visibility',
                    layerVisibility(settings, preferences.current.basemap, preferences.current.showMapLines),
                );
            }
            map.addSource('explorer-context', { type: 'geojson', data: current.current.boundaries, promoteId: 'key' });
            map.addSource('explorer-selection', { type: 'geojson', data: EMPTY_BOUNDARIES });
            map.addLayer({
                id: 'explorer-fill',
                type: 'fill',
                source: 'explorer-context',
                paint: {
                    'fill-color': '#39925c',
                    'fill-opacity': ['case', ['boolean', ['feature-state', 'hover'], false], 0.2, 0],
                },
            });
            map.addLayer({
                id: 'explorer-lines',
                type: 'line',
                source: 'explorer-context',
                paint: {
                    'line-color': ['case', ['boolean', ['feature-state', 'hover'], false], '#04977d', '#4c877e'],
                    'line-width': ['case', ['boolean', ['feature-state', 'hover'], false], 1.8, 0.65],
                    'line-opacity': ['case', ['boolean', ['feature-state', 'hover'], false], 0.85, preferences.current.showMapLines ? 0.65 : 0],
                },
            });
            map.addLayer({
                id: 'explorer-selected-fill',
                type: 'fill',
                source: 'explorer-selection',
                filter: ['==', '$type', 'Polygon'],
                paint: { 'fill-color': '#16a36d', 'fill-opacity': 0.16 },
            });
            map.addLayer({
                id: 'explorer-selected-glow',
                type: 'line',
                source: 'explorer-selection',
                filter: ['==', '$type', 'Polygon'],
                paint: {
                    'line-color': '#ffffff',
                    'line-width': ['interpolate', ['linear'], ['zoom'], 5, 5, 12, 8],
                    'line-opacity': 0.9,
                },
            });
            map.addLayer({
                id: 'explorer-selected-line',
                type: 'line',
                source: 'explorer-selection',
                filter: ['==', '$type', 'Polygon'],
                layout: { 'line-join': 'round', 'line-cap': 'round' },
                paint: {
                    'line-color': '#087849',
                    'line-width': ['interpolate', ['linear'], ['zoom'], 5, 2.5, 12, 4],
                    'line-opacity': 1,
                },
            });
            map.addLayer({
                id: 'explorer-selected-point',
                type: 'circle',
                source: 'explorer-selection',
                filter: ['==', '$type', 'Point'],
                paint: { 'circle-color': '#078c74', 'circle-radius': 7, 'circle-stroke-color': '#fff', 'circle-stroke-width': 3 },
            });
            setReady(true);
            frameSelection(true);
        };

        const select = (event: MapLayerMouseEvent) => {
            const resourceLayers = ['resource-points', 'resource-clusters', 'telecom-points', 'telecom-clusters', 'dost-resource-points'].filter(
                (layer) => map.getLayer(layer),
            );
            if (resourceLayers.length && map.queryRenderedFeatures(event.point, { layers: resourceLayers }).length) return;
            const key = event.features?.[0]?.properties?.key;
            const location = current.current.boundaries.features.find((feature) => feature.properties.key === key)?.properties;
            if (location) current.current.onSelect(location);
        };
        const hover = (event: MapLayerMouseEvent) => {
            map.getCanvas().style.cursor = 'pointer';
            current.current.onHover(event.features?.[0]?.properties?.key ?? null);
        };
        const leave = () => {
            map.getCanvas().style.cursor = '';
            current.current.onHover(null);
        };
        const rotate = () => setBearing(map.getBearing());
        const onError = (event: { error: { message: string }; sourceId?: string }) => {
            const message = event.error.message ?? '';
            if (
                event.sourceId === 'explorer-satellite' ||
                (current.current.satellite && message.includes(current.current.satellite.url.split('/').slice(0, 3).join('/')))
            ) {
                setBasemap('streets');
                setWarning('Satellite imagery is unavailable. The street map remains available.');
            } else if (event.sourceId?.includes('dem') || message.includes('mapterhorn')) {
                map.setTerrain(null);
                setTerrain(false);
                setWarning('Elevation tiles are unavailable. You can continue exploring in 2D.');
            } else {
                setWarning('Some map tiles could not load. Check your connection or retry the basemap.');
            }
        };
        map.on('style.load', install);
        map.on('click', 'explorer-fill', select);
        map.on('mousemove', 'explorer-fill', hover);
        map.on('mouseleave', 'explorer-fill', leave);
        map.on('rotateend', rotate);
        map.on('error', onError);
        const resize = new ResizeObserver(() => {
            map.resize();
            frameSelection(true);
        });
        resize.observe(container.current);
        return () => {
            resize.disconnect();
            motion.removeEventListener('change', onMotion);
            map.off('style.load', install);
            map.off('click', 'explorer-fill', select);
            map.off('mousemove', 'explorer-fill', hover);
            map.off('mouseleave', 'explorer-fill', leave);
            map.off('rotateend', rotate);
            map.off('error', onError);
            map.remove();
            mapRef.current = null;
        };
    }, [frameSelection, countryPadding]);

    useEffect(() => {
        mapRef.current?.setMinZoom(options.minZoom);
    }, [options.minZoom]);

    useEffect(() => {
        const map = mapRef.current;
        if (!ready || !map) return;
        (map.getSource('explorer-context') as GeoJSONSource | undefined)?.setData(options.boundaries);
    }, [options.boundaries, ready]);

    useEffect(() => {
        const map = mapRef.current;
        if (!ready || !map) return;
        const feature =
            options.boundary ??
            (options.selected?.center
                ? {
                      type: 'Feature' as const,
                      properties: options.selected,
                      geometry: { type: 'Point' as const, coordinates: options.selected.center },
                  }
                : null);
        (map.getSource('explorer-selection') as GeoJSONSource | undefined)?.setData({
            type: 'FeatureCollection',
            features: feature ? [feature] : [],
        });
    }, [options.boundary, options.selected, ready]);

    useEffect(() => {
        const map = mapRef.current;
        if (!ready || !map) return;
        map.removeFeatureState({ source: 'explorer-context' });
        if (options.hovered) map.setFeatureState({ source: 'explorer-context', id: options.hovered }, { hover: true });
    }, [options.hovered, options.boundaries, ready]);

    useEffect(() => {
        if (ready) frameSelection();
    }, [options.revision, options.expanded, options.rightPanelWidth, ready, terrain, frameSelection]);

    useEffect(() => {
        if (
            ready &&
            options.selected &&
            !options.boundary &&
            !options.selected.bounds &&
            !options.selected.center &&
            options.resourcePins.length > 0 &&
            fallbackFramed.current !== options.selected.key
        )
            frameSelection();
    }, [ready, options.selected, options.boundary, options.resourcePins, frameSelection]);

    useEffect(() => {
        const map = mapRef.current;
        if (!ready || !map) return;
        map.setTerrain(terrain ? { source: 'explorer-dem', exaggeration: 1 } : null);
        map.setLayoutProperty('explorer-hillshade', 'visibility', terrain && basemap === 'streets' ? 'visible' : 'none');
    }, [terrain, ready, basemap]);

    useEffect(() => {
        if (ready) mapRef.current?.setProjection({ type: globe ? 'globe' : 'mercator' });
    }, [globe, ready]);

    useEffect(() => {
        const map = mapRef.current;
        if (!ready || !map) return;
        if (map.getLayer('explorer-satellite')) map.setLayoutProperty('explorer-satellite', 'visibility', basemap === 'streets' ? 'none' : 'visible');

        map.setPaintProperty('explorer-selected-line', 'line-color', basemap === 'streets' ? '#087849' : '#85f4b5');
        map.setPaintProperty('explorer-selected-glow', 'line-color', basemap === 'streets' ? '#ffffff' : '#073b2c');
        map.setPaintProperty('explorer-selected-fill', 'fill-opacity', basemap === 'streets' ? 0.16 : 0.2);
        map.setPaintProperty('explorer-lines', 'line-color', [
            'case',
            ['boolean', ['feature-state', 'hover'], false],
            basemap === 'streets' ? '#04977d' : '#b4f6df',
            basemap === 'streets' ? '#4c877e' : '#91bfb2',
        ]);
        map.setPaintProperty('explorer-lines', 'line-opacity', [
            'case',
            ['boolean', ['feature-state', 'hover'], false],
            0.85,
            showMapLines ? 0.65 : 0,
        ]);
        for (const [id, settings] of visibility.current) {
            if (map.getLayer(id)) map.setLayoutProperty(id, 'visibility', layerVisibility(settings, basemap, showMapLines));
        }
    }, [basemap, ready, showMapLines]);

    return {
        container,
        ready,
        terrain,
        globe,
        basemap,
        showMapLines,
        bearing,
        warning,
        fatal,
        toggleTerrain: () => setTerrain((value) => !value),
        toggleProjection: () => setGlobe((value) => !value),
        toggleMapLines: () => setShowMapLines((value) => !value),
        setBasemap,
        zoom: (direction: number) =>
            mapRef.current?.zoomTo((mapRef.current?.getZoom() ?? 5) + direction, { duration: reducedMotion.current ? 0 : 250 }),
        resetBearing: () => mapRef.current?.easeTo({ bearing: 0, duration: reducedMotion.current ? 0 : 500 }),
        retry: () => {
            setWarning(null);
            setReady(false);
            mapRef.current?.setStyle(BRIGHT, { diff: false });
        },
        dismissWarning: () => setWarning(null),
    };
}
