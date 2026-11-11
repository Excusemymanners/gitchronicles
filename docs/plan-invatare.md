# Plan de învățare — GitChronicle

Planul complet al licenței e în [licenta.md](licenta.md). Acesta e drumul pas cu pas prin care construiesc aplicația singur.

**Ținta:** aplicația completă până în ianuarie 2027, cu o fază pe săptămână. Calendarul e în [README](../README.md#plan-de-dezvoltare).

**Regulă:** întâi aplicația merge pe laptop (`php artisan serve`, Postgres și Redis în containere Podman). Kubernetes (Faza 4) vine la final, ca pas de deploy.

**La fiecare fază:** notez ce am ales și de ce, pentru lucrare.

---

## Faza 0 — Git și rularea locală ✅
- **Învăț:** git (init, commit, log), cum pornește o aplicație Laravel și ce face Vite.
- **Fac:** `git init` și primul commit, apoi pornesc aplicația cu `php artisan serve` + `npm run dev`.
- **Gata când:** văd pagina Laravel în browser și `git log` arată primul commit.

## Faza 1 — Inertia + React ✅
- **Învăț:** cum trimite Laravel date către React fără un API separat (rute → controller → pagină React cu props).
- **Fac:** instalez Inertia și React și fac o pagină de start în React.
- **Gata când:** ruta `/` afișează o pagină React și am un test care verifică asta.

**Progres:**
- [x] 1.1 a) pachetul `inertiajs/inertia-laravel`
- [x] 1.1 b) middleware-ul `HandleInertiaRequests`
- [x] 1.1 c) middleware-ul înregistrat în `bootstrap/app.php`
- [x] 1.1 d) rama HTML `resources/views/app.blade.php`
- [x] 1.2 a) pachetele React + TypeScript
- [x] 1.2 b) `vite.config.js`: `react()` în lista `plugins`, `input` → `app.tsx`
- [x] 1.2 c) `tsconfig.json`
- [x] 1.2 d) `resources/js/app.tsx`
- [x] 1.2 e) verificare: `npx tsc --noEmit` ✅, `npm run build` ✅
- [x] 1.3 prima pagină React: `pages/home.tsx` + `Inertia::render` (de șters: `home.blade.php`, `welcome.blade.php`)
- [x] 1.4 testul pentru pagina home (`tests/Feature/HomePageTest.php`)

**Jurnal:**
- 2026-10-28 — Inertia configurat pe server (middleware în grupul `web`, rama `app.blade.php`). React 19 + TypeScript 7 instalate.
- 2026-10-28 — Idei noi în plan: time travel pe commit-uri (în Faza 9); leaderboard, video și postări pe Instagram (bonusuri, ianuarie).
- 2026-10-28 — Vite configurat pentru React: `tsc` și `npm run build` trec. Lecții: `react()` e un plugin Vite (stă în `plugins`, nu în `fonts`); un fișier nesalvat în editor nu ajunge pe disc, deci am pornit Auto Save.
- 2026-10-28 — Prima pagină React: ruta `/` trimite `mesaj` din PHP în `pages/home.tsx` prin `Inertia::render`. Lecții: `use Inertia\Inertia;` „prezintă” clasa înainte s-o folosești; dacă o rută apare de două ori, o câștigă ultima; în stack trace contează prima linie din codul meu (`routes/web.php:11`).
- 2026-10-28 — **Faza 1 gata.** Primul test (`HomePageTest`) verifică pagina `home` și prop-ul `mesaj`. `withoutVite()` în `TestCase` face ca testele să nu depindă de `npm run build`. Toate trei verificările trec: `php artisan test`, `npx tsc --noEmit`, `npm run build`.

## Faza 2 — Utilizatori și repository-uri ✅
- **Învăț:** migrații, modele Eloquent, validare, formulare Inertia, autentificare.
- **Fac:** login, modelul `Repository` (url, ultimul_sha, status), un formular „adaugă repository” și lista publică a repository-urilor.
- **Regulă (schimbată pe 2026-11-09):** aplicația e gratuită și publică; oricine vede lista și paginile repository-urilor. Contul trebuie doar ca să adaugi un repository nou: după login (sau cont nou) se adaugă automat exact cel cerut.
- **Gata când:** fără cont văd lista; lipesc un link de GitHub, mi se cere login, iar după login repository-ul apare singur în listă.

**Progres:**
- [x] 2.1 tabelul `repositories` (migrație) + modelul `Repository`
- [x] 2.2 login (cont nou, intrare, ieșire, limită de 5 încercări greșite)
- [x] 2.3 formularul „adaugă repository” + lista publică
- [x] 2.4 teste (84 trec)
- [x] 2.5 verificare cap-coadă în browser: vizitator → link → login → repository adăugat

**Jurnal:**
- 2026-11-09 — Faza 2 implementată cu Claude Code. Decizii: autentificare scrisă de mână (fără starter kit), ca s-o pot explica; linkurile de GitHub sunt aduse la o formă unică (`App\Support\GitHubUrl`), ca `github.com/Laravel/Framework.git` și `https://github.com/laravel/framework` să fie același repository; regulile stricte pentru proprietar/nume protejează și viitorul `git clone`; mesajele de eroare în română (`lang/ro`).
- 2026-11-09 — Regulă nouă: aplicația e publică. Un repository există o singură dată (`url` unic), indiferent cine l-a adăugat; doar cine l-a adăugat îl poate șterge. Adăugarea trece printr-un singur loc (`App\Actions\AddRepository`), folosit și după login, ca să adauge automat linkul cerut de vizitator. Am instalat Laravel Boost (ghiduri și unelte pentru Laravel).
- 2026-11-10 — Logo: pana cronicarului într-o insignă rotundă, după „rețeta” logo-ului GitHub (cerc plin, desen decupat), dar cu desenul nostru: pana scrie o linie cu două commit-uri în formă de inel, ca simbolul de commit din GitHub. Octocat-ul nu poate fi folosit (regulile GitHub interzic). Numele e scris cu Mona Sans, fontul open-source al GitHub. Fișiere: `components/app-logo.tsx`, `public/favicon.svg`, `public/logo.svg`.
- 2026-11-10 — Lecție: în `app.blade.php` lipsea `@fonts`, deci fonturile din `vite.config.js` nu se încărcau deloc (nici Instrument Sans, din Faza 1); pagina folosea fontul sistemului.
- 2026-11-10 — **Faza 2 gata.** Test cap-coadă în Chrome (Playwright): ca vizitator lipesc `github.com/Octocat/Hello-World.git`, sunt trimis la login („adăugăm automat…”), îmi fac cont și ajung direct pe pagina `octocat/hello-world`, fără erori în pagină.
- 2026-11-10 — Logo nou (desenat de mine): iconiță bleumarin în care pana scrie o linie cu două commit-uri, iar linia se continuă într-un cerc ca un cadran de ceas (timpul, istoria). E folosit ca imagine (`public/logo.png`, `logo-256.png`, `favicon.ico`, `apple-touch-icon.png`).

## Faza 3 — Joburi: clonarea
- **Învăț:** cozi și joburi în Laravel, rularea comenzilor git din PHP, testarea joburilor, containere Podman.
- **Fac:** pornesc Postgres (cu pgvector) și Redis în containere Podman și instalez Horizon. Apoi un job care face `git clone`, salvează SHA-ul ultimului commit și schimbă statusul (în așteptare → clonat / eroare).
- **Gata când:** adaug un repository, jobul îl clonează și pe pagină văd SHA-ul.

**Progres:**
- [x] 3.1 statusurile pipeline-ului (`RepositoryStatus`: în așteptare → se clonează → se analizează → se scrie documentația → gata / eroare) și tabelul `processing_runs`
- [x] 3.2 jobul `CloneRepository`: `git clone` fără parole, maximum 4 minute, mesaje de eroare clare
- [x] 3.3 lanțul `ProcessRepository` → `CloneRepository` → `FinishProcessing`, pornit automat când se adaugă un repository
- [x] 3.4 „Reîncearcă” după o eroare + comanda `php artisan repositories:process {id}` / `--all-pending`
- [x] 3.5 pagina se actualizează singură la 2 secunde cât se procesează; arată commitul (link la GitHub), ramura principală și data clonării
- [x] 3.6 teste fără internet (un repository git local ține locul GitHub-ului): 112 trec; verificat pe bune: `octocat/hello-world` are SHA-ul `7fd1a60`, același ca `git ls-remote`
- [ ] 3.7 Postgres + Redis + Horizon în Podman — amânat: PHP-ul de pe laptop nu are `pdo_pgsql` și `phpredis` (se instalează doar cu sudo); până atunci coada merge pe `database` (SQLite)

**Jurnal:**
- 2026-11-10 — Faza 3 implementată cu Claude Code. Decizii și motivele lor:
  - Un lanț de joburi (`Bus::chain`) pornit dintr-un singur loc, `ProcessRepository`: fiecare etapă pornește doar dacă cea dinainte a reușit, iar fazele următoare își pun pasul în lanț. `catch`-ul lanțului pune repository-ul pe „Eroare” și închide rularea.
  - Fiecare rulare e un rând în `processing_runs` (cine a pornit-o, commitul, durata în ms, contoare): sunt măsurătorile pentru lucrare. Orele păstrează milisecundele, altfel durata putea greși cu până la o secundă.
  - git pornit cu `Process`, cu comanda ca listă (nu ca text pentru shell) și cu `--` înaintea URL-ului, ca nimic din URL să nu devină comandă sau opțiune. Fără parole (`credential.helper=`, `GIT_ASKPASS=''`, `GIT_TERMINAL_PROMPT=0`): se clonează doar repository-uri publice, chiar dacă workerul pornește din terminalul VS Code, care știe contul meu de GitHub. `--filter=blob:none --no-tags`: clona e mică, dar are tot istoricul de commituri.
  - Erori: pe pagină apare un mesaj scurt în română, iar detaliile (ce a spus git) merg în log. „Nu există sau e privat”, „durează peste 4 minute” și „nu are commituri” opresc jobul imediat, fiindcă nu trec singure; orice altă eroare git se mai încearcă o dată, după 30 de secunde.
  - Un singur pipeline pe repository: `ShouldBeUnique` pe `ProcessRepository` și verificarea „există o rulare în curs?”, pentru că două clone în același folder s-ar strica una pe alta.
  - `retry_after` al cozii `database` e acum 360 s (clonarea are voie 300 s); altfel un al doilea worker ar lua același job cât timp primul încă lucrează.
  - „Reîncearcă” îl poate apăsa orice utilizator logat, dar doar când statusul e „Eroare”: repository-urile sunt publice și o clonare nouă nu strică nimic. Împarte limita de 10 pe minut cu adăugarea.
  - Ștergerea unui repository îi șterge și clona (evenimentul `deleted` din model). În teste discul `local` e mereu fals (`TestCase`): un test care ștergea repository-ul 1 a șters o dată clona reală a repository-ului 1 din aplicația locală (am refăcut-o cu comanda).
  - Mesajele flash nu mai dispar la reîmprospătarea automată: un răspuns fără mesaj nu mai ascunde mesajul afișat.
- 2026-11-10 — Lecție: `php artisan serve` doar pune joburile în coadă; le rulează un worker (`php artisan queue:work` sau `composer run dev`, care pornește și `queue:listen`).

## Faza 4 — Kubernetes (se face la final, după Faza 9)
- **Învăț:** imagini de container (Podman), Pods, Deployments, Services, StatefulSets, Kustomize.
- **Fac:** imaginea aplicației, un cluster `kind`, Postgres + pgvector, Redis, aplicația și workerii Horizon, toate în cluster.
- **Gata când:** tot ce aveam în Faza 3 merge la fel, dar în Kubernetes.

**Securitate înainte de a deschide aplicația spre internet:**
- [ ] Fără port forwarding de pe laptopul personal. Pentru acces privat: Tailscale; pentru public de acasă: Cloudflare Tunnel (zero porturi deschise).
- [ ] Pe VPS, spre internet sunt deschise doar porturile 80/443; firewall pe tot restul. SSH doar cu cheie, fără parolă.
- [ ] HTTPS automat: Traefik din k3s + Let's Encrypt.
- [ ] Postgres, Redis și API-ul Kubernetes nu se văd din afara clusterului (Services de tip `ClusterIP`).
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`; secretele în Kubernetes Secrets, nu în git.
- [ ] Imaginea folosește PHP fixat, minim 8.5.12, și se reconstruiește la fiecare actualizare de securitate.
- [ ] Limite: rate limiting la login și la „adaugă repository”; timp și mărime maximă la `git clone`; buget de tokeni AI pe utilizator.
- [ ] Workerii care clonează cod străin rulează în containere separate, cu resurse limitate.
- [ ] Backup zilnic al bazei de date.
- [ ] `composer audit` și `npm audit` rulate automat (GitHub Actions + Dependabot).
- [ ] Niciodată `php artisan serve` sau `npm run dev` spre internet: sunt doar pentru laptop.

## Faza 5 — Analiza codului
- **Învăț:** tree-sitter (parsarea codului) și grafuri de dependențe.
- **Fac:** un serviciu mic în Python care extrage din cod fișierele, clasele, funcțiile și importurile. Laravel le salvează în baza de date și construiește graful de dependențe.
- **Gata când:** pentru un repository clonat văd ce module are și cine pe cine importă.

**Progres:**
- [x] 5.1 analizorul `analyzer/analyze.py` (Python + tree-sitter): pentru PHP, JavaScript/JSX, TypeScript/TSX, Python, Go și Java găsește clasele, funcțiile, metodele și importurile; pentru restul fișierelor doar limbajul și liniile; răspunde în JSON; instalare cu `composer analyzer:install`
- [x] 5.2 importurile sunt legate de fișierul din repository spre care arată (PSR-4 din `composer.json`, căi relative și aliasuri `@/` din `tsconfig.json`, module Python, `go.mod`, pachete Java)
- [x] 5.3 tabelele `repository_files`, `code_symbols`, `file_imports`, `modules`, `module_dependencies` și modelele lor
- [x] 5.4 `ModuleGrouper` (un modul = folderul, tăiat la 2 niveluri; peste 40 de fișiere se împarte o dată) și `RepositoryAnalyzer` (listează fișierele cu `git ls-files`, rulează analizorul, salvează totul într-o tranzacție, descrierea din README)
- [x] 5.5 jobul `AnalyzeRepository` în lanț, după clonare (status „Se analizează codul”); rularea notează `files_total`, `files_analyzed`, `modules_total`
- [x] 5.6 secțiunea „Structura codului” pe pagina repository-ului: cifre, limbaje cu bare, module, cele mai puternice dependențe
- [x] 5.7 teste: un proiect mic de test (`tests/Fixtures/analyzer-project`, PHP + TypeScript + Python) analizat cu analizorul real; `ModuleGrouper` testat fără Python; 139 de teste trec
- [x] 5.8 verificat pe bune pe cele 3 repository-uri din aplicația locală (vezi jurnalul)

**Jurnal:**
- 2026-11-10 — Faza 5 implementată cu Claude Code. Decizii și motivele lor:
  - Analizorul e un script Python separat (`analyzer/`), pornit din Laravel cu `Process` (comanda ca listă, fără shell, limită de 4 minute). Lista de fișiere intră pe stdin (`--files-from -`), așa că nu rămân fișiere temporare. Python pornește cu `-I` (izolat), ca nimic din repository-ul clonat să nu poată fi importat din greșeală.
  - `tree-sitter-language-pack` 1.25 descarcă gramaticile la prima folosire. Ca analiza să nu folosească niciodată internetul, `composer analyzer:install` le descarcă o singură dată în `analyzer/.venv/grammars`, iar analizorul refuză să pornească dacă lipsește vreuna (altfel ar încerca să o descarce).
  - Simbolurile și importurile se caută mergând prin arborele sintactic al fiecărui fișier, cu un tabel mic „tip de nod → tip de simbol” pentru fiecare limbaj (de exemplu `class_declaration` → `class`). Am ales asta în locul funcției gata făcute `process()` din pachet ca să pot explica exact ce se întâmplă.
  - Un fișier care nu poate fi citit ajunge în lista `errors` și restul analizei merge mai departe; erorile se scriu doar în log. Legăturile simbolice nu se urmează niciodată (ar putea arăta spre fișiere din afara clonei, de exemplu `/etc/passwd`): PHP le sare după modul din git (120000), iar Python le refuză încă o dată.
  - Ce fișiere se analizează decide PHP, cu o singură comandă `git ls-files --format=...`, care dă pentru fiecare fișier modul, SHA-ul conținutului (`blob_sha`, folosit în Faza 9 ca să vedem ce s-a schimbat), mărimea și dacă git îl consideră binar. Sar peste `vendor/`, `node_modules/`, `dist/`, `build/`, `.min.js`, fișierele binare, cele peste 1 MB și fișierele lock (`composer.lock`, `package-lock.json`, ...): lock-urile au mii de linii generate care ar fi ascuns codul adevărat în statistici.
  - Modulele (`ModuleGrouper`): folderul fișierului tăiat la 2 niveluri, „/” pentru rădăcină; un modul cu peste 40 de fișiere se împarte o singură dată, un nivel mai jos — o pagină de documentație pe modul (Faza 6) trebuie să rămână de mărime rezonabilă.
  - Dependențele dintre module se calculează în PHP din importurile rezolvate: A → B când un fișier din A importă un fișier din B; `weight` = câte astfel de importuri. Importurile din același modul nu contează.
  - Salvarea înlocuiește analiza veche într-o singură tranzacție, cu ștergeri explicite (dependențe, importuri, simboluri, fișiere, module), ca pagina să vadă fie analiza veche, fie pe cea nouă, niciodată jumătăți. Rândurile se scriu cu `insert` în bucăți de 500, nu unul câte unul.
  - Tabelele analizei nu au `created_at`/`updated_at`: fiecare analiză le rescrie complet, iar `repositories.analyzed_at` spune deja când. Toate au `repository_id`, ca ștergerea și numărarea pe repository să fie o singură interogare.
  - `AnalyzeRepository` are o singură încercare (`$tries = 1`): analiza nu folosește rețeaua, deci o eroare s-ar repeta; după eșec există „Reîncearcă”.
  - Pe pagină, `code_structure` e un prop-closure: Inertia rulează interogările doar când pagina îl cere; e inclus în reîmprospătarea la 2 secunde, ca rezultatul să apară singur când se termină analiza.
- 2026-11-10 — Verificare pe bune, cu un worker temporar, fiecare repository procesat singur:
  - `excusemymanners/gitchronicles` (versiunea de pe GitHub, `3ac73c4`): 103 fișiere urmărite, 94 analizate, 28 de module, 20 de dependențe; cele mai puternice: `tests/Feature → app/Models` (6), `app/Http → app/Actions` (3), `app/Http → app/Models` (3), `routes → app/Http` (3). Analizorul Python: 0,07 s; toată rularea: 2,4 s.
  - `octocat/hello-world`: 1 fișier, 1 modul, descrierea „Hello World!”; rularea: 1,7 s.
  - `heygen-com/hyperframes` (adăugat din aplicație, era în coadă): 9.924 de fișiere urmărite, 9.188 analizate, 584 de module, 56 de dependențe; analizorul Python: 16 s; toată rularea (clonare 38 s + analiză 18 s): 57 s.
  - Observații: într-un monorepo TypeScript importurile dintre pachete se fac după numele pachetului (`@hyperframes/core`), așa că apar ca externe; iar folderele foarte mari (`packages/studio/src`, 1.695 de fișiere) rămân un singur modul, fiindcă împărțirea se face o singură dată.

## Faza 6 — Documentația generată cu AI
- **Învăț:** apeluri la Claude (prin CLI-ul Claude Code cu abonamentul sau prin SDK-ul oficial PHP), prompturi și controlul costului în tokeni.
- **Fac:** o pagină Markdown pentru fiecare modul, plus o pagină de ansamblu, afișate cu meniu lateral și căutare (stil Docsify).
- **Gata când:** pentru un repo mic am o documentație completă pe care o pot naviga.

**Progres:**
- [x] 6.1 stratul AI comun (`app/Ai`): interfața `TextGenerator` și trei variante alese din `AI_DRIVER`: Claude Code cu abonamentul (`claude-code`), API-ul Anthropic (`claude`), fără AI (`none`); teste fără rețea și fără CLI
- [x] 6.2 verificat pe bune o dată prin Claude Code: răspuns în 2,7 s, 839 tokeni la intrare și 63 la ieșire (0,008 $ dacă ar fi fost pe API), fără cheie API în mediu
- [x] 6.3 pagina repository-ului cu tab-uri (Prezentare, Documentație, Diagrame, Asistent, Istoric, Video), pregătită pentru fazele 6–9: tab-urile care nu sunt încă gata spun doar ce vor arăta
- [x] 6.4 spațiul de lucru al repository-ului (stil NotebookLM): cuprinsul în stânga, capitolul în mijloc, panoul „Elemente” în dreapta, bara asistentului jos; pe telefon cuprinsul e un sertar
- [x] 6.5 tabelele `documents` (o pagină: prezentarea, README-ul sau un modul) și `document_versions` (fiecare schimbare a textului, pentru time travel); `processing_runs` are acum și `cost_usd_equivalent`
- [x] 6.6 `ContextBuilder`: ce primește cel care scrie o pagină, mereu la fel pentru același cod (fișiere, clase și funcții cu liniile lor, importuri, module dependente, codul celor mai importante fișiere în limita de 60.000 de caractere și lista fișierelor lăsate pe dinafară); `input_hash` = sha256 al acestui context
- [x] 6.7 două feluri de a scrie paginile: șablonul fără AI (doar fapte din analiză) și Claude prin `TextGenerator` (prompt fix, în engleză, pagini în română cu legături la liniile exacte de pe GitHub); dacă Claude nu poate, pagina o scrie șablonul și e marcată „scrisă automat”
- [x] 6.8 README-ul generat: doar secțiunile pentru care repository-ul are dovezi (comenzi din composer.json, package.json, Makefile, ...), descărcabil ca README.md
- [x] 6.9 jobul `GenerateDocumentation` în pipeline, după analiză (status „Se scrie documentația”): paginile modulelor, apoi prezentarea, apoi README-ul; sare peste paginile neschimbate; comanda `php artisan repositories:document {id} {--force} {--page=*}`
- [x] 6.10 tab-ul „Documentație”: capitolele în stânga (pe telefon, un meniu care se deschide), Markdown fără HTML, „Copiază” și „Descarcă README.md”, cine a scris pagina și câți tokeni a folosit; „Despre proiect” pe Prezentare
- [x] 6.11 teste fără CLI și fără API (FakeTextGenerator): 223 trec
- [x] 6.12 verificat pe bune (vezi jurnalul): fără AI pe `excusemymanners/gitchronicles`, `octocat/hello-world` și `heygen-com/hyperframes`; cu Claude, două pagini de modul și prezentarea pentru `gitchronicles`
- [ ] 6.13 căutarea în documentație (stil Docsify) — încă nu

**Jurnal:**
- 2026-11-10 — Fundația pentru fazele 6–9. Decizii și motivele lor:
  - Toate textele AI trec prin aceeași interfață, `TextGenerator`, iar `AI_DRIVER` alege cine le scrie: restul aplicației nu depinde de felul în care e apelat Claude.
  - Local folosesc CLI-ul Claude Code logat cu abonamentul meu, ca să nu cheltui credite API. Pornește ca `claude -p` cu comanda ca listă, cu textul pe STDIN, fără unelte (`--tools ""`), fără MCP și fără setările laptopului, într-un folder gol din `/tmp` șters după apel, ca să nu vadă proiectul, `.env` sau vreun `CLAUDE.md`.
  - Din mediul CLI-ului scot `ANTHROPIC_API_KEY` și `ANTHROPIC_AUTH_TOKEN`, ca să folosească mereu abonamentul, chiar dacă într-o zi pun o cheie în `.env`.
  - Varianta cu API folosește SDK-ul oficial `anthropic-ai/sdk`, fiindcă e biblioteca întreținută de Anthropic pentru PHP. Modelul e `claude-opus-5-5`, cu efortul `medium` scris explicit, ca să știu exact cât „gândește” modelul.
  - Promptul de sistem e marcat pentru cache, pentru că paginile aceluiași repository primesc aceleași instrucțiuni și astfel costă mai puțin.
  - `fallbacks: "default"`: dacă modelul refuză o cerere, API-ul încearcă singur modelul de rezervă, fără cod în plus la mine.
  - Verific `stop_reason` înainte să citesc textul: un refuz sau un text tăiat la `max_tokens` devin erori clare, nu documentație pe jumătate.
  - SDK-ul nu reîncearcă singur; eroarea (`AiException`) spune dacă merită reîncercat (limită atinsă, API supraîncărcat, conexiune pierdută), iar jobul decide. Așa reîncercările sunt într-un singur loc.
  - Mesajele de eroare sunt în română, pentru pagină; detaliile merg în log, fără prompturi și fără chei.
  - Cu `AI_DRIVER=none`, apelul aruncă `AiDisabled`, iar fiecare funcție are un mod fără AI: nu inventăm text. În teste AI-ul e mereu oprit (`phpunit.xml`), deci testele nu cheltuie nimic.
  - Tokenii de intrare numără tot ce a primit modelul (necache-uiți, scriși în cache, citiți din cache), ca măsurătorile din lucrare să fie complete.
  - Pagina repository-ului are tab-uri, fiecare cu URL-ul lui. Antetul comun e `RepositoryLayout`, iar serverul îi trimite aceleași date pe fiecare tab printr-un trait (`RendersRepositoryPage`), ca să nu le repet în fiecare controller.
  - Tab-urile sunt legături obișnuite (`<nav>` cu `aria-current`): merg cu Tab și Enter, cu butonul Înapoi și se pot trimite cuiva. Pe telefon bara se derulează orizontal și tab-ul deschis e adus în vedere.
  - Pagina „Prezentare” e făcută din secțiuni separate, fiecare cu componenta și prop-ul ei. O secțiune fără date reale nu apare deloc, ca să nu afișăm nimic inventat.
  - Pașii procesării sunt o listă în ordine (`ProcessRepository::STAGES`: Clone → Analyze → ImportCommits → GenerateDocumentation → BuildDiagrams → IndexCode → Finish), ca o fază nouă să-și adauge jobul cu o singură linie.
  - Pachete noi: `anthropic-ai/sdk` (API-ul), `react-markdown` și `remark-gfm` (documentația), `mermaid` (diagramele).
- 2026-11-10 — Tema întunecată, implicită, cu comutator spre tema luminoasă. Decizii și motivele lor:
  - Culorile sunt tokeni numiți după rol (`canvas`, `surface`, `line`, `ink`, `accent`, `info`/`warning`/`success`/`danger`), definiți o singură dată în `resources/css/app.css`; clasa `.dark` le schimbă doar valorile, așa că o componentă scrie `bg-surface text-ink` și arată bine în ambele teme, fără variante `dark:` peste tot.
  - Paleta întunecată vine din logo (fundal bleumarin, text alb-argintiu, accent albastru-indigo). Textul nu e alb pur (`#e8ebf4`), ca să nu obosească ochii, și fiecare pereche text/fundal are contrastul cerut de WCAG AA (minimum 4,5:1), măsurat.
  - Tema se alege într-un script mic din `<head>`, înainte ca browserul să deseneze ceva, ca pagina să nu clipească în alb. Alegerea se păstrează în `localStorage` (`gitchronicle-theme`), fără server și fără cont; când nu e nimic salvat, tema e cea întunecată.
  - La schimbarea temei se trimite evenimentul `gitchronicle-theme-change`, ca diagramele Mermaid să se poată redesena cu culorile noi. Componentele React citesc tema cu `useTheme()`, făcut cu `useSyncExternalStore`, felul recomandat de React pentru o valoare care trăiește în afara lui (clasa de pe `<html>`).
  - Accentul indigo înseamnă „se poate apăsa” (legături, butonul principal, tab-ul deschis, conturul de focus), iar culorile de stare spun ce se întâmplă. Statusurile au patru culori, pe grupuri: chihlimbar = așteaptă, albastru = se lucrează, verde = gata, roșu = eșuat; eticheta spune etapa exactă.
  - Pe fundal închis umbrele nu se văd, așa că un element „mai sus” are un fundal mai deschis (`surface-2`, `surface-raised`) și un chenar. Logo-ul primește un contur subțire doar în tema întunecată, altfel pătratul lui bleumarin se pierde pe fundal.
  - Pe telefon comutatorul rămâne sus, lângă logo, iar navigarea trece pe al doilea rând.
  - Un test verifică că fiecare pagină declară ambele scheme de culori și că tema se alege în `<head>`, înainte de conținut.
- 2026-11-10 — Spațiul de lucru al repository-ului, în stilul NotebookLM. Decizii și motivele lor:
  - Tab-urile au fost înlocuite de un spațiu de lucru: cuprinsul în stânga (ca pe Wikipedia sau pe un site de documentație), capitolul deschis în mijloc, într-o coloană de cel mult 760 px, ușor de citit, panoul „Elemente” în dreapta și bara asistentului mereu jos. Rutele și paginile Inertia au rămas aceleași, deci orice link vechi merge în continuare.
  - Cuprinsul și elementele vin de la server dintr-un singur loc, `WorkspaceResource`, trimis de `RendersRepositoryPage` ca prop-ul `workspace` pe fiecare pagină. Un capitol apare doar când are date reale (README-ul generat, un tip de diagramă, commit-urile); un grup fără capitole nu apare deloc. Tabelele fazelor următoare sunt verificate cu `Schema::hasTable`, ca pagina să meargă și înainte să existe.
  - `workspace` e un prop-closure: reîmprospătarea de la 2 secunde cere doar `repository` și `can_retry`, deci cuprinsul nu se recalculează degeaba. Numărul de interogări nu crește cu numărul de module; un test le numără pentru un repository cu un modul și pentru unul cu 40.
  - Modulele sunt grupate pe folderul de sus, în ordinea unui explorator de fișiere: folderele obișnuite, apoi cele de unelte (`.github`), apoi rădăcina. Un folder cu un singur modul apare direct, fără să trebuiască deschis. De la 40 de module apare o căsuță de filtrare (`heygen-com/hyperframes` are 584).
  - Un modul fără pagină de documentație duce la rândul lui din „Structura codului” (ancora `#modul-{id}`), evidențiat cu `:target`. Legăturile spre o secțiune a paginii deschise sunt ancore obișnuite: browserul derulează fără o cerere la server, iar capitolul evidențiat se calculează din cale și ancoră.
  - Layout-ul spațiului de lucru e layout-ul implicit al paginilor `repositories/…` (opțiunea `layout` din `createInertiaApp`), așa că Inertia îl păstrează montat între capitole: cuprinsul își păstrează derularea și folderele deschise, iar conversația cu asistentul nu se va pierde la schimbarea capitolului. Paginile nu s-au schimbat: `RepositoryLayout` e acum doar rama capitolului (firul paginii, starea procesării, eroarea).
  - Fiecare coloană se derulează separat. Coloana din mijloc are atributul `scroll-region`, ca Inertia să o readucă sus la un capitol nou și să-i refacă poziția la „Înapoi”. Coloanele sunt `relative`: altfel textele `sr-only` (poziționate absolut) ieșeau din ele și derulau toată pagina, cu bara de sus cu tot.
  - Pe telefon cuprinsul e un sertar `<dialog>` deschis cu `showModal()`: browserul ține singur focusul în el și îl închide cu Escape. Panoul „Elemente” trece sub capitol, iar bara asistentului rămâne jos. Fiecare panou e montat într-un singur loc, ales cu `matchMedia`, nu de două ori și ascuns cu CSS.
  - Accesibilitate: „Sari la conținut”, `aria-current` pe capitolul deschis, folderele sunt butoane cu `aria-expanded`, conturul de focus pe tot ce se poate apăsa, iar animațiile rulează doar cu `motion-safe`.
  - Cardurile din „Elemente” fără date spun „Încă nu a fost generat” și nu duc nicăieri; bara asistentului e dezactivată până când codul e indexat (Faza 8). Cele trei întrebări de pornire numesc module reale: modulul folosit cel mai mult de celelalte, cea mai puternică dependență și cel mai mare modul rămas, fără folderele de teste.
- 2026-11-10 — Documentația generată (Faza 6). Decizii și motivele lor:
  - Cel care scrie o pagină primește un context construit determinist (`App\Documentation\ContextBuilder`): aceleași date dau exact același text, deci același sha256 (`input_hash`). Dacă hash-ul nu s-a schimbat, pagina nu se mai scrie: asta face actualizarea incrementală ieftină (Faza 9).
  - Commitul nu intră în hash, ci doar în legăturile spre GitHub (`SourceLinks`); altfel fiecare commit nou ar rescrie toate paginile. O pagină nerescrisă păstrează legăturile la commitul ei (`source_sha`), iar liniile sunt aceleași, fiindcă modulul nu s-a schimbat.
  - Codul trimis pentru un modul are o limită (60.000 de caractere). Fișierele intră în ordinea importanței: cele importate de cele mai multe fișiere, apoi cele cu cele mai multe clase și funcții, apoi cele mai lungi. Primul fișier care nu mai încape intră doar cu începutul, iar restul apar într-o listă de fișiere omise, cu motivul. Listele lungi (fișiere, simboluri, importuri) au și ele limite, iar ce rămâne pe dinafară e numărat, ca să nu se piardă nimic pe tăcute.
  - Liniile de cod sunt trimise numerotate („12| …”), ca modelul să poată cita exact liniile.
  - Două moduri de scriere cu aceeași interfață (`DocumentationWriter`): șablonul fără AI scrie doar fapte din analiză (mărimi, nume, linii, importuri, comenzi din fișierele de pachete) și spune deschis ce nu se știe; Claude scrie text explicativ. Promptul de sistem e în engleză și e același la fiecare apel (se poate pune în cache); conținutul repository-ului e tratat ca date, nu ca instrucțiuni.
  - Un răspuns al lui Claude e folosit doar dacă are titlul și toate secțiunile cerute; altfel pagina o scrie șablonul. Dacă Claude dă eroare, pagina o scrie tot șablonul și e marcată „scrisă automat” (`is_stale`, cu motivul), iar celelalte pagini merg mai departe. O eroare care se va repeta (limita abonamentului, timp depășit) oprește Claude până la finalul rulării, ca să nu așteptăm degeaba pagină după pagină.
  - O pagină scrisă de Claude nu e înlocuită de șablon când AI-ul e oprit și codul e același; o pagină a șablonului e rescrisă de Claude când AI-ul devine disponibil.
  - Comenzile din „Cum îl rulezi” și din README vin doar din fișierele proiectului (scripturile din composer.json și package.json, ținte din Makefile, comanda standard a fiecărui fișier de pachete), fiecare cu fișierul din care vine. README-ul generat lasă deoparte secțiunile fără dovezi.
  - Un modul are pagina lui dacă are cod într-un limbaj recunoscut (un folder doar cu `.gitignore` nu are ce explica) și e printre cele mai mari 40. Celelalte module apar în tabelul din prezentare, cu motivul pentru care nu au pagină.
  - Un apel la Claude poate dura până la 5 minute, dar coada dă un job altui worker după 6 minute. De aceea jobul începe o pagină nouă doar în primele 30 de secunde, apoi pune în fața lanțului un job nou cu paginile rămase, din aceeași rulare. Fără AI, toate paginile se scriu într-un singur job.
  - Fiecare schimbare a textului unei pagini devine un rând în `document_versions` (cu commitul), pentru time travel. Paginile modulelor care nu mai există se șterg la finalul rulării.
  - Interfața e făcută din bucăți refolosibile (`components/docs`: Markdown, lista capitolelor, pagina), iar lista capitolelor vine de la server dintr-un singur loc (`DocumentNavigation`), ca s-o poată folosi și viitorul spațiu de lucru al repository-ului.
  - Markdown-ul se afișează fără HTML și fără imagini încărcate de pe alte site-uri; legăturile spre alte site-uri se deschid într-o filă nouă, fără acces la pagina noastră (`rel="noopener noreferrer"`).
  - Pe pagină scrie cine a scris textul: „Scris de Claude (model) prin abonament” sau „Scris automat din analiza codului (fără AI)”, plus tokenii folosiți. Coloana `ai_driver` reține dacă textul a venit prin abonament sau prin API.
- 2026-11-10 — Verificare pe bune:
  - Fără AI: `excusemymanners/gitchronicles` (commitul `3ac73c4`) — 25 de pagini (23 de module, prezentarea, README-ul); `octocat/hello-world` — 2 pagini (modulul lui nu are cod recunoscut); `heygen-com/hyperframes` (584 de module) — 42 de pagini în 7 secunde. Pentru modulul lui cel mai mare (1.695 de fișiere), textul trimis lui Claude ar avea circa 137.000 de caractere: limitele țin.
  - Cu Claude (driverul `claude-code`, abonamentul): paginile `app/Http`, `resources/js` și prezentarea pentru `gitchronicles`, în 2 min 33 s. `app/Http`: 12.121 de tokeni la intrare, 6.087 la ieșire (0,22 $ dacă ar fi fost pe API); `resources/js`: 18.173 / 6.110 (0,26 $); prezentarea: 8.632 / 5.384 (0,17 $). Total: 38.926 / 17.581 de tokeni, 0,65 $ echivalent API. Am verificat 8 dintre legăturile citate și câteva afirmații (de exemplu `MAX_ATTEMPTS = 5`, statusurile din `types/index.ts` de la acel commit) cu codul: toate erau exacte.
- 2026-11-11 — Finisarea interfeței, partea întâi: fundația vizuală, prima pagină și paginile de autentificare. Decizii și motivele lor:
  - Fonturile încarcă și subsetul `latin-ext`. Până acum se încărca doar `latin`, așa că „ă”, „ș” și „ț” erau desenate de un font al sistemului, în mijlocul cuvintelor.
  - Trei fonturi, fiecare cu rolul lui: Mona Sans pentru titluri (fontul din logo), Instrument Sans pentru textul de citit, JetBrains Mono pentru SHA-uri, căi și cod. Toate se descarcă la build și se servesc de la noi; se preîncarcă doar cele de pe primul ecran.
  - Un singur motiv grafic, luat din logo: „șina” — o linie cu inele de commit care se termină într-un cadran (`PipelineRail`). Arată doar șiruri reale de pași: etapele procesării pe prima pagină și starea unei procesări în curs.
  - Tema luminoasă a trecut de la gri și indigo la „hârtie” cu tentă bleumarin, ca să semene cu logo-ul; fiecare pereche text–fundal are contrast de cel puțin 4,5:1.
  - Mișcarea are un singur set de durate și curbe, ca tokeni CSS: 120 ms pentru răspunsul la apăsare, 220 ms pentru trecerea dintre pagini. Trecerile dintre pagini folosesc View Transitions din Inertia v3, pornite o singură dată în `app.tsx`; reîncărcările parțiale și cele repetate (polling) sunt excluse, ca pagina să nu clipească. Dacă sistemul cere „mișcare redusă”, nu rulează nicio animație.
  - Prima pagină spune ce face produsul, are formularul de adăugare ca acțiune principală, „Cum funcționează” (cei șapte pași reali ai procesării), cele cinci capitole ale unui repository și galeria. Fiecare card arată doar date reale: fișiere, module, commit-uri, pagini de documentație, diagrame, limbajele principale și ultimul commit procesat. Un repository care are doar structura codului spune asta, nu arată cifre goale.
  - Datele galeriei vin din două interogări, oricâte repository-uri ar fi în listă (`RepositoryCardResource`; un test numără interogările), ca pagina să nu încetinească pe măsură ce lista crește.
  - Paginile de autentificare și de înregistrare folosesc același cadru (`AuthShell`), ca să se simtă parte din același produs.

## Faza 7 — Diagrame C4 + prezentare
- **Fac:** diagrame Mermaid C4 generate din graful de dependențe și din fișierele de configurare, plus o prezentare Marp (PDF/PPTX) construită din documentația generată.
- **Gata când:** pagina repository-ului arată diagramele Container și Component și pot descărca prezentarea.

**Progres:**
- [x] 7.1 tabelul `diagrams` (repository, tip, cheie, cod Mermaid, `meta`, `source_sha`) și modelul `Diagram`
- [x] 7.2 `ArchitectureDiagramBuilder`: patru vederi desenate doar din datele reale — „Arhitectura pe straturi”, C4 Containere, C4 Componente, C4 Context; o vedere fără date nu apare
- [x] 7.3 `TechnologyDetector` + `TechnologyCatalog`: tehnologiile din `composer.json`, `package.json`, `pyproject.toml`/`requirements.txt`, `go.mod`, `.env.example`, `Dockerfile`, `docker-compose*`, `k8s/`, fiecare cu fișierul din care vine; logo-urile din pachetul Iconify „logos” (CC0), doar cele 61 folosite, într-un fișier local
- [x] 7.4 „Cum funcționează”: `FlowDiagramWriter` cere lui Claude 1–3 fluxuri (JSON strict), `FlowParser` le verifică; fără AI sau la eroare secțiunea lipsește
- [x] 7.5 jobul `BuildDiagrams` în lanț, după documentație (nu depinde de ea); idempotent; tokenii fluxurilor se adună la rulare
- [x] 7.6 tab-ul „Diagrame” (componente refolosibile: `mermaid-diagram`, `diagrams-content`, `diagram-card`) și previzualizarea arhitecturii pe „Prezentare”
- [x] 7.7 teste: constructorul pe proiectul de test din Faza 5 cu analizorul real, escaparea, trunchierea, tehnologiile, validarea fluxurilor, jobul și pagina; 268 de teste trec
- [x] 7.8 verificat pe bune pe cele 3 repository-uri din aplicația locală (vezi jurnalul)
- [x] 7.9 prezentarea Marp (PDF și PPTX): 10 slide-uri din aceleași date reale ca video-ul (ce face, ce îl face special, diagrama de containere desenată ca PNG, tehnologii, module, cifre, istoric, cum începi), cu tema și fonturile aplicației; vezi „Video scurt” la bonusuri

**Jurnal:**
- 2026-11-10 — Faza 7: diagramele de arhitectură și fluxurile aplicației. Decizii și motivele lor:
  - Diagramele de arhitectură se construiesc determinist, din analiza codului și din fișierele proiectului: aceleași date dau același cod Mermaid, iar un nod sau o săgeată apare doar dacă datele îl arată. Un repository cu doar un README nu primește nicio diagramă.
  - Diagramele sunt „flowchart”-uri Mermaid stilizate C4 (nu sintaxa C4 a lui Mermaid), cu layout-ul ELK: straturi clare și săgeți în unghi drept. Mermaid 12 include deja ELK și îl folosește implicit, așa că nu am instalat `@mermaid-js/layout-elk` — README-ul pachetului spune că ar încărca doar a doua copie a aceluiași cod.
  - „Arhitectura pe straturi”: un modul intră într-un strat (Intrare, Interfața, Web/API, Logica aplicației, Date, Altele) după numele folderelor lui (`LayerClassifier`); regulile se verifică într-o ordine fixă, de exemplu `app/Http` e Web/API înainte să fie „app”. Testele, configurarea, documentația, traducerile, `storage`, rădăcina și folderele fără cod sunt lăsate deoparte și listate sub diagramă.
  - În straturi desenez o singură săgeată între două straturi (când modulele unuia importă din celălalt), fără numere: prima variantă, cu toate săgețile modul → modul, era greu de citit. Detaliile modul → modul sunt în „Componente”.
  - Modulele unui strat stau în rânduri de câte 3 (legături nedesenate `~~~`), cel mult 6 pe strat și 30 în total; restul e numărat într-o cutie „+ N alte module”. ELK primește `nodePlacementStrategy: SIMPLE` și `cycleBreakingStrategy: GREEDY_MODEL_ORDER`: fără ele, la importuri „în sus” (de exemplu `app/Models` → `app/Enums`) ordinea straturilor se inversa și grupurile se așezau în scară.
  - Containere (C4 nivelul 2): fiecare folder cu un manifest (cel mult 2 niveluri, fără teste și exemple) e un proiect; din el ies serverul (framework-ul sau limbajul), paginile din browser (React, Vue, ...) ca al doilea container, un worker de cozi când există joburi Laravel, bazele de date și serverele de cache din `.env.example` (doar valorile active, nu comentariile) și din `docker-compose`, și sistemele externe din SDK-urile din manifest (Anthropic, Stripe, AWS, ...). O săgeată apare doar când fișierele proiectului leagă cele două capete.
  - Tot ce putem recunoaște stă într-un singur tabel (`TechnologyCatalog`: pachet → tehnologie, rol, logo), ca să fie clar de ce apare fiecare tehnologie; la framework-uri arăt versiunea majoră din manifest („Laravel 13”). Lista tehnologiilor găsite, cu fișierul fiecăreia, apare sub diagrama de containere.
  - Logo-urile vin din pachetul Iconify „logos” (CC0), dar doar cele 61 pe care le poate cere catalogul, salvate în `resources/js/lib/diagram-icons.json` și încărcate la nevoie: diagramele merg și fără internet, fără CDN. Un test verifică că fiecare logo din catalog există în fișier.
  - Un container cu logo e o plăcuță deschisă la culoare (și în tema întunecată), ca logo-urile negre (GitHub, Next.js) să se vadă; fără logo, e o cutie albastru-închis, ca în C4.
  - Componente (C4 nivelul 3): cele mai mari 15 module, fiecare cu numele folderului și clasele principale (clasele, apoi componentele React — funcții cu literă mare —, apoi interfețele), și cele mai puternice 25 de dependențe; modulele fără săgeți stau într-o grilă deasupra, altfel ELK le punea pe un singur rând foarte lat.
  - Context (C4 nivelul 1): utilizatorul, sistemul (cu descrierea din README) și sistemele externe găsite.
  - Textul din repository (foldere, clase, README) ajunge în etichete doar escapat (`MermaidText`): ghilimelele, `<`, `>`, `&`, `#` și backtick-ul devin coduri Mermaid (`#quot;`, `#lt;`, ...), iar rândurile noi devin spații. Singurul HTML din etichete e `<b>` și `<br>`, puse de cod.
  - „Cum funcționează”: Claude (prin `TextGenerator`) primește fișierele de intrare (rute, controllere, comenzi, joburi, fișiere main) cu liniile numerotate, într-un buget de 50.000 de caractere, plus rezumatele modulelor, dependențele și simbolurile cu liniile lor, și răspunde cu JSON strict. `FlowParser` respinge orice flux cu `click`, `href`, `call`, `javascript:`, HTML, `%%{init}%%`, front matter, forme `@{ }` sau stiluri, și păstrează doar sursele care arată spre linii reale din fișierele trimise. În browser, Mermaid rulează oricum cu `securityLevel: 'strict'`.
  - Contextul fluxurilor nu conține SHA-ul commitului: hash-ul lui se schimbă doar când se schimbă codul pe care îl vede Claude, iar atunci nu-l mai întrebăm din nou — un proiect neschimbat costă zero tokeni. Fără AI sau când Claude nu răspunde, fluxurile scrise pentru același commit rămân, iar cele mai vechi se șterg.
  - Jobul `BuildDiagrams` stă după slotul documentației, dar nu depinde de ea; e idempotent (înlocuiește diagramele, șterge vederile rămase fără date) și notează pe fiecare diagramă commitul (`source_sha`). Timeout 340 s, sub `retry_after` (360 s) al cozii.
  - Interfața e făcută din piese refolosibile pentru viitorul layout cu trei panouri: `mermaid-diagram` (o diagramă: desen, temă, copiere, ecran complet, eroare), `diagrams-content` (toate secțiunile) și `diagram-card` (previzualizarea mică dintr-un card).
  - Mermaid se încarcă doar când o pagină desenează o diagramă (import dinamic): bundle-ul principal a rămas la 352 KB. Diagramele se desenează pe rând, nu în paralel, pentru că Mermaid ține o configurație globală.
  - Culorile vin din tema paginii (tokenii `--color-*` când există, altfel aceleași valori scrise în cod); în tema întunecată, clasele C4 primesc variantele lor întunecate, iar diagramele se redesenează la schimbarea temei. Codul salvat (și cel copiat cu „Copiază codul Mermaid”) rămâne în varianta luminoasă, ca să arate la fel în orice editor Mermaid.
  - O diagramă nu se micșorează sub 75% din mărimea ei firească: dacă nu încape (de exemplu pe telefon), caseta se derulează orizontal și poate fi deschisă pe „Ecran complet”. La eroare apare un mesaj scurt și codul într-un `<details>`; dacă opțiunile ELK ale unei diagrame dau eroare, se încearcă o dată fără ele.
- 2026-11-10 — Verificare pe bune, în baza de date separată a worktree-ului:
  - `excusemymanners/gitchronicles` (`3ac73c4`): 4 vederi în 33 ms — 16 module cu cod în 5 straturi; containerele Laravel 13 · PHP ^8.3, React 19 · TypeScript (Inertia pe săgeată) și SQLite, găsite în `composer.json`, `package.json` și `.env.example`; componentele cu clasele lor (`RepositoryController`, `AddRepository`, `GitHubUrl`, ...).
  - Fluxurile pentru același repository, printr-un singur apel Claude Code (abonamentul): 16,1 s, 7.951 tokeni la intrare și 1.769 la ieșire (0,10 $ dacă ar fi fost pe API); 3 fluxuri valide — adăugarea unui repository, autentificarea cu repository-ul în așteptare, vizualizarea și ștergerea —, fiecare cu 4 surse care arată spre liniile reale (de exemplu `routes/web.php` L12–L15).
  - `octocat/hello-world`: nicio diagramă (are doar README), iar pagina spune asta.
  - `heygen-com/hyperframes`: 4 vederi în ~1 s, din 518 module cu cod; straturile arată cele mai mari 22, containerele 5 servere Hono (+ 12 alte pachete), contextul Amazon Web Services (din `@aws-sdk` în `packages/aws-lambda`).

## Faza 8 — Chat-ul despre cod (RAG)
- **Învăț:** embedding-uri, pgvector, căutare semantică.
- **Fac:** împart codul în bucăți, le salvez ca vectori și fac un chat care răspunde citând fișierul și liniile.
- **Gata când:** întreb „unde se face autentificarea?” și primesc un răspuns cu fișierul și liniile corecte.

**Progres:**
- [x] 8.1 tabela `code_chunks` (o funcție, o metodă sau o clasă cu liniile ei exacte; restul liniilor în ferestre de cel mult 80) și, pe SQLite, indexul full-text `code_chunks_fts` (FTS5); interfața `CodeSearch` are două variante: `SqliteCodeSearch` (FTS5, scor bm25) și `LikeCodeSearch` (SQL simplu, pentru alte baze de date)
- [x] 8.2 `CodeChunker`, `CodeIndexer` și jobul `IndexCode` în pipeline (după diagrame, înainte de „gata”); fișierele peste 200 KB, cele generate (rânduri foarte lungi) și desenele SVG sunt sărite; o reindexare înlocuiește tot, într-o tranzacție
- [x] 8.3 întrebarea devine termeni de căutare (`SearchTerms`): fără diacritice, fără cuvinte de legătură, ca prefixe („modulele” → `modul*`), plus un glosar mic român → englez („autentificare” → `authenticat*`, `login*`); `MentionedPaths` recunoaște folderele și fișierele numite în întrebare
- [x] 8.4 `AnswerQuestion`: 8 fragmente (cel mult 2 din același fișier și 2 din teste, cele de 1–2 linii la urmă), răspuns în română cu citări [1], [2]; fără AI, sau când Claude nu poate răspunde, răspunsul e chiar lista fragmentelor
- [x] 8.5 `POST /repositories/{id}/assistant`: public, 20 de întrebări pe oră pentru fiecare IP, întrebarea între 3 și 1.000 de caractere, cel mult 6 mesaje anterioare; citările duc la liniile exacte pe GitHub, la commitul indexat
- [x] 8.6 interfața: `useAssistant` (conversația, ținută doar în browser), `AssistantThread` (mesaje în Markdown, citări, surse, așteptare, eroare cu „Încearcă din nou”), `AssistantBar` (Enter trimite, întrebările de pornire ale spațiului de lucru); bara de jos a spațiului de lucru (`AssistantDock`) le folosește, cu conversația într-un panou care se poate ascunde, iar pagina „Asistent” arată aceeași conversație pe toată înălțimea
- [x] 8.7 teste: 67 noi, 299 în total; Claude nu e apelat niciodată în teste
- [x] 8.8 verificat pe bune (vezi jurnalul)
- [ ] 8.9 opțional, după deploy: căutare semantică cu embedding-uri (pgvector), comparată cu cea pe cuvinte

**Jurnal:**
- 2026-11-10 — Faza 8, asistentul pe cod. Decizii și motivele lor:
  - Căutarea e full-text (FTS5 din SQLite, cu scorul bm25), nu cu embedding-uri: merge local, fără un serviciu extern și fără costuri, iar întrebările despre cod conțin de obicei nume din cod (clase, fișiere, funcții). Embedding-urile rămân o variantă de comparat mai târziu.
  - O bucată = o funcție, o metodă sau o clasă fără metode, cu liniile ei exacte (din `code_symbols`, Faza 5) și cu comentariul de deasupra ei; restul liniilor (importuri, proprietăți, Markdown) în ferestre de cel mult 80 de linii; un simbol de peste 120 de linii se taie în ferestre. Așa o citare duce exact la funcția despre care vorbește răspunsul.
  - Numele unei metode include clasa (`LoginController.store`), ca metoda să fie găsită și după numele clasei.
  - Indexul FTS5 e scris explicit din cod, nu prin triggere: după salvarea bucăților, `rebuild()` scrie câte un rând pentru fiecare. `repository_id` stă și el în index (fără să fie căutabil), ca indexul unui repository să se șteargă cu o singură comandă, chiar dacă o analiză nouă a șters deja bucățile în cascadă. La ștergerea repository-ului, modelul șterge și indexul.
  - În index, identificatorii sunt despărțiți în cuvinte (`LoginController` → „Login Controller”), iar FTS5 ignoră majusculele și diacriticele; întrebarea trece prin aceeași despărțire.
  - Codul e scris în engleză, întrebările vin în română, așa că un glosar mic de termeni de programare adaugă cuvintele englezești („autentificare” → `authenticat*`, `login*`). Am ales `authenticat*` și nu `auth*`, fiindcă `auth*` găsea și `authorize`.
  - Cuvintele care descriu întrebarea, nu codul („modulul”, „fișierul”, „definite”, „implementată”), nu se caută.
  - Clasamentul: o potrivire în numele simbolului contează de 6 ori, în cale de 4 ori față de una în cod. Apoi păstrez cel mult 2 fragmente din același fișier și 2 din teste (testele arată cum se folosește codul, dar nu sunt codul), iar fragmentele de 1–2 linii (un tip, o constantă) vin după cele lungi.
  - Dacă întrebarea numește un folder sau un fișier care există („Ce face modulul app/Http?”), caut doar acolo și completez cu începutul fișierelor, README-ul primul.
  - O întrebare de continuare („Și cine o apelează?”) are puține cuvinte: abia când găsește sub 8 fragmente, completez cu o căutare care include și cuvintele întrebării anterioare. La început le amestecam mereu, iar o întrebare nouă primea fragmente despre cea veche.
  - Promptul e în engleză (instrucțiunile sunt urmate mai sigur), răspunsul în română. Modelul primește fragmentele numerotate, cu numerele liniilor, trebuie să citeze [1], [2] doar din ele și să spună clar când nu ajung. Tot ce vine din repository e marcat ca date, nu ca instrucțiuni, iar etichetele de închidere scrise în cod sunt neutralizate.
  - Fără AI (`AI_DRIVER=none`) sau când Claude nu poate (de exemplu limita abonamentului), răspunsul e lista fragmentelor, cu primele 20 de linii din fiecare și legătura la GitHub: asistentul ajută și fără AI, și nu inventează nimic.
  - Endpointul e public, ca restul paginilor, dar limitat la 20 de întrebări pe oră pentru fiecare IP, fiindcă fiecare întrebare poate porni un apel la Claude. Conversația stă doar în pagină: browserul trimite ultimele 6 mesaje cu fiecare întrebare, serverul nu salvează nimic.
  - Citările duc la `https://github.com/{proprietar}/{nume}/blob/{sha}/{cale}#L{a}-L{b}`, la commitul indexat, deci liniile rămân corecte și după push-uri noi. La fișierele Markdown adaug `?plain=1`, altfel GitHub le arată randate și liniile nu pot fi evidențiate.
  - Interfața e din piese separate (`useAssistant`, `AssistantThread`, `AssistantBar`), ca bara de întrebări să stea în josul oricărei pagini a repository-ului. `AssistantProvider`, pus în `WorkspaceLayout` sub cheia repository-ului, ține o singură conversație pentru bara de jos și pagina „Asistent”: conversația rămâne când deschizi alt capitol și începe din nou la alt repository.
  - Culorile vin din tokenii temei (`bg-surface`, `text-ink`, `bg-accent-strong`, ...), deci asistentul arată bine în tema întunecată și în cea luminoasă fără clase `dark:`.
- 2026-11-10 — Verificare pe bune:
  - `excusemymanners/gitchronicles` (`3ac73c4`): 94 de fișiere → 251 de bucăți (139 sunt funcții, metode sau clase), indexate în 0,16 s.
  - `heygen-com/hyperframes`: 8.596 de fișiere → 57.260 de bucăți, în 16,7 s și 38 MB de memorie. Aici am găsit o problemă: cu un JOIN obișnuit, SQLite parcurgea toate bucățile repository-ului și rula căutarea din nou pentru fiecare (143 s pentru o întrebare). Cu `CROSS JOIN`, care îl obligă să citească întâi indexul, aceeași căutare durează 70 ms.
  - Fără AI, trei întrebări puse din pagină: „Ce face modulul app/Http?” (începutul celor 8 fișiere din app/Http), „Unde se face autentificarea?” (`LoginRequest::authenticate`, `LoginController::store`, pagina de login, mesajele din `lang/ro/auth.php`), „Cum se validează linkul de GitHub?” (`GitHubUrl::normalize` și `parse`).
  - Cu Claude, prin abonament, fără credite API: „Unde se face autentificarea?” → răspuns în 9,3 s, 4.332 de tokeni la intrare și 762 la ieșire (0,05 $ dacă ar fi fost pe API). A citat corect 4 fragmente (`LoginRequest.php` 41–59, `LoginController.php` 28–45, `login.tsx` 11–86, `lang/ro/auth.php` 1–22) și a spus singur ce lipsea din fragmente. Încă trei întrebări pe hyperframes au folosit între 2.611 și 8.513 tokeni la intrare.
  - Limita de 20 de întrebări pe oră a funcționat și în browser: la a 21-a întrebare, pagina a spus după câte minute se poate întreba din nou, cu butonul „Încearcă din nou”.
  - Observație: bm25 calculează cât de rar e un cuvânt pe tot indexul, adică pe toate repository-urile; după indexarea unui repository mare, ordinea rezultatelor la unul mic s-a schimbat puțin.

## Faza 9 — Actualizarea incrementală (miezul licenței)
- **Fac:** `git diff` față de ultimul SHA, reprocesez doar ce s-a schimbat, propag schimbarea prin graf, apoi adaug webhook-ul GitHub și detectarea documentației învechite.
- **Time travel:** nu mai suprascriu documentația și diagramele, ci le păstrez pe fiecare cu SHA-ul commit-ului lor. În interfață, un slider prin commit-uri arată cum arăta proiectul atunci și ce componente au apărut sau au dispărut.
- **Gata când:** după un push se regenerează doar paginile afectate, pot măsura timpul și tokenii economisiți și pot „călători” înapoi la un commit vechi.

**Progres:**
- [x] 9.1 istoricul git: tabelele `commits` și `commit_files`, `CommitHistory` (citește `git log --numstat` din clonă) și jobul `ImportCommits`, pus în lanț imediat după analiză: prima dată cele mai noi 500 de commit-uri, apoi doar cele de după ultimul commit procesat
- [x] 9.2 tab-ul „Istoric”: commit-urile pe luni (în română), rulările GitChronicle pe commit-urile procesate, căutare după mesaj sau autor, „Încarcă mai multe”, „Zone fierbinți” (top 10) și „Contribuitori”; pe „Prezentare”, cardurile „Activitate” (graficul ultimelor 12 luni) și „Zone fierbinți” (top 5)
- [x] 9.3 teste: parsarea pe un repository git local cu subiecte „grele” (separatori, diacritice, emoji, corp pe mai multe rânduri), clone blobless cu și fără acces la origine, import idempotent și incremental, jobul în lanț, clasamentul și excluderile zonelor fierbinți, paginile pentru vizitatori, paginarea și căutarea; 220 de teste trec
- [x] 9.4 clona se actualizează: `git fetch` (blobless, fără tag-uri) în clona existentă și mutarea pe noul HEAD; o clonă lipsă sau stricată se clonează din nou. Același commit ca ultima rulare reușită → rulare „nimic nou”, fără niciun pas și fără AI
- [x] 9.5 setul de schimbări (`App\Git\ChangeSet`: `git diff --name-status --no-renames` de la commitul ultimei rulări reușite) și impactul prin graful modulelor (`App\Incremental\ImpactAnalysis`: modulele cu fișiere schimbate și vecinii lor la un pas), salvate pe rulare (`change_summary`, `modules_affected`, `module_paths`); peste 40% fișiere schimbate, rulare completă cu motivul scris
- [x] 9.6 fiecare pas folosește impactul: documentația verifică doar paginile modulelor afectate, paginile noi, pe cele depășite și, la o schimbare a proiectului, prezentarea și README-ul (`input_hash` rămâne plasa de siguranță); analiza re-parsează doar fișierele cu alt conținut (cache-ul analizorului); indexul asistentului retaie doar fișierele schimbate, într-o tranzacție; diagramele deterministe se redesenează, fluxurile lui Claude doar când li s-a schimbat intrarea
- [x] 9.7 documentația depășită: o pagină scrisă de Claude, pe care Claude nu o poate rescrie acum (AI oprit, limita abonamentului, o eroare), își păstrează textul și primește motivul exact („Codul modulului s-a schimbat la commitul a8fe3a5 (4 fișiere); pagina e de la commitul c6d243a. …”)
- [x] 9.8 time travel: tabelul `diagram_versions` lângă `document_versions`, ambele legate de rularea care le-a scris (`processing_run_id`); pe „Documentație” și „Diagrame”, un selector și un slider prin commit-urile procesate (`?sha=`), bannerul „Vezi o versiune veche…” cu „Înapoi la versiunea curentă” și, la diagrame, ce s-a schimbat de atunci (vederi redesenate, module apărute sau dispărute)
- [x] 9.9 declanșatorii: webhook-ul GitHub (`POST /webhooks/github`, semnătura HMAC-SHA256 comparată cu `hash_equals`, 60 de livrări pe minut), `php artisan repositories:check-updates` (programat la 15 minute) și butonul „Verifică actualizări”; rulările automate pornesc cel mult o dată la 10 minute pe repository; orice comandă git rulează fără prompt de parolă (`GIT_TERMINAL_PROMPT=0`)
- [x] 9.10 interfața: pe „Istoric”, fiecare rulare arată felul ei, ce a pornit-o, fișierele schimbate, modulele afectate (listă deschisă la cerere), paginile rescrise și păstrate, tokenii, costul echivalent API, durata și economia față de ultima procesare completă; bara de sus: „Actualizat la commitul … · acum …”
- [x] 9.11 măsurătoarea: `php artisan gitchronicle:benchmark {id} --commits=N` compară, pe istoria reală, actualizarea incrementală cu procesarea de la zero, fără AI (tokenii doar ca estimare, marcată ca atare), și salvează CSV și JSON în `storage/app/private/benchmarks/`
- [x] 9.12 teste: 69 de teste pentru Faza 9, pe repository-uri git locale construite în teste (fetch în loc de reclonare, setul de schimbări, impactul prin graf, pragul de 40%, doar paginile afectate, pagini depășite cu motivul lor, reindexarea pe fișiere, versiunile diagramelor și călătoria în timp, o rulare eșuată la jumătate, istorie rescrisă și commit pierdut, clonă stricată, webhook-ul, verificarea programată cu git simulat, intervalul minim, comanda de măsurare); toată suita: 588 de teste trec

**Jurnal:**
- 2026-11-10 — Istoricul repository-ului. Decizii și motivele lor:
  - Clona e „blobless” (`--filter=blob:none`, din Faza 3): are toate commit-urile, dar conținutul fișierelor doar pentru ultimul commit. Ca să numere liniile, `git log --numstat` descarcă singur conținuturile vechi, câte un commit pe rând. Am măsurat pe `heygen-com/hyperframes`: 37 s pentru 50 de commit-uri (≈ 0,7 s pe commit), deci vreo 6 minute pentru 500 — prea mult.
  - Soluția: `git backfill` (git ≥ 2.49) descarcă dintr-o singură cerere conținuturile de care au nevoie exact commit-urile citite (500 de commit-uri: ≈ 5 s). Apoi `git log` rulează cu `GIT_NO_LAZY_FETCH=1`, deci nu mai iese niciodată pe rețea: dacă tot lipsește ceva, eșuează imediat în loc să aștepte.
  - Dacă descărcarea nu merge (GitHub nu răspunde, git prea vechi pe server), commit-urile se citesc din nou cu `--name-only`, care nu are nevoie de conținuturi: istoricul și fișierele apar oricum, doar „+/−” rămân necunoscute (`null`), nu 0.
  - Formatul lui `git log`: câmpurile sunt despărțite de octetul NUL (git nu-l acceptă în mesaje, nume sau căi), fiecare commit începe cu octetul `\x1e`, iar cele patru câmpuri de după SHA se iau după poziție, nu căutând separatorul — așa un subiect care conține chiar `\x1e` nu strică nimic. Corpul mesajului nu se citește deloc (`%s` = doar subiectul). Textul care nu e UTF-8 valid se repară (`mb_scrub`), ca să poată fi trimis ca JSON.
  - `--no-renames`: un fișier redenumit apare ca șters + adăugat, fiindcă găsirea redenumirilor ar cere conținuturi nedescărcate. `--topo-order`: un commit nu apare niciodată înaintea celor de după el, deci „primul commit necitit” poate fi exclus sigur din descărcare. Autorul e `%aN`, adică după `.mailmap`-ul proiectului: aceeași persoană scrisă în două feluri contează o dată.
  - Commit-urile de merge sunt marcate (`is_merge`) și n-au contoare: git nu arată schimbări pentru ele, iar „0 fișiere” ar fi fost fals.
  - Importul e idempotent: commit-urile se salvează cu `upsert` după `(repository_id, sha)`, iar fișierele unui commit importat din nou se înlocuiesc, nu se dublează. „Doar cele noi” = commit-urile de după `to_sha`-ul ultimei rulări reușite, dacă acel commit e în baza de date; altfel (primul import, istorie rescrisă) se citesc din nou cele mai noi 500.
  - „Zone fierbinți” = fișierele schimbate de cele mai multe commit-uri. Le număr în SQL și scot în PHP, unde regulile se citesc ușor, fișierele lock, `vendor/`, `node_modules/`, `dist/`, `build/`, `.min.js`, `.min.css` și `.map`. Totalul de linii apare doar dacă git a numărat fiecare schimbare a fișierului (un favicon binar o dată ar fi dat o sumă incompletă). Mărimea de acum vine din analiza codului, când o are.
  - „Activitate”: dacă nu s-a importat primul commit al proiectului, lunile mai vechi decât commit-urile importate sunt „necunoscute”, nu 0 — altfel graficul ar fi arătat luni goale care de fapt au avut commit-uri.
  - Rulările GitChronicle se pun pe commit-ul procesat după `to_sha`; mai multe rulări pe același commit se strâng într-o listă care se deschide la cerere.
  - Interfața e făcută din piese refolosibile (`TimelineContent`, `ActivityCard`, `HotspotsCard`), pentru viitorul spațiu de lucru cu panouri; pagina „Istoric” și „Prezentare” doar le compun. `TimelineContent` își alege coloanele după lățimea propriului container (container queries din Tailwind), nu după ecran.
  - Culorile vin din tokenii temei (`bg-surface`, `text-ink`, `bg-accent`…), deci istoricul arată bine și în tema întunecată, și în cea luminoasă: rulările GitChronicle folosesc tonul `info`, liniile adăugate/șterse `success`/`danger`, barele zonelor fierbinți `warning`.
  - Lista de commit-uri e un prop `Inertia::scroll` (30 pe pagină) cu `<InfiniteScroll manual preserveUrl>`: butonul „Încarcă mai multe” adaugă pagina următoare sub cele încărcate, fără să schimbe adresa. Căutarea așteaptă 300 ms după ultima tastă și cere doar lista, de la prima pagină (`reset`).
- 2026-11-10 — Verificare pe bune, cu un worker temporar, pe copii ale clonelor: `excusemymanners/gitchronicles` — 14 commit-uri cu 162 de fișiere schimbate, importate în 0,74 s; `octocat/hello-world` — 3 commit-uri (un merge), 0,62 s; `heygen-com/hyperframes` — 500 de commit-uri cu 4.362 de fișiere schimbate, toate cu liniile numărate, 5,98 s. O a doua procesare găsește 0 commit-uri noi, în 16 ms.
- 2026-11-11 — Actualizarea incrementală, verificată și măsurată. Decizii și motivele lor:
  - Clona nu se mai șterge la fiecare rulare: `git fetch` aduce doar commit-urile noi, iar o clonă lipsă sau stricată (alt `origin`, fără `HEAD`) se clonează din nou — e mult mai ieftin și rezultatul e același.
  - Punctul de plecare (`from_sha`) e commitul ultimei rulări **reușite**, nu `last_sha`: după o rulare care a eșuat la jumătate, următoarea compară de la ultimul commit documentat complet, deci nu pierde schimbările rulării eșuate (am scris un test exact pentru asta).
  - `git diff --name-status --no-renames` compară arborii celor două commit-uri, care sunt în clona blobless: un fișier redenumit e șters + adăugat (găsirea redenumirilor ar cere conținuturi nedescărcate), iar după un force push diferența tot se poate calcula cât timp commitul vechi e în clonă; dacă nu mai e, rularea devine completă și scrie de ce, în loc să cadă.
  - Impactul merge un singur pas prin graf, în ambele sensuri (cine importă din modulul schimbat și pe cine importă el), pentru că pagina unui modul e scrisă doar din fișierele lui și din legăturile lui directe: mai departe de un pas nimic din ce vede cel care scrie nu se schimbă. Fiecare pagină verificată se rescrie oricum doar dacă i s-a schimbat contextul (`input_hash`).
  - Peste 40% fișiere schimbate se reprocesează tot, ca în planul lucrării: atunci a afla ce e afectat costă cam cât a face totul.
  - Prezentarea generală și README-ul se verifică doar când apare sau dispare un modul, se schimbă un fișier din rădăcină (README, `package.json`…) sau s-au schimbat cel puțin 25% din module: sunt paginile cele mai scumpe și descriu proiectul, nu detaliile unui modul.
  - Analiza rămâne a tuturor fișierelor, dar analizorul ține minte ce a parsat după conținut, deci la o actualizare parsează doar fișierele schimbate; importurile se rezolvă din nou de fiecare dată, pentru că un fișier neschimbat poate importa unul nou sau șters. Cifrele sunt mai jos.
  - O pagină scrisă de Claude nu e înlocuită de șablon când Claude nu o poate rescrie: rămâne textul lui, marcat depășit, cu motivul exact, iar următoarea rulare cu AI o reîncearcă. Am găsit la rularea reală o greșeală: fără AI, o pagină depășită ținea pornită orice rulare la același commit (8,3 s în loc de „nimic nou”), deși n-avea cine s-o rescrie; acum reîncercarea are loc doar când e configurat un driver AI.
  - Fiecare versiune de pagină sau diagramă ține minte rularea care a scris-o (`processing_run_id`), iar starea de la un commit e, pentru fiecare pagină, ultima versiune scrisă de o rulare cu id-ul cel mult egal cu al rulării acelui commit. Prima variantă căuta după SHA-urile commit-urilor reușite și pierdea paginile scrise de o rulare care a eșuat mai târziu (deși rămăseseră pe site); id-urile rulărilor doar cresc, deci regula e simplă și exactă.
  - O „oprire” a călătoriei în timp e ultima rulare reușită a unui commit: un commit procesat din nou (reprocesare completă, reîncercarea unei pagini depășite) arată starea lui cea mai nouă, iar commitul curent rămâne corect și după o revenire prin force push la un commit vechi.
  - Webhook-ul: fără secret configurat adresa nu există (404); semnătura se verifică înainte de orice, cu `hash_equals` (timp constant); `ping` primește 200; un push pe altă ramură, la un repository necunoscut sau care nu e „Gata” primește 202 „ignorat”, ca GitHub să nu reîncerce. Ramura principală se ia din payload-ul semnat (e corectă și după o redenumire pe GitHub), iar livrările de tip formular sunt citite și ele, din câmpul `payload`.
  - Rulările pornite de webhook sau de verificarea programată au un interval minim de 10 minute pe repository, numărat de la ultima rulare care a lucrat (o rulare „nimic nou” nu costă nimic): o rafală de push-uri nu poate chema AI-ul de mai multe ori, iar commitul venit între timp îl prinde verificarea următoare. Butonul cere cont și are limita lui de cereri.
  - Orice comandă git pornește prin `App\Git\Git::process()`, cu `GIT_TERMINAL_PROMPT=0` și `GIT_ASKPASS` gol: o clonă blobless poate ieși pe rețea chiar și dintr-o simplă citire, iar workerul nu trebuie să aștepte o parolă pe care n-o tastează nimeni.
  - Costul echivalent API al fluxurilor din diagrame se adună acum pe rulare, lângă tokenii lor, ca cifrele de pe „Istoric” să descrie aceleași apeluri.
  - Pe „Istoric”, rularea care a lucrat vine înaintea rulărilor „nimic nou” de pe același commit, altfel cifrele ei erau ascunse în lista care se deschide la cerere.
- 2026-11-11 — Măsurători reale, cu AI oprit (`AI_DRIVER=none`: niciun apel Claude; costul AI se vede prin caracterele de context care ar fi fost trimise):
  - `heygen-com/hyperframes`, pornită de verificarea programată, de la `124591a` la `a8fe3a5`: 335 de fișiere schimbate din 10.068 (104 noi, 231 modificate), 333 parsate din nou, 64 de module afectate din 587 (52 cu fișiere schimbate + 12 vecini), 24 de pagini rescrise și 18 păstrate, 2.415.997 caractere de context, 28,4 s (toată comanda, cu `git fetch`: 29,9 s). O pagină scrisă de Claude a rămas marcată depășită, cu motivul ei.
  - Actualizarea dinainte, `c6d243a` → `124591a`: 225 de fișiere schimbate, 58 de module afectate din 584, 17 pagini rescrise și 25 păstrate, 1.822.960 de caractere, 25,4 s. Referința completă la `c6d243a` (fără clonare): 9.188 de fișiere parsate, 42 de pagini, 3.890.137 de caractere, 68,7 s.
  - „Nimic nou” (fetch + comparare + închiderea rulării): 0,7 s pe hyperframes și 0,7 s pe `excusemymanners/gitchronicles` (care nu are commit-uri noi pe GitHub după `3ac73c4`).
  - `php artisan gitchronicle:benchmark 3 --commits=5`: ultimele 5 commit-uri ale lui hyperframes (`9bab57a` … `a8fe3a5`, pornind de la `f36463e` procesat complet), fiecare procesat incremental și de la zero, într-o bază de date separată, cu AI oprit. Total: incremental 59,3 s față de 319,9 s de la zero (de 5,4 ori mai puțin), 19 pagini scrise față de 210, 2.172.418 caractere de context față de 19.510.681 (de 9 ori mai puțin). Pe commit: 2–32 de fișiere schimbate din ~10.060, 8–22 de module afectate din 587, 1–7 pagini scrise (35–41 păstrate), 9,6–13,7 s incremental față de 62,3–66,4 s de la zero.
  - Pe pași, totaluri pe cele 5 commit-uri (incremental / de la zero): analiza 22,9 s / 167,3 s (2–32 de fișiere parsate din nou față de ~9.330), documentația 18,5 s / 42,1 s, indexul asistentului 1,7 s / 94,0 s, diagramele 16,2 s / 16,5 s (se redesenează mereu: sunt deterministe și ieftine). De aceea analiza rămâne a tuturor fișierelor, cu cache: ~4,5 s pe commit nu justifică o analiză parțială, mai greu de explicat.
  - Tokeni: cu AI oprit nu s-a trimis nimic. Comanda îi **estimează** din caractere, cu raportul de 2,4 caractere pe token măsurat pe paginile scrise deja de Claude în această instalare: ≈ 905.000 de tokeni la intrare incremental față de ≈ 8,1 milioane de la zero. Este o estimare, nu o măsurătoare. Rezultatele: `storage/app/private/benchmarks/heygen-com-hyperframes-20261111-122109.json` și `.csv`.
  - Limite cunoscute: pagina unui modul șters dispare cu toate versiunile ei, deci în trecut se văd doar paginile care există și azi; un push venit cât rulează o actualizare e ignorat de webhook și îl prinde verificarea programată (fără ea, abia următorul push); fiecare commit nou refăcut duce și la un video nou (un apel AI pentru scenariu), limitat doar de intervalul minim.

## Faza 10 — Experimente, deploy, lucrarea
- Compar actualizarea incrementală cu reindexarea completă pe 3–5 proiecte open-source.
- Fac deploy pe un VPS cu k3s.
- Scriu lucrarea, pornind de la notițele din fiecare fază.

## Bonusuri — ianuarie, doar dacă rămâne timp
În ordinea asta, pentru că fiecare îl folosește pe cel dinainte.

### Discover / leaderboard
- **Învăț:** GitHub Search API, Laravel Scheduler (joburi care rulează singure la oră fixă).
- **Fac:** un job zilnic notează stelele repo-urilor urmărite. Din „pozele” zilnice calculez cine a câștigat cele mai multe stele (GitHub nu are API oficial pentru Trending). Pagina „Descoperă” are secțiunile: în trend, în creștere, comori ascunse, pe limbaje.
- **Gata când:** pagina arată un clasament care se actualizează singur în fiecare zi.

### Video scurt
- **Învăț:** HyperFrames (HTML/CSS/animații → MP4), FFmpeg.
- **Fac:** dintr-un șablon HTML și documentația generată fac un video vertical (9:16) despre un repo.
- **Gata când:** pentru orice repo documentat pot genera un MP4.

**Progres:**
- [x] V.1 uneltele de randare într-un proiect Node separat (`renderers/`): HyperFrames 0.9.29 (HTML + GSAP → MP4 prin Chrome și FFmpeg), Marp CLI 4.5.1, Mermaid pentru diagrama din slide-uri; `composer media:install` le instalează, împreună cu vocea
- [x] V.2 tabelul `media_assets` (video, slide-uri PDF/PPTX: stare, fișier, commit, durată, mărime) și fișierele în `storage/app/private/media/{id}`
- [x] V.3 scenariul video-ului: `FactsCollector` adună faptele reale (README, „Despre proiect”, GitHub, analiza, istoricul), iar `ScriptWriter` cere lui Claude scenele ca JSON strict; fiecare propoziție are un citat care trebuie să existe cuvânt cu cuvânt în textul trimis, altfel scena e respinsă; fără AI, `TemplateScenes` face un video mai scurt doar din fapte
- [x] V.4 vocea în română cu Piper (local, fără internet), muzica generată de un program propriu (fără drepturi de autor ale altcuiva), mixate cu FFmpeg; subtitrări WebVTT din aceleași texte
- [x] V.5 joburile de pe coada separată `media` (`GenerateMedia` → `RenderPresentationVideo`, `RenderPresentationSlides`), pornite după ce repository-ul e „gata”, fără să întârzie sau să strice procesarea; comanda `php artisan repositories:media {id} {--force} {--now}`
- [x] V.6 pagina „Video și slide-uri”: playerul vertical cu subtitrări, descărcări MP4/PDF/PPTX și lista „De unde vine fiecare propoziție” (citatul și sursa fiecărei scene); cardurile „Video de prezentare” și „Prezentare (slide-uri)” din panoul „Elemente”
- [x] V.7 teste fără Chrome, FFmpeg, Piper sau rețea (procesele sunt simulate); 516 teste trec
- [x] V.8 verificat pe bune pe `excusemymanners/gitchronicles`: video de 32,5 s (1080×1920, voce + muzică), 10 slide-uri; totul în 1 min 28 s, cu un singur apel la Claude pentru scenariu

**Jurnal:**
- 2026-11-11 — Video-ul și slide-urile. Decizii și motivele lor:
  - Video-ul spune ce face proiectul și ce îl face special (cârlig, problema rezolvată, 2–3 funcționalități, cum începi, cifre, îndemn), nu statistici despre cod: așa arată reel-urile care prezintă repository-uri.
  - Regula „doar date reale” e verificată de server, nu doar cerută modelului: fiecare scenă vine cu un citat, iar citatul trebuie găsit în textul trimis lui Claude (după normalizarea spațiilor). O scenă fără citat valid e respinsă și înlocuită de varianta din șablon sau lăsată deoparte.
  - Uneltele de randare stau în `renderers/`, nu în `package.json`-ul aplicației: sunt mari, rulează doar pe server și nu trebuie să ajungă în pachetul trimis browserului.
  - Randarea e pe coada `media`, separată: durează cam un minut și nu trebuie să țină pe loc procesarea altor repository-uri. Dacă randarea eșuează, repository-ul rămâne „gata”; pagina arată motivul.
  - Vocea e Piper (`ro_RO-mihai-medium`), rulat local ca proces separat: fără servicii plătite și fără internet după instalare. Muzica e generată de un program propriu, ca să nu depindă de licența unei piese.
  - Durata fiecărei scene vine din durata reală a propoziției rostite, măsurată cu `ffprobe`, ca vocea și imaginea să rămână sincronizate. Video-ul are cel mult 35 de secunde: dacă ar depăși, a treia funcționalitate rămâne doar în slide-uri.
  - Marp nu desenează Mermaid, așa că diagrama de containere e desenată separat ca PNG (Chrome + Mermaid) și pusă în slide ca imagine; fonturile și imaginile sunt incluse în fișier, deci PDF-ul arată la fel pe orice calculator.
  - Pagina arată pentru fiecare propoziție citatul și sursa ei („De unde vine fiecare propoziție”), ca oricine să poată verifica video-ul.
  - De știut: FFmpeg-ul din Fedora nu are `libx264`, așa că video-ul se codează pe placa video (VAAPI) și iese mai mare (≈13 MB la 32 s); calea spre alt FFmpeg se poate pune în `.env` (`MEDIA_FFMPEG_PATH`).

### Postări automate pe Instagram
- **Învăț:** Instagram Graph API (container media → publicare), aplicații Meta.
- **Fac:** zilnic aleg „repo-ul zilei” din leaderboard, generez video-ul și îl postez ca Reel.
- **Pregătire din timp:** cont Instagram profesional + pagină de Facebook legată + aplicație Meta. Video-ul trebuie să fie la un link public, deci merge doar după deploy.
- **Gata când:** în fiecare zi apare singur un Reel nou pe cont.

### Consultanță
- **Învăț:** Semgrep, OSV-Scanner, scc, `git log` pentru hotspots.
- **Fac:** rulez uneltele pe repo, apoi LLM-ul strânge rezultatele într-un raport cu recomandări.
- **Gata când:** pentru un repo pot descărca un raport de consultanță.

---

## Start: Faza 0

```bash
cd ~/Desktop/gitchronicle/gitchronicles
git config --global user.name "Numele Tău"
git config --global user.email "emailul@tau.com"
git config --global init.defaultBranch main
git init
git add .
git commit -m "Proiect Laravel 13 inițial"
```

Pornesc aplicația în două terminale:

```bash
php artisan serve     # terminalul 1
npm install           # terminalul 2, o singură dată
npm run dev           # terminalul 2
```

Apoi deschid http://127.0.0.1:8000.
