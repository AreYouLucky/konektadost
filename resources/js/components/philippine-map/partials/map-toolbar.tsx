import { cn } from '@/lib/utils';
import { Check, Globe2, Layers3, LocateFixed, MapPin, Minus, Mountain, Navigation, Plus, X } from 'lucide-react';
import { useId, useState, type ReactNode } from 'react';
import type { Basemap, useExplorerMap } from '../use-explorer-map';

interface MapToolbarProps {
    map: Pick<
        ReturnType<typeof useExplorerMap>,
        | 'ready'
        | 'terrain'
        | 'globe'
        | 'basemap'
        | 'bearing'
        | 'showMapLines'
        | 'zoom'
        | 'resetBearing'
        | 'toggleTerrain'
        | 'toggleProjection'
        | 'toggleMapLines'
        | 'setBasemap'
    >;
    loading: boolean;
    satelliteAvailable: boolean;
    onReset: () => void;
    markerControls?: ReactNode;
}

function MapTool({
    children,
    label,
    active,
    disabled,
    expanded,
    controls,
    onClick,
}: {
    children: ReactNode;
    label: string;
    active?: boolean;
    disabled?: boolean;
    expanded?: boolean;
    controls?: string;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            // Shared toolbar button: edit size, colors and interaction states here.
            className="group/map-tool relative flex h-[42px] w-[38px] shrink-0 cursor-pointer items-center justify-center rounded-[9px] border-0 bg-transparent text-[#123575] transition-[background-color,color,transform] duration-180 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#123575] enabled:hover:bg-white enabled:hover:text-blue-900 enabled:active:scale-[0.94] disabled:cursor-wait disabled:opacity-45 aria-pressed:bg-[#123575] aria-pressed:text-white motion-reduce:transition-none @[768px]/philippine-map:size-11"
            aria-label={label}
            title={label}
            aria-pressed={active}
            aria-expanded={expanded}
            aria-controls={controls}
            disabled={disabled}
            onClick={onClick}
        >
            {children}
            <span
                className="pointer-events-none absolute bottom-[calc(100%+17px)] left-1/2 hidden -translate-x-1/2 translate-y-[5px] rounded-md bg-[#123575] px-2.5 py-[7px] text-[10px] whitespace-nowrap text-[#f1fbf0] opacity-0 transition-[opacity,transform] duration-180 group-hover/map-tool:translate-y-0 group-hover/map-tool:opacity-100 group-focus-visible/map-tool:translate-y-0 group-focus-visible/map-tool:opacity-100 motion-reduce:transition-none @[768px]/philippine-map:block"
                aria-hidden="true"
            >
                {label}
            </span>
        </button>
    );
}

export default function MapToolbar({ map, loading, satelliteAvailable, onReset, markerControls }: MapToolbarProps) {
    const appearanceId = useId();
    const mapLinesDescriptionId = useId();
    const [layersOpen, setLayersOpen] = useState(false);
    const [markersOpen, setMarkersOpen] = useState(false);
    const markerId = useId();
    const modes: { value: Basemap; title: string; description: string }[] = [
        { value: 'streets', title: 'Explorer', description: 'Streets & terrain' },
        ...(satelliteAvailable
            ? [
                  { value: 'hybrid' as const, title: 'Hybrid', description: 'Imagery & labels' },
                  { value: 'satellite' as const, title: 'Satellite', description: 'Uncluttered imagery' },
              ]
            : []),
    ];

    return (
        <aside
            // Dock: edit position, spacing, background and border here. @[768px]/philippine-map: styles apply when the map is at least 768px wide.
            className="absolute bottom-[max(34px,calc(env(safe-area-inset-bottom)+16px))] left-1/2 z-20 flex max-w-[calc(100cqw-16px)] -translate-x-1/2 items-center gap-1 rounded-2xl border border-[#fffffff0] bg-white p-[5px] shadow-[0_8px_32px_#1a4a3b20] backdrop-blur-[18px] @[768px]/philippine-map:max-w-[calc(100cqw-24px)] @[768px]/philippine-map:gap-2 @[768px]/philippine-map:rounded-[19px] @[768px]/philippine-map:p-[7px]"
            data-map-toolbar
            role="toolbar"
            aria-label="Map controls"
            aria-orientation="horizontal"
            onKeyDown={(event) => {
                if (event.key === 'Escape') {
                    setLayersOpen(false);
                    setMarkersOpen(false);
                }
            }}
        >
            <div className="flex shrink-0 items-center">
                <MapTool label="Zoom in" disabled={!map.ready} onClick={() => map.zoom(1)}>
                    <Plus size={19} />
                </MapTool>
                <MapTool label="Zoom out" disabled={!map.ready} onClick={() => map.zoom(-1)}>
                    <Minus size={19} />
                </MapTool>
                <span className="mx-0.5 block h-5 w-px bg-[#dfe9da] @[768px]/philippine-map:mx-1" aria-hidden="true" />
                <MapTool label="Reset bearing to north" disabled={!map.ready} onClick={map.resetBearing}>
                    <Navigation size={18} style={{ transform: `rotate(${-map.bearing}deg)` }} />
                </MapTool>
            </div>
            <div className="flex shrink-0 items-center border-l border-[#dfe9da] pl-1 @[768px]/philippine-map:pl-2">
                <MapTool label="Return to the Philippines" disabled={loading} onClick={onReset}>
                    <LocateFixed size={20} />
                </MapTool>
                <MapTool
                    label={map.terrain ? 'Switch to 2D map' : 'Enable 3D terrain'}
                    active={map.terrain}
                    disabled={!map.ready}
                    onClick={map.toggleTerrain}
                >
                    <Mountain size={20} />
                    <span className="absolute right-[5px] bottom-1 rounded-[2px] bg-[#eff6ff] px-0.5 py-px text-[6px] leading-none font-bold">
                        {map.terrain ? '3D' : '2D'}
                    </span>
                </MapTool>
                <MapTool
                    label={map.globe ? 'Switch to flat projection' : 'Switch to globe projection'}
                    active={map.globe}
                    disabled={!map.ready}
                    onClick={map.toggleProjection}
                >
                    <Globe2 size={20} />
                </MapTool>
            </div>
            {markerControls && (
                <div className="relative border-l border-[#dfe9da] pl-1 @[768px]/philippine-map:pl-2">
                    <MapTool
                        label="Map markers and legend"
                        active={markersOpen}
                        expanded={markersOpen}
                        controls={markerId}
                        onClick={() => {
                            setMarkersOpen(!markersOpen);
                            setLayersOpen(false);
                        }}
                    >
                        <MapPin size={20} />
                    </MapTool>
                    {markersOpen && (
                        <div
                            id={markerId}
                            role="group"
                            aria-label="Marker visibility and legend"
                            className="absolute right-0 bottom-[calc(100%+18px)] max-h-[calc(100cqh-130px)] w-[min(275px,calc(100cqw-24px))] overflow-y-auto overscroll-contain rounded-2xl border border-white bg-[#eff6ff]/98 shadow-[0_12px_38px_#1f4d3a20] backdrop-blur-xl"
                        >
                            <button
                                type="button"
                                aria-label="Close marker legend"
                                onClick={() => setMarkersOpen(false)}
                                className="absolute top-2 right-2 grid size-6 place-items-center rounded-md text-[#738580] hover:bg-[#eff6ff] focus-visible:outline-2 focus-visible:outline-[#123575]"
                            >
                                <X size={13} />
                            </button>
                            {markerControls}
                        </div>
                    )}
                </div>
            )}
            <div className="relative border-l border-[#dfe9da] pl-1 @[768px]/philippine-map:pl-2">
                <MapTool
                    label="Map appearance"
                    active={layersOpen}
                    expanded={layersOpen}
                    controls={appearanceId}
                    disabled={!map.ready}
                    onClick={() => {
                        setLayersOpen(!layersOpen);
                        setMarkersOpen(false);
                    }}
                >
                    <Layers3 size={20} />
                </MapTool>
                {layersOpen && (
                    <div
                        // Appearance menu opens above the dock on every screen size.
                        className="animate-in fade-in slide-in-from-bottom-1 absolute right-0 bottom-[calc(100%+18px)] max-h-[calc(100cqh-120px)] w-[220px] overflow-y-auto overscroll-contain rounded-[17px] border border-white bg-[#eff6fff7] p-[15px] shadow-[0_12px_38px_#1f4d3a20] backdrop-blur-[18px] duration-200 motion-reduce:animate-none @[768px]/philippine-map:bottom-[calc(100%+20px)] @[768px]/philippine-map:w-[245px]"
                        id={appearanceId}
                        role="group"
                        aria-label="Basemap appearance"
                    >
                        <div className="mb-3 flex items-center justify-between text-[11px] font-semibold">
                            <span>Map appearance</span>
                            <button
                                type="button"
                                className="grid cursor-pointer place-items-center border-0 bg-transparent p-1 text-[#7b9079] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#123575]"
                                onClick={() => setLayersOpen(false)}
                                aria-label="Close map appearance"
                            >
                                <X size={14} />
                            </button>
                        </div>
                        {modes.map((mode) => (
                            <button
                                type="button"
                                key={mode.value}
                                className="my-[3px] flex w-full cursor-pointer items-center gap-3 rounded-[10px] border border-transparent bg-transparent p-[9px] text-left text-[#1e3a8a] hover:bg-[#eff6ff] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#123575] aria-pressed:border-[#bfdbfe] aria-pressed:bg-[#dbeafe]"
                                onClick={() => map.setBasemap(mode.value)}
                                aria-pressed={map.basemap === mode.value}
                            >
                                <span
                                    className={cn(
                                        'grid h-9 w-[39px] shrink-0 place-items-center rounded-[7px]',
                                        mode.value === 'streets'
                                            ? 'bg-[linear-gradient(145deg,#cbd8af_30%,#bdd8cb_31%,#bdd8cb_48%,#e4e7cc_49%)] text-[#799e78]'
                                            : 'bg-[linear-gradient(145deg,#60724d,#34544a_50%,#6f8155)] text-[#c0d4a5]',
                                    )}
                                >
                                    <Layers3 size={20} />
                                </span>
                                <span className="flex flex-1 flex-col gap-[3px]">
                                    <strong className="text-[11px] font-[550]">{mode.title}</strong>
                                    <small className="text-[9px] text-[#869b7f]">{mode.description}</small>
                                </span>
                                {map.basemap === mode.value && <Check size={15} />}
                            </button>
                        ))}
                        <button
                            type="button"
                            role="switch"
                            aria-label="Map lines"
                            aria-describedby={mapLinesDescriptionId}
                            aria-checked={map.showMapLines}
                            disabled={!map.ready}
                            onClick={map.toggleMapLines}
                            className="group/lines mt-3 flex min-h-11 w-full cursor-pointer items-center justify-between gap-3 border-0 border-t border-[#2b564a1a] bg-transparent pt-3 text-left focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#123575] disabled:cursor-wait disabled:opacity-45"
                        >
                            <span className="flex flex-col gap-[3px]">
                                <strong className="text-[11px] font-semibold text-[#1e3a8a]">Map lines</strong>
                                <small id={mapLinesDescriptionId} className="text-[9px] text-[#869b7f]">
                                    Ferry routes &amp; boundaries
                                </small>
                            </span>
                            <span
                                aria-hidden="true"
                                className="flex h-[18px] w-8 shrink-0 items-center rounded-full bg-[#cbd5e1] p-0.5 transition-colors group-aria-checked/lines:bg-[#123575] motion-reduce:transition-none"
                            >
                                <span className="size-3.5 rounded-full bg-white shadow-sm transition-transform group-aria-checked/lines:translate-x-3.5 motion-reduce:transition-none" />
                            </span>
                        </button>
                        <div className="mt-[13px] flex items-center gap-[7px] text-[10px] text-[#809475]">
                            <Mountain size={13} />
                            {map.terrain ? 'Real elevation · 1× terrain' : 'Top-down · 2D map'}
                        </div>
                    </div>
                )}
            </div>
        </aside>
    );
}
