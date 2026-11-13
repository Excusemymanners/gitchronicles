/*
 * The GitChronicle logo (Brand 3): a "G" drawn as a git branch. The bowl of the G is an arc that starts at a
 * commit (the node at the upper right) and runs round to the right side; the crossbar comes in from there and ends
 * in a second commit at the centre. History that turns into a letter — the repository's story, written down.
 *
 * It is drawn inline in one colour (currentColor), so it takes the text colour around it and works on both
 * themes without a second file. The same shape, as files, is in public/brand/ (drawn by
 * gitchronicle-dev/shots/brand3/logo/brand.py on the same 32-unit grid):
 * - gitchronicle-glyph.svg: the glyph alone (ink on light, light ink under a dark system theme);
 * - gitchronicle-mark.svg: the glyph on a rounded tile, for favicons and documents;
 * - favicon.ico, favicon-16/32.png, apple-touch-icon.png, icon-192/512.png: the tile as pictures.
 * The Brand v2 files (the quill on a navy tile) are kept in public/brand/v2/.
 */

/**
 * Back to Brand v2 in one line: set this to '/brand/v2/gitchronicle-mark.svg' and the app shows the old tile
 * again (as an <img>). `null` draws the Brand 3 mark.
 */
const LEGACY_MARK_SRC: string | null = null;

type MarkProps = {
    className?: string;
    /**
     * The header's mark has the same view-transition name on every page, so on a page change it glides from one
     * place to the other instead of disappearing and coming back. A second mark on the same page needs
     * shared={false}: the name must be unique.
     */
    shared?: boolean;
};

/** The mark alone. It is decorative (aria-hidden): the text next to it says the name. */
export function AppLogoMark({ className = '', shared = true }: MarkProps) {
    const style = shared ? { viewTransitionName: 'brand-mark' } : undefined;

    if (LEGACY_MARK_SRC !== null) {
        return <img src={LEGACY_MARK_SRC} alt="" width={512} height={512} style={style} className={`rounded-[24%] select-none ${className}`} draggable={false} />;
    }

    return (
        <svg aria-hidden="true" viewBox="0 0 32 32" fill="none" style={style} className={`shrink-0 ${className}`}>
            <path d="M24.36 8.47A11.25 11.25 0 1 0 27.15 17.5H18" stroke="currentColor" strokeWidth="3.4" strokeLinecap="round" strokeLinejoin="round" />
            <circle cx="24.36" cy="8.47" r="3.6" fill="currentColor" />
            <circle cx="16" cy="17.5" r="3.3" fill="currentColor" />
        </svg>
    );
}

type LogoProps = {
    className?: string;
};

/**
 * The mark + the name "GitChronicle" in Mona Sans 600 (GitHub's open-source typeface), in one colour.
 * Proportions: the mark is 24 px (its G is about as tall as the name's capitals plus their ascent), the name
 * 17 px with slightly tight tracking, 8 px between them, the name centred optically on the mark.
 */
export default function AppLogo({ className = '' }: LogoProps) {
    return (
        <span className={`inline-flex items-center gap-2 ${className}`}>
            <AppLogoMark className="size-6" />
            <span className="font-brand text-[1.0625rem] leading-none font-semibold tracking-[-0.015em]">GitChronicle</span>
        </span>
    );
}
