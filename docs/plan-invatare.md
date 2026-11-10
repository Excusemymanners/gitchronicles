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

## Faza 7 — Diagrame C4 + prezentare
- **Fac:** diagrame Mermaid C4 generate din graful de dependențe și din fișierele de configurare, plus o prezentare Marp (PDF/PPTX) construită din documentația generată.
- **Gata când:** pagina repository-ului arată diagramele Container și Component și pot descărca prezentarea.

## Faza 8 — Chat-ul despre cod (RAG)
- **Învăț:** embedding-uri, pgvector, căutare semantică.
- **Fac:** împart codul în bucăți, le salvez ca vectori și fac un chat care răspunde citând fișierul și liniile.
- **Gata când:** întreb „unde se face autentificarea?” și primesc un răspuns cu fișierul și liniile corecte.

## Faza 9 — Actualizarea incrementală (miezul licenței)
- **Fac:** `git diff` față de ultimul SHA, reprocesez doar ce s-a schimbat, propag schimbarea prin graf, apoi adaug webhook-ul GitHub și detectarea documentației învechite.
- **Time travel:** nu mai suprascriu documentația și diagramele, ci le păstrez pe fiecare cu SHA-ul commit-ului lor. În interfață, un slider prin commit-uri arată cum arăta proiectul atunci și ce componente au apărut sau au dispărut.
- **Gata când:** după un push se regenerează doar paginile afectate, pot măsura timpul și tokenii economisiți și pot „călători” înapoi la un commit vechi.

**Progres:**
- [x] 9.1 istoricul git: tabelele `commits` și `commit_files`, `CommitHistory` (citește `git log --numstat` din clonă) și jobul `ImportCommits`, pus în lanț imediat după analiză: prima dată cele mai noi 500 de commit-uri, apoi doar cele de după ultimul commit procesat
- [x] 9.2 tab-ul „Istoric”: commit-urile pe luni (în română), rulările GitChronicle pe commit-urile procesate, căutare după mesaj sau autor, „Încarcă mai multe”, „Zone fierbinți” (top 10) și „Contribuitori”; pe „Prezentare”, cardurile „Activitate” (graficul ultimelor 12 luni) și „Zone fierbinți” (top 5)
- [x] 9.3 teste: parsarea pe un repository git local cu subiecte „grele” (separatori, diacritice, emoji, corp pe mai multe rânduri), clone blobless cu și fără acces la origine, import idempotent și incremental, jobul în lanț, clasamentul și excluderile zonelor fierbinți, paginile pentru vizitatori, paginarea și căutarea; 220 de teste trec
- [ ] 9.4 actualizarea incrementală a documentației (`git diff` față de ultimul SHA, propagarea prin graf), webhook-ul și time travel

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
