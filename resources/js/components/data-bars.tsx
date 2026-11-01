import { useId } from 'react';

export interface ChartRow {
    label: string;
    value: number | null;
    formatted?: string;
    color?: string;
}

/** Text labels carry the full values; bar lengths are visual approximations. */
export default function DataBars({ title, description, rows, unit = '' }: { title: string; description?: string; rows: ChartRow[]; unit?: string }) {
    const id = useId();
    const measured = rows.filter((row) => row.value !== null && Number.isFinite(row.value));
    const min = Math.min(0, ...measured.map((row) => row.value!));
    const max = Math.max(0, ...measured.map((row) => row.value!));
    const span = max - min || 1;
    const zero = (-min / span) * 100;
    return (
        <figure aria-labelledby={id} className="min-w-0 space-y-3">
            <figcaption id={id}>
                <p className="text-sm font-semibold">{title}</p>
                {description && <p className="text-muted-foreground mt-1 text-xs leading-relaxed">{description}</p>}
            </figcaption>
            {rows.length ? (
                <div className="space-y-3">
                    {rows.map((row, index) => {
                        const valid = row.value !== null && Number.isFinite(row.value);
                        const amount = valid ? row.value! : 0;
                        return (
                            <div key={`${row.label}:${index}`}>
                                <div className="mb-1.5 flex items-baseline justify-between gap-3 text-xs">
                                    <span className="min-w-0 break-words">{row.label}</span>
                                    <span className="shrink-0 font-semibold tabular-nums">
                                        {valid
                                            ? `${row.formatted ?? new Intl.NumberFormat('en-PH', { maximumFractionDigits: 2 }).format(amount)}${unit}`
                                            : 'Unavailable'}
                                    </span>
                                </div>
                                <div aria-hidden="true" className="relative h-2.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                                    {min < 0 && <span className="absolute inset-y-0 w-px bg-slate-400" style={{ left: `${zero}%` }} />}
                                    {valid && (
                                        <span
                                            className="absolute inset-y-0 rounded-full"
                                            style={{
                                                left: `${amount < 0 ? ((amount - min) / span) * 100 : zero}%`,
                                                width: `${(Math.abs(amount) / span) * 100}%`,
                                                backgroundColor: row.color ?? (amount < 0 ? '#d97706' : '#004a95'),
                                            }}
                                        />
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>
            ) : (
                <p className="text-muted-foreground text-xs">No recorded figures to chart.</p>
            )}
        </figure>
    );
}
