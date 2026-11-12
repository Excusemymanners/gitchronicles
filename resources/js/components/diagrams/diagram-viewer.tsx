import { router } from '@inertiajs/react';
import { useLayoutEffect, useRef, useState } from 'react';
import type { KeyboardEvent, MouseEvent } from 'react';
import { CloseIcon } from '@/components/icons';
import { CopyCodeIcon, DownloadIcon, FitIcon, FullScreenIcon, ImageIcon, ZoomInIcon, ZoomOutIcon } from '@/components/diagrams/diagram-icons';
import { downloadPng, downloadSvg, fileNameFor } from '@/components/diagrams/diagram-export';
import { usePanZoom } from '@/components/diagrams/use-pan-zoom';
import type { DiagramModules } from '@/components/diagrams/types';
import { useI18n } from '@/hooks/use-i18n';
import { formatNumber } from '@/lib/format';

type Props = {
    /** The SVG Mermaid drew. */
    svg: string;
    /** The Mermaid source, for "Copy Mermaid code". */
    code: string;
    title: string;
    /** Node id -> module, for the nodes that open a documentation page. */
    modules: DiagramModules;
    /** Where the module pages live, e.g. "/repositories/1/docs"; null = nodes are not links. */
    docsBase: string | null;
    /** Set in the page; the full-screen dialog passes `onClose` instead. */
    onFullScreen?: () => void;
    onClose?: () => void;
};

type Tooltip = { path: string; left: number; top: number };

const iconButton =
    'pressable inline-flex size-8 items-center justify-center rounded-md text-ink-muted hover:bg-surface-2 hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent disabled:opacity-50';

/** The id the builder gave a node (m1, c3, ...), read from Mermaid's element: `<id>-flowchart-<node>-<n>`. */
function nodeIdOf(element: Element): string | null {
    return element.getAttribute('data-id') ?? /-flowchart-(.+)-\d+$/.exec(element.id)?.[1] ?? null;
}

/**
 * One diagram as a small tool: a toolbar (zoom, fit, full screen, copy code, download) over a canvas that
 * can be panned and zoomed. Boxes that stand for a module with a documentation page are links.
 */
export default function DiagramViewer({ svg, code, title, modules, docsBase, onFullScreen, onClose }: Props) {
    const { t } = useI18n();
    const canvasRef = useRef<HTMLDivElement>(null);
    const contentRef = useRef<HTMLDivElement>(null);
    const [size, setSize] = useState<{ width: number; height: number } | null>(null);
    const [tooltip, setTooltip] = useState<Tooltip | null>(null);
    const [message, setMessage] = useState('');
    const pan = usePanZoom(canvasRef, size);
    const isFullScreen = onClose !== undefined;

    // Give the SVG its natural pixel size (Mermaid writes width="100%"), and make the linked boxes reachable.
    useLayoutEffect(() => {
        const element = contentRef.current?.querySelector('svg');

        if (!element) {
            return;
        }

        const box = element.viewBox.baseVal;
        element.setAttribute('width', String(box.width));
        element.setAttribute('height', String(box.height));
        element.style.maxWidth = 'none';
        element.style.display = 'block';

        element.querySelectorAll('g.node').forEach((node) => {
            const id = nodeIdOf(node);
            const module = id ? modules[id] : undefined;

            if (!module || !docsBase) {
                return;
            }

            node.setAttribute('data-module-node', id as string);
            node.setAttribute('tabindex', '0');
            node.setAttribute('role', 'link');
            node.setAttribute('aria-label', t('diagrams.mermaid.openModule', { path: module.path }));
        });

        setSize({ width: box.width, height: box.height });
    }, [svg, modules, docsBase, t]);

    function moduleOf(target: EventTarget | null) {
        const node = (target as Element | null)?.closest?.('[data-module-node]');
        const module = node ? modules[node.getAttribute('data-module-node') ?? ''] : undefined;

        return node && module ? { node, module } : null;
    }

    function showTooltip(target: EventTarget | null) {
        const found = moduleOf(target);
        const canvas = canvasRef.current;

        if (!found || !canvas) {
            setTooltip(null);

            return;
        }

        const canvasBox = canvas.getBoundingClientRect();
        const nodeBox = found.node.getBoundingClientRect();
        setTooltip({
            path: found.module.path,
            left: Math.min(Math.max(nodeBox.left - canvasBox.left + nodeBox.width / 2, 120), canvasBox.width - 120),
            top: Math.max(nodeBox.top - canvasBox.top - 8, 56),
        });
    }

    function open(target: EventTarget | null) {
        const found = moduleOf(target);

        if (found && docsBase) {
            router.visit(`${docsBase}/${found.module.slug}`);
        }
    }

    function onClick(event: MouseEvent) {
        if (!pan.justDragged.current) {
            open(event.target);
        }
    }

    function onKeyDown(event: KeyboardEvent<HTMLDivElement>) {
        if (event.key === 'Enter' && moduleOf(event.target)) {
            event.preventDefault();
            open(event.target);

            return;
        }

        pan.handlers.onKeyDown(event);
    }

    function announce(text: string) {
        setMessage(text);
        window.setTimeout(() => setMessage(''), 2500);
    }

    async function copyCode() {
        try {
            await navigator.clipboard.writeText(code);
            announce(t('diagrams.mermaid.copied'));
        } catch {
            announce(t('diagrams.mermaid.copyFailed'));
        }
    }

    function drawnSvg(): SVGSVGElement | null {
        return contentRef.current?.querySelector('svg') ?? null;
    }

    async function savePng() {
        const element = drawnSvg();

        if (element && canvasRef.current) {
            const saved = await downloadPng(element, fileNameFor(title), getComputedStyle(canvasRef.current).backgroundColor);

            if (!saved) {
                announce(t('diagrams.mermaid.pngFailed'));
            }
        }
    }

    return (
        <div className={`flex flex-col overflow-hidden ${isFullScreen ? 'h-full' : 'rounded-lg border border-line bg-surface'}`}>
            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-b border-line px-3 py-2">
                <p className="min-w-32 flex-1 truncate text-sm font-medium text-ink">{title}</p>
                <div className="flex flex-wrap items-center gap-0.5">
                    <span aria-live="polite" className="mr-2 text-xs text-ink-subtle">
                        {message}
                    </span>
                    <span className="mr-1 hidden w-10 text-right text-xs tabular-nums text-ink-subtle sm:inline" data-testid="zoom-level">
                        {formatNumber(Math.round(pan.view.scale * 100))}%
                    </span>
                    <button type="button" className={iconButton} onClick={pan.zoomOut} aria-label={t('diagrams.mermaid.zoomOut')} title={t('diagrams.mermaid.zoomOut')}>
                        <ZoomOutIcon />
                    </button>
                    <button type="button" className={iconButton} onClick={pan.zoomIn} aria-label={t('diagrams.mermaid.zoomIn')} title={t('diagrams.mermaid.zoomIn')}>
                        <ZoomInIcon />
                    </button>
                    <button type="button" className={iconButton} onClick={pan.fit} aria-label={t('diagrams.mermaid.fit')} title={t('diagrams.mermaid.fit')}>
                        <FitIcon />
                    </button>
                    {onFullScreen && (
                        <button type="button" className={iconButton} onClick={onFullScreen} aria-label={t('diagrams.mermaid.fullScreen')} title={t('diagrams.mermaid.fullScreen')}>
                            <FullScreenIcon />
                        </button>
                    )}
                    <button type="button" className={iconButton} onClick={copyCode} aria-label={t('diagrams.mermaid.copyCode')} title={t('diagrams.mermaid.copyCode')}>
                        <CopyCodeIcon />
                    </button>
                    <button type="button" className={iconButton} onClick={() => drawnSvg() && downloadSvg(drawnSvg() as SVGSVGElement, fileNameFor(title))} aria-label={t('diagrams.mermaid.downloadSvg')} title={t('diagrams.mermaid.downloadSvg')}>
                        <DownloadIcon />
                    </button>
                    <button type="button" className={iconButton} onClick={savePng} aria-label={t('diagrams.mermaid.downloadPng')} title={t('diagrams.mermaid.downloadPng')}>
                        <ImageIcon />
                    </button>
                    {onClose && (
                        <button type="button" className={`${iconButton} ml-1`} onClick={onClose} aria-label={t('diagrams.mermaid.close')} title={t('diagrams.mermaid.close')} autoFocus>
                            <CloseIcon />
                        </button>
                    )}
                </div>
            </div>

            <div
                ref={canvasRef}
                role="group"
                tabIndex={0}
                aria-label={t('diagrams.mermaid.canvasLabel', { title })}
                aria-describedby={undefined}
                data-testid="diagram-canvas"
                {...pan.handlers}
                onKeyDown={onKeyDown}
                onClick={onClick}
                onPointerOver={(event) => showTooltip(event.target)}
                onPointerOut={() => setTooltip(null)}
                onFocus={(event) => showTooltip(event.target)}
                onBlur={() => setTooltip(null)}
                className={`relative overflow-hidden bg-canvas select-none focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-accent ${
                    isFullScreen ? 'min-h-0 flex-1' : 'h-[min(78dvh,44rem)]'
                } ${pan.isDragging ? 'cursor-grabbing' : 'cursor-grab'} touch-pan-y`}
            >
                <div className="motion-safe:animate-fade-in">
                    <div
                        ref={contentRef}
                        data-testid="diagram-content"
                        style={{ transform: `translate(${pan.view.x}px, ${pan.view.y}px) scale(${pan.view.scale})`, transformOrigin: '0 0' }}
                        className={`absolute top-0 left-0 will-change-transform ${pan.animated ? 'transition-transform duration-(--motion-base) ease-(--ease-out)' : ''} [&_[data-module-node]]:cursor-pointer [&_[data-module-node]:focus-visible]:outline-2 [&_[data-module-node]:focus-visible]:outline-accent`}
                        dangerouslySetInnerHTML={{ __html: svg }}
                    />
                </div>

                {tooltip && (
                    <div
                        role="tooltip"
                        style={{ left: tooltip.left, top: tooltip.top }}
                        className="pointer-events-none absolute z-10 max-w-60 -translate-x-1/2 -translate-y-full rounded-md bg-accent-strong px-2.5 py-1.5 text-xs text-on-accent shadow-float"
                    >
                        <p className="font-mono break-all">{tooltip.path}</p>
                        <p className="opacity-80">{t('diagrams.mermaid.openDocs')}</p>
                    </div>
                )}

                <p className="pointer-events-none absolute right-2 bottom-2 hidden rounded-sm bg-surface/90 px-2 py-0.5 text-xs text-ink-subtle sm:block">
                    {t('diagrams.mermaid.hint')}
                </p>
            </div>
        </div>
    );
}
