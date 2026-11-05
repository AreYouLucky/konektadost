export function format(value: number | null): string {
    if (value === null) return '—';
    if (value === 0) return '0';
    if (Math.abs(value) < 1) return value.toFixed(3);
    return value.toLocaleString(undefined, { maximumFractionDigits: 2 });
}
