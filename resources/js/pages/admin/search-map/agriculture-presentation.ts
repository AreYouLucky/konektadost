import { Beef, Egg, Fish, Leaf, Sprout, Wheat, type LucideIcon } from 'lucide-react';

export function agricultureIcon(category: string | null, type: string): LucideIcon {
    if (category === 'fisheries') return Fish;
    if (/poultry/i.test(type)) return Egg;
    if (category === 'livestock_poultry') return Beef;
    if (/herb/i.test(type)) return Sprout;
    if (/vegetable/i.test(type)) return Leaf;
    return Wheat;
}

/** Format decimal strings for display without rounding them through binary floating-point. */
export function agricultureNumber(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') return 'Not recorded';
    const raw = String(value);
    const match = /^(-?)(\d+)(?:\.(\d+))?$/.exec(raw);
    if (!match) return raw;
    const fraction = match[3]?.replace(/0+$/, '');
    return `${match[1]}${match[2].replace(/\B(?=(\d{3})+(?!\d))/g, ',')}${fraction ? `.${fraction}` : ''}`;
}
