/*
 * Logo-ul GitChronicle: pana cronicarului scrie o linie cu două commit-uri, care se continuă într-un cerc
 * ca un cadran de ceas (timpul, istoria repository-ului). Imaginea e în public/logo-256.png
 * (și public/logo.png, 512 px, pentru documente).
 */

type MarkProps = {
    className?: string;
    /**
     * Iconița din antet are același nume de tranziție pe toate paginile (view-transition-name), așa că
     * la trecerea dintre pagini alunecă de la un loc la altul în loc să dispară și să reapară.
     * O a doua iconiță pe aceeași pagină trebuie să primească shared={false}: numele trebuie să fie unic.
     */
    shared?: boolean;
};

/**
 * Doar iconița. E decorativă (alt gol): numele îl citește textul de lângă ea.
 * Fundalul ei bleumarin se pierde pe fundalul la fel de închis al temei întunecate,
 * așa că acolo primește un contur subțire, cu aceleași colțuri rotunjite ca imaginea.
 */
export function AppLogoMark({ className = '', shared = true }: MarkProps) {
    return (
        <img
            src="/logo-256.png"
            alt=""
            width={256}
            height={256}
            style={shared ? { viewTransitionName: 'brand-mark' } : undefined}
            className={`rounded-[24%] dark:ring-1 dark:ring-line-strong ${className}`}
            draggable={false}
        />
    );
}

type LogoProps = {
    className?: string;
};

/** Iconița + numele „GitChronicle”, scris cu Mona Sans (fontul open-source al GitHub), la mărimea din bara de sus. */
export default function AppLogo({ className = '' }: LogoProps) {
    return (
        <span className={`inline-flex items-center gap-2.5 ${className}`}>
            <AppLogoMark className="size-7 shrink-0" />
            <span className="font-brand text-base font-bold tracking-tight">GitChronicle</span>
        </span>
    );
}
