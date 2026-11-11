import { useEffect, useRef, useState } from 'react';

/*
 * Ajutoarele de mișcare din JavaScript. Duratele și curbele stau în resources/css/app.css (--motion-…, --ease-…);
 * aici e doar ce nu se poate face din CSS.
 */

/** Adevărat dacă vizitatorul a cerut sistemului mai puțină mișcare. */
export function prefersReducedMotion(): boolean {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * Face o schimbare pe pagină cu o trecere lină (View Transitions API): browserul fotografiază pagina,
 * aplică schimbarea și topește imaginea veche în cea nouă. Unde API-ul lipsește sau mișcarea e redusă,
 * schimbarea se face pur și simplu pe loc.
 */
export function withViewTransition(update: () => void): void {
    if (!document.startViewTransition || prefersReducedMotion()) {
        update();

        return;
    }

    document.startViewTransition(update);
}

/** Încetinește spre capăt: numărul urcă repede la început și se așază lin pe valoarea finală. */
function easeOut(progress: number): number {
    return 1 - Math.pow(1 - progress, 3);
}

/**
 * Un număr care urcă până la valoarea lui: de la 0 la prima afișare, apoi de la valoarea veche la cea nouă.
 * Doar pentru totaluri pe care vizitatorul le citește ca rezultat; cu mișcarea redusă întoarce direct valoarea.
 */
export function useCountUp(value: number, durationMs = 600): number {
    const [shown, setShown] = useState(0);
    const shownRef = useRef(0);

    useEffect(() => {
        if (prefersReducedMotion()) {
            shownRef.current = value;
            setShown(value);

            return;
        }

        const from = shownRef.current;
        const startedAt = performance.now();
        let frame = 0;

        function step(now: number) {
            const progress = Math.min(1, (now - startedAt) / durationMs);
            const current = Math.round(from + (value - from) * easeOut(progress));

            shownRef.current = current;
            setShown(current);

            if (progress < 1) {
                frame = requestAnimationFrame(step);
            }
        }

        frame = requestAnimationFrame(step);

        return () => cancelAnimationFrame(frame);
    }, [value, durationMs]);

    return shown;
}
