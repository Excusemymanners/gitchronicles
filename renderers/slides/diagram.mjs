/**
 * Desenează o diagramă Mermaid ca imagine PNG (de patru ori mai fină decât mărimea ei, ca să poată fi mărită), pentru slide-uri:
 * Marp nu știe Mermaid, iar un SVG Mermaid pus ca imagine iese gol (etichetele lui sunt HTML).
 *
 * Folosește Chrome-ul calculatorului (CHROME_PATH) prin puppeteer-core și Mermaid-ul local, cu aceleași
 * logo-uri de tehnologii ca aplicația (resources/js/lib/diagram-icons.json) și culorile temei întunecate
 * a aplicației, ca diagrama să se vadă bine pe fundalul bleumarin al slide-urilor. Nu folosește internetul.
 *
 * Folosire: node diagram.mjs <cod.mmd> <imagine.png> <iconite.json>
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import puppeteer from 'puppeteer-core';

const [codePath, outputPath, iconsPath] = process.argv.slice(2);

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

/** Clasele C4 în varianta întunecată, aceleași ca în resources/js/lib/mermaid.ts (DARK_CLASS_STYLES). */
const DARK_CLASS_STYLES = {
    person: 'fill:#1e3a8a,stroke:#60a5fa,color:#e8ebf4',
    system: 'fill:#1e3a8a,stroke:#60a5fa,color:#e8ebf4',
    containerBox: 'fill:#1e3a8a,stroke:#60a5fa,color:#e8ebf4',
    component: 'fill:#1d4ed8,stroke:#60a5fa,color:#ffffff',
    externalBox: 'fill:#26304a,stroke:#4a5675,color:#e8ebf4',
    more: 'fill:none,stroke:#4a5675,stroke-dasharray:4 3,color:#a3acc2',
    boundary: 'fill:none,stroke:#838ca4,stroke-dasharray:6 4',
};

const darkCode = code.replace(/^(\s*classDef\s+)([\w-]+)(\s+).*$/gm, (line, start, name, space) =>
    name in DARK_CLASS_STYLES ? `${start}${name}${space}${DARK_CLASS_STYLES[name]}` : line,
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

    await page.evaluate(async (source, iconPack) => {
        const mermaid = window.mermaid;
        mermaid.registerIconPacks([{ name: 'logos', icons: iconPack }]);
        mermaid.initialize({
            startOnLoad: false,
            securityLevel: 'strict',
            theme: 'base',
            darkMode: true,
            fontFamily: "'Instrument Sans', sans-serif",
            themeVariables: {
                fontFamily: "'Instrument Sans', sans-serif",
                fontSize: '15px',
                background: '#111727',
                mainBkg: '#1a2440',
                primaryColor: '#1a2440',
                primaryTextColor: '#e8ebf4',
                primaryBorderColor: '#4a5675',
                nodeBorder: '#4a5675',
                nodeTextColor: '#e8ebf4',
                secondaryColor: '#172036',
                tertiaryColor: '#0a0e1a',
                textColor: '#e8ebf4',
                titleColor: '#a3acc2',
                lineColor: '#838ca4',
                clusterBkg: '#111727',
                clusterBorder: '#253049',
                edgeLabelBackground: '#111727',
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
    }, darkCode, icons);

    const element = await page.$('#diagram');
    await element.screenshot({ path: outputPath, omitBackground: true });
} finally {
    await browser.close();
}
