# Uneltele pentru video și slide-uri

Un proiect Node separat de aplicație (package.json-ul din rădăcină e doar pentru Vite): aici stau uneltele
care fac video-ul de prezentare și slide-urile unui repository. Laravel le pornește din joburile de pe coada
`media` (`RenderPresentationVideo`, `RenderPresentationSlides`).

Instalare, o singură dată: `composer media:install` — `npm ci` aici, apoi `bash tts/install.sh`: mediul Python
`tts/.venv` (Piper, Kokoro, edge-tts) și modelele vocilor în `tts/voices` (singurul pas care folosește internetul,
~800 MB, câteva minute; nimic din ele nu se urcă în git). Scriptul se poate rula oricând din nou: aduce doar ce
lipsește și verifică fișierele Kokoro cu SHA-256.

**Vocea fiecărei limbi** e o singură linie în `.env`, „motor:voce” (`App\Media\Narrator`):

| Setare | Ce înseamnă |
|---|---|
| `MEDIA_VOICE_EN=kokoro:am_puck` | Kokoro-82M, local (pe procesor, ~0,25 s pentru o secundă de voce), doar engleză — **implicit** |
| `MEDIA_VOICE_RO=edge:ro-RO-EmilNeural` | vocile online gratuite din Microsoft Edge (edge-tts) — **implicit pentru română, dar opțional și neoficial**: serviciul nu are contract, se poate schimba sau opri, iar frazele pleacă la Microsoft. Fără internet, video-ul e citit de Piper |
| `MEDIA_VOICE_RO=piper:ro_RO-mihai-medium` | Piper, local — vocea română complet offline (mai plată) |

De ce acestea: nu există încă un motor neural local cu română (Kokoro, Chatterbox și XTTS-v2 nu o au), iar vocea
Edge are tonul cu două treimi mai variat decât Piper (măsurat pe aceeași frază); alegerea finală e a urechii.

Dacă vocea aleasă lipsește sau eșuează, tot video-ul e citit de Piper cu modelul limbii (`MEDIA_PIPER_MODEL`,
`MEDIA_PIPER_MODEL_EN`), ca un video să nu amestece două voci.
Mai trebuie Google Chrome (sau Chromium) și FFmpeg pe calculator; căile lor sunt în `config/gitchronicle.php`
(`MEDIA_BROWSER_PATH`, `MEDIA_FFMPEG_PATH`, `MEDIA_FFPROBE_PATH`).

| Folder | Ce face |
|---|---|
| `capture/github.mjs` | Fotografia paginii reale de GitHub a repository-ului (Chrome prin puppeteer-core), în aspectul de telefon (400 px CSS) și de 2,7 ori mai fină — exact 1080 px, lățimea video-ului: bucăți PNG, harta reperelor (`landmarks.json`), imaginea de previzualizare, avatarul și logo-ul din README. Contactează doar GitHub |
| `video/` | Compoziția HyperFrames (HTML + GSAP → MP4): derulează fotografia paginii 1:1 (fără mărire), luminează elementul real și afișează subtitrarea; `index.html`, fonturile și GSAP, toate locale |
| `audio/music.mjs` | Muzica de fundal, compusă de program (sinusoide, tobe, arpegiu), deterministă |
| `slides/` | Tema Marp a slide-urilor și desenarea diagramei Mermaid ca imagine PNG |
| `tts/` | Vocile: `install.sh` (instalarea), `speak.py` (Kokoro și Edge, toate frazele unui video într-un proces), modelele în `voices/`: Kokoro v1.0, Piper `ro_RO-mihai-medium` și `en_US-joe-medium` |

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
| Vocea `en_US-joe-medium` (descărcată la instalare, rezerva engleză) | — | CC0 | https://huggingface.co/rhasspy/piper-voices/tree/main/en/en_US/joe/medium |
| Kokoro-82M v1.0 (`kokoro-v1.0.onnx`, `voices-v1.0.bin`, descărcate la instalare) | 1.0 | Apache-2.0 | https://huggingface.co/hexgrad/Kokoro-82M |
| kokoro-onnx (rulat ca proces separat) | 0.4.7 | MIT | https://github.com/thewh1teagle/kokoro-onnx |
| phonemizer-fork + espeak-ng (prin espeakng-loader; folosite de kokoro-onnx) | 3.3.1 | GPL-3.0 | https://github.com/thewh1teagle/phonemizer-fork |
| edge-tts (driver opțional, online) | 7.3.1 | LGPL-3.0 | https://github.com/rany2/edge-tts |
| Pagina de GitHub fotografiată | — | conținutul aparține proprietarului repository-ului și GitHub-ului; e arătată ca atare, cu sursa pe ecran | https://github.com |
| Muzica de fundal (`audio/music.mjs`) | — | codul acestui proiect: muzica e generată de program, nu e o înregistrare | — |

Piper și phonemizer/espeak-ng sunt GPL, dar aplicația nu le include și nu se leagă de ele: le pornește ca
programe separate, cu textul pe intrarea standard, la fel ca pe `git` sau `ffmpeg`.
