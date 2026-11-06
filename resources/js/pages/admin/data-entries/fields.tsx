import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { Crosshair, LoaderCircle, MapPin, Search, X } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import type { EntryField, FormValue, FormValues } from './types';

const control =
    'w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 shadow-xs outline-none focus-visible:border-[#087dcc] focus-visible:ring-2 focus-visible:ring-[#087dcc]/20 aria-invalid:border-red-400 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

/** One form field, rendered from its server definition. */
export function EntryFieldInput({
    field,
    values,
    setValue,
    error,
    editing,
}: {
    field: EntryField;
    values: FormValues;
    setValue: (name: string, value: FormValue) => void;
    error?: string;
    editing: boolean;
}) {
    const id = useId();
    const listId = `${id}-suggestions`;
    const value = values[field.name];
    const described = [field.help || (field.type === 'password' && editing) ? `${id}-help` : null, error ? `${id}-error` : null]
        .filter(Boolean)
        .join(' ');
    const common = { id, 'aria-invalid': error ? true : undefined, 'aria-describedby': described || undefined };

    if (field.type === 'location') {
        return (
            <div className={cn('space-y-1.5', field.span === 2 && 'sm:col-span-2')}>
                <LocationPicker
                    label={field.label}
                    values={values}
                    setValue={setValue}
                    coordinates={
                        values.latitude !== undefined && values.latitude !== '' && values.longitude !== undefined && values.longitude !== ''
                            ? { latitude: Number(values.latitude), longitude: Number(values.longitude) }
                            : null
                    }
                    hasCoordinateFields={values.latitude !== undefined}
                    error={error}
                />
            </div>
        );
    }

    return (
        <div className={cn('space-y-1.5', (field.span === 2 || field.type === 'textarea') && 'sm:col-span-2')}>
            {field.type === 'boolean' ? (
                <label htmlFor={id} className="flex cursor-pointer items-center gap-2 pt-6 text-sm text-slate-700 dark:text-slate-200">
                    <Checkbox id={id} checked={Boolean(value)} onCheckedChange={(checked) => setValue(field.name, checked === true)} />
                    {field.label}
                </label>
            ) : (
                <Label htmlFor={id} className="text-xs font-medium text-slate-700 dark:text-slate-200">
                    {field.label}
                    {field.required && (
                        <span className="text-red-600" aria-hidden="true">
                            {' '}
                            *
                        </span>
                    )}
                </Label>
            )}

            {field.type === 'textarea' && (
                <textarea
                    {...common}
                    rows={3}
                    value={String(value ?? '')}
                    required={field.required}
                    maxLength={field.max}
                    placeholder={field.placeholder}
                    onChange={(event) => setValue(field.name, event.target.value)}
                    className={cn(control, 'min-h-20 resize-y')}
                />
            )}
            {field.type === 'select' && (
                <select
                    {...common}
                    value={String(value ?? '')}
                    required={field.required}
                    onChange={(event) => setValue(field.name, event.target.value)}
                    className={control}
                >
                    <option value="">{field.required ? 'Choose…' : 'None'}</option>
                    {field.options?.map((option) => (
                        <option key={option.value} value={String(option.value)}>
                            {option.label}
                        </option>
                    ))}
                </select>
            )}
            {field.type === 'list' && <ListInput {...common} value={value} onChange={(items) => setValue(field.name, items)} listId={listId} />}
            {['text', 'email', 'url', 'integer', 'decimal', 'date', 'password'].includes(field.type) && (
                <Input
                    {...common}
                    type={
                        field.type === 'integer' || field.type === 'decimal'
                            ? 'number'
                            : field.type === 'text'
                              ? 'text'
                              : field.type === 'url'
                                ? 'url'
                                : field.type
                    }
                    value={String(value ?? '')}
                    required={field.required || (field.type === 'password' && !editing)}
                    min={field.type === 'integer' || field.type === 'decimal' ? field.min : undefined}
                    max={field.type === 'integer' || field.type === 'decimal' ? field.max : undefined}
                    maxLength={field.type === 'text' ? field.max : undefined}
                    step={field.type === 'decimal' ? (field.step ?? 'any') : field.type === 'integer' ? 1 : undefined}
                    placeholder={field.placeholder}
                    autoComplete={field.type === 'password' ? 'new-password' : 'off'}
                    list={field.suggestions?.length ? listId : undefined}
                    onChange={(event) => setValue(field.name, event.target.value)}
                />
            )}
            {field.suggestions && field.suggestions.length > 0 && (
                <datalist id={listId}>
                    {field.suggestions.map((suggestion) => (
                        <option key={suggestion} value={suggestion} />
                    ))}
                </datalist>
            )}

            {(field.help || (field.type === 'password' && editing)) && (
                <p id={`${id}-help`} className="text-[11px] leading-relaxed text-slate-500">
                    {field.type === 'password' && editing ? 'Leave blank to keep the current password. ' : ''}
                    {field.type === 'password' && editing ? '' : field.help}
                </p>
            )}
            {error && (
                <p id={`${id}-error`} className="text-xs text-red-600">
                    {error}
                </p>
            )}
        </div>
    );
}

/** Comma-separated text that edits a string array without fighting the cursor. */
function ListInput({
    value,
    onChange,
    listId,
    ...props
}: {
    value: unknown;
    onChange: (items: string[]) => void;
    listId: string;
    id: string;
    'aria-invalid'?: boolean;
    'aria-describedby'?: string;
}) {
    const [text, setText] = useState(Array.isArray(value) ? value.join(', ') : '');
    return (
        <Input
            {...props}
            value={text}
            list={listId}
            placeholder="e.g. 4G, 5G"
            onChange={(event) => {
                setText(event.target.value);
                onChange(
                    event.target.value
                        .split(',')
                        .map((item) => item.trim())
                        .filter(Boolean),
                );
            }}
        />
    );
}

interface PlaceResult {
    key: string;
    id: number;
    level: 'province' | 'municipality' | 'barangay';
    name: string;
    path: { id: number; level: string; name: string }[];
}

async function getJson<T>(url: string, signal?: AbortSignal): Promise<T> {
    const response = await fetch(url, {
        signal,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (!response.ok || response.redirected) throw new Error('Request failed');
    return response.json() as Promise<T>;
}

/** City/municipality + barangay picker: search by name, or detect from the entered coordinates. */
function LocationPicker({
    label,
    values,
    setValue,
    coordinates,
    hasCoordinateFields,
    error,
}: {
    label: string;
    values: FormValues;
    setValue: (name: string, value: FormValue) => void;
    coordinates: { latitude: number; longitude: number } | null;
    hasCoordinateFields: boolean;
    error?: string;
}) {
    const id = useId();
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<PlaceResult[]>([]);
    const [searching, setSearching] = useState(false);
    const [detecting, setDetecting] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const current = typeof values.location_label === 'string' ? values.location_label : null;

    useEffect(() => {
        const term = query.trim();
        if (term.length < 2) {
            setResults([]);
            return;
        }
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            setSearching(true);
            getJson<{ results: PlaceResult[] }>(route('admin.search-map.search', { q: term }), controller.signal)
                .then((data) => setResults(data.results.filter((result) => result.level !== 'province').slice(0, 8)))
                .catch(() => !controller.signal.aborted && setMessage('Search is unavailable right now.'))
                .finally(() => !controller.signal.aborted && setSearching(false));
        }, 250);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [query]);

    const choose = (municipalityId: number | null, barangayId: number | null, text: string | null) => {
        setValue('municipality_id', municipalityId);
        setValue('barangay_id', barangayId);
        setValue('location_label', text);
        setQuery('');
        setResults([]);
    };

    const pick = (result: PlaceResult) => {
        const municipality = result.level === 'municipality' ? result : result.path.find((place) => place.level === 'municipality');
        const names = [
            result.name,
            ...result.path
                .slice(0, -1)
                .reverse()
                .map((place) => place.name),
        ];
        choose(municipality?.id ?? null, result.level === 'barangay' ? result.id : null, names.join(', '));
        setMessage(null);
    };

    const detect = async () => {
        if (!coordinates) return;
        setDetecting(true);
        setMessage(null);
        try {
            const found = await getJson<{ municipality_id: number | null; barangay_id: number | null; label: string | null }>(
                route('admin.data-entries.locate', coordinates),
            );
            if (found.municipality_id) choose(found.municipality_id, found.barangay_id, found.label);
            else setMessage('These coordinates are outside every recorded city boundary.');
        } catch {
            setMessage('Could not check these coordinates.');
        } finally {
            setDetecting(false);
        }
    };

    return (
        <fieldset className="space-y-1.5" aria-describedby={`${id}-hint`}>
            <legend className="text-xs font-medium text-slate-700 dark:text-slate-200">{label}</legend>
            <div className="flex flex-wrap items-center gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900">
                <MapPin className="size-4 shrink-0 text-[#087dcc]" aria-hidden="true" />
                <span className={cn('min-w-0 flex-1 truncate', current ? 'text-slate-900 dark:text-slate-100' : 'text-slate-500')}>
                    {current ?? (hasCoordinateFields ? 'Not set: will be detected from the coordinates on save' : 'Not set')}
                </span>
                {hasCoordinateFields && (
                    <button
                        type="button"
                        onClick={() => void detect()}
                        disabled={!coordinates || detecting}
                        className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-[#087dcc] hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {detecting ? (
                            <LoaderCircle className="size-3.5 animate-spin" aria-hidden="true" />
                        ) : (
                            <Crosshair className="size-3.5" aria-hidden="true" />
                        )}
                        Detect from coordinates
                    </button>
                )}
                {current && (
                    <button
                        type="button"
                        onClick={() => choose(null, null, null)}
                        aria-label="Clear location"
                        className="rounded-md p-1 text-slate-500 hover:bg-slate-200 hover:text-slate-700"
                    >
                        <X className="size-3.5" aria-hidden="true" />
                    </button>
                )}
            </div>
            <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
                <Input
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder="Search a city, municipality or barangay"
                    aria-label={`Search ${label}`}
                    className="pl-9"
                    autoComplete="off"
                />
                {searching && (
                    <LoaderCircle className="absolute top-1/2 right-3 size-4 -translate-y-1/2 animate-spin text-slate-400" aria-hidden="true" />
                )}
                {results.length > 0 && (
                    <ul className="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-md border border-slate-200 bg-white py-1 shadow-lg dark:border-slate-700 dark:bg-slate-950">
                        {results.map((result) => (
                            <li key={result.key}>
                                <button
                                    type="button"
                                    onClick={() => pick(result)}
                                    className="flex w-full flex-col items-start px-3 py-2 text-left hover:bg-blue-50 focus-visible:bg-blue-50 focus-visible:outline-none dark:hover:bg-slate-800"
                                >
                                    <span className="text-sm text-slate-900 dark:text-slate-100">{result.name}</span>
                                    <span className="text-[11px] text-slate-500">
                                        {result.level === 'barangay' ? 'Barangay' : 'City / municipality'} ·{' '}
                                        {result.path
                                            .slice(0, -1)
                                            .map((place) => place.name)
                                            .join(' / ')}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            <p id={`${id}-hint`} className="text-[11px] leading-relaxed text-slate-500">
                {message ?? 'Choosing a barangay also sets its city. Inventories and assessments count an entry by this link.'}
            </p>
            {error && <p className="text-xs text-red-600">{error}</p>}
        </fieldset>
    );
}
