import { useSyncExternalStore } from 'react';

export type Theme = 'dark' | 'light';

/**
 * Cheia din localStorage în care păstrăm alegerea ('dark' sau 'light').
 * Scriptul din resources/views/app.blade.php o citește înainte de prima desenare a paginii,
 * așa că trebuie să rămână aceeași în ambele locuri.
 */
export const THEME_STORAGE_KEY = 'gitchronicle-theme';

/** Evenimentul trimis pe `window` la fiecare schimbare de temă (de exemplu, ca diagramele să se redeseneze). */
export const THEME_CHANGE_EVENT = 'gitchronicle-theme-change';

/** Tema afișată acum. O spune clasa „dark” de pe <html>, pusă de scriptul din app.blade.php. */
export function currentTheme(): Theme {
    return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
}

/** Schimbă tema: clasa de pe <html>, alegerea salvată și evenimentul pentru componentele care ascultă. */
export function setTheme(theme: Theme): void {
    document.documentElement.classList.toggle('dark', theme === 'dark');

    try {
        localStorage.setItem(THEME_STORAGE_KEY, theme);
    } catch {
        // Fără acces la localStorage (unele moduri private), tema ține doar până la reîncărcarea paginii.
    }

    window.dispatchEvent(new CustomEvent<Theme>(THEME_CHANGE_EVENT, { detail: theme }));
}

/**
 * Valoarea unui token de culoare în tema afișată acum, de exemplu themeColor('surface') → '#111727'.
 * Pentru cod care nu poate folosi clase Tailwind (de exemplu culorile diagramelor Mermaid).
 */
export function themeColor(token: string): string {
    return getComputedStyle(document.documentElement).getPropertyValue(`--color-${token}`).trim();
}

function subscribe(onThemeChange: () => void): () => void {
    window.addEventListener(THEME_CHANGE_EVENT, onThemeChange);

    return () => window.removeEventListener(THEME_CHANGE_EVENT, onThemeChange);
}

/**
 * Tema curentă, pentru o componentă React: componenta se redesenează singură când tema se schimbă.
 * useSyncExternalStore e felul recomandat de React de a citi o valoare care trăiește în afara lui
 * (aici, clasa de pe <html>).
 */
export function useTheme(): Theme {
    return useSyncExternalStore(subscribe, currentTheme);
}
