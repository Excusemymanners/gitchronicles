/**
 * Citește un logo sau un avatar (PNG, JPEG, GIF, WebP, SVG) cu Chrome și scrie pe ieșirea standard o
 * versiune de 48×48 pixeli ca JSON: lista valorilor RGBA, rând cu rând. PHP-ul alege din ea culoarea
 * dominantă (DominantColor). Merge și pentru SVG, pe care PHP-ul fără GD nu-l poate citi. Nu folosește internetul.
 *
 * Folosire: CHROME_PATH=… node pixels.mjs <imagine> <tip-mime>
 */

import { readFileSync } from 'node:fs';
import puppeteer from 'puppeteer-core';

const [imagePath, mime] = process.argv.slice(2);

if (!imagePath || !mime || !process.env.CHROME_PATH) {
    console.error('Folosire: CHROME_PATH=… node pixels.mjs <imagine> <tip-mime>');
    process.exit(2);
}

const uri = `data:${mime};base64,${readFileSync(imagePath).toString('base64')}`;
const browser = await puppeteer.launch({
    executablePath: process.env.CHROME_PATH,
    headless: true,
    args: ['--no-first-run', '--no-default-browser-check', '--disable-extensions'],
});

try {
    const page = await browser.newPage();

    /* Imaginea vine de pe internet: pagina nu are voie să ceară nimic în afară de ea însăși (date: și about:). */
    await page.setRequestInterception(true);
    page.on('request', (request) => (/^(data:|about:)/.test(request.url()) ? request.continue() : request.abort()));

    await page.setContent('<!doctype html><html><body></body></html>');

    const pixels = await page.evaluate(async (source) => {
        const image = new Image();
        image.src = source;
        await image.decode();

        const canvas = document.createElement('canvas');
        canvas.width = 48;
        canvas.height = 48;
        const context = canvas.getContext('2d', { willReadFrequently: true });
        context.drawImage(image, 0, 0, 48, 48);

        return Array.from(context.getImageData(0, 0, 48, 48).data);
    }, uri);

    console.log(JSON.stringify(pixels));
} finally {
    await browser.close();
}
