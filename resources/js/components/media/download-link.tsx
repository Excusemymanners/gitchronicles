import { formatFileSize } from '@/lib/format';

type Props = {
    /** Adresa de descărcare; `null` cât timp fișierul nu există: atunci butonul nu apare. */
    href: string | null;
    /** De exemplu „Descarcă MP4”. */
    label: string;
    /** Mărimea fișierului, afișată lângă text când o știm. */
    sizeBytes?: number | null;
    /** Butonul principal (plin) sau unul secundar (cu contur). */
    variant?: 'primary' | 'secondary';
};

const variants = {
    primary: 'bg-accent-strong text-on-accent hover:bg-accent-strong-hover',
    secondary: 'bg-surface text-ink ring-1 ring-inset ring-line-strong hover:bg-surface-2',
};

/**
 * O legătură de descărcare, arătată ca un buton. E un <a download> obișnuit, nu o vizită Inertia:
 * serverul răspunde cu fișierul, nu cu o pagină.
 */
export default function DownloadLink({ href, label, sizeBytes = null, variant = 'secondary' }: Props) {
    if (href === null) {
        return null;
    }

    return (
        <a
            href={href}
            download
            className={`inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-semibold shadow-xs focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent ${variants[variant]}`}
        >
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" className="size-4">
                <path d="M12 4v11M7 11l5 5 5-5M5 20h14" />
            </svg>
            {label}
            {sizeBytes !== null && <span className="font-normal opacity-80">{formatFileSize(sizeBytes)}</span>}
        </a>
    );
}
