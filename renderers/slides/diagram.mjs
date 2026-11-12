/**
 * Desenează o diagramă Mermaid ca imagine PNG (de patru ori mai fină decât mărimea ei, ca să poată fi mărită), pentru slide-uri:
 * Marp nu știe Mermaid, iar un SVG Mermaid pus ca imagine iese gol (etichetele lui sunt HTML).
 *
 * Folosește Chrome-ul calculatorului (CHROME_PATH) prin puppeteer-core și Mermaid-ul local, cu aceleași
 * logo-uri de tehnologii ca aplicația (resources/js/lib/diagram-icons.json) și culorile temei întunecate
 * a aplicației, ca diagrama să se vadă bine pe fundalul bleumarin al slide-urilor. Nu folosește internetul.
 *
 * Folosire: node diagram.mjs <cod.mmd> <imagine.png> <iconite.json> [paleta.json]
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import puppeteer from 'puppeteer-core';

const [codePath, outputPath, iconsPath] = process.argv.slice(2, 5);

if (!codePath || !outputPath || !iconsPath || !process.env.CHROME_PATH) {
    console.error('Folosire: CHROME_PATH=… node diagram.mjs <cod.mmd> <imagine.png> <iconite.json>');
    process.exit(2);
}

const here = dirname(fileURLToPath(import.meta.url));
const code = readFileSync(codePath, 'utf8');
const icons = JSON.parse(readFileSync(iconsPath, 'utf8'));
const fonts = join(here, '..', 'video', 'assets', 'fonts');

/** Fonturile, în base64 (pagina e goală, fără acces la fișiere). */
const fontFace = (weight, subset) => {
    const data = readFileSync(join(fonts, `instrument-sans-${subset}-${weight}-normal.woff2`)).toString('base64');

    return `@font-face { font-family: 'Instrument Sans'; font-weight: ${weight}; src: url(data:font/woff2;base64,${data}) format('woff2'); }`;
};

/**
 * Culorile diagramei vin din paleta repository-ului (SlidePalette): fundalul slide-ului e hârtia lui, iar
 * casetele au culoarea lui de accent. Fără paletă (al patrulea argument) se folosesc culori neutre.
 */
const palette = process.argv[5]
    ? JSON.parse(readFileSync(process.argv[5], 'utf8'))
    : { paper: '#fafafa', surface: '#f0f0f0', line: '#d0d0d0', ink: '#151515', muted: '#555555', accent: '#2a5bd7', onAccent: '#ffffff', accentInk: '#2347a8', brand: '#14295f', onBrand: '#ffffff' };

/** Clasele C4 ca în resources/js/lib/mermaid.ts, dar cu paleta slide-ului. */
const CLASS_STYLES = {
    person: `fill:${palette.brand},stroke:${palette.brand},color:${palette.onBrand}`,
    system: `fill:${palette.brand},stroke:${palette.brand},color:${palette.onBrand}`,
    containerBox: `fill:${palette.accent},stroke:${palette.accentInk},color:${palette.onAccent}`,
    component: `fill:${palette.accent},stroke:${palette.accentInk},color:${palette.onAccent}`,
    externalBox: `fill:${palette.surface},stroke:${palette.muted},color:${palette.ink}`,
    more: `fill:none,stroke:${palette.muted},stroke-dasharray:4 3,color:${palette.muted}`,
    boundary: `fill:none,stroke:${palette.muted},stroke-dasharray:6 4`,
};

const themedCode = code.replace(/^(\s*classDef\s+)([\w-]+)(\s+).*$/gm, (line, start, name, space) =>
    name in CLASS_STYLES ? `${start}${name}${space}${CLASS_STYLES[name]}` : line,
);

const browser = await puppeteer.launch({
    executablePath: process.env.CHROME_PATH,
    headless: true,
    args: ['--no-first-run', '--no-default-browser-check', '--disable-extensions'],
});

try {
    const page = await browser.newPage();
    await page.setViewport({ width: 1800, height: 1200, deviceScaleFactor: 4 });
    await page.setContent(`<!doctype html><html><head><meta charset="utf-8"><style>
        ${fontFace(400, 'latin')} ${fontFace(400, 'latin-ext')} ${fontFace(600, 'latin')} ${fontFace(600, 'latin-ext')}
        html, body { margin: 0; background: transparent; }
        #diagram { display: inline-block; padding: 12px; }
    </style></head><body><div id="diagram"></div></body></html>`);
    await page.addScriptTag({ path: join(here, '..', 'node_modules', 'mermaid', 'dist', 'mermaid.min.js') });

    await page.evaluate(async (source, iconPack, pal) => {
        const mermaid = window.mermaid;
        mermaid.registerIconPacks([{ name: 'logos', icons: iconPack }]);
        mermaid.initialize({
            startOnLoad: false,
            securityLevel: 'strict',
            theme: 'base',
            darkMode: false,
            fontFamily: "'Instrument Sans', sans-serif",
            themeVariables: {
                fontFamily: "'Instrument Sans', sans-serif",
                fontSize: '16px',
                background: pal.paper,
                mainBkg: pal.surface,
                primaryColor: pal.surface,
                primaryTextColor: pal.ink,
                primaryBorderColor: pal.muted,
                nodeBorder: pal.muted,
                nodeTextColor: pal.ink,
                secondaryColor: pal.surface,
                tertiaryColor: pal.paper,
                textColor: pal.ink,
                titleColor: pal.muted,
                lineColor: pal.muted,
                clusterBkg: pal.paper,
                clusterBorder: pal.line,
                edgeLabelBackground: pal.paper,
            },
        });

        await Promise.all([document.fonts.load("400 15px 'Instrument Sans'", 'ăîșț'), document.fonts.load("600 15px 'Instrument Sans'", 'ăîșț')]);

        // La fel ca în aplicație: dacă opțiunile ELK nu merg, mai încercăm o dată fără ele.
        let svg;

        try {
            svg = (await mermaid.render('slide-diagram', source)).svg;
        } catch (error) {
            svg = (await mermaid.render('slide-diagram-2', source.replace(/^ {2}elk:\n(?: {4}.*\n)+/m, ''))).svg;
        }

        document.getElementById('diagram').innerHTML = svg;
    }, themedCode, icons, palette);

    const element = await page.$('#diagram');
    await element.screenshot({ path: outputPath, omitBackground: true });
} finally {
    await browser.close();
}
