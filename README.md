# GitChronicle

Asistent AI care documentează automat un repository GitHub și ține documentația la zi pe baza istoricului git: după prima indexare, procesează doar ce s-a schimbat de la ultimul commit indexat, nu tot proiectul de la zero.

Proiect de licență — Tehnologia Informației, 2026–2027.

- Planul complet al lucrării: [docs/licenta.md](docs/licenta.md)
- Planul de învățare, pas cu pas: [docs/plan-invatare.md](docs/plan-invatare.md)

## Ce face

- **Documentație** în stil DeepWiki: o pagină pentru fiecare modul, cu navigare și căutare.
- **Diagrame de arhitectură C4** (Context, Container, Component), generate din codul proiectului.
- **Asistent pe cod**: răspunde la întrebări despre repository și citează fișierul și liniile exacte.
- **Actualizare incrementală**: la fiecare push se regenerează doar paginile afectate.
- **Consultanță**: raport cu bug-uri, vulnerabilități și zonele de cod schimbate cel mai des.
- **Prezentare**: slide-uri despre repository (PDF/PPTX).
- **Video scurt** generat la fiecare release.

## Stare

**Faza curentă:** Faza 1 — Inertia + React (în lucru).

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
| Inertia | Leagă Laravel de React fără un API separat | 1 | 🔄 |
| React + TypeScript | Interfața: pagini, formulare, chat, documentație | 1 | ⏳ |
| PHPUnit | Teste pentru fiecare funcționalitate | 1+ | ⏳ |
| SQLite | Baza de date locală la început | 2 | ⏳ |
| Eloquent + migrații | Tabelele `users`, `repositories` și accesul la ele | 2 | ⏳ |
| Cozi Laravel + Process | Joburile care rulează `git clone` / `git diff` în fundal | 3 | ⏳ |
| Podman | Imaginea de container a aplicației | 4 | ⏳ |
| Kubernetes + kind + Kustomize | Rularea aplicației, a bazei de date și a workerilor ca în producție | 4 | ⏳ |
| PostgreSQL | Baza de date principală | 4 | ⏳ |
| Redis + Horizon | Coada de joburi și monitorizarea workerilor | 4 | ⏳ |
| Python + tree-sitter | Analiza codului: clase, funcții, importuri → graful de dependențe | 5 | ⏳ |
| Laravel AI SDK + Claude API | Generarea documentației, a rapoartelor și a răspunsurilor din chat | 6, 8 | ⏳ |
| react-markdown | Afișarea documentației generate | 6 | ⏳ |
| Mermaid (C4) | Diagramele de arhitectură | 7 | ⏳ |
| pgvector + Voyage AI | Embedding-uri și căutare semantică pentru asistent | 8 | ⏳ |
| GitHub webhooks | Pornesc actualizarea incrementală la fiecare push | 9 | ⏳ |
| Semgrep, OSV-Scanner, scc | Consultanța: bug-uri, vulnerabilități, statistici despre cod | bonus | ⏳ |
| Marp | Prezentarea repository-ului ca slide-uri | bonus | ⏳ |
| HyperFrames + FFmpeg | Video-urile scurte despre repository | bonus | ⏳ |
| k3s + KEDA | Deploy pe VPS; workerii se scalează după lungimea cozii | 10 | ⏳ |

## Plan de dezvoltare

Calendar orientativ.

| Perioadă | Faza | Rezultat | Stare |
|---|---|---|---|
| 28 oct – 3 nov 2026 | 1. Inertia + React + TypeScript | Pagina de start în React, cu test | 🔄 |
| 4 – 17 nov | 2. Utilizatori și repository-uri | Login; adaug un URL de GitHub și îl văd în listă | ⏳ |
| 18 – 30 nov | 3. Jobul de clonare | 🏁 Aplicația clonează un repo și afișează SHA-ul | ⏳ |
| Decembrie | 4. Containere și Kubernetes | Tot ce există, rulând în cluster | ⏳ |
| Ianuarie | 5. Analiza codului (tree-sitter) | Modulele și dependențele unui repo | ⏳ |
| Februarie | 6–7. Documentație AI + diagrame C4 | 🏁 Documentație completă pentru un repo mic | ⏳ |
| Martie | 8. Asistentul pe cod (RAG) | Răspunsuri cu fișierul și liniile citate | ⏳ |
| Aprilie | 9. Actualizarea incrementală | 🏁 Miezul lucrării funcționează | ⏳ |
| Mai | 10. Experimente, bonusuri, deploy | Rezultate măsurate; aplicația online | ⏳ |
| Iunie 2027 | Lucrarea scrisă | Lucrarea predată | ⏳ |

## Mod de lucru

1. Un pas mic = un commit; push la sfârșitul fiecărei zile de lucru.
2. O fază e gata doar când rezultatul ei funcționează și are un test.
3. Pentru fiecare fază se notează deciziile și motivele lor, pentru lucrare.
4. Miezul (fazele 1–9) înaintea bonusurilor (consultanță, prezentare, video).

## Rulare locală

Cerințe: PHP 8.3+, Composer, Node.js 22+.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
```

Apoi, în două terminale:

```bash
php artisan serve   # aplicația, pe http://127.0.0.1:8000
npm run dev         # Vite: CSS/JS și reîncărcare automată
```
