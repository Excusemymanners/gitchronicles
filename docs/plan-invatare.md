# Plan de învățare — GitChronicle

Planul complet al licenței e în [licenta.md](licenta.md). Acesta e drumul pas cu pas prin care construiesc aplicația singur.

**Regulă:** întâi aplicația merge pe laptop (SQLite, `php artisan serve`), abia apoi o mut în Kubernetes (Faza 4).

**La fiecare fază:** notez ce am ales și de ce, pentru lucrare.

---

## Faza 0 — Git și rularea locală
- **Învăț:** git (init, commit, log), cum pornește o aplicație Laravel și ce face Vite.
- **Fac:** `git init` și primul commit, apoi pornesc aplicația cu `php artisan serve` + `npm run dev`.
- **Gata când:** văd pagina Laravel în browser și `git log` arată primul commit.

## Faza 1 — Inertia + React
- **Învăț:** cum trimite Laravel date către React fără un API separat (rute → controller → pagină React cu props).
- **Fac:** instalez Inertia și React și fac o pagină de start în React.
- **Gata când:** ruta `/` afișează o pagină React și am un test care verifică asta.

## Faza 2 — Utilizatori și repository-uri
- **Învăț:** migrații, modele Eloquent, validare, formulare Inertia, autentificare.
- **Fac:** login, modelul `Repository` (url, ultimul_sha, status), un formular „adaugă repository” și lista repository-urilor mele.
- **Gata când:** mă loghez, adaug un URL de GitHub și îl văd în listă.

## Faza 3 — Joburi: clonarea
- **Învăț:** cozi și joburi în Laravel, rularea comenzilor git din PHP, testarea joburilor.
- **Fac:** un job care face `git clone`, salvează SHA-ul ultimului commit și schimbă statusul (în așteptare → clonat / eroare).
- **Gata când:** adaug un repository, jobul îl clonează și pe pagină văd SHA-ul.

## Faza 4 — Containere și Kubernetes
- **Învăț:** imagini de container (Podman), Pods, Deployments, Services, StatefulSets, Kustomize.
- **Fac:** imaginea aplicației, un cluster `kind`, Postgres + pgvector, Redis, aplicația și workerii Horizon, toate în cluster.
- **Gata când:** tot ce aveam în Faza 3 merge la fel, dar în Kubernetes.

## Faza 5 — Analiza codului
- **Învăț:** tree-sitter (parsarea codului) și grafuri de dependențe.
- **Fac:** un serviciu mic în Python care extrage din cod fișierele, clasele, funcțiile și importurile. Laravel le salvează în baza de date și construiește graful de dependențe.
- **Gata când:** pentru un repository clonat văd ce module are și cine pe cine importă.

## Faza 6 — Documentația generată cu AI
- **Învăț:** apeluri la un LLM prin Prism, prompturi și controlul costului în tokeni.
- **Fac:** o pagină Markdown pentru fiecare modul, plus o pagină de ansamblu, afișate cu meniu lateral și căutare (stil Docsify).
- **Gata când:** pentru un repo mic am o documentație completă pe care o pot naviga.

## Faza 7 — Diagrame C4
- **Fac:** diagrame Mermaid C4 generate din graful de dependențe și din fișierele de configurare.
- **Gata când:** pagina repository-ului arată diagramele Container și Component.

## Faza 8 — Chat-ul despre cod (RAG)
- **Învăț:** embedding-uri, pgvector, căutare semantică.
- **Fac:** împart codul în bucăți, le salvez ca vectori și fac un chat care răspunde citând fișierul și liniile.
- **Gata când:** întreb „unde se face autentificarea?” și primesc un răspuns cu fișierul și liniile corecte.

## Faza 9 — Actualizarea incrementală (miezul licenței)
- **Fac:** `git diff` față de ultimul SHA, reprocesez doar ce s-a schimbat, propag schimbarea prin graf, apoi adaug webhook-ul GitHub și detectarea documentației învechite.
- **Gata când:** după un push se regenerează doar paginile afectate și pot măsura timpul și tokenii economisiți.

## Faza 10 — Experimente, deploy, lucrarea
- Compar actualizarea incrementală cu reindexarea completă pe 3–5 proiecte open-source.
- Fac deploy pe un VPS cu k3s.
- Scriu lucrarea, pornind de la notițele din fiecare fază.

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
