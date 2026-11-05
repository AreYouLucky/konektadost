import { ArrowUpRight, LoaderCircle, MapPin, Search, X } from 'lucide-react';
import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';
import { getGeography, LEVEL_LABELS, type GeographyApi, type SearchResult } from '../types';

export default function SearchBar({ onSelect, api }: { onSelect: (location: SearchResult) => void; api: GeographyApi }) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<SearchResult[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(-1);
    const root = useRef<HTMLDivElement>(null);
    const input = useRef<HTMLInputElement>(null);
    const listId = useId();

    useEffect(() => {
        const controller = new AbortController();
        const term = query.trim();
        setActive(-1);
        setResults([]);
        setError(null);
        setLoading(term.length >= 2);
        if (term.length < 2) return () => controller.abort();
        const timer = window.setTimeout(() => {
            void getGeography<{ results: SearchResult[] }>(api.search(term), controller.signal)
                .then((data) => {
                    if (!controller.signal.aborted) setResults(data.results);
                })
                .catch((failure: unknown) => {
                    if (!controller.signal.aborted) setError(failure instanceof Error ? failure.message : 'Search is unavailable.');
                })
                .finally(() => {
                    if (!controller.signal.aborted) setLoading(false);
                });
        }, 280);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [query, api]);

    useEffect(() => {
        const close = (event: PointerEvent) => {
            if (!root.current?.contains(event.target as Node)) setOpen(false);
        };
        document.addEventListener('pointerdown', close);
        return () => document.removeEventListener('pointerdown', close);
    }, []);

    useEffect(() => {
        if (active >= 0) document.getElementById(`${listId}-${active}`)?.scrollIntoView({ block: 'nearest' });
    }, [active, listId]);

    const choose = (result: SearchResult) => {
        onSelect(result);
        setOpen(false);
        setQuery('');
        input.current?.blur();
    };
    const keyboard = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Escape') {
            setOpen(false);
            return;
        }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            setOpen(true);
            setActive((index) => (results.length ? (index + (event.key === 'ArrowDown' ? 1 : -1) + results.length) % results.length : -1));
        }
        if (event.key === 'Enter' && open && active >= 0 && results[active]) {
            event.preventDefault();
            choose(results[active]);
        }
    };
    const expanded = open && query.trim().length >= 2;

    return (
        <div ref={root} className="explorer-search">
            <Search size={17} aria-hidden="true" />
            <input
                ref={input}
                value={query}
                maxLength={80}
                placeholder="Find a place in the Philippines"
                aria-label="Search provinces, cities, municipalities, and barangays"
                role="combobox"
                aria-autocomplete="list"
                aria-expanded={expanded}
                aria-controls={listId}
                aria-activedescendant={active >= 0 && expanded ? `${listId}-${active}` : undefined}
                autoComplete="off"
                spellCheck={false}
                onFocus={() => setOpen(true)}
                onKeyDown={keyboard}
                onChange={(event) => {
                    setQuery(event.target.value);
                    setOpen(true);
                }}
            />
            {loading ? (
                <LoaderCircle size={16} className="explorer-spin" aria-label="Searching" />
            ) : query ? (
                <button
                    type="button"
                    className="search-clear"
                    aria-label="Clear search"
                    onClick={() => {
                        setQuery('');
                        input.current?.focus();
                    }}
                >
                    <X size={15} />
                </button>
            ) : (
                <kbd aria-hidden="true">⌕</kbd>
            )}
            {expanded && (
                <div className="search-popover">
                    <div className="search-popover-label">{loading ? 'Finding your next destination…' : 'Locations in your dataset'}</div>
                    <div id={listId} role="listbox" aria-label="Location suggestions" className="search-results">
                        {results.map((result, index) => (
                            <button
                                key={result.key}
                                type="button"
                                id={`${listId}-${index}`}
                                role="option"
                                aria-selected={index === active}
                                className={`search-result ${index === active ? 'is-active' : ''}`}
                                onMouseEnter={() => setActive(index)}
                                onMouseDown={(event) => event.preventDefault()}
                                onClick={() => choose(result)}
                            >
                                <span className="search-result-pin">
                                    <MapPin size={16} />
                                </span>
                                <span className="search-result-copy">
                                    <strong>{result.name}</strong>
                                    <small>
                                        {LEVEL_LABELS[result.level]} ·{' '}
                                        {result.path
                                            .slice(0, -1)
                                            .map((location) => location.name)
                                            .join(' / ') || 'Philippines'}
                                    </small>
                                </span>
                                <ArrowUpRight size={15} />
                            </button>
                        ))}
                    </div>
                    <div className="search-feedback" role="status" aria-live="polite">
                        {error ?? (!loading && results.length === 0 ? 'No matching places. Try a shorter name or a different spelling.' : null)}
                    </div>
                    {results.length > 0 && (
                        <div className="search-keyboard-hint">
                            <span>↑ ↓ to explore</span>
                            <span>↵ to select</span>
                            <span>esc to close</span>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
