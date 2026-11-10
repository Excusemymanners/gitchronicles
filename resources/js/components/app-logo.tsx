/*
 * Logo-ul GitChronicle: pana cronicarului scrie o linie cu două commit-uri, care se continuă într-un cerc
 * ca un cadran de ceas (timpul, istoria repository-ului). Imaginea e în public/logo-256.png
 * (și public/logo.png, 512 px, pentru documente).
 */

type MarkProps = {
    className?: string;
};

/** Doar iconița. E decorativă (alt gol): numele îl citește textul de lângă ea. */
export function AppLogoMark({ className }: MarkProps) {
    return <img src="/logo-256.png" alt="" width={256} height={256} className={className} draggable={false} />;
}

type LogoProps = {
    className?: string;
};

/** Iconița + numele „GitChronicle”, scris cu Mona Sans (fontul open-source al GitHub). */
export default function AppLogo({ className = '' }: LogoProps) {
    return (
        <span className={`inline-flex items-center gap-2.5 ${className}`}>
            <AppLogoMark className="size-8 shrink-0" />
            <span className="font-brand text-lg font-bold tracking-tight">GitChronicle</span>
        </span>
    );
}
