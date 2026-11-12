/**
 * Măsoară slide-urile unei prezentări (HTML-ul făcut de Marp) și spune care nu încap: un slide „nu încape”
 * dacă vreun element al conținutului iese din zona lui (jos peste subsol, la dreapta peste margine) sau dacă
 * textul lui e tăiat. Rezultatul e un JSON cu numerele slide-urilor (de la 1) pe ieșirea standard:
 * {"overflowing":[3,7],"slides":14}. Nu folosește internetul.
 *
 * Folosire: CHROME_PATH=… node fit.mjs <prezentare.html>
 */

import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
import puppeteer from 'puppeteer-core';

const [htmlPath] = process.argv.slice(2);

if (!htmlPath || !process.env.CHROME_PATH) {
    console.error('Folosire: CHROME_PATH=… node fit.mjs <prezentare.html>');
    process.exit(2);
}

/** Cât de jos poate ajunge conținutul (subsolul începe la 720 − 64) și cât de la dreapta (margine de 88). */
const LIMIT_BOTTOM = 720 - 62;
const LIMIT_RIGHT = 1280 - 60;

const browser = await puppeteer.launch({
    executablePath: process.env.CHROME_PATH,
    headless: true,
    args: ['--no-first-run', '--no-default-browser-check', '--disable-extensions'],
});

try {
    const page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 720 });
    await page.goto(pathToFileURL(resolve(htmlPath)).href, { waitUntil: 'load' });
    await page.evaluate(() => document.fonts.ready);

    const result = await page.evaluate((bottomLimit, rightLimit) => {
        const sections = [...document.querySelectorAll('section')];
        const overflowing = [];

        sections.forEach((section, index) => {
            const box = section.getBoundingClientRect();
            const scale = box.width / 1280 || 1;
            let broken = section.scrollHeight > section.clientHeight + 1 || section.scrollWidth > section.clientWidth + 1;

            for (const element of section.querySelectorAll('h1, h2, h3, p, li, pre, figure, img, strong, span')) {
                if (element.closest('footer') || element.classList.contains('credit')) {
                    continue;
                }

                const rect = element.getBoundingClientRect();

                if (rect.height === 0 || rect.width === 0) {
                    continue;
                }

                const bottom = (rect.bottom - box.top) / scale;
                const right = (rect.right - box.left) / scale;

                if (bottom > bottomLimit || right > rightLimit) {
                    broken = true;
                }
            }

            if (broken) {
                overflowing.push(index + 1);
            }
        });

        return { overflowing, slides: sections.length };
    }, LIMIT_BOTTOM, LIMIT_RIGHT);

    console.log(JSON.stringify(result));
} finally {
    await browser.close();
}
