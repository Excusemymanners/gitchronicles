<img src="public/logo.png" alt="" width="96">

# GitChronicle

Asistent AI care documentează automat un repository GitHub și ține documentația la zi pe baza istoricului git: după prima indexare, procesează doar ce s-a schimbat de la ultimul commit indexat, nu tot proiectul de la zero.

Proiect de licență — Tehnologia Informației, 2026–2027.

- Planul complet al lucrării: [docs/licenta.md](docs/licenta.md)
- Planul de învățare, pas cu pas: [docs/plan-invatare.md](docs/plan-invatare.md)

## Ce face

- **Spațiu de lucru pentru fiecare repository**, în stilul NotebookLM: cuprinsul în stânga (ca pe Wikipedia), capitolul deschis în mijloc, elementele generate (diagrame, video, slide-uri, activitate, zone fierbinți) în dreapta și bara asistentului mereu jos, în care întrebi orice despre cod.
- **Documentație** în stil DeepWiki: o pagină pentru fiecare modul, cu navigare și căutare.
- **Diagrame de arhitectură C4** (Context, Container, Component), generate din codul proiectului.
- **Asistent pe cod**: răspunde la întrebări despre repository și citează fișierul și liniile exacte.
- **Actualizare incrementală**: la fiecare push se regenerează doar paginile afectate.
- **Time travel**: un slider prin istoria commit-urilor arată cum arătau documentația și arhitectura la orice moment.
- **Prezentare**: slide-uri despre repository (PDF/PPTX).
- **Discover / leaderboard** *(bonus)*: pagina publică „Descoperă” cu repository-uri interesante de pe GitHub, clasate după cât de repede câștigă stele (măsurat din notări zilnice), cu „repo-ul zilei” și butonul „Documentează”.
- **Video scurt** *(bonus)* generat pentru un repository.
- **Postări automate pe Instagram** *(bonus)*: zilnic, un Reel cu „repo-ul zilei” din leaderboard.
- **Consultanță** *(bonus)*: raport cu bug-uri, vulnerabilități și zonele de risc (fișierele schimbate des care au și probleme), făcut cu Semgrep, OSV-Scanner și scc, descărcabil ca Markdown.

## Stare

**Faza curentă:** Faza 9 — Actualizarea incrementală, gata. La un commit nou, GitChronicle aduce doar commit-urile noi (`git fetch` în clona existentă), află din `git diff` ce fișiere s-au schimbat, propagă schimbarea prin graful modulelor (modulele schimbate și vecinii lor la un pas) și reface doar paginile, fragmentele de cod și versiunile de diagrame atinse; peste 40% fișiere schimbate reprocesează tot. O pagină scrisă de Claude pe care Claude nu o poate rescrie acum rămâne, marcată „depășită”, cu motivul exact. Actualizarea pornește dintr-un webhook GitHub, dintr-o verificare programată la 15 minute sau din butonul „Verifică actualizări”. Paginile și diagramele își păstrează versiunile, așa că pe „Documentație” și „Diagrame” se poate „călători” la un commit procesat mai vechi; tab-ul „Istoric” arată pentru fiecare rulare ce s-a schimbat, ce s-a rescris și cât a costat. Înainte: Faza 8 — asistentul pe cod: codul analizat e tăiat în bucăți (funcții, clase, ferestre de linii) și indexat cu căutarea full-text din SQLite (FTS5); bara asistentului, jos pe orice pagină a repository-ului, răspunde la întrebări despre cod cu Claude și citează fișierele și liniile exacte pe GitHub, iar fără AI arată fragmentele găsite. Înainte: Faza 7 — diagramele de arhitectură (straturi și C4 Containere, Componente, Context, desenate cu Mermaid și ELK doar din analiza codului și din fișierele proiectului, plus 1–3 fluxuri „Cum funcționează” explicate de Claude); Faza 6 — documentația generată (o pagină pentru fiecare modul, „Despre proiect” și un README descărcabil, scrise de Claude sau, fără AI, de un șablon care folosește doar faptele din analiză; paginile al căror cod nu s-a schimbat nu se mai scriu); Faza 5 — un analizor în Python cu tree-sitter găsește clasele, funcțiile și importurile, iar Laravel le grupează în module. Pagina repository-ului e un spațiu de lucru: cuprinsul cu capitolele (documentație, arhitectură, modulele grupate pe foldere, istoric) în stânga, capitolul în mijloc, panoul „Elemente” în dreapta și bara asistentului jos; capitolele apar doar când au date reale. Stratul AI comun scrie textele prin Claude Code, cu abonamentul (fără credite API), sau prin API-ul Anthropic. Interfața are temă întunecată (implicită), cu un comutator spre tema luminoasă. Postgres, Redis și Horizon sunt amânate până la deploy. Progresul pe pași e în [docs/plan-invatare.md](docs/plan-invatare.md).

**Descoperă (bonus):** gata. Comanda `php artisan discover:refresh`, pornită zilnic de Laravel Scheduler, caută cu GitHub Search API repository-uri noi și populare, active sau noi pe limbaje și notează în fiecare zi stelele fiecărui repository urmărit. Pagina publică „Descoperă” (`/discover`) le clasează după stelele câștigate în 24 de ore, 7 sau 30 de zile, măsurate din două notări; până există două notări, creșterea e „necunoscut” și ordinea folosește media de stele pe zi de la creare, iar pagina spune asta. Fiecare rând are „Documentează” (îl adaugă în GitChronicle) sau „Deschide documentația”.

**Istoric:** gata. După analiza codului, GitChronicle importă din clonă cele mai noi 500 de commit-uri (apoi doar pe cele noi), cu fișierele și liniile schimbate. Tab-ul „Istoric” le arată pe luni, cu rulările GitChronicle pe commit-urile procesate, căutare, „Zone fierbinți” (fișierele schimbate cel mai des) și contribuitori.

**Consultanță (bonus):** gata. După ce un repository e gata, Semgrep (cu reguli scrise pentru GitChronicle), OSV-Scanner și scc citesc clona; pagina „Consultanță” arată problemele după gravitate, zonele de risc (commit-uri × gravitate), problemele pe fișiere cu legături spre rândurile de pe GitHub, dependențele vulnerabile cu versiunea care le repară și statisticile codului; raportul se descarcă în Markdown (în engleză).

## Tehnologii

✅ folosită · 🔄 în lucru · ⏳ urmează

| Tehnologie | Rolul în GitChronicle | Faza | Stare |
|---|---|---|---|
| Git | Istoricul proiectului; „materia primă” a aplicației, care citește istoricul altor repo-uri | 0 | ✅ |
| GitHub | Găzduiește proiectul; de aici se clonează repo-urile, vin webhook-urile și se deschid PR-urile automate | 0 | ✅ |
| PHP + Composer | Limbajul backend-ului și managerul de pachete PHP | 0 | ✅ |
| Laravel 13 | Backend-ul: rute, baza de date, autentificare, joburi, apeluri AI | 0 | ✅ |
| Artisan | Comenzile Laravel: server, generare de fișiere, migrații, workeri | 0 | ✅ |
| Blade | Șablonul HTML de bază în care se montează React | 0 | ✅ |
| npm + Vite | Pachetele JavaScript; compilarea CSS/JS și reîncărcarea paginii | 0 | ✅ |
| Tailwind CSS | Stilul interfeței | 0 | ✅ |
| Inertia | Leagă Laravel de React fără un API separat | 1 | ✅ |
| React + TypeScript | Interfața: pagini, formulare, chat, documentație | 1 | ✅ |
| PHPUnit | Teste pentru fiecare funcționalitate | 1+ | ✅ |
| SQLite | Baza de date locală la început | 2 | ✅ |
| Eloquent + migrații | Tabelele `users`, `repositories` și accesul la ele | 2 | ✅ |
| Cozi Laravel + Process | Joburile care rulează `git clone` / `git diff` în fundal | 3 | ✅ |
| Podman | Containere pentru Postgres și Redis; mai târziu imaginea aplicației | 3, 4 | ⏳ |
| PostgreSQL | Baza de date principală | 3 | ⏳ |
| Redis + Horizon | Coada de joburi și monitorizarea workerilor | 3 | ⏳ |
| Kubernetes + kind + Kustomize | Rularea aplicației, a bazei de date și a workerilor ca în producție | 4 | ⏳ |
| Python + tree-sitter | Analiza codului: clase, funcții, importuri → graful de dependențe | 5 | ✅ |
| Claude (Claude Code CLI sau API, SDK-ul oficial PHP) | Generarea documentației, a rapoartelor și a răspunsurilor din chat; local prin Claude Code, cu abonamentul | 6, 8 | 🔄 |
| react-markdown + remark-gfm | Afișarea documentației generate și a răspunsurilor asistentului (Markdown fără HTML) | 6, 8 | ✅ |
| Mermaid 12 + ELK | Diagramele de arhitectură (straturi, C4 Context/Container/Component) și fluxurile „Cum funcționează”, desenate în browser | 7 | ✅ |
| Iconify „logos” (CC0) | Logo-urile tehnologiilor din diagrame, salvate local (doar cele folosite) | 7 | ✅ |
| SQLite FTS5 (bm25) | Căutarea în cod pentru asistent: fragmentele cele mai potrivite unei întrebări | 8 | ✅ |
| pgvector + Voyage AI | Embedding-uri și căutare semantică pentru asistent (opțional, comparată cu FTS5) | 8 | ⏳ |
| GitHub webhooks | Pornesc actualizarea incrementală la fiecare push | 9 | ✅ |
| Semgrep, OSV-Scanner, scc | Consultanța: bug-uri, vulnerabilități, statistici despre cod | bonus | ✅ |
| Marp | Prezentarea repository-ului ca slide-uri (PDF și PPTX) | 7 | ✅ |
| HyperFrames + FFmpeg | Video-urile scurte despre repository (HTML + GSAP → MP4) | bonus | ✅ |
| Piper TTS | Vocea românească a video-ului, generată local, fără internet | bonus | ✅ |
| GitHub Search API + Laravel Scheduler | Leaderboard: notează zilnic stelele repo-urilor și calculează cine crește cel mai repede | bonus | ✅ |
| Instagram Graph API | Postarea automată a Reel-ului „repo-ul zilei” | bonus | ⏳ |
| k3s + KEDA | Deploy pe VPS; workerii se scalează după lungimea cozii | 10 | ⏳ |

## Plan de dezvoltare

**Ținta: aplicația completă până în ianuarie 2027.** Din ianuarie: doar ajustări, măsurători și scrierea lucrării.

Ritm: o fază pe săptămână. Kubernetes vine la final, ca pas de deploy; până atunci Postgres și Redis rulează în containere Podman simple.

| Săptămâna | Faza | Rezultat | Stare |
|---|---|---|---|
| 28 oct – 3 nov 2026 | 1. Inertia + React + TypeScript | Pagina de start în React, cu test | ✅ |
| 4 – 10 nov | 2. Utilizatori și repository-uri | Aplicație publică; login doar ca să adaugi un repo, apoi se adaugă singur | ✅ |
| 11 – 17 nov | 3. Joburi + Postgres, Redis, Horizon | 🏁 Aplicația clonează un repo și afișează SHA-ul | 🔄 |
| 18 – 24 nov | 5. Analiza codului (tree-sitter) | Modulele și dependențele unui repo | ✅ |
| 25 nov – 1 dec | 6. Documentația generată cu AI | 🏁 Documentație completă pentru un repo mic | ✅ |
| 2 – 8 dec | 7. Diagrame C4 + prezentare (Marp) | Diagrame și slide-uri pentru repo | 🔄 |
| 9 – 15 dec | 8. Asistentul pe cod (RAG) | Răspunsuri cu fișierul și liniile citate | ✅ |
| 16 – 22 dec | 9. Actualizarea incrementală + webhook + time travel | 🏁 Miezul lucrării funcționează; istoria arhitecturii pe commit-uri | ✅ |
| 23 dec – 5 ian | 4. Kubernetes + deploy k3s | Aplicația online | ⏳ |
| Ianuarie | Ajustări + experimente + bonusuri | Rezultate măsurate pentru lucrare. Dacă rămâne timp: leaderboard → video → Instagram, apoi consultanța | ⏳ |
| Februarie – iunie 2027 | Lucrarea scrisă | Lucrarea predată | ⏳ |

## Mod de lucru

1. Un pas mic = un commit; push la sfârșitul fiecărei zile de lucru.
2. O fază e gata doar când rezultatul ei funcționează și are un test.
3. Pentru fiecare fază se notează deciziile și motivele lor, pentru lucrare.
4. Miezul (fazele 1–9) înaintea bonusurilor (leaderboard, video, Instagram, consultanță).

## Rulare locală

Cerințe: PHP 8.3+, Composer, Node.js 22+, Python 3.10+.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
composer analyzer:install   # analizorul de cod: analyzer/.venv + gramaticile tree-sitter
composer media:install      # opțional, pentru video și slide-uri: renderers/node_modules + vocea Piper
composer consulting:install # opțional, pentru consultanță: Semgrep, OSV-Scanner și scc în scanners/ (fără root)
```

Apoi, în trei terminale:

```bash
php artisan serve       # aplicația, pe http://127.0.0.1:8000
npm run dev             # Vite: CSS/JS și reîncărcare automată
php artisan queue:work  # workerul care rulează joburile (clonarea, analiza codului, documentația)
```

Sau totul într-un singur terminal: `composer run dev`. Fără worker, repository-urile adăugate rămân „În așteptare”. Pentru cele adăugate înainte de Faza 3: `php artisan repositories:process --all-pending`.

Video-ul de prezentare și slide-urile (PDF, PPTX) se fac după ce repository-ul e gata, pe coada separată `media`: `composer run dev` pornește și workerul ei, iar de mână e `php artisan queue:work --queue=media`. Au nevoie de `composer media:install`, de Google Chrome și de FFmpeg (căile lor: `MEDIA_BROWSER_PATH`, `MEDIA_FFMPEG_PATH`, `MEDIA_FFPROBE_PATH` în `.env`); `MEDIA_AUTO_GENERATE=false` le oprește. De mână: `php artisan repositories:media {id}` (`--force` le reface și pentru același commit, `--now` le randează direct în terminal). Uneltele și licențele lor sunt în [renderers/README.md](renderers/README.md).

Consultanța (bug-uri, vulnerabilități, zone de risc, statistici despre cod) rulează tot pe coada `media`, după fiecare procesare (`CONSULTING_AUTO_RUN=false` o oprește). De mână: `php artisan repositories:consult {id}` (`--force` o reface pentru același commit, `--now` rulează uneltele direct în terminal). Are nevoie de `composer consulting:install`; o unealtă lipsă apare pe pagină ca „Nu e instalată”. În timpul unei analize, singurul lucru care iese de pe calculator sunt numele, versiunile și ecosistemele pachetelor din fișierele lock, trimise de OSV-Scanner la `api.osv.dev`. Detaliile, versiunile și licențele: [scanners/README.md](scanners/README.md).

Clasamentul „Descoperă”: `php artisan discover:refresh` adună stelele de azi (durează câteva minute, pentru că așteaptă 8 secunde între căutări ca să rămână sub limita GitHub). Zilnic îl pornește Laravel Scheduler: pe server prin cron (`* * * * * php artisan schedule:run`), local cu `php artisan schedule:work`; ora e `DISCOVER_SCHEDULE_TIME` (implicit 03:00), iar `DISCOVER_SCHEDULE_ENABLED=false` îl oprește. Merge fără cont GitHub; un `GITHUB_TOKEN` opțional (orice token personal, fără permisiuni) ridică limitele API-ului. Creșterea pe 24 de ore apare după a doua zi cu notări, cea pe 7 și 30 de zile după tot atâtea zile.

Documentația unui repository deja analizat se poate scrie și direct din terminal: `php artisan repositories:document {id}` (`--force` rescrie și paginile neschimbate, `--page=overview` scrie doar paginile date).

### Actualizarea incrementală (Faza 9)

Un repository deja procesat se actualizează în trei feluri; fiecare face `git fetch` și reface doar ce s-a schimbat de la ultimul commit procesat cu succes:

- **Butonul „Verifică actualizări”** din bara de sus a repository-ului (doar pentru utilizatorii autentificați, pe un repository „Gata”).
- **Verificarea programată:** `php artisan repositories:check-updates` (sau `… check-updates {id}`) întreabă GitHub de ultimul commit (`git ls-remote`, fără clonare) și pornește actualizarea doar dacă e altul. Rulează singură la 15 minute (`UPDATES_CHECK_CRON`; gol = oprită), dar numai dacă rulează și scheduler-ul: local `php artisan schedule:work`, pe server `* * * * * php artisan schedule:run` în cron.
- **Webhook-ul GitHub:** pune în `.env` un secret lung, `GITHUB_WEBHOOK_SECRET=…` (fără el, adresa răspunde 404). Pe GitHub, în repository → Settings → Webhooks → Add webhook: *Payload URL* `https://<adresa-aplicației>/webhooks/github`, *Content type* `application/json`, *Secret* același ca în `.env`, *Just the push event*. GitHub trebuie să poată ajunge la aplicație, deci local e nevoie de un tunel (de exemplu `cloudflared tunnel --url http://127.0.0.1:8000` sau `ngrok http 8000`), iar URL-ul tunelului intră în *Payload URL*. Contează doar push-urile pe ramura principală ale unui repository adăugat în GitChronicle și aflat în starea „Gata”.

Webhook-ul și verificarea programată pornesc cel mult o actualizare la `UPDATES_MIN_INTERVAL_MINUTES` (implicit 10) pe repository, ca o rafală de push-uri să nu cheme AI-ul de mai multe ori; commitul venit între timp îl prinde verificarea următoare. Peste `INCREMENTAL_FULL_THRESHOLD` (implicit 40%) fișiere schimbate se reprocesează tot. Reprocesare completă la cerere: `php artisan repositories:process {id} --full`.

**Repository-uri incomplete.** Un repository „Gata” căruia îi lipsește ceva din ce face procesarea (istoricul, documentația, diagramele sau indexul asistentului; de exemplu unul procesat de o versiune mai veche) primește o procesare completă, chiar dacă pe GitHub nu e niciun commit nou: de la „Verifică actualizări”, de la webhook și de la verificarea programată (aceasta doar o dată pe commit). Lângă „Verifică actualizări” e **„Regenerează tot”** (cu confirmare, pentru utilizatorii autentificați, pe un repository „Gata” sau eșuat): o procesare completă, toate etapele din nou (paginile al căror context nu s-a schimbat rămân la fel). Toate cele incomplete deodată, din terminal: `php artisan repositories:process --all-incomplete`. Video-ul și slide-urile nu fac un repository incomplet: au coada lor (`repositories:media`).

**Administrator.** Rolul se dă doar din terminal, unui cont existent: `php artisan users:admin {email}` (`--revoke` îl ia înapoi). Un administrator poate face pe orice repository ce poate face cel care l-a adăugat, de exemplu să-l șteargă.

Măsurătoarea pentru lucrare: `php artisan gitchronicle:benchmark {id} --commits=5` reia ultimele N commit-uri ale clonei într-o bază de date și o copie a clonei separate (datele aplicației nu se ating) și compară, pentru fiecare commit, actualizarea incrementală cu procesarea de la zero: timpul fiecărui pas, fișierele parsate, paginile scrise și caracterele de context care ar fi trimise lui Claude. Implicit nu cheamă AI-ul: tokenii apar doar ca **estimare** (caractere / raportul caractere-pe-token măsurat pe paginile scrise deja de Claude), iar `--ai` folosește driverul configurat. Rezultatele se salvează ca JSON și CSV în `storage/app/private/benchmarks/`. Are nevoie de rețea o dată, ca git să descarce conținutul commit-urilor vechi.

Textele AI: în `.env`, `AI_DRIVER=claude-code` le scrie cu CLI-ul Claude Code logat pe laptop (abonamentul, fără credite API; calea lui în `CLAUDE_CODE_BIN`), `AI_DRIVER=claude` folosește API-ul (cere `ANTHROPIC_API_KEY`), iar `AI_DRIVER=none` merge fără AI.
