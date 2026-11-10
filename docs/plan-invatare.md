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

## Faza 3 — Joburi: clonarea
- **Învăț:** cozi și joburi în Laravel, rularea comenzilor git din PHP, testarea joburilor, containere Podman.
- **Fac:** pornesc Postgres (cu pgvector) și Redis în containere Podman și instalez Horizon. Apoi un job care face `git clone`, salvează SHA-ul ultimului commit și schimbă statusul (în așteptare → clonat / eroare).
- **Gata când:** adaug un repository, jobul îl clonează și pe pagină văd SHA-ul.

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

## Faza 6 — Documentația generată cu AI
- **Învăț:** apeluri la un LLM prin Prism, prompturi și controlul costului în tokeni.
- **Fac:** o pagină Markdown pentru fiecare modul, plus o pagină de ansamblu, afișate cu meniu lateral și căutare (stil Docsify).
- **Gata când:** pentru un repo mic am o documentație completă pe care o pot naviga.

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
