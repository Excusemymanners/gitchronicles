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
- **Discover / leaderboard** *(bonus)*: repository-uri interesante, clasate după cât de repede câștigă stele.
- **Video scurt** *(bonus)* generat pentru un repository.
- **Postări automate pe Instagram** *(bonus)*: zilnic, un Reel cu „repo-ul zilei” din leaderboard.
- **Consultanță** *(bonus)*: raport cu bug-uri, vulnerabilități și zonele de cod schimbate cel mai des.

## Stare

**Faza curentă:** Faza 5 — Analiza codului, gata. După clonare, un analizor în Python cu tree-sitter citește fișierele (PHP, JavaScript, TypeScript, Python, Go, Java), găsește clasele, funcțiile și importurile, iar Laravel le grupează în module și construiește graful de dependențe. Pagina repository-ului e un spațiu de lucru: cuprinsul cu capitolele (documentație, arhitectură, modulele grupate pe foldere, istoric) în stânga, capitolul în mijloc, panoul „Elemente” în dreapta și bara asistentului jos; capitolele apar doar când au date reale. Stratul AI comun scrie textele prin Claude Code, cu abonamentul (fără credite API), sau prin API-ul Anthropic. Interfața are temă întunecată (implicită), cu un comutator în antet spre tema luminoasă; alegerea se ține minte în browser. Postgres, Redis și Horizon sunt amânate până la deploy. Progresul pe pași e în [docs/plan-invatare.md](docs/plan-invatare.md).

**Istoric:** gata. După analiza codului, GitChronicle importă din clonă cele mai noi 500 de commit-uri (apoi doar pe cele noi), cu fișierele și liniile schimbate. Tab-ul „Istoric” le arată pe luni, cu rulările GitChronicle pe commit-urile procesate, căutare, „Zone fierbinți” (fișierele schimbate cel mai des) și contribuitori; „Prezentare” are cardurile „Activitate” și „Zone fierbinți”.

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
| react-markdown | Afișarea documentației generate | 6 | 🔄 |
| Mermaid (C4) | Diagramele de arhitectură | 7 | 🔄 |
| pgvector + Voyage AI | Embedding-uri și căutare semantică pentru asistent | 8 | ⏳ |
| GitHub webhooks | Pornesc actualizarea incrementală la fiecare push | 9 | ⏳ |
| Semgrep, OSV-Scanner, scc | Consultanța: bug-uri, vulnerabilități, statistici despre cod | bonus | ⏳ |
| Marp | Prezentarea repository-ului ca slide-uri | 7 | ⏳ |
| HyperFrames + FFmpeg | Video-urile scurte despre repository | bonus | ⏳ |
| GitHub Search API + Laravel Scheduler | Leaderboard: notează zilnic stelele repo-urilor și calculează cine crește cel mai repede | bonus | ⏳ |
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
| 25 nov – 1 dec | 6. Documentația generată cu AI | 🏁 Documentație completă pentru un repo mic | ⏳ |
| 2 – 8 dec | 7. Diagrame C4 + prezentare (Marp) | Diagrame și slide-uri pentru repo | ⏳ |
| 9 – 15 dec | 8. Asistentul pe cod (RAG) | Răspunsuri cu fișierul și liniile citate | ⏳ |
| 16 – 22 dec | 9. Actualizarea incrementală + webhook + time travel | 🏁 Miezul lucrării funcționează; istoria arhitecturii pe commit-uri | 🔄 |
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
```

Apoi, în trei terminale:

```bash
php artisan serve       # aplicația, pe http://127.0.0.1:8000
npm run dev             # Vite: CSS/JS și reîncărcare automată
php artisan queue:work  # workerul care rulează joburile (clonarea, analiza codului)
```

Sau totul într-un singur terminal: `composer run dev`. Fără worker, repository-urile adăugate rămân „În așteptare”. Pentru cele adăugate înainte de Faza 3: `php artisan repositories:process --all-pending`.

Textele AI: în `.env`, `AI_DRIVER=claude-code` le scrie cu CLI-ul Claude Code logat pe laptop (abonamentul, fără credite API; calea lui în `CLAUDE_CODE_BIN`), `AI_DRIVER=claude` folosește API-ul (cere `ANTHROPIC_API_KEY`), iar `AI_DRIVER=none` merge fără AI.
