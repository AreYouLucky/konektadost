import {
    BookOpen,
    Cpu,
    Database,
    FlaskConical,
    GraduationCap,
    HeartPulse,
    History,
    RadioTower,
    ShieldAlert,
    TestTube,
    Users,
    Wheat,
    type LucideIcon,
} from 'lucide-react';

/** Mirrors the field definitions in App\Support\DataEntries\DataEntryRegistry. */
export type FieldType =
    'text' | 'textarea' | 'email' | 'url' | 'integer' | 'decimal' | 'boolean' | 'date' | 'select' | 'list' | 'password' | 'location';

export interface EntryField {
    name: string;
    label: string;
    type: FieldType;
    required?: boolean;
    list?: boolean;
    unique?: boolean;
    min?: number;
    max?: number;
    step?: string | number;
    options?: { value: string | number; label: string }[];
    suggestions?: string[];
    help?: string;
    placeholder?: string;
    span?: number;
}

export interface SectionMeta {
    key: string;
    label: string;
    singular: string;
    group: string;
    icon: string;
    description: string;
    note: string | null;
    hasLocation: boolean;
    hasCoordinates: boolean;
}

export interface EntryRow {
    id: number;
    title: string;
    location_label?: string | null;
    [key: string]: unknown;
}

export type FormValue = string | number | boolean | null | string[];
export type FormValues = Record<string, FormValue>;

const ICONS: Record<string, LucideIcon> = {
    BookOpen,
    Cpu,
    FlaskConical,
    GraduationCap,
    HeartPulse,
    History,
    RadioTower,
    ShieldAlert,
    TestTube,
    Users,
    Wheat,
};

export const sectionIcon = (name: string): LucideIcon => ICONS[name] ?? Database;

/** Empty form values for a new entry, or a row's values for editing. */
export function formValues(fields: EntryField[], row: EntryRow | null): FormValues {
    const values: FormValues = {};
    for (const field of fields) {
        if (field.type === 'location') {
            values.municipality_id = typeof row?.municipality_id === 'number' ? row.municipality_id : null;
            values.barangay_id = typeof row?.barangay_id === 'number' ? row.barangay_id : null;
            values.location_label = row?.location_label ?? null;
        } else if (field.type === 'boolean') {
            values[field.name] = Boolean(row?.[field.name]);
        } else if (field.type === 'list') {
            const items = row?.[field.name];
            values[field.name] = Array.isArray(items) ? items.map(String) : [];
        } else {
            const value = row?.[field.name];
            values[field.name] = value === null || value === undefined ? '' : String(value);
        }
    }
    return values;
}
