import { getLocale, pluralize, translate } from '@/lib/i18n';

/*
 * Formatarea numerelor, datelor și duratelor în limba interfeței (getLocale(), vezi lib/i18n.ts):
 * același apel dă „12,345” și „9 Nov 2026” în engleză, „12.345” și „9 nov. 2026” în română.
 * Funcțiile sunt simple (nu hook-uri), așa că se pot chema oriunde; o componentă care le folosește
 * se redesenează la schimbarea limbii dacă folosește useI18n().
 */

/** Formatoarele Intl, create o singură dată pentru fiecare limbă (crearea lor e scumpă, folosirea nu). */
const formatters = new Map<string, unknown>();

function cached<T>(name: string, create: (locale: string) => T): T {
    const key = `${getLocale()}:${name}`;

    if (!formatters.has(key)) {
        formatters.set(key, create(getLocale()));
    }

    return formatters.get(key) as T;
}

/** O dată ISO 8601 cu ora, de exemplu „Nov 9, 2026, 2:05 PM” sau „9 nov. 2026, 14:05”. */
export function formatDate(isoDate: string): string {
    const date = new Date(isoDate);

    // O dată invalidă ar arunca o eroare în format(), așa că afișăm textul primit.
    if (Number.isNaN(date.getTime())) {
        return isoDate;
    }

    return cached('date', (locale) => new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' })).format(date);
}

/** Pragurile unităților de timp, de la cea mai mică: până la câte secunde folosim unitatea de dinainte. */
const relativeUnits: { unit: Intl.RelativeTimeFormatUnit; seconds: number }[] = [
    { unit: 'minute', seconds: 60 },
    { unit: 'hour', seconds: 3600 },
    { unit: 'day', seconds: 86400 },
    { unit: 'month', seconds: 2592000 },
    { unit: 'year', seconds: 31536000 },
];

/** Cât timp a trecut de la o dată ISO 8601, de exemplu „5 min. ago” / „acum 5 min.”; sub un minut: „just now” / „chiar acum”. */
export function formatRelative(isoDate: string, now: Date = new Date()): string {
    const seconds = Math.round((new Date(isoDate).getTime() - now.getTime()) / 1000);

    if (Number.isNaN(seconds)) {
        return isoDate;
    }

    if (Math.abs(seconds) < 60) {
        return translate('format.justNow');
    }

    // Cea mai mare unitate care încape măcar o dată în intervalul trecut.
    const { unit, seconds: unitSeconds } = [...relativeUnits].reverse().find((candidate) => Math.abs(seconds) >= candidate.seconds) ?? relativeUnits[0];

    return cached('relative', (locale) => new Intl.RelativeTimeFormat(locale, { numeric: 'auto', style: 'short' })).format(
        Math.round(seconds / unitSeconds),
        unit,
    );
}

/** Primele 7 caractere ale unui SHA, ca în `git log --oneline`. */
export function shortSha(sha: string): string {
    return sha.slice(0, 7);
}

/** Un număr cu separatorul de mii al limbii: „12,345” / „12.345”. */
export function formatNumber(value: number): string {
    return cached('number', (locale) => new Intl.NumberFormat(locale)).format(value);
}

/** O parte din întreg (între 0 și 1) ca procent: 0.452 → „45.2%” / „45,2 %”. */
export function formatPercent(share: number): string {
    return cached('percent', (locale) => new Intl.NumberFormat(locale, { style: 'percent', maximumFractionDigits: 1 })).format(share);
}

/**
 * Un număr urmat de cuvântul dat de apelant, după regula limbii: în engleză singularul doar pentru 1;
 * în română și „de” de la 20 în sus (după ultimele două cifre): „1 linie”, „5 linii”, „20 de linii”, „101 linii”.
 * Pentru textele noi folosiți plural('lines', n) din useI18n(), cu formele scrise în dicționare.
 */
export function countLabel(count: number, singular: string, plural: string): string {
    if (count === 1) {
        return `1 ${singular}`;
    }

    const lastTwoDigits = count % 100;
    const needsDe = getLocale() === 'ro' && count !== 0 && (lastTwoDigits === 0 || lastTwoDigits >= 20);

    return `${formatNumber(count)} ${needsDe ? 'de ' : ''}${plural}`;
}

/** Mărimea unui fișier: „197 KB”, „8.2 MB” / „8,2 MB”. */
export function formatFileSize(bytes: number): string {
    if (bytes < 1024 * 1024) {
        return `${formatNumber(Math.max(1, Math.round(bytes / 1024)))} KB`;
    }

    return `${formatDecimal(bytes / (1024 * 1024))} MB`;
}

/** Un număr cu cel mult o zecimală: 45.25 → „45.3” / „45,3”, 12 → „12”. */
export function formatDecimal(value: number): string {
    return cached('decimal', (locale) => new Intl.NumberFormat(locale, { maximumFractionDigits: 1 })).format(value);
}

/** O diferență cu semn: 1234 → „+1,234” / „+1.234”, -5 → „-5”, 0 → „0”. */
export function formatSignedNumber(value: number): string {
    return cached('signed', (locale) => new Intl.NumberFormat(locale, { signDisplay: 'exceptZero' })).format(value);
}

/** O zi de forma „2026-11-11” (fără oră): „Nov 11, 2026” / „11 nov. 2026”. */
export function formatDay(isoDay: string): string {
    const date = new Date(`${isoDay}T00:00:00Z`);

    if (Number.isNaN(date.getTime())) {
        return isoDay;
    }

    return cached('day', (locale) => new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeZone: 'UTC' })).format(date);
}

/** Vârsta unui proiect din numărul de zile: „created today” / „creat azi”, „23 days” / „23 de zile”, „5 months”, „3 years”. */
export function formatAge(days: number): string {
    if (days < 1) {
        return translate('format.createdToday');
    }

    if (days < 60) {
        return pluralize('days', days);
    }

    if (days < 730) {
        return pluralize('months', Math.floor(days / 30));
    }

    return pluralize('years', Math.floor(days / 365));
}

/** O listă în limba interfeței: „a, b and c” / „a, b și c”. */
export function formatList(items: string[]): string {
    return cached('list', (locale) => new Intl.ListFormat(locale, { style: 'long', type: 'conjunction' })).format(items);
}

/** O durată în milisecunde: „0.8 s” / „0,8 s”, „37 s”, „1 min 9 s”. */
export function formatDuration(milliseconds: number): string {
    const seconds = milliseconds / 1000;

    if (seconds < 60) {
        return `${formatDecimal(seconds)} s`;
    }

    const minutes = Math.floor(seconds / 60);
    const restSeconds = Math.round(seconds - minutes * 60);

    return restSeconds === 0 ? `${formatNumber(minutes)} min` : `${formatNumber(minutes)} min ${restSeconds} s`;
}

/** O poziție într-un video, ca pe un player: 3500 → „0:03”, 71000 → „1:11”. */
export function formatTimecode(milliseconds: number): string {
    const totalSeconds = Math.floor(milliseconds / 1000);

    return `${Math.floor(totalSeconds / 60)}:${String(totalSeconds % 60).padStart(2, '0')}`;
}
