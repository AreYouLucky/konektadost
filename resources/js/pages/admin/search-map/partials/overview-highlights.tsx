import { Cpu, Layers3, LoaderCircle, MapPin, Wheat } from 'lucide-react';
import { agricultureIcon, agricultureNumber } from '../agriculture-presentation';
import { count } from '../resource-presentation';
import type { useProvinceAgriculture } from '../use-province-agriculture';
import type { ProvinceResourceData } from '../use-province-resources';

export function TechnologyHighlight({ data }: { data: ProvinceResourceData }) {
    const { technologies } = data;
    const available = technologies.status === 'available';
    const sectors = [...new Set(technologies.records.map((item) => item.sector?.trim()).filter((sector): sector is string => !!sector))];
    return (
        <section aria-label="DOST technology highlight" className="rounded-xl border border-blue-100 bg-white p-4">
            <div className="flex items-center gap-2.5">
                <span className="grid size-8 shrink-0 place-items-center rounded-lg bg-blue-50 text-[#123575]">
                    <Cpu size={18} aria-hidden="true" />
                </span>
                <div>
                    <h3 className="text-sm font-semibold text-[#123575]">DOST technologies</h3>
                    <p className="mt-0.5 text-[11px] text-slate-500">Catalog matches for this location</p>
                </div>
            </div>
            {available ? (
                <>
                    <dl className="mt-4 grid grid-cols-2 gap-3 rounded-lg bg-blue-50/60 p-3">
                        <div className="flex flex-col">
                            <dt className="order-last mt-1 text-[11px] text-slate-600">Catalog matches</dt>
                            <dd className="text-2xl font-semibold tracking-tight text-[#123575] tabular-nums">
                                {technologies.count === null ? '—' : count.format(technologies.count)}
                            </dd>
                        </div>
                        <div className="flex flex-col border-l border-blue-100 pl-3">
                            <dt className="order-last mt-1 flex items-center gap-1 text-[11px] text-slate-600">
                                <MapPin size={11} aria-hidden="true" /> City reference pins
                            </dt>
                            <dd className="text-2xl font-semibold tracking-tight text-[#123575] tabular-nums">
                                {count.format(technologies.pinned_count)}
                            </dd>
                        </div>
                    </dl>
                    {sectors.length > 0 && (
                        <details className="mt-3 border-t border-slate-100 pt-3">
                            <summary className="cursor-pointer text-[11px] font-medium text-[#123575]">
                                <span className="inline-flex items-center gap-1.5">
                                    <Layers3 size={13} aria-hidden="true" /> Catalog sectors ({count.format(sectors.length)})
                                </span>
                            </summary>
                            <ul className="mt-2 space-y-1.5 pl-4 text-[11px] leading-relaxed text-slate-600">
                                {sectors.map((sector) => (
                                    <li key={sector} className="list-disc">
                                        {sector}
                                    </li>
                                ))}
                            </ul>
                        </details>
                    )}
                    <p className="mt-3 text-[10px] leading-relaxed text-slate-500">
                        {technologies.count === 0
                            ? 'No catalog location matches recorded. This does not confirm the absence of deployments.'
                            : 'City reference pins are catalog location references, not exact deployment sites.'}
                    </p>
                </>
            ) : (
                <p className="mt-3 text-xs text-slate-500">The technology catalog is currently unavailable.</p>
            )}
        </section>
    );
}

export function AgricultureHighlight({ agriculture }: { agriculture: ReturnType<typeof useProvinceAgriculture> }) {
    const { data, loading, error, retry } = agriculture;
    return (
        <section aria-label="Agriculture overview" className="rounded-xl border border-slate-200 bg-white p-4">
            <div className="flex items-center gap-2.5">
                <span className="grid size-8 shrink-0 place-items-center rounded-lg bg-amber-50 text-amber-700">
                    <Wheat size={18} aria-hidden="true" />
                </span>
                <div>
                    <h3 className="text-sm font-semibold text-[#123575]">Crops &amp; livelihood</h3>
                    <p className="mt-0.5 text-[10px] text-[#738580]">Modeled agriculture · {data?.model_year ?? 'year not recorded'}</p>
                </div>
            </div>
            {loading ? (
                <p role="status" className="mt-3 flex items-center gap-2 text-xs text-[#738580]">
                    <LoaderCircle size={14} className="animate-spin" aria-hidden="true" /> Loading agriculture…
                </p>
            ) : error ? (
                <p role="alert" className="mt-3 text-xs text-amber-900">
                    {error}{' '}
                    <button type="button" onClick={retry} className="font-semibold text-[#123575] underline">
                        Retry
                    </button>
                </p>
            ) : data?.status === 'available' ? (
                <>
                    <div className="mt-3 grid grid-cols-3 gap-2">
                        {data.categories.map((category) => {
                            const Icon = agricultureIcon(category.code, category.name);
                            return (
                                <div key={category.id ?? category.name} className="min-w-0 rounded-lg bg-amber-50/70 px-2 py-3">
                                    <Icon size={18} aria-hidden="true" className="mb-2 text-amber-700" />
                                    <p className="text-lg font-semibold tracking-tight break-words text-[#153d38] tabular-nums">
                                        {agricultureNumber(category.record_count)}
                                    </p>
                                    <p className="mt-0.5 text-[10px] leading-snug break-words text-[#527368]">{category.name}</p>
                                    <p className="mt-1 text-[9px] text-[#738580]">Modeled entries</p>
                                </div>
                            );
                        })}
                    </div>
                    <p className="mt-2 text-[10px] text-[#738580]">
                        {agricultureNumber(data.commodity_count)} named commodities · Modeled estimates, not measured production.
                    </p>
                </>
            ) : (
                <p className="mt-3 text-xs text-[#738580]">
                    {data?.status === 'no_data' ? 'No modeled agriculture entries are linked to this area.' : 'Agriculture figures are unavailable.'}
                </p>
            )}
        </section>
    );
}
