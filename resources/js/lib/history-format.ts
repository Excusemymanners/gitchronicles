/** Formatări pentru istoricul git (tab-ul „Istoric” și cardurile lui), toate în română. */

const monthFormatter = new Intl.DateTimeFormat('ro-RO', { month: 'long', year: 'numeric' });

const shortMonthFormatter = new Intl.DateTimeFormat('ro-RO', { month: 'short', year: 'numeric' });

const dayFormatter = new Intl.DateTimeFormat('ro-RO', { dateStyle: 'medium' });

const usdFormatter = new Intl.NumberFormat('ro-RO', { style: 'currency', currency: 'USD', maximumFractionDigits: 2 });

const secondsFormatter = new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 1 });

/** Luna unei date ISO 8601, ca cheie de grupare: „2026-11” (în fusul orar al vizitatorului). */
export function monthKey(isoDate: string): string {
    const date = new Date(isoDate);

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

/** O cheie „2026-11” ca lună întreagă: „noiembrie 2026”; cu `short`: „nov. 2026”. */
export function formatMonth(key: string, short = false): string {
    const [year, month] = key.split('-').map(Number);
    const date = new Date(year, month - 1, 1);

    return (short ? shortMonthFormatter : monthFormatter).format(date);
}

/** Doar ziua unei date ISO 8601, de exemplu „9 nov. 2026”. */
export function formatDay(isoDate: string): string {
    return dayFormatter.format(new Date(isoDate));
}

/** O durată în milisecunde: „0,8 s”, „37 s”, „1 min 9 s”. */
export function formatDuration(milliseconds: number): string {
    const seconds = milliseconds / 1000;

    if (seconds < 60) {
        return `${secondsFormatter.format(seconds)} s`;
    }

    const minutes = Math.floor(seconds / 60);
    const restSeconds = Math.round(seconds - minutes * 60);

    return restSeconds === 0 ? `${minutes} min` : `${minutes} min ${restSeconds} s`;
}

/** O sumă în dolari, de exemplu „0,12 USD”. */
export function formatUsd(amount: number): string {
    return usdFormatter.format(amount);
}

/** Legătura spre commit pe GitHub. */
export function commitUrl(owner: string, name: string, sha: string): string {
    return `https://github.com/${owner}/${name}/commit/${sha}`;
}

/** Legătura spre un fișier pe GitHub, așa cum era la commit-ul dat; fiecare parte a căii e codificată. */
export function fileUrl(owner: string, name: string, sha: string, path: string): string {
    return `https://github.com/${owner}/${name}/blob/${sha}/${path.split('/').map(encodeURIComponent).join('/')}`;
}
