/**
 * Saving a drawn diagram as a file. Both exports start from the SVG that is on the page, so they show
 * exactly what the reader sees (theme colors included).
 */

/** The SVG as a standalone file: its natural size written into width/height, so it opens at the right size anywhere. */
function standaloneSvg(svg: SVGSVGElement): { text: string; width: number; height: number } {
    const box = svg.viewBox.baseVal;
    const clone = svg.cloneNode(true) as SVGSVGElement;

    clone.setAttribute('width', String(box.width));
    clone.setAttribute('height', String(box.height));
    clone.style.removeProperty('max-width');
    clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
    // The click targets we add for navigation are not part of the picture.
    clone.querySelectorAll('[data-module-node]').forEach((node) => {
        ['tabindex', 'role', 'aria-label', 'data-module-node'].forEach((name) => node.removeAttribute(name));
    });

    return { text: new XMLSerializer().serializeToString(clone), width: box.width, height: box.height };
}

function saveBlob(blob: Blob, fileName: string): void {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = fileName;
    link.click();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

export function downloadSvg(svg: SVGSVGElement, fileName: string): void {
    saveBlob(new Blob([standaloneSvg(svg).text], { type: 'image/svg+xml;charset=utf-8' }), `${fileName}.svg`);
}

/**
 * Draws the SVG on a canvas at 2x and saves it as PNG, on the page's paper color (the SVG itself is transparent).
 * Resolves to false when the browser refuses to turn the picture into a PNG.
 */
export async function downloadPng(svg: SVGSVGElement, fileName: string, background: string): Promise<boolean> {
    const { text, width, height } = standaloneSvg(svg);
    const scale = 2;

    try {
        const image = new Image();
        image.src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(text)}`;
        await image.decode();

        const canvas = document.createElement('canvas');
        canvas.width = Math.round(width * scale);
        canvas.height = Math.round(height * scale);
        const context = canvas.getContext('2d');

        if (!context) {
            return false;
        }

        context.fillStyle = background;
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(image, 0, 0, canvas.width, canvas.height);

        const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/png'));

        if (!blob) {
            return false;
        }

        saveBlob(blob, `${fileName}.png`);

        return true;
    } catch {
        return false;
    }
}

/** A file name from a diagram title: "Layered architecture" -> "layered-architecture". */
export function fileNameFor(title: string): string {
    return title.toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-|-$/g, '') || 'diagram';
}
