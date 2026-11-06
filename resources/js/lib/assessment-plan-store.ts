import type { ActionPlanSnapshot } from './assessment-action-plan';
import { fetchWithCsrf, isSessionEnded } from './csrf';

/** Where the plan came from: the map assessment (`level` + `location`) or the city assessment (`city` PSGC). */
export type AssessmentPlanScope = { level: string; location: number; map?: string; focus?: string } | { city: string; focus?: string };

export interface SavedAssessmentPlanSummary {
    id: number;
    kind: 'assessment';
    title: string;
    version: number;
    status: string;
    ai_assisted: boolean;
    level: string;
    location_id: number;
    location_name: string;
    province_name: string | null;
    psg_code: string | null;
    created_at: string;
    /** Reopens the live assessment the plan was generated from. */
    assessment_url: string;
}

export interface SavedAssessmentPlan extends SavedAssessmentPlanSummary {
    snapshot: ActionPlanSnapshot;
}

export interface SaveResult {
    plan: SavedAssessmentPlanSummary;
    /** False when an identical plan was already saved; its existing version is returned. */
    created: boolean;
}

/** The scope the server needs to resolve the location; null when the page has neither form. */
export function assessmentPlanScope(report: {
    location: { psg_code: string | null };
    focus?: string;
    assessmentParams?: Record<string, string | number>;
}): AssessmentPlanScope | null {
    const params = report.assessmentParams;
    const focus = report.focus || undefined;
    if (params?.level && params.location) {
        const location = Number(params.location);
        if (!Number.isInteger(location) || location < 1) return null;
        return { level: String(params.level), location, map: params.map ? String(params.map) : undefined, focus };
    }
    const city = report.location.psg_code;
    return city && /^\d{10}$/.test(city) ? { city, focus } : null;
}

/** Small, stable string hash (cyrb53) so the de-duplication key stays short. */
function hash(text: string) {
    let h1 = 0xdeadbeef,
        h2 = 0x41c6ce57;
    for (let index = 0; index < text.length; index++) {
        const code = text.charCodeAt(index);
        h1 = Math.imul(h1 ^ code, 2654435761);
        h2 = Math.imul(h2 ^ code, 1597334677);
    }
    h1 = Math.imul(h1 ^ (h1 >>> 16), 2246822507) ^ Math.imul(h2 ^ (h2 >>> 13), 3266489909);
    h2 = Math.imul(h2 ^ (h2 >>> 16), 2246822507) ^ Math.imul(h1 ^ (h1 >>> 13), 3266489909);
    return (h2 >>> 0).toString(16).padStart(8, '0') + (h1 >>> 0).toString(16).padStart(8, '0');
}

/** Generation time changes on every render, so it is not part of a plan's identity (the server does the same). */
export function planFingerprint(userId: number | string, scope: AssessmentPlanScope, snapshot: ActionPlanSnapshot) {
    return `${userId}:${hash(JSON.stringify({ scope, snapshot: { ...snapshot, plan: { ...snapshot.plan, generatedAt: null } } }))}`;
}

async function readJson(response: Response, fallback: string) {
    if (isSessionEnded(response)) throw new Error('Your session has expired. Refresh and sign in again.');
    if (response.status === 429) throw new Error('Too many requests. Please wait a minute and try again.');
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload) throw new Error(payload?.message || fallback);
    return payload;
}

const saves = new Map<string, Promise<SaveResult>>();

/**
 * Saves the plan as the next version for this location. Repeated calls with the same plan share one
 * request (and the server returns the existing version), so opening the plan again never duplicates it.
 */
export function saveAssessmentPlan(userId: number | string, scope: AssessmentPlanScope, snapshot: ActionPlanSnapshot): Promise<SaveResult> {
    const key = planFingerprint(userId, scope, snapshot);
    const existing = saves.get(key);
    if (existing) return existing;
    const pending = fetchWithCsrf(route('location.assess.plans.store'), {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ ...scope, snapshot }),
    })
        .then((response) => readJson(response, 'The action plan could not be saved. Please retry.'))
        .then((result: SaveResult) => {
            if (result.created) window.dispatchEvent(new Event('planning-plans-updated'));
            return result;
        });
    // A failed save may be retried; a successful one is remembered for this page session.
    pending.catch(() => saves.delete(key));
    saves.set(key, pending);
    return pending;
}

export async function loadAssessmentPlan(id: number, signal?: AbortSignal): Promise<SavedAssessmentPlan> {
    const response = await fetch(route('location.assess.plans.show', { plan: id }), {
        credentials: 'same-origin',
        signal,
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (response.status === 404) throw new Error('This saved plan could not be found.');
    const payload = await readJson(response, 'The saved plan could not be loaded. Please retry.');
    return payload.plan;
}
