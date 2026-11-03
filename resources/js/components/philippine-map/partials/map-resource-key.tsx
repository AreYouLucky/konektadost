import { useId } from 'react';
import {
    ALL_MARKERS_VISIBLE,
    markerGlyphY,
    markerGroup,
    markerShape,
    RESOURCE_MARKERS,
    type MarkerGroup,
    type MarkerVisibility,
} from '../resource-marker-designs';
import type { MapLocation, MapResourcePin } from '../types';

export function ResourceMarkerSymbol({ category }: { category: MapResourcePin['category'] }) {
    return (
        <svg viewBox="0 0 40 48" className="h-6 w-5 shrink-0" aria-hidden="true">
            <path d={markerShape(category)} fill={RESOURCE_MARKERS[category].color} stroke="white" strokeWidth="2" />
            <g
                transform={`translate(10 ${markerGlyphY(category)}) scale(.85)`}
                fill="none"
                stroke="white"
                strokeWidth="1.8"
                strokeLinecap="round"
                strokeLinejoin="round"
            >
                {RESOURCE_MARKERS[category].paths.map((path, index) => (
                    <path key={index} d={path} />
                ))}
            </g>
        </svg>
    );
}

const groups: { key: MarkerGroup; category: MapResourcePin['category']; title: string }[] = [
    { key: 'laboratories', category: 'laboratory', title: 'DOST Laboratories' },
    { key: 'technologies', category: 'dost_tech', title: 'DOST Technologies' },
    { key: 'telecom', category: 'telecom', title: 'Cell Sites / Towers' },
    { key: 'facilities', category: 'facility', title: 'Local Facilities' },
];

export default function MapResourceKey({
    selected,
    hasBoundary,
    pins,
    visibility,
    onVisibilityChange,
    loading = false,
    error,
    onRetry,
}: {
    selected: MapLocation | null;
    hasBoundary: boolean;
    pins: MapResourcePin[];
    visibility: MarkerVisibility;
    onVisibilityChange: (value: MarkerVisibility) => void;
    loading?: boolean;
    error?: string | null;
    onRetry?: () => void;
}) {
    const id = useId();
    const counts: Record<MarkerGroup, number> = { laboratories: 0, technologies: 0, telecom: 0, facilities: 0 };
    for (const pin of pins) counts[markerGroup(pin.category)]++;
    const shown = pins.filter((pin) => visibility[markerGroup(pin.category)]).length;
    return (
        <section data-map-resource-key aria-labelledby={`${id}-title`} className="p-3 text-[#284d45]">
            <header className="mb-2 pr-6">
                <h2 id={`${id}-title`} className="text-xs font-semibold">
                    Map markers
                </h2>
                <p aria-live="polite" className="mt-0.5 text-[10px] text-[#738580]">
                    {loading ? 'Loading markers…' : `${shown.toLocaleString()} / ${pins.length.toLocaleString()} shown`} ·{' '}
                    {selected?.name ?? 'Philippines'}
                </p>
            </header>
            {error && (
                <p role="alert" className="mb-2 text-[10px] text-amber-800">
                    {error}{' '}
                    {onRetry && (
                        <button type="button" onClick={onRetry} className="font-semibold underline">
                            Retry
                        </button>
                    )}
                </p>
            )}
            <fieldset className="space-y-1">
                <legend className="sr-only">Choose visible marker categories</legend>
                {groups.map((group) => (
                    <label
                        key={group.key}
                        htmlFor={`${id}-${group.key}`}
                        className={`flex min-h-9 cursor-pointer items-center gap-2 rounded-lg border px-2 py-1 ${visibility[group.key] ? 'border-[#087dcc]/15 bg-[#eff6ff]' : 'border-transparent bg-white/50 text-[#738580]'}`}
                    >
                        <ResourceMarkerSymbol category={group.category} />
                        <span className="flex-1 text-[11px] font-medium">{group.title}</span>
                        <span className="text-[10px] tabular-nums">{counts[group.key].toLocaleString()}</span>
                        <input
                            id={`${id}-${group.key}`}
                            type="checkbox"
                            checked={visibility[group.key]}
                            onChange={(event) => onVisibilityChange({ ...visibility, [group.key]: event.target.checked })}
                            className="size-3.5 cursor-pointer accent-[#087dcc]"
                        />
                    </label>
                ))}
            </fieldset>
            <div className="mt-1 flex justify-between text-[10px]">
                <button
                    type="button"
                    onClick={() => onVisibilityChange({ ...ALL_MARKERS_VISIBLE })}
                    className="min-h-7 font-semibold text-[#087dcc] hover:underline"
                >
                    Show all
                </button>
                <button
                    type="button"
                    onClick={() => onVisibilityChange({ laboratories: false, technologies: false, telecom: false, facilities: false })}
                    className="min-h-7 text-[#087dcc] hover:underline"
                >
                    Hide all
                </button>
            </div>
            {selected && !hasBoundary && (
                <p className="mt-1 text-[10px] text-amber-800">Boundary unavailable. Only explicit barangay PSGC matches can be shown.</p>
            )}
        </section>
    );
}
