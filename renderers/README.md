# Uneltele pentru video și slide-uri

Un proiect Node separat de aplicație (package.json-ul din rădăcină e doar pentru Vite): aici stau uneltele
care fac video-ul de prezentare și slide-urile unui repository. Laravel le pornește din joburile de pe coada
`media` (`RenderPresentationVideo`, `RenderPresentationSlides`).

Instalare, o singură dată: `composer media:install` — `npm ci` aici, vocea Piper în `tts/.venv` și modelele
vocilor (română și engleză) în `tts/voices` (singurul pas care folosește internetul; nimic din ele nu se urcă în git).
Mai trebuie Google Chrome (sau Chromium) și FFmpeg pe calculator; căile lor sunt în `config/gitchronicle.php`
(`MEDIA_BROWSER_PATH`, `MEDIA_FFMPEG_PATH`, `MEDIA_FFPROBE_PATH`).

| Folder | Ce face |
|---|---|
| `capture/github.mjs` | Fotografia paginii reale de GitHub a repository-ului (Chrome prin puppeteer-core): bucăți PNG, harta reperelor (`landmarks.json`), imaginea de previzualizare și avatarul. Contactează doar GitHub |
| `video/` | Compoziția HyperFrames (HTML + GSAP → MP4): derulează fotografia paginii, încadrează elementul real și afișează subtitrarea; `index.html`, fonturile, logo-ul și GSAP, toate locale |
| `audio/music.mjs` | Muzica de fundal, compusă de program (sinusoide, tobe, arpegiu), deterministă |
| `slides/` | Tema Marp a slide-urilor și desenarea diagramei Mermaid ca imagine PNG |
| `tts/` | Vocile: Piper TTS cu modelele `en_US-ljspeech-high` (engleză) și `ro_RO-mihai-medium` (română) |

## Licențe

| Ce | Versiune | Licență | Sursa |
|---|---|---|---|
| HyperFrames | 0.9.29 | Apache-2.0 | https://github.com/heygen-com/hyperframes |
| GSAP (copiat în `video/vendor`) | 3.15.0 | GSAP Standard License (gratuit, inclusiv comercial) | https://gsap.com/standard-license |
| Marp CLI | 4.5.1 | MIT | https://github.com/marp-team/marp-cli |
| Mermaid | 12.3.0 | MIT | https://github.com/mermaid-js/mermaid |
| Puppeteer Core | 24.43.1 | Apache-2.0 | https://github.com/puppeteer/puppeteer |
| Instrument Sans, Mona Sans, JetBrains Mono (`video/assets/fonts`) | @fontsource 5.3.0 | SIL OFL 1.1 (`video/assets/fonts/LICENSE.txt`) | https://fontsource.org |
| Piper TTS (`piper-tts`, rulat ca proces separat) | 1.8.0 | GPL-3.0-or-later | https://github.com/OHF-Voice/piper1-gpl |
| Vocea `ro_RO-mihai-medium` (descărcată la instalare) | — | setul de date: CC0; antrenată din modelul de bază „rdh”, tot CC0 | https://huggingface.co/rhasspy/piper-voices/tree/main/ro/ro_RO/mihai/medium |
| Vocea `en_US-ljspeech-high` (descărcată la instalare) | — | setul de date LJ Speech: domeniul public | https://huggingface.co/rhasspy/piper-voices/tree/main/en/en_US/ljspeech/high |
| Pagina de GitHub fotografiată | — | conținutul aparține proprietarului repository-ului și GitHub-ului; e arătată ca atare, cu sursa pe ecran | https://github.com |
| Muzica de fundal (`audio/music.mjs`) | — | codul acestui proiect: muzica e generată de program, nu e o înregistrare | — |
| Logo-ul (`video/assets/logo.png`) | — | logo-ul GitChronicle (copie a lui `public/logo.png`) | — |

Piper e GPL, dar aplicația nu îl include și nu se leagă de el: îl pornește ca program separat, cu textul pe
intrarea standard, la fel ca pe `git` sau `ffmpeg`.
