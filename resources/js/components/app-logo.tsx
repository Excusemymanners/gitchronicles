/*
 * Logo-ul GitChronicle: pana cronicarului scrie o linie cu două commit-uri, care se continuă într-un cerc
 * ca un cadran de ceas (timpul, istoria repository-ului). Desenul e redesenat curat, după imaginea originală
 * (public/logo.png, păstrată neschimbată), în public/brand/:
 * - gitchronicle-mark.svg: iconița (pătrat bleumarin cu colțuri rotunjite), ține de la 16 px la 512 px;
 *   are o margine fină deschisă, așa că se vede și pe fundalul la fel de închis al temei întunecate;
 * - gitchronicle-glyph.svg: varianta într-o singură culoare, fără fundal (cerneală, sau hârtie pe ecran întunecat);
 * - favicon-uri, apple-touch-icon.png și icon-192/512.png, făcute din aceeași iconiță.
 */

/** Iconița afișată în aplicație. Pentru a reveni la imaginea originală: '/logo-256.png'. */
const MARK_SRC = '/brand/gitchronicle-mark.svg';

type MarkProps = {
    className?: string;
    /**
     * Iconița din antet are același nume de tranziție pe toate paginile (view-transition-name), așa că
     * la trecerea dintre pagini alunecă de la un loc la altul în loc să dispară și să reapară.
     * O a doua iconiță pe aceeași pagină trebuie să primească shared={false}: numele trebuie să fie unic.
     */
    shared?: boolean;
};

/** Doar iconița. E decorativă (alt gol): numele îl citește textul de lângă ea. */
export function AppLogoMark({ className = '', shared = true }: MarkProps) {
    return (
        <img
            src={MARK_SRC}
            alt=""
            width={512}
            height={512}
            style={shared ? { viewTransitionName: 'brand-mark' } : undefined}
            className={`rounded-[24%] select-none ${className}`}
            draggable={false}
        />
    );
}

type LogoProps = {
    className?: string;
};

/**
 * Iconița + numele „GitChronicle”, scris cu Mona Sans (fontul open-source al GitHub).
 * Proporțiile ansamblului: iconița are 28 px, numele 17 px (înălțimea majusculelor e cam 0,43 din iconiță),
 * spațiul dintre ele e 10 px (cam o treime din iconiță), iar numele stă centrat optic pe iconiță.
 */
export default function AppLogo({ className = '' }: LogoProps) {
    return (
        <span className={`inline-flex items-center gap-2.5 ${className}`}>
            <AppLogoMark className="size-7 shrink-0" />
            <span className="font-brand text-[1.0625rem] leading-none font-bold tracking-[-0.015em]">GitChronicle</span>
        </span>
    );
}
