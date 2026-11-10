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

/** Primele 7 caractere ale unui SHA, ca în `git log --oneline`. */
export function shortSha(sha: string): string {
    return sha.slice(0, 7);
}
