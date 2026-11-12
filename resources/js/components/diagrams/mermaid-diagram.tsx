import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { useI18n } from '@/hooks/use-i18n';
import { renderDiagram, useDiagramTheme } from '@/lib/mermaid';

type Props = {
    /** Codul Mermaid, așa cum l-a salvat serverul. */
    code: string;
    /** Numele diagramei, pentru cititoarele de ecran. */
    title: string;
    /** Previzualizare mică (de exemplu într-un card): fără butoane, micșorată ca să încapă toată. */
    compact?: boolean;
};

type RenderState = { status: 'loading' } | { status: 'ready'; svg: string } | { status: 'error' };

/**
 * O diagramă Mermaid: o desenează în browser, o redesenează când se schimbă tema și oferă
 * „Copiază codul Mermaid”. Dacă desenul nu reușește, spune asta pe scurt și arată codul.
 *
 * Mărimea: o diagramă nu se mărește peste mărimea ei firească și nu se micșorează sub 75% din ea;
 * dacă tot nu încape (de exemplu pe telefon), caseta se derulează orizontal.
 */
export default function MermaidDiagram({ code, title, compact = false }: Props) {
    const { t } = useI18n();
    const theme = useDiagramTheme();
    const [state, setState] = useState<RenderState>({ status: 'loading' });
    const [copyStatus, setCopyStatus] = useState<'idle' | 'copied' | 'failed'>('idle');
    const [isFullScreen, setIsFullScreen] = useState(false);
    const [isOverflowing, setIsOverflowing] = useState(false);
    const scrollBoxRef = useRef<HTMLDivElement>(null);
    const diagramRef = useRef<HTMLDivElement>(null);
    const dialogRef = useRef<HTMLDialogElement>(null);

    // La o schimbare de temă păstrăm desenul vechi până e gata cel nou, ca pagina să nu clipească.
    useEffect(() => {
        let cancelled = false;

        renderDiagram(code, theme).then(
            (svg) => !cancelled && setState({ status: 'ready', svg }),
            () => !cancelled && setState({ status: 'error' }),
        );

        return () => {
            cancelled = true;
        };
    }, [code, theme]);

    // Mermaid scrie lățimea firească în style="max-width: …px"; de aici știm limita de jos (75%).
    useLayoutEffect(() => {
        const svg = diagramRef.current?.querySelector('svg');

        if (!svg) {
            return;
        }

        svg.style.height = 'auto';

        if (compact) {
            svg.style.width = '100%';
            svg.style.height = '100%';
        } else {
            const naturalWidth = parseFloat(svg.style.maxWidth);

            if (naturalWidth > 0) {
                svg.style.minWidth = `${Math.round(naturalWidth * 0.75)}px`;
            }
        }
    }, [state, compact]);

    // Când diagrama e mai lată decât caseta (de obicei pe telefon), spunem că se poate derula.
    useEffect(() => {
        const scrollBox = scrollBoxRef.current;

        if (!scrollBox || state.status !== 'ready') {
            return;
        }

        const observer = new ResizeObserver(() => setIsOverflowing(scrollBox.scrollWidth > scrollBox.clientWidth + 1));
        observer.observe(scrollBox);

        return () => observer.disconnect();
    }, [state]);

    // „Ecran complet”: un <dialog> modal cu diagrama la mărimea ei firească, derulabilă în ambele direcții.
    // Desenul se pune în dialog doar cât e deschis (altfel ar exista de două ori în pagină).
    useEffect(() => {
        if (isFullScreen) {
            dialogRef.current?.showModal();
        }
    }, [isFullScreen]);

    async function copyCode() {
        try {
            await navigator.clipboard.writeText(code);
            setCopyStatus('copied');
        } catch {
            setCopyStatus('failed');
        }

        window.setTimeout(() => setCopyStatus('idle'), 2500);
    }

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
            <div role="img" aria-label={t('diagrams.mermaid.label', { title })} className="h-full w-full">
                {state.status === 'loading' ? (
                    <div className="h-full w-full rounded-md bg-surface-2 motion-safe:animate-pulse" />
                ) : (
                    <div ref={diagramRef} className="h-full w-full [&_svg]:mx-auto" dangerouslySetInnerHTML={{ __html: state.svg }} />
                )}
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center justify-end gap-2">
                <span aria-live="polite" className="text-xs text-ink-subtle">
                    {copyStatus === 'copied' && t('diagrams.mermaid.copied')}
                    {copyStatus === 'failed' && t('diagrams.mermaid.copyFailed')}
                </span>
                <button type="button" onClick={() => setIsFullScreen(true)} disabled={state.status !== 'ready'} className={toolbarButton}>
                    {t('diagrams.mermaid.fullScreen')}
                </button>
                <button type="button" onClick={copyCode} className={toolbarButton}>
                    {t('diagrams.mermaid.copyCode')}
                </button>
            </div>

            {/* Caseta se derulează orizontal când diagrama e mai lată decât pagina; tabIndex o face accesibilă din tastatură. */}
            <div
                ref={scrollBoxRef}
                role="group"
                aria-label={t('diagrams.mermaid.label', { title })}
                tabIndex={0}
                className="overflow-x-auto rounded-lg border border-line bg-surface p-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
                {state.status === 'loading' ? (
                    <div className="h-64 rounded-md bg-surface-2 motion-safe:animate-pulse">
                        <span className="sr-only">{t('diagrams.mermaid.drawing')}</span>
                    </div>
                ) : (
                    <div ref={diagramRef} className="[&_svg]:mx-auto" dangerouslySetInnerHTML={{ __html: state.svg }} />
                )}
            </div>

            {isOverflowing && (
                <p className="text-xs text-ink-subtle">{t('diagrams.mermaid.overflow')}</p>
            )}

            {isFullScreen && state.status === 'ready' && (
                <dialog
                    ref={dialogRef}
                    aria-label={t('diagrams.mermaid.fullScreenLabel', { title })}
                    onClose={() => setIsFullScreen(false)}
                    className="m-auto h-[calc(100dvh-2rem)] max-h-none w-[calc(100vw-2rem)] max-w-none rounded-lg border border-line bg-surface-raised p-0 text-ink shadow-lg backdrop:bg-canvas/80 backdrop:backdrop-blur-sm"
                >
                    <div className="flex h-full flex-col">
                        <div className="flex items-center justify-between gap-4 border-b border-line px-4 py-3">
                            <p className="truncate text-sm font-semibold">{title}</p>
                            <button type="button" onClick={() => dialogRef.current?.close()} className={toolbarButton} autoFocus>
                                {t('diagrams.mermaid.close')}
                            </button>
                        </div>
                        {/* Aici diagrama are mărimea ei firească: se derulează în ambele direcții. */}
                        <div
                            ref={showAtNaturalSize}
                            className="min-h-0 flex-1 overflow-auto p-4 [&_svg]:mx-auto"
                            dangerouslySetInnerHTML={{ __html: state.svg }}
                        />
                    </div>
                </dialog>
            )}
        </div>
    );
}

/** În dialog, diagrama nu se micșorează: lățimea minimă devine lățimea ei firească. */
function showAtNaturalSize(element: HTMLDivElement | null): void {
    const svg = element?.querySelector('svg');

    if (svg) {
        svg.style.minWidth = svg.style.maxWidth;
    }
}

const toolbarButton =
    'rounded-md bg-surface px-2.5 py-1 text-xs font-medium text-ink shadow-xs ring-1 ring-line-strong ring-inset hover:bg-surface-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent disabled:cursor-not-allowed disabled:opacity-60';
