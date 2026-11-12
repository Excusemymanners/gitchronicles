import { router } from '@inertiajs/react';
import { createElement, Fragment } from 'react';
import type { ReactNode } from 'react';
import { DEFAULT_LOCALE, dictionaries } from '@/lang';
import type { CountKey, Locale, PluralForms, TranslationKey } from '@/lang';

/*
 * Limba interfeței, în browser. Serverul o alege la fiecare cerere (App\Http\Middleware\SetLocale) și o trimite
 * în două locuri: atributul <html lang> al primei pagini și prop-ul comun `locale` al fiecărui răspuns Inertia.
 * Aici o ținem într-o singură variabilă, ca să o citească la fel componentele (prin useI18n) și funcțiile
 * simple din lib/format.ts, care nu sunt hook-uri.
 */

/** Valorile puse în locul lui {nume} dintr-un text. */
export type TranslationParams = Record<string, string | number>;

function isLocale(value: unknown): value is Locale {
    return typeof value === 'string' && Object.hasOwn(dictionaries, value);
}

/** Limba de acum. La pornire, cea scrisă de server în <html lang> (resources/views/app.blade.php). */
let currentLocale: Locale = isLocale(document.documentElement.lang) ? document.documentElement.lang : DEFAULT_LOCALE;

const listeners = new Set<() => void>();

/** Limba de acum: 'en' sau 'ro'. */
export function getLocale(): Locale {
    return currentLocale;
}

/** Schimbă limba afișată (o valoare necunoscută înseamnă engleza) și anunță componentele care o folosesc. */
export function setLocale(locale: unknown): void {
    const nextLocale = isLocale(locale) ? locale : DEFAULT_LOCALE;

    if (nextLocale === currentLocale) {
        return;
    }

    currentLocale = nextLocale;
    document.documentElement.lang = nextLocale;
    listeners.forEach((listener) => listener());
}

/** Pentru useSyncExternalStore (hooks/use-i18n.ts): cheamă `listener` la fiecare schimbare de limbă. */
export function subscribeToLocale(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

/*
 * Fiecare răspuns al serverului aduce limba aleasă (prop-ul `locale`). „beforeUpdate” vine înainte ca pagina nouă
 * să fie desenată, așa că pagina apare direct în limba ei. O reîncărcare parțială (only: […]) nu aduce prop-ul,
 * iar atunci limba rămâne aceeași. Paginile refăcute din istoric (butonul Înapoi) nu trec pe aici: păstrează
 * limba aleasă ultima dată.
 */
router.on('beforeUpdate', (event) => {
    const { locale } = event.detail.page.props;

    if (locale !== undefined) {
        setLocale(locale);
    }
});

/** Textul de la o cale ('home.hero.title') dintr-un dicționar, sau undefined dacă nu există. */
function lookup(dictionary: object, key: string): unknown {
    return key.split('.').reduce<unknown>((node, part) => (node as Record<string, unknown> | undefined)?.[part], dictionary);
}

/** Pune valorile în locurile {nume}; un loc fără valoare rămâne cum e, ca greșeala să se vadă. */
function interpolate(text: string, params: TranslationParams = {}): string {
    return text.replace(/\{(\w+)\}/g, (placeholder, name: string) => (Object.hasOwn(params, name) ? String(params[name]) : placeholder));
}

/** Textul unei chei în limba de acum, cu valorile puse la locul lor: translate('discover.pageOf', { current: 2, last: 5 }). */
export function translate(key: TranslationKey, params?: TranslationParams): string {
    const text = lookup(dictionaries[currentLocale], key) ?? lookup(dictionaries[DEFAULT_LOCALE], key);

    return typeof text === 'string' ? interpolate(text, params) : key;
}

/**
 * Un număr cu substantivul lui, în forma cerută de regula de plural a limbii (Intl.PluralRules):
 * pluralize('files', 20) → „20 files” sau „20 de fișiere”. Numărul e scris cu separatorul de mii al limbii.
 */
export function pluralize(key: CountKey, count: number, params?: TranslationParams): string {
    const forms: PluralForms = dictionaries[currentLocale].counts[key];
    const category = new Intl.PluralRules(currentLocale).select(count);

    return interpolate(forms[category] ?? forms.other, { count: new Intl.NumberFormat(currentLocale).format(count), ...params });
}

/**
 * Ca translate(), dar valorile pot fi elemente React (un <strong>, un <code>, o dată în <time>), așa că
 * propoziția rămâne întreagă în dicționar, cu ordinea cuvintelor din fiecare limbă:
 * translateRich('discover.rankingFor', { day: <time …>11 nov.</time> }).
 */
export function translateRich(key: TranslationKey, values: Record<string, ReactNode>): ReactNode {
    return translate(key)
        .split(/(\{\w+\})/)
        .map((part, index) => {
            const name = /^\{(\w+)\}$/.exec(part)?.[1];

            return createElement(Fragment, { key: index }, name !== undefined && Object.hasOwn(values, name) ? values[name] : part);
        });
}
