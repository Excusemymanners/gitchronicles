const numberFormatter = new Intl.NumberFormat('ro-RO');

const percentFormatter = new Intl.NumberFormat('ro-RO', { style: 'percent', maximumFractionDigits: 1 });

const dateFormatter = new Intl.DateTimeFormat('ro-RO', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

/** O dată ISO 8601 afișată în română, de exemplu „9 nov. 2026, 14:05”. */
export function formatDate(isoDate: string): string {
    const date = new Date(isoDate);

    // O dată invalidă ar arunca o eroare în format(), așa că afișăm textul primit.
    if (Number.isNaN(date.getTime())) {
        return isoDate;
    }

    return dateFormatter.format(date);
}

const relativeFormatter = new Intl.RelativeTimeFormat('ro-RO', { numeric: 'auto', style: 'short' });

/** Pragurile unităților de timp, de la cea mai mică: până la câte secunde folosim unitatea de dinainte. */
const relativeUnits: { unit: Intl.RelativeTimeFormatUnit; seconds: number }[] = [
    { unit: 'minute', seconds: 60 },
    { unit: 'hour', seconds: 3600 },
    { unit: 'day', seconds: 86400 },
    { unit: 'month', seconds: 2592000 },
    { unit: 'year', seconds: 31536000 },
];

/** Cât timp a trecut de la o dată ISO 8601, de exemplu „acum 5 min.” sau „acum 2 zile”; sub un minut: „chiar acum”. */
export function formatRelative(isoDate: string, now: Date = new Date()): string {
    const seconds = Math.round((new Date(isoDate).getTime() - now.getTime()) / 1000);

    if (Number.isNaN(seconds)) {
        return isoDate;
    }

    if (Math.abs(seconds) < 60) {
        return 'chiar acum';
    }

    // Cea mai mare unitate care încape măcar o dată în intervalul trecut.
    const { unit, seconds: unitSeconds } = [...relativeUnits].reverse().find((candidate) => Math.abs(seconds) >= candidate.seconds) ?? relativeUnits[0];

    return relativeFormatter.format(Math.round(seconds / unitSeconds), unit);
}

/** Primele 7 caractere ale unui SHA, ca în `git log --oneline`. */
export function shortSha(sha: string): string {
    return sha.slice(0, 7);
}

/** Un număr cu separatorul de mii din română, de exemplu „12.345”. */
export function formatNumber(value: number): string {
    return numberFormatter.format(value);
}

/** O parte din întreg (între 0 și 1) ca procent, de exemplu 0.452 → „45,2 %”. */
export function formatPercent(share: number): string {
    return percentFormatter.format(share);
}

/**
 * Un număr urmat de cuvântul potrivit în română: „1 linie”, „5 linii”, „20 de linii”, „101 linii”.
 * De la 20 în sus (după ultimele două cifre) româna cere „de”.
 */
export function countLabel(count: number, singular: string, plural: string): string {
    if (count === 1) {
        return `1 ${singular}`;
    }

    const lastTwoDigits = count % 100;
    const needsDe = count !== 0 && (lastTwoDigits === 0 || lastTwoDigits >= 20);

    return `${formatNumber(count)} ${needsDe ? 'de ' : ''}${plural}`;
}

/** Mărimea unui fișier: „197 KB”, „8,2 MB”. */
export function formatFileSize(bytes: number): string {
    if (bytes < 1024 * 1024) {
        return `${formatNumber(Math.max(1, Math.round(bytes / 1024)))} KB`;
    }

    return `${new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 1 }).format(bytes / (1024 * 1024))} MB`;
}

/** O poziție într-un video, ca pe un player: 3500 → „0:03”, 71000 → „1:11”. */
export function formatTimecode(milliseconds: number): string {
    const totalSeconds = Math.floor(milliseconds / 1000);

    return `${Math.floor(totalSeconds / 60)}:${String(totalSeconds % 60).padStart(2, '0')}`;
}
