import { Building2, FlaskConical, GraduationCap, HeartPulse, ShieldCheck, type LucideIcon } from 'lucide-react';

export const RESOURCE_CATEGORIES: Record<string, { icon: LucideIcon; label: string; color: string; fill: string }> = {
    health: { icon: HeartPulse, label: 'Health', color: 'text-[#dc4660]', fill: 'bg-[#dc4660]' },
    education: { icon: GraduationCap, label: 'Education', color: 'text-[#287ac5]', fill: 'bg-[#287ac5]' },
    safety: { icon: ShieldCheck, label: 'Safety', color: 'text-[#c17a13]', fill: 'bg-[#c17a13]' },
    facility: { icon: Building2, label: 'Other facilities', color: 'text-[#087dcc]', fill: 'bg-[#087dcc]' },
    laboratory: { icon: FlaskConical, label: 'Laboratories', color: 'text-[#087dcc]', fill: 'bg-[#087dcc]' },
};

export const count = new Intl.NumberFormat('en-PH');

export function sourceLabel(source: string | null): string {
    if (source === 'osm_hot') return 'OpenStreetMap · HOT';
    if (source === 'onelab') return 'OneLab';
    return source || 'Source not recorded';
}
