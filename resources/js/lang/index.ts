import type en from '@/lang/en';

/*
 * Limbile interfeței. Engleza e limba implicită și cea de rezervă: o cheie care ar lipsi dintr-o limbă
 * (TypeScript nu lasă) s-ar afișa în engleză.
 *
 * Adăugarea unei limbi noi (de exemplu germana, „de”) are trei pași:
 *  1. dicționarul resources/js/lang/de.ts — o copie tradusă a lui en.ts, cu tipul `Dictionary` — înregistrat
 *     mai jos, în `dictionaryLoaders`;
 *  2. folderul lang/de pentru mesajele serverului — o copie tradusă a lui lang/en (aceleași fișiere și chei);
 *  3. o intrare în config/app.php, la 'supported_locales': 'de' => 'Deutsch' (numele, în limba lui).
 * tests/Feature/TranslationFilesTest.php verifică pașii 1–3 și cheile din lang/; `npx tsc --noEmit` verifică
 * cheile dicționarului.
 */

/*
 * Fiecare dicționar e un fișier separat, încărcat doar când limba lui e cea aleasă (import dinamic): pagina
 * nu aduce textele celeilalte limbi. lib/i18n.ts îl așteaptă înainte de prima desenare.
 */
export const dictionaryLoaders = {
    en: () => import('@/lang/en').then((module) => module.default as Dictionary),
    ro: () => import('@/lang/ro').then((module) => module.default as Dictionary),
} satisfies Record<string, () => Promise<Dictionary>>;

/** Codul unei limbi a interfeței: 'en' sau 'ro'. */
export type Locale = keyof typeof dictionaryLoaders;

export const DEFAULT_LOCALE: Locale = 'en';

/**
 * Formele de plural ale unui substantiv numărat, după categoriile lui Intl.PluralRules pentru limba respectivă
 * (engleza: one, other; româna: one, few, other). `other` e obligatorie: e forma folosită când lipsește alta.
 */
export type PluralForms = { other: string } & Partial<Record<Intl.LDMLPluralRule, string>>;

type English = typeof en;

/** Același arbore de chei ca dicționarul englez, cu un text oarecare în locul fiecărui text. */
type Messages<T> = { [K in keyof T]: T[K] extends string ? string : Messages<T[K]> };

/** Forma pe care trebuie să o aibă dicționarul oricărei limbi. */
export type Dictionary = Messages<Omit<English, 'counts'>> & { counts: Record<keyof English['counts'], PluralForms> };

/** Toate căile cu punct către un text: 'home.hero.title' | 'nav.discover' | … */
type Paths<T, Prefix extends string = ''> = {
    [K in keyof T & string]: T[K] extends string ? `${Prefix}${K}` : Paths<T[K], `${Prefix}${K}.`>;
}[keyof T & string];

/** O cheie pentru t(): orice text din dicționar, în afară de `counts`. */
export type TranslationKey = Paths<Omit<English, 'counts'>>;

/** O cheie pentru plural(): un substantiv din `counts`, de exemplu 'files'. */
export type CountKey = keyof English['counts'];
