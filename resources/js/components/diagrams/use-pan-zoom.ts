import { useCallback, useEffect, useRef, useState } from 'react';
import type { KeyboardEvent, PointerEvent, RefObject } from 'react';

/** Where the diagram sits in its canvas: `translate(x, y) scale(scale)` with the origin at the top left. */
export type PanZoom = { scale: number; x: number; y: number };

export const MIN_SCALE = 0.3;
export const MAX_SCALE = 4;
/** Space left around the diagram when it is fitted to the canvas. */
const FIT_MARGIN = 16;
/** One click on + or − multiplies the scale by this. */
const ZOOM_STEP = 1.25;
/** A pointer that moved less than this many pixels between press and release was a click, not a drag. */
const DRAG_THRESHOLD = 4;

type Size = { width: number; height: number };

/**
 * Pan and zoom for one diagram, without a library.
 *
 * - Ctrl + wheel (and the pinch of a trackpad, which browsers send as Ctrl + wheel) zooms around the pointer;
 *   a plain wheel is left alone, so the page keeps scrolling;
 * - dragging pans; the keys + − 0 work while the canvas has focus; a double click fits the diagram;
 * - `animated` is true only for changes made by a button or key, so dragging and wheel zoom stay instant.
 *
 * `contentSize` is the natural size of the diagram in pixels; `null` until the SVG is in the page.
 */
export function usePanZoom(canvasRef: RefObject<HTMLElement | null>, contentSize: Size | null) {
    const [view, setView] = useState<PanZoom>({ scale: 1, x: 0, y: 0 });
    const [animated, setAnimated] = useState(false);
    const [isDragging, setIsDragging] = useState(false);
    const viewRef = useRef(view);
    const drag = useRef<{ pointerId: number; startX: number; startY: number; originX: number; originY: number; moved: boolean } | null>(null);
    /** True when the last pointer gesture was a drag, so the click that ends it must not open a link. */
    const justDragged = useRef(false);
    const hasFitted = useRef(false);

    const apply = useCallback((next: PanZoom, withTransition: boolean) => {
        viewRef.current = next;
        setAnimated(withTransition);
        setView(next);
    }, []);

    const fit = useCallback(
        (withTransition = true) => {
            const canvas = canvasRef.current;

            if (!canvas || !contentSize || canvas.clientWidth === 0) {
                return;
            }

            // A very tall diagram may need less than MIN_SCALE to fit: "fit" always wins over the zoom limits.
            const scale = Math.min((canvas.clientWidth - FIT_MARGIN * 2) / contentSize.width, (canvas.clientHeight - FIT_MARGIN * 2) / contentSize.height, 1);

            apply(
                {
                    scale,
                    x: (canvas.clientWidth - contentSize.width * scale) / 2,
                    y: (canvas.clientHeight - contentSize.height * scale) / 2,
                },
                withTransition,
            );
        },
        [apply, canvasRef, contentSize],
    );

    /** Zoom by `factor`, keeping the point (px, py) of the canvas where it is; default: the canvas center. */
    const zoomBy = useCallback(
        (factor: number, withTransition: boolean, px?: number, py?: number) => {
            const canvas = canvasRef.current;
            const current = viewRef.current;

            if (!canvas) {
                return;
            }

            const pointX = px ?? canvas.clientWidth / 2;
            const pointY = py ?? canvas.clientHeight / 2;
            // Zooming out never goes below MIN_SCALE, unless the diagram is already smaller (fitted).
            const scale = Math.min(MAX_SCALE, Math.max(current.scale * factor, Math.min(MIN_SCALE, current.scale)));
            const ratio = scale / current.scale;

            apply({ scale, x: pointX - (pointX - current.x) * ratio, y: pointY - (pointY - current.y) * ratio }, withTransition);
        },
        [apply, canvasRef],
    );

    // The first time the diagram is known, and whenever it is another drawing, fit it to the canvas.
    useEffect(() => {
        if (contentSize) {
            fit(false);
            hasFitted.current = true;
        }
    }, [contentSize]); // eslint-disable-line react-hooks/exhaustive-deps

    // When the canvas changes size (window, full screen), keep the diagram fitted if the reader has not moved it.
    useEffect(() => {
        const canvas = canvasRef.current;

        if (!canvas) {
            return;
        }

        let isUntouched = true;
        const observer = new ResizeObserver(() => {
            if (hasFitted.current && isUntouched) {
                fit(false);
            }
        });
        observer.observe(canvas);
        const markTouched = () => {
            isUntouched = false;
        };
        canvas.addEventListener('pointerdown', markTouched);
        canvas.addEventListener('wheel', markTouched);

        return () => {
            observer.disconnect();
            canvas.removeEventListener('pointerdown', markTouched);
            canvas.removeEventListener('wheel', markTouched);
        };
    }, [canvasRef, fit]);

    // Ctrl + wheel: a native listener, because React's onWheel is passive and could not stop the browser's own page zoom.
    useEffect(() => {
        const canvas = canvasRef.current;

        if (!canvas) {
            return;
        }

        const onWheel = (event: WheelEvent) => {
            if (!event.ctrlKey) {
                return;
            }

            event.preventDefault();
            const box = canvas.getBoundingClientRect();
            zoomBy(Math.exp(-event.deltaY * 0.0025), false, event.clientX - box.left, event.clientY - box.top);
        };

        canvas.addEventListener('wheel', onWheel, { passive: false });

        return () => canvas.removeEventListener('wheel', onWheel);
    }, [canvasRef, zoomBy]);

    const onPointerDown = (event: PointerEvent<HTMLElement>) => {
        if (event.button !== 0) {
            return;
        }

        drag.current = {
            pointerId: event.pointerId,
            startX: event.clientX,
            startY: event.clientY,
            originX: viewRef.current.x,
            originY: viewRef.current.y,
            moved: false,
        };
        justDragged.current = false;
    };

    const onPointerMove = (event: PointerEvent<HTMLElement>) => {
        const state = drag.current;

        if (!state || state.pointerId !== event.pointerId) {
            return;
        }

        const dx = event.clientX - state.startX;
        const dy = event.clientY - state.startY;

        if (!state.moved && Math.hypot(dx, dy) < DRAG_THRESHOLD) {
            return;
        }

        if (!state.moved) {
            state.moved = true;
            setIsDragging(true);
            // Only now do we capture the pointer, so a plain click still reaches the node under it.
            event.currentTarget.setPointerCapture(event.pointerId);
        }

        apply({ ...viewRef.current, x: state.originX + dx, y: state.originY + dy }, false);
    };

    const endDrag = (event: PointerEvent<HTMLElement>) => {
        const state = drag.current;

        if (!state || state.pointerId !== event.pointerId) {
            return;
        }

        justDragged.current = state.moved;
        drag.current = null;
        setIsDragging(false);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLElement>) => {
        if (event.target !== event.currentTarget || event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }

        if (event.key === '+' || event.key === '=') {
            zoomBy(ZOOM_STEP, true);
        } else if (event.key === '-' || event.key === '_') {
            zoomBy(1 / ZOOM_STEP, true);
        } else if (event.key === '0') {
            fit();
        } else {
            return;
        }

        event.preventDefault();
    };

    return {
        view,
        animated,
        isDragging,
        justDragged,
        zoomIn: () => zoomBy(ZOOM_STEP, true),
        zoomOut: () => zoomBy(1 / ZOOM_STEP, true),
        fit: () => fit(true),
        handlers: { onPointerDown, onPointerMove, onPointerUp: endDrag, onPointerCancel: endDrag, onKeyDown, onDoubleClick: () => fit(true) },
    };
}
