import { cn } from '@/lib/utils';
import { ArrowLeft, ArrowRight, Check, ChevronDown, ChevronRight, Globe2, LoaderCircle, MapPin, RotateCcw, X } from 'lucide-react';
import 'maplibre-gl/dist/maplibre-gl.css';
import { useCallback, useEffect, useId, useMemo, useRef, useState, type CSSProperties } from 'react';
import { scopeMapPins } from './marker-scope';
import MapResourceKey from './partials/map-resource-key';
import MapToolbar from './partials/map-toolbar';
import SearchBar from './partials/search-bar';
import './philippine-map.css';
import { markerGroup, type MarkerVisibility } from './resource-marker-designs';
import { ADMIN_GEOGRAPHY_API, LEVEL_LABELS, type MapLocation, type MapResourcePin, type PhilippineMapProps } from './types';
import { useExplorerMap } from './use-explorer-map';
import { useGeography } from './use-geography';

const EMPTY_LOCATIONS: MapLocation[] = [];
const EMPTY_PINS: MapResourcePin[] = [];

export default function PhilippineMap({
    provinces = EMPTY_LOCATIONS,
    geographyError = null,
    satellite = null,
    className,
    fullScreen = false,
    title = 'Intelligence Map',
    syncUrl = false,
    initialTerrain = false,
    initialGlobe = false,
    minZoom = 5,
    api = ADMIN_GEOGRAPHY_API,
    onSelectionChange,
    selectionContent,
    resourcePins = EMPTY_PINS,
    resourceLoading = false,
    resourceError = null,
    onRetryResources,
    rightPanelWidth = 0,
    children,
}: PhilippineMapProps) {
    const geography = useGeography(provinces, geographyError, api, syncUrl);
    const navigate = geography.navigate;
    const bodyId = useId();
    const selectionCallback = useRef(onSelectionChange);
    selectionCallback.current = onSelectionChange;
    const [hovered, setHovered] = useState<string | null>(null);
    const [expanded, setExpanded] = useState(false);
    const [markerVisibility, setMarkerVisibility] = useState<MarkerVisibility>({
        laboratories: true,
        technologies: true,
        telecom: false,
        facilities: false,
    });
    const selected = geography.path.at(-1) ?? null;
    const scopedPins = useMemo(() => scopeMapPins(resourcePins, selected, geography.boundary), [resourcePins, selected, geography.boundary]);
    const visiblePins = useMemo(() => scopedPins.filter((pin) => markerVisibility[markerGroup(pin.category)]), [scopedPins, markerVisibility]);
    const hasSelectionContent = Boolean(selectionContent);
    useEffect(() => {
        if (hasSelectionContent) setExpanded(true);
    }, [hasSelectionContent, selected?.key]);
    useEffect(() => {
        selectionCallback.current?.(selected, geography.path);
    }, [selected, geography.path]);
    const parent = geography.path.findLast((location) => location.level !== 'barangay');
    const select = useCallback(
        (location: MapLocation) => {
            setHovered(null);
            void navigate(location);
        },
        [navigate],
    );
    const map = useExplorerMap({
        resourcePins: visiblePins,
        rightPanelWidth,
        boundaries: geography.boundaries,

        boundary: geography.boundary,
        selected,
        hovered,
        expanded,
        revision: geography.revision,
        satellite,
        initialTerrain,
        initialGlobe,
        minZoom,
        onSelect: select,
        onHover: setHovered,
    });
    const reset = () => {
        setHovered(null);
        void geography.navigate(null);
    };
    const back = () => {
        if (selected?.level === 'barangay') select(geography.path[1]);
        else if (parent?.level === 'municipality') select(geography.path[0]);
        else reset();
    };
    const section = !parent ? 'Provinces' : parent.level === 'province' ? 'Cities & municipalities' : 'Barangays';
    const context = hovered ? geography.locations.find((location) => location.key === hovered) : null;
    return (
        <div
            className={cn(
                '[container-type:size] relative isolate h-[640px] w-full overflow-hidden [container-name:philippine-map]',
                fullScreen && 'fixed inset-0 h-dvh',
                className,
            )}
            data-philippine-map
        >
            <section className="philippine-explorer" aria-label={title} data-expanded={expanded} data-basemap={map.basemap}>
                <div ref={map.container} className="explorer-map" aria-label="Interactive map of the Philippines" />
                <section className={`location-explorer ${expanded ? 'is-expanded' : ''}`} aria-label="Location explorer">
                    <button
                        type="button"
                        className="sheet-handle"
                        aria-label={expanded ? 'Collapse location explorer' : 'Expand location explorer'}
                        aria-expanded={expanded}
                        aria-controls={bodyId}
                        onClick={() => setExpanded(!expanded)}
                    >
                        <span />
                    </button>
                    <div className="explorer-heading">
                        <span className="explorer-heading-icon">
                            <Globe2 size={19} aria-hidden="true" />
                        </span>
                        <div>
                            <h1>{title}</h1>
                            <p>Find a province, city or barangay</p>
                        </div>
                    </div>
                    <SearchBar
                        api={api}
                        onSelect={(location) => {
                            select(location);
                            setExpanded(false);
                        }}
                    />
                    <nav className="explorer-breadcrumb" aria-label="Geographic breadcrumb">
                        <button type="button" onClick={reset} aria-current={geography.path.length === 0 ? 'location' : undefined}>
                            <Globe2 size={13} />
                            <span>Philippines</span>
                        </button>
                        {geography.path.map((location, index) => (
                            <span className="breadcrumb-segment" key={location.key}>
                                <ChevronRight size={11} aria-hidden="true" />
                                <button
                                    type="button"
                                    onClick={() => select(location)}
                                    title={location.name}
                                    aria-current={index === geography.path.length - 1 ? 'location' : undefined}
                                >
                                    {location.name}
                                </button>
                            </span>
                        ))}
                    </nav>
                    <div className="mobile-explorer-summary">
                        <button type="button" aria-expanded={expanded} aria-controls={bodyId} onClick={() => setExpanded(!expanded)}>
                            <span>
                                {section}
                                <small>{geography.loading ? 'Loading locations…' : `${geography.locations.length} places to explore`}</small>
                            </span>
                            <ChevronDown size={18} />
                        </button>
                    </div>
                    <div id={bodyId} className="explorer-body" aria-busy={geography.loading}>
                        {selected && (
                            <section className="selected-location-card" aria-label="Selected location information" aria-live="polite">
                                <span className="selected-location-icon">
                                    <MapPin size={20} />
                                </span>
                                <div className="selected-location-copy">
                                    <span className="eyebrow">{LEVEL_LABELS[selected.level]}</span>
                                    <h2>{selected.name}</h2>
                                    <p>
                                        {geography.path
                                            .slice(0, -1)
                                            .map((location) => location.name)
                                            .join(' / ') || 'Philippines'}
                                    </p>
                                </div>
                                <button type="button" aria-label="Return to parent location" className="selected-location-close" onClick={back}>
                                    <X size={15} />
                                </button>
                            </section>
                        )}
                        {selectionContent && <div className="explorer-selection-content">{selectionContent}</div>}
                        <div className="explorer-section-title">
                            <div>
                                {parent && (
                                    <button type="button" className="explorer-back" aria-label="Back one geographic level" onClick={back}>
                                        <ArrowLeft size={15} />
                                    </button>
                                )}
                                <h2>{section}</h2>
                            </div>
                            <span>{geography.locations.length}</span>
                        </div>
                        {geography.error && (
                            <div role="alert" className="geography-error">
                                <MapPin size={20} />
                                <p>{geography.error}</p>
                                <button type="button" onClick={() => void geography.retry()}>
                                    <RotateCcw size={13} />
                                    Try again
                                </button>
                            </div>
                        )}
                        <div className="location-list" key={parent?.key ?? 'philippines'}>
                            {geography.locations.map((location, index) => (
                                <button
                                    type="button"
                                    key={location.key}
                                    className={`location-row ${selected?.key === location.key ? 'is-selected' : ''} ${hovered === location.key ? 'is-hovered' : ''}`}
                                    style={{ '--row-index': Math.min(index, 12) } as CSSProperties}
                                    disabled={geography.loading}
                                    onMouseEnter={() => setHovered(location.key)}
                                    onMouseLeave={() => setHovered(null)}
                                    onFocus={() => setHovered(location.key)}
                                    onBlur={() => setHovered(null)}
                                    onClick={() => select(location)}
                                    aria-pressed={selected?.key === location.key}
                                >
                                    <span className="location-row-symbol">
                                        {selected?.key === location.key ? <Check size={15} /> : <MapPin size={15} strokeWidth={1.5} />}
                                    </span>
                                    <span className="location-row-name">{location.name}</span>
                                    <ArrowRight className="location-row-arrow" size={15} />
                                </button>
                            ))}
                            {!geography.loading && !geography.error && geography.locations.length === 0 && (
                                <div className="empty-geography">
                                    <MapPin size={25} />
                                    <strong>No linked locations yet</strong>
                                    <p>This dataset has no child records for the selected place. You can still explore its map.</p>
                                </div>
                            )}
                        </div>
                    </div>
                    {geography.loading && (
                        <div className="geography-progress" role="status">
                            <LoaderCircle size={13} className="explorer-spin" />
                            Finding your perspective…
                        </div>
                    )}
                </section>
                <MapToolbar
                    map={map}
                    loading={geography.loading}
                    satelliteAvailable={satellite !== null}
                    onReset={reset}
                    markerControls={
                        resourcePins !== EMPTY_PINS ? (
                            <MapResourceKey
                                selected={selected}
                                hasBoundary={geography.boundary !== null}
                                pins={scopedPins}
                                visibility={markerVisibility}
                                onVisibilityChange={setMarkerVisibility}
                                loading={resourceLoading}
                                error={resourceError}
                                onRetry={onRetryResources}
                            />
                        ) : undefined
                    }
                />

                {context && (
                    <div className="map-hover-label" aria-hidden="true">
                        <MapPin size={12} />
                        {context.name}
                    </div>
                )}
                {!map.ready && !map.fatal && !map.warning && (
                    <div className="map-loading" role="status">
                        <LoaderCircle size={17} className="explorer-spin" />
                        Preparing your map…
                    </div>
                )}
                {(map.warning || map.fatal) && (
                    <div className="map-notice" role="alert">
                        <p>{map.fatal ?? map.warning}</p>
                        {!map.fatal && (
                            <>
                                <button type="button" onClick={map.retry}>
                                    Retry map
                                </button>
                                <button type="button" onClick={map.dismissWarning} aria-label="Dismiss map notice">
                                    <X size={14} />
                                </button>
                            </>
                        )}
                    </div>
                )}
                <div className="map-coordinate-label" aria-hidden="true">
                    <span className="coordinate-cross">+</span>PHILIPPINES
                    <span className="coordinate-divider" />
                    {map.globe ? 'GLOBE' : 'MERCATOR'}
                    <span className="coordinate-divider" />
                    {map.terrain ? '3D TERRAIN' : '2D VIEW'}
                </div>
                {children}
            </section>
        </div>
    );
}
