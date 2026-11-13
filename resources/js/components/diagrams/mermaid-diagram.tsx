import { useEffect, useRef, useState } from 'react';
import type { RefObject } from 'react';
import DiagramViewer from '@/components/diagrams/diagram-viewer';
import type { DiagramHighlight } from '@/components/diagrams/diagram-viewer';
import type { DiagramModules } from '@/components/diagrams/types';
import { useI18n } from '@/hooks/use-i18n';
import { renderDiagram, useDiagramTheme } from '@/lib/mermaid';

type Props = {
    /** Codul Mermaid, așa cum l-a salvat serverul. */
    code: string;
    /** Numele diagramei, pentru cititoarele de ecran. */
    title: string;
    /** Previzualizare mică (de exemplu într-un card): fără butoane, micșorată ca să încapă toată. */
    compact?: boolean;
    /** Nodurile care duc la documentația unui modul. */
    modules?: DiagramModules;
    /** Începutul adreselor paginilor de module, de exemplu „/repositories/1/docs”. */
    docsBase?: string | null;
    /** Told which box the pointer or the focus is on (node id), or null. */
    onNodeActive?: (nodeId: string | null) => void;
    /** What to light in the picture (boxes, arrows, sequence messages). */
    highlight?: DiagramHighlight;
};

type RenderState = { status: 'loading' } | { status: 'ready'; svg: string } | { status: 'error' };

const NO_MODULES: DiagramModules = {};

/** Adevărat când elementul e la cel mult o jumătate de ecran de zona vizibilă; mereu adevărat dacă `isGated` e fals. */
function useIsNearScreen(ref: RefObject<HTMLElement | null>, isGated: boolean): boolean {
    const [isNear, setIsNear] = useState(false);

    useEffect(() => {
        const element = ref.current;

        if (!isGated || isNear || element === null) {
            return;
        }

        if (typeof IntersectionObserver === 'undefined') {
            setIsNear(true);

            return;
        }

        const observer = new IntersectionObserver(
            (entries) => entries.some((entry) => entry.isIntersecting) && setIsNear(true),
            { rootMargin: '50% 0px' },
        );
        observer.observe(element);

        return () => observer.disconnect();
    }, [ref, isGated, isNear]);

    return !isGated || isNear;
}

/**
 * O diagramă Mermaid: o desenează în browser, o redesenează când se schimbă tema și o arată într-un
 * DiagramViewer (zoom, deplasare, ecran complet, export). Dacă desenul nu reușește, spune asta pe scurt
 * și arată codul. Varianta „compact” e doar o previzualizare micșorată, fără unelte.
 */
export default function MermaidDiagram({ code, title, compact = false, modules = NO_MODULES, docsBase = null, onNodeActive, highlight }: Props) {
    const { t } = useI18n();
    const theme = useDiagramTheme();
    const [state, setState] = useState<RenderState>({ status: 'loading' });
    const [isFullScreen, setIsFullScreen] = useState(false);
    const dialogRef = useRef<HTMLDialogElement>(null);
    const previewRef = useRef<HTMLDivElement>(null);
    const previewHolderRef = useRef<HTMLDivElement>(null);

    // O previzualizare (card) se desenează abia când ajunge aproape de ecran: Mermaid și ELK sunt peste 2 MB,
    // iar un card de sub pagină nu trebuie să încetinească ce se vede. Diagrama întreagă se desenează imediat.
    const isNearScreen = useIsNearScreen(previewHolderRef, compact);

    // La o schimbare de temă păstrăm desenul vechi până e gata cel nou, ca pagina să nu clipească.
    useEffect(() => {
        if (!isNearScreen) {
            return;
        }

        let cancelled = false;

        renderDiagram(code, theme).then(
            (svg) => !cancelled && setState({ status: 'ready', svg }),
            () => !cancelled && setState({ status: 'error' }),
        );

        return () => {
            cancelled = true;
        };
    }, [code, theme, isNearScreen]);

    // Previzualizarea umple caseta ei; Mermaid scrie width="100%" și o lățime maximă pe care o scoatem.
    useEffect(() => {
        const svg = previewRef.current?.querySelector('svg');

        if (compact && svg) {
            svg.style.maxWidth = 'none';
            svg.style.width = '100%';
            svg.style.height = '100%';
        }
    }, [state, compact]);

    // „Ecran complet”: un <dialog> modal; desenul se pune în el doar cât e deschis.
    useEffect(() => {
        if (isFullScreen) {
            dialogRef.current?.showModal();
        }
    }, [isFullScreen]);

    if (state.status === 'error') {
        return (
            <div role="alert" className="rounded-lg border border-danger-line bg-danger-soft p-4 text-sm text-danger-ink">
                <p>{t('diagrams.mermaid.failed')}</p>
                {!compact && (
                    <details className="mt-3">
                        <summary className="cursor-pointer font-medium">{t('diagrams.mermaid.code')}</summary>
                        <pre className="mt-2 max-h-80 overflow-auto rounded-md border border-line bg-canvas p-3 font-mono text-xs text-ink">
                            {code}
                        </pre>
                    </details>
                )}
            </div>
        );
    }

    if (compact) {
        return (
            <div ref={previewHolderRef} role="img" aria-label={t('diagrams.mermaid.label', { title })} className="h-full w-full">
                {state.status === 'loading' ? (
                    <div className="skeleton h-full w-full rounded-md" />
                ) : (
                    <div ref={previewRef} className="h-full w-full [&_svg]:mx-auto" dangerouslySetInnerHTML={{ __html: state.svg }} />
                )}
            </div>
        );
    }

    if (state.status === 'loading') {
        return (
            <div className="skeleton h-[min(78dvh,44rem)] rounded-lg border border-line">
                <span className="sr-only">{t('diagrams.mermaid.drawing')}</span>
            </div>
        );
    }

    return (
        <>
            <DiagramViewer svg={state.svg} code={code} title={title} modules={modules} docsBase={docsBase} onFullScreen={() => setIsFullScreen(true)} onNodeActive={onNodeActive} highlight={highlight} />

            {isFullScreen && (
                <dialog
                    ref={dialogRef}
                    aria-label={t('diagrams.mermaid.fullScreenLabel', { title })}
                    onClose={() => setIsFullScreen(false)}
                    className="m-auto h-[calc(100dvh-2rem)] max-h-none w-[calc(100vw-2rem)] max-w-none overflow-hidden rounded-lg border border-line bg-surface-raised p-0 text-ink shadow-float backdrop:bg-canvas/80 backdrop:backdrop-blur-sm"
                >
                    <DiagramViewer svg={state.svg} code={code} title={title} modules={modules} docsBase={docsBase} onClose={() => dialogRef.current?.close()} onNodeActive={onNodeActive} highlight={highlight} />
                </dialog>
            )}
        </>
    );
}
