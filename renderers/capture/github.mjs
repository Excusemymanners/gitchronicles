/**
 * Fotografiază pagina de GitHub a unui repository public, așa cum o vede un vizitator neautentificat pe
 * telefon, în tema întunecată. Fotografia e „scena” video-ului de prezentare: video-ul derulează pagina
 * reală și mărește elementele reale (numele, stelele, titlurile din README, comanda de instalare).
 *
 * Ce scrie în folderul de ieșire:
 *   - page-00.png, page-01.png, …  pagina tăiată în bucăți (o singură imagine foarte înaltă ar depăși
 *     textura maximă a lui Chrome, 16.384 px). Pagina e așezată pe 400 px CSS (aspectul de telefon al
 *     GitHub, cu literele mari) și fotografiată de 2,7 ori mai fin, adică exact 1080 px: lățimea video-ului.
 *     Așa, video-ul arată fiecare pixel al fotografiei ca pe un pixel al lui, fără nicio mărire sau
 *     micșorare — textul rămâne la fel de clar ca pe ecranul unui telefon;
 *   - landmarks.json  unde se află, în pixeli CSS ai paginii, reperele reale: numele, descrierea, stelele,
 *     fork-urile, lista de fișiere, rândul ultimului commit, bara de limbaje, fiecare titlu, imagine și
 *     paragraf din README;
 *   - social.(png|jpg)  imaginea de previzualizare a repository-ului (og:image), avatar.(png|jpg) al proprietarului;
 *   - logo.png  logo-ul repository-ului, când README-ul începe cu unul (fotografiat de pe pagină).
 *
 * Reguli de siguranță:
 *   - se deschid doar github.com și gazdele de fișiere ale GitHub (githubassets.com, githubusercontent.com);
 *     orice altă cerere e oprită înainte să plece;
 *   - Chrome pornește cu un mediu minim, construit aici: nicio variabilă a workerului (chei, parole) nu ajunge la el;
 *   - nimic din conținutul repository-ului nu e modificat: se ascund doar elementele care plutesc peste
 *     pagină (bannere, ferestre, bare lipite de ecran) și bara de sus a GitHub pentru vizitatori
 *     („Sign in”, „Sign up”), care nu ține de repository;
 *   - totul are limită de timp; la orice problemă scriptul iese cu cod 1 și un motiv pe stdout (JSON),
 *     iar video-ul se face fără fotografie — niciodată cu una falsă.
 *
 * Folosire: CHROME_PATH=… node github.mjs <proprietar> <nume> <folder-ieșire> [înălțime-maximă-css]
 */

import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import puppeteer from 'puppeteer-core';

const [owner, name, outputDirectory, maxHeightArgument] = process.argv.slice(2);

/**
 * Lățimea unui telefon, în pixeli CSS, și de câte ori mai fină e fotografia: 400 × 2,7 = 1080 px, lățimea
 * video-ului. GitHub trimite sub 544 px aspectul de telefon (o coloană, litere mari).
 */
const WIDTH = 400;
const FRAME_WIDTH = 1080;
const SCALE = FRAME_WIDTH / WIDTH;
/** Înălțimea unei bucăți, în pixeli CSS (3240 px reali, un număr întreg). */
const TILE_HEIGHT = 1200;
/** Cât din pagină se fotografiază cel mult, în pixeli CSS. */
const MAX_HEIGHT = Math.max(TILE_HEIGHT, Number(maxHeightArgument) || 20000);
/** Limitele de timp, în milisecunde: încărcarea paginii, apariția README-ului, toată fotografierea. */
const NAVIGATION_TIMEOUT = 20000;
const README_TIMEOUT = 8000;
const TOTAL_TIMEOUT = 80000;
/** Cel mai mare fișier descărcat (previzualizarea, avatarul). */
const MAX_DOWNLOAD_BYTES = 4 * 1024 * 1024;

/** Oprește scriptul cu un motiv pe care Laravel îl poate citi. */
function fail(reason, detail) {
    process.stdout.write(JSON.stringify({ error: reason, detail: detail ?? null }) + '\n');
    process.exit(1);
}

const validName = /^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/;

if (!owner || !name || !outputDirectory || !process.env.CHROME_PATH) {
    console.error('Folosire: CHROME_PATH=… node github.mjs <proprietar> <nume> <folder-ieșire> [înălțime-maximă-css]');
    process.exit(2);
}

if (!validName.test(owner) || !validName.test(name)) {
    fail('invalid_name');
}

/** Doar GitHub și gazdele lui de fișiere. */
function isAllowedHost(host) {
    return host === 'github.com' || host.endsWith('.githubassets.com') || host.endsWith('.githubusercontent.com');
}

function isAllowedUrl(url) {
    try {
        const parsed = new URL(url);

        if (parsed.protocol === 'data:' || parsed.protocol === 'blob:') {
            return true;
        }

        return parsed.protocol === 'https:' && isAllowedHost(parsed.host);
    } catch {
        return false;
    }
}

/**
 * Descarcă o imagine de la GitHub (previzualizarea, avatarul), urmând cel mult trei redirecționări și
 * verificând gazda fiecăreia. Întoarce numele fișierului scris sau null.
 */
async function downloadImage(url, baseName) {
    try {
        let current = url;

        for (let hop = 0; hop < 4; hop += 1) {
            if (!isAllowedUrl(current) || !current.startsWith('https:')) {
                return null;
            }

            const response = await fetch(current, { redirect: 'manual', signal: AbortSignal.timeout(10000), headers: { 'User-Agent': 'GitChronicle' } });

            if (response.status >= 300 && response.status < 400 && response.headers.get('location')) {
                current = new URL(response.headers.get('location'), current).toString();
                continue;
            }

            const type = (response.headers.get('content-type') || '').split(';')[0].trim();
            const extension = { 'image/png': 'png', 'image/jpeg': 'jpg' }[type];

            if (!response.ok || !extension) {
                return null;
            }

            /* Citim în bucăți și ne oprim la limită: un răspuns fără sfârșit nu trebuie să umple memoria. */
            if (Number(response.headers.get('content-length') ?? 0) > MAX_DOWNLOAD_BYTES) {
                return null;
            }

            const chunks = [];
            let total = 0;

            for await (const chunk of response.body) {
                total += chunk.length;

                if (total > MAX_DOWNLOAD_BYTES) {
                    return null;
                }

                chunks.push(chunk);
            }

            const bytes = Buffer.concat(chunks);

            /* Tipul declarat nu e de ajuns: octeții trebuie să fie într-adevăr PNG sau JPEG. */
            const isPng = bytes.subarray(0, 4).equals(Buffer.from([0x89, 0x50, 0x4e, 0x47]));
            const isJpeg = bytes.subarray(0, 3).equals(Buffer.from([0xff, 0xd8, 0xff]));

            if (bytes.length === 0 || !(isPng || isJpeg)) {
                return null;
            }

            writeFileSync(join(outputDirectory, `${baseName}.${extension}`), bytes);

            return `${baseName}.${extension}`;
        }
    } catch {
        return null;
    }

    return null;
}

const watchdog = setTimeout(() => fail('timeout'), TOTAL_TIMEOUT);

mkdirSync(outputDirectory, { recursive: true });

/* Mediul lui Chrome: doar ce îi trebuie ca să pornească. Folderele vin de la Laravel (ToolEnvironment). */
const browserEnvironment = {};

for (const key of ['PATH', 'HOME', 'TMPDIR', 'LANG', 'XDG_CONFIG_HOME', 'XDG_CACHE_HOME', 'XDG_STATE_HOME', 'XDG_DATA_HOME']) {
    if (process.env[key]) {
        browserEnvironment[key] = process.env[key];
    }
}

let browser;

try {
    browser = await puppeteer.launch({
        executablePath: process.env.CHROME_PATH,
        headless: true,
        env: browserEnvironment,
        args: ['--no-first-run', '--no-default-browser-check', '--disable-extensions', '--disable-background-networking', '--hide-scrollbars', '--mute-audio'],
    });
} catch (error) {
    fail('browser_failed', String(error?.message ?? error).slice(0, 200));
}

let blockedRequests = 0;

try {
    const page = await browser.newPage();
    await page.setViewport({ width: WIDTH, height: 932, deviceScaleFactor: SCALE, isMobile: true, hasTouch: true });
    await page.emulateMediaFeatures([
        { name: 'prefers-color-scheme', value: 'dark' },
        { name: 'prefers-reduced-motion', value: 'reduce' },
    ]);
    await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-US,en;q=0.9' });
    await page.setRequestInterception(true);

    page.on('request', (request) => {
        if (isAllowedUrl(request.url())) {
            request.continue();
        } else {
            blockedRequests += 1;
            request.abort();
        }
    });

    const url = `https://github.com/${owner}/${name}`;
    let response;

    /* O a doua încercare, după o pauză scurtă: o eroare de rețea de o clipă nu trebuie să lase video-ul fără pagină. */
    for (let attempt = 1; attempt <= 2 && !response; attempt += 1) {
        try {
            response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: NAVIGATION_TIMEOUT });
        } catch (error) {
            if (attempt === 2) {
                fail('unreachable', String(error?.message ?? error).slice(0, 200));
            }

            await new Promise((resolve) => setTimeout(resolve, 1500));
        }
    }

    if (!response || response.status() !== 200) {
        fail('http_status', String(response ? response.status() : 'no response'));
    }

    /* O redirecționare spre autentificare sau spre alt site înseamnă că nu vedem pagina repository-ului. */
    const finalUrl = new URL(page.url());

    if (finalUrl.host !== 'github.com' || finalUrl.pathname.split('/').filter(Boolean).length !== 2) {
        fail('unexpected_page', page.url().slice(0, 200));
    }

    /* README-ul vine odată cu pagina; dacă repository-ul nu are unul, mergem mai departe fără el. */
    await page.waitForSelector('article.markdown-body', { timeout: README_TIMEOUT }).catch(() => null);
    await page.waitForNetworkIdle({ idleTime: 600, timeout: 12000 }).catch(() => null);

    /*
     * Ascundem doar ce plutește peste pagină (position: fixed — bannere, ferestre, meniuri) și dezlipim
     * barele „sticky”, ca să rămână la locul lor din pagină. Animațiile se opresc, ca fotografia să fie stabilă.
     */
    await page.addStyleTag({ content: '*, *::before, *::after { animation: none !important; transition: none !important; scroll-behavior: auto !important; }' });
    await page.evaluate(() => {
        for (const element of document.querySelectorAll('body *')) {
            const position = getComputedStyle(element).position;

            if (position === 'fixed') {
                element.style.setProperty('visibility', 'hidden', 'important');
            } else if (position === 'sticky') {
                element.style.setProperty('position', 'static', 'important');
            }
        }

        /* Bara de sus pentru vizitatori („Sign in”, „Sign up”) nu e a repository-ului: pagina începe cu el. */
        for (const header of document.querySelectorAll('header.header-logged-out, header.HeaderMktg')) {
            header.style.setProperty('display', 'none', 'important');
        }
    });

    /* Derulăm o dată toată pagina, ca imaginile încărcate „leneș” să apară, apoi ne întoarcem sus. */
    await page.evaluate(async (limit) => {
        const step = 900;

        for (let y = 0; y < Math.min(document.documentElement.scrollHeight, limit); y += step) {
            window.scrollTo(0, y);
            await new Promise((resolve) => setTimeout(resolve, 60));
        }

        window.scrollTo(0, 0);
    }, MAX_HEIGHT);
    await page.waitForNetworkIdle({ idleTime: 600, timeout: 10000 }).catch(() => null);
    await page.evaluate(async () => {
        const images = [...document.querySelectorAll('article.markdown-body img')].filter((image) => !image.complete);
        await Promise.race([
            Promise.all(images.map((image) => new Promise((resolve) => { image.onload = resolve; image.onerror = resolve; }))),
            new Promise((resolve) => setTimeout(resolve, 6000)),
        ]);
    });

    /* Reperele: unde se află fiecare element real, în pixeli CSS ai paginii. */
    const found = await page.evaluate((repositoryName, limit) => {
        const clean = (text) => (text || '').replace(/\s+/g, ' ').trim();

        const box = (element) => {
            const rect = element.getBoundingClientRect();

            return { x: Math.round(rect.x + scrollX), y: Math.round(rect.y + scrollY), w: Math.round(rect.width), h: Math.round(rect.height) };
        };

        const visible = (element) => {
            const rect = element.getBoundingClientRect();
            const style = getComputedStyle(element);

            return rect.width > 1 && rect.height > 1 && style.visibility !== 'hidden' && style.display !== 'none';
        };

        const inside = (item) => item.y >= 0 && item.y + item.h <= limit;
        const mark = (element, text) => (element && visible(element) ? { text: clean(text ?? element.textContent).slice(0, 300), ...box(element) } : null);

        /** Cel mai mic element vizibil din afara README-ului al cărui text întreg se potrivește. */
        const byText = (pattern, selector) => {
            let best = null;

            for (const element of document.querySelectorAll(selector)) {
                if (element.closest('article.markdown-body') || !visible(element) || !pattern.test(clean(element.textContent))) {
                    continue;
                }

                const rect = element.getBoundingClientRect();

                if (best === null || rect.width * rect.height < best.area) {
                    best = { element, area: rect.width * rect.height };
                }
            }

            return best?.element ?? null;
        };

        const article = document.querySelector('article.markdown-body');
        const nameElement = [...document.querySelectorAll('[itemprop="name"], #repository-container-header strong a, a[data-pjax="#repo-content-pjax-container"]')]
            .find((element) => visible(element) && clean(element.textContent).toLowerCase() === repositoryName.toLowerCase())
            ?? byText(new RegExp('^' + repositoryName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '$', 'i'), 'a, strong, div, span');

        /* Descrierea: paragraful de sub titlul „About”. */
        const aboutHeading = [...document.querySelectorAll('h2, h3')].find((heading) => visible(heading) && clean(heading.textContent) === 'About');
        let about = null;

        if (aboutHeading) {
            const section = aboutHeading.parentElement;
            about = [...(section?.querySelectorAll('p') ?? [])].find((paragraph) => visible(paragraph) && clean(paragraph.textContent).length > 0 && !paragraph.querySelector('em')) ?? null;
        }

        /* Limbajele: titlul „Languages”, bara colorată și lista de sub ea, luate împreună. */
        const languagesHeading = [...document.querySelectorAll('h2, h3')].find((heading) => visible(heading) && clean(heading.textContent) === 'Languages');
        let languages = null;

        if (languagesHeading) {
            const section = languagesHeading.parentElement;
            const list = section ? [...section.querySelectorAll('li')].filter(visible).map((item) => clean(item.textContent)) : [];
            languages = section && visible(section) ? { text: list.join(' · ').slice(0, 300), ...box(section) } : null;
        }

        /* Contribuitorii: titlul „Contributors” și avatarele de sub el, luate împreună. */
        const contributorsHeading = [...document.querySelectorAll('h2, h3')].find((heading) => visible(heading) && /^Contributors/.test(clean(heading.textContent)));
        let contributors = null;

        if (contributorsHeading) {
            let section = contributorsHeading.parentElement;

            /* Urcăm până la elementul care cuprinde și avatarele (titlul singur e prea mic). */
            while (section && section.parentElement && section.getBoundingClientRect().height < contributorsHeading.getBoundingClientRect().height + 30) {
                section = section.parentElement;
            }

            contributors = section && visible(section) && section.getBoundingClientRect().height < 600 ? { text: clean(contributorsHeading.textContent).slice(0, 80), ...box(section) } : null;
        }

        const files = document.querySelector('table[aria-labelledby="folders-and-files"]');
        /* Rândul cu ultimul commit (autorul și data), primul din tabelul de fișiere. */
        const commit = document.querySelector('[data-testid="latest-commit"]') ?? files?.querySelector('tbody tr') ?? null;
        const stars = byText(/^[\d.,]+[km]?\s+stars?$/i, 'a, span, div');
        const forks = byText(/^[\d.,]+[km]?\s+forks?$/i, 'a, span, div');

        const headings = [];
        const images = [];
        const blocks = [];

        if (article) {
            for (const heading of article.querySelectorAll('h1, h2, h3, h4')) {
                const text = clean(heading.textContent);

                if (text !== '' && visible(heading)) {
                    headings.push({ level: Number(heading.tagName.slice(1)), text: text.slice(0, 160), ...box(heading) });
                }
            }

            for (const image of article.querySelectorAll('img, video')) {
                const item = { alt: clean(image.getAttribute('alt')).slice(0, 120), ...box(image) };

                /* Insignele și iconițele mici nu sunt „imagini din README” de arătat. */
                if (visible(image) && item.w >= 200 && item.h >= 90) {
                    images.push(item);
                }
            }

            const blockSelector = 'p, li, pre, blockquote, td, th, h1, h2, h3, h4, h5, h6, summary';

            for (const element of article.querySelectorAll(blockSelector)) {
                const text = clean(element.textContent);

                /* Un element care conține alte blocuri (un <li> cu paragrafe) e reprezentat de ele. */
                if (text === '' || !visible(element) || (element.tagName !== 'PRE' && element.querySelector(blockSelector))) {
                    continue;
                }

                const block = { kind: element.tagName.toLowerCase(), text: text.slice(0, 1200), ...box(element) };

                if (element.tagName === 'PRE') {
                    /* Fiecare rând al unui bloc de cod, cu locul lui: așa putem încadra exact comanda de instalare. */
                    const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
                    const lines = [];
                    let line = { text: '', range: null };
                    const flush = () => {
                        if (line.range && clean(line.text) !== '') {
                            const rect = line.range.getBoundingClientRect();
                            lines.push({ text: clean(line.text).slice(0, 200), x: Math.round(rect.x + scrollX), y: Math.round(rect.y + scrollY), w: Math.round(rect.width), h: Math.round(rect.height) });
                        }

                        line = { text: '', range: null };
                    };

                    for (let node = walker.nextNode(); node && lines.length < 40; node = walker.nextNode()) {
                        let offset = 0;

                        for (const part of node.data.split('\n')) {
                            if (offset > 0) {
                                flush();
                            }

                            if (part.length > 0) {
                                const start = node.data.indexOf(part, offset === 0 ? 0 : offset);

                                if (line.range === null) {
                                    line.range = document.createRange();
                                    line.range.setStart(node, start);
                                }

                                line.range.setEnd(node, start + part.length);
                                line.text += part;
                            }

                            offset += part.length + 1;
                        }
                    }

                    flush();
                    block.lines = lines;
                    block.text = clean(element.textContent).slice(0, 1200);
                }

                blocks.push(block);
            }
        }

        /*
         * Logo-ul repository-ului: prima imagine a README-ului, când stă la începutul lui (înainte de primul
         * paragraf de text) și nu e o insignă (insignele sunt mici: sub 32 px înălțime).
         */
        let logo = null;

        if (article) {
            const firstText = [...article.querySelectorAll('p, li, pre')].find((element) => visible(element) && clean(element.textContent).length > 40);
            const limit = firstText ? box(firstText).y : box(article).y + 500;
            const candidate = [...article.querySelectorAll('img, picture img')].find((image) => visible(image) && box(image).y < limit && box(image).w >= 96 && box(image).h >= 32);

            if (candidate) {
                candidate.setAttribute('data-gitchronicle-logo', '1');
                logo = { alt: clean(candidate.getAttribute('alt')).slice(0, 120), ...box(candidate) };
            }
        }

        const social = document.querySelector('meta[property="og:image"]')?.getAttribute('content') ?? null;

        return {
            height: document.documentElement.scrollHeight,
            background: getComputedStyle(document.body).backgroundColor,
            colorScheme: matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light',
            landmarks: {
                name: mark(nameElement),
                about: mark(about),
                stars: mark(stars),
                forks: mark(forks),
                files: files && visible(files) ? { text: '', ...box(files) } : null,
                commit: mark(commit),
                languages,
                contributors,
                readme: article && visible(article) ? { text: '', ...box(article) } : null,
                logo: logo ? { text: logo.alt, x: logo.x, y: logo.y, w: logo.w, h: logo.h } : null,
            },
            headings: headings.filter(inside),
            images: images.filter(inside),
            blocks: blocks.filter(inside),
            social,
        };
    }, name, MAX_HEIGHT);

    if (found.landmarks.name === null) {
        fail('unexpected_page', 'repository name not found');
    }

    for (const key of Object.keys(found.landmarks)) {
        const landmark = found.landmarks[key];

        /* Un reper care a rămas sub limita fotografiei nu există pentru video (README-ul poate fi tăiat de ea). */
        if (landmark !== null && key !== 'readme' && landmark.y + landmark.h > MAX_HEIGHT) {
            found.landmarks[key] = null;
        }
    }

    /* Fotografia, în bucăți. */
    const capturedHeight = Math.min(found.height, MAX_HEIGHT);
    const tiles = [];

    for (let y = 0, index = 0; y < capturedHeight; y += TILE_HEIGHT, index += 1) {
        const height = Math.min(TILE_HEIGHT, capturedHeight - y);
        const file = `page-${String(index).padStart(2, '0')}.png`;
        await page.screenshot({ path: join(outputDirectory, file), type: 'png', clip: { x: 0, y, width: WIDTH, height }, captureBeyondViewport: true });
        tiles.push({ file, y, height });
    }

    const socialIsCustom = typeof found.social === 'string' && new URL(found.social, url).host === 'repository-images.githubusercontent.com';
    const socialFile = found.social ? await downloadImage(new URL(found.social, url).toString(), 'social') : null;
    const avatarFile = await downloadImage(`https://github.com/${owner}.png?size=240`, 'avatar');
    /* Logo-ul se fotografiază de pe pagină, la aceeași finețe: e exact imaginea pe care o arată README-ul. */
    let logoFile = null;

    if (found.landmarks.logo !== null) {
        const element = await page.$('[data-gitchronicle-logo]');
        logoFile = element ? await element.screenshot({ path: join(outputDirectory, 'logo.png'), type: 'png', captureBeyondViewport: true }).then(() => 'logo.png').catch(() => null) : null;
    }

    const result = {
        version: 2,
        url,
        captured_at: new Date().toISOString(),
        viewport: { width: WIDTH, scale: SCALE },
        page: { height: found.height, captured_height: capturedHeight, background: found.background, color_scheme: found.colorScheme },
        tiles,
        landmarks: found.landmarks,
        headings: found.headings,
        images: found.images,
        blocks: found.blocks,
        social: socialFile ? { file: socialFile, custom: socialIsCustom } : null,
        avatar: avatarFile ? { file: avatarFile } : null,
        logo: logoFile ? { file: logoFile } : null,
        blocked_requests: blockedRequests,
    };

    writeFileSync(join(outputDirectory, 'landmarks.json'), JSON.stringify(result));
    process.stdout.write(JSON.stringify({ ok: true, tiles: tiles.length, height: capturedHeight, headings: found.headings.length, blocks: found.blocks.length }) + '\n');
} catch (error) {
    await browser.close().catch(() => null);
    fail('capture_failed', String(error?.message ?? error).slice(0, 200));
}

clearTimeout(watchdog);
await browser.close().catch(() => null);
process.exit(0);
