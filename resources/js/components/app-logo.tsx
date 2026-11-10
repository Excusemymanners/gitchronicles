import { useId } from 'react';

/*
 * Logo-ul GitChronicle: „rețeta” insignei GitHub (un cerc plin din care desenul e decupat, ca spațiu gol),
 * dar cu desenul nostru: pana cronicarului scrie o linie cu două commit-uri în formă de inel,
 * ca simbolul de commit din GitHub. Adică: istoria git, scrisă ca o cronică.
 *
 * Pana e desenată în coordonate proprii (vârful scrisului în 0,0, vârful fulgului sus) și apoi
 * mutată, rotită și scalată în cercul de 48 × 48 cu FEATHER_TRANSFORM.
 */
const FEATHER_TRANSFORM = 'translate(22.6 37) rotate(26) scale(.96)';
const VANE = 'M0 -6C-5.2 -9.2 -7.9 -14.2 -7.7 -19.4C-7.4 -25.6 -3.9 -30.8 0.6 -34C3.5 -30.2 4.6 -24.4 4.2 -18.4C3.8 -12.8 2.2 -8.6 0 -6Z';
const NIB = 'M-1.25 -5.2L1.25 -5.2L0 1.6Z';
const SHAFT = 'M0 -6.6Q0.5 -20 0.55 -32.4';
const SPLITS = 'M-7.72 -20.9L-7.6 -18.9L-2.9 -16.6ZM4 -26.4L3.5 -28.4L1.5 -25Z';
const COMMITS = [11, 17.4];
const LINE_Y = 38.4;

type MarkProps = {
    className?: string;
};

/**
 * Doar simbolul (insigna rotundă). Are culoarea textului din jur (currentColor),
 * deci se schimbă cu o clasă ca `text-gray-900`. E decorativ: numele îl citește textul de lângă el.
 */
export function AppLogoMark({ className }: MarkProps) {
    // Fiecare logo de pe pagină are nevoie de o mască cu id unic; useId îl generează.
    const maskId = `gc-quill-${useId().replace(/[^a-zA-Z0-9_-]/g, '')}`;

    return (
        <svg viewBox="0 0 48 48" className={className} aria-hidden="true" focusable="false">
            <mask id={maskId} maskUnits="userSpaceOnUse" x="0" y="0" width="48" height="48">
                <rect width="48" height="48" fill="#fff" />
                <g transform={FEATHER_TRANSFORM} fill="#000">
                    <path d={VANE} />
                    <path d={NIB} />
                </g>
                <path d={`M${COMMITS[0]} ${LINE_Y}H21.9`} stroke="#000" strokeWidth="2" strokeLinecap="round" />
                {COMMITS.map((x) => (
                    <circle key={x} cx={x} cy={LINE_Y} r="2.4" fill="#000" />
                ))}
            </mask>

            <circle cx="24" cy="24" r="23" fill="currentColor" mask={`url(#${maskId})`} />

            {/* Detaliile desenate înapoi peste spațiul gol: tija penei, crestăturile fulgului, centrul inelelor. */}
            <g transform={FEATHER_TRANSFORM} fill="currentColor">
                <path d={SHAFT} fill="none" stroke="currentColor" strokeWidth="1.46" strokeLinecap="round" />
                <path d={SPLITS} />
            </g>
            {COMMITS.map((x) => (
                <circle key={x} cx={x} cy={LINE_Y} r="1" fill="currentColor" />
            ))}
        </svg>
    );
}

type LogoProps = {
    className?: string;
};

/** Simbolul + numele „GitChronicle”, scris cu Mona Sans (fontul open-source al GitHub). */
export default function AppLogo({ className = '' }: LogoProps) {
    return (
        <span className={`inline-flex items-center gap-2 ${className}`}>
            <AppLogoMark className="size-7 shrink-0" />
            <span className="font-brand text-lg font-bold tracking-tight">GitChronicle</span>
        </span>
    );
}
