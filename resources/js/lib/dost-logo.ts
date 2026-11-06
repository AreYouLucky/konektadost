import type { jsPDF } from 'jspdf';
import logoUrl from '../../images/dost-logo-horizontal.png?url';

/** DOST horizontal lockup (symbol + "Department of Science and Technology"), black text on transparent. */
export const DOST_LOGO_URL: string = logoUrl;
/** Width ÷ height of the lockup. */
export const DOST_LOGO_RATIO = 2155 / 384;
/** Width ÷ height of the symbol alone (the left part of the lockup), for compact marks. */
export const DOST_MARK_RATIO = 305 / 384;

let cached: Promise<string | null> | null = null;

function readAsDataUrl(blob: Blob): Promise<string | null> {
    return new Promise((resolve) => {
        const reader = new FileReader();
        reader.onload = () => resolve(typeof reader.result === 'string' ? reader.result : null);
        reader.onerror = () => resolve(null);
        reader.readAsDataURL(blob);
    });
}

/** Downscales to 900 px wide so the logo does not add the full 2155 px image to every PDF. */
function downscale(blob: Blob): Promise<string | null> {
    return new Promise((resolve) => {
        // A blob: URL is same-origin, so drawing it never taints the canvas.
        const objectUrl = URL.createObjectURL(blob);
        const done = (value: string | null) => {
            URL.revokeObjectURL(objectUrl);
            resolve(value);
        };
        const image = new Image();
        image.onload = () => {
            try {
                const canvas = document.createElement('canvas');
                canvas.width = 900;
                canvas.height = Math.round(900 / DOST_LOGO_RATIO);
                const context = canvas.getContext('2d');
                if (!context) return done(null);
                context.drawImage(image, 0, 0, canvas.width, canvas.height);
                done(canvas.toDataURL('image/png'));
            } catch {
                done(null);
            }
        };
        image.onerror = () => done(null);
        image.src = objectUrl;
    });
}

async function loadLogo(): Promise<string | null> {
    try {
        // Fetched as a blob instead of drawing <img src={DOST_LOGO_URL}> directly: in development Vite serves
        // assets from another origin (e.g. [::1]:5173 vs 127.0.0.1:8000), and a cross-origin image taints the
        // canvas, so toDataURL() threw and every PDF silently lost its logo.
        const response = await fetch(DOST_LOGO_URL);
        if (!response.ok) return null;
        const blob = await response.blob();
        return (await downscale(blob)) ?? (await readAsDataUrl(blob));
    } catch {
        return null;
    }
}

/**
 * The logo as a PNG data URL for PDF letterheads, loaded once per page session.
 * Resolves to null when it cannot load, so a PDF is still produced without it; a failure is not
 * cached, so the next download tries again.
 */
export function loadDostLogoDataUrl(): Promise<string | null> {
    cached ??= loadLogo().then((logo) => {
        if (!logo) cached = null;
        return logo;
    });
    return cached;
}

/** Draws the logo with its top-left at (x, y); returns the height used (0 without a logo). */
export function drawPdfLogo(doc: jsPDF, logo: string | null | undefined, x: number, y: number, width: number): number {
    if (!logo) return 0;
    const height = width / DOST_LOGO_RATIO;
    doc.addImage(logo, 'PNG', x, y, width, height, 'dost-logo', 'FAST');
    return height;
}
