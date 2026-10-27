# Licență — Asistent AI pentru documentarea automată și incrementală a unui repository software

**Student:** Pilat Luca — Tehnologia Informației, anul IV, grupa 412
**An universitar:** 2026–2027

## 1. Ideea
Sistem care primește un repository GitHub, îi analizează codul și dependențele și generează automat:
- documentație în stil DeepWiki / Docsify (pagini pe module, navigare, căutare);
- diagrame arhitecturale C4 (Context, Container, Component);
- un asistent conversațional care răspunde la întrebări despre cod, citând fișierele și liniile;
- (bonus) video-uri scurte (shorts) despre repository, în stil gittrend.io, cu HyperFrames.

**Challenge principal:** după prima indexare, documentația se actualizează doar pe baza istoricului git de la ultima indexare, nu de la zero.

**Titlu propus:** „Asistent AI pentru generarea și actualizarea incrementală a documentației și a diagramelor arhitecturale C4 ale unui repository software”

## 2. Tehnologii
| Componentă | Tehnologie |
|---|---|
| Aplicație web | Laravel + Livewire (sau Inertia + React) |
| Cozi / joburi | Laravel Horizon + Redis |
| Bază de date | PostgreSQL + pgvector |
| Analiză cod | Serviciu Python/Node cu tree-sitter |
| Git | git CLI din joburi (clone, diff între SHA-uri) |
| LLM | Claude / OpenAI prin Prism (Laravel) |
| Diagrame | Mermaid (sintaxa C4) |
| Afișare documentație | Markdown randat în stil Docsify/DeepWiki |
| Shorts (bonus) | HyperFrames (Node) |
| Containere | Imagini construite cu Podman |
| Orchestrare | Kubernetes — local: kind (pe Podman); producție: k3s pe VPS |
| Manifeste | Kustomize (overlay-uri dev / prod); Helm doar pentru componente externe (ex. KEDA) |
| Hosting | VPS (Hetzner / DigitalOcean) cu k3s |

## 3. Arhitectură
1. Utilizatorul introduce URL-ul repository-ului.
2. Job de clonare → job de parsare (tree-sitter) → graf de dependențe.
3. Joburi LLM generează documentația per modul + diagramele C4.
4. Codul e împărțit în bucăți (funcții/clase) → embedding-uri în pgvector.
5. Asistentul conversațional folosește RAG peste embedding-uri.
6. Se salvează SHA-ul ultimului commit indexat.

## 4. Actualizare incrementală
- `git diff --name-status <ultimul_SHA>..HEAD` → fișiere adăugate / modificate / șterse / redenumite.
- Reparsare doar pentru fișierele schimbate; embedding-uri recalculate doar pentru bucățile afectate.
- Graful de dependențe identifică modulele afectate indirect → se regenerează doar paginile și componentele C4 atinse.
- Cache pe hash de fișier: un fișier neschimbat nu se reprocesează.
- Dacă diff-ul e prea mare (ex. > 40% din fișiere) → reindexare completă.
- Opțional: webhook GitHub la push declanșează actualizarea automat.

## 5. Diagrame C4
- **Component / Container:** derivate determinist din graful de dependențe și din config (Dockerfile, docker-compose, package.json, composer.json).
- **Context:** completat de LLM pe baza README-ului.
- Export în Mermaid C4 → versionabil, actualizabil incremental.

## 6. Hosting și scalabilitate
- Licență: un VPS (minim 4 GB RAM) cu k3s; aplicația, workerii Horizon, Postgres și Redis rulează ca Deployment-uri / StatefulSet-uri separate.
- Gâtul de sticlă: indexarea și LLM-ul → rezolvat prin cozi; workerii se scalează automat cu KEDA după lungimea cozii Redis, iar la nevoie se adaugă noduri noi în cluster fără schimbări de cod.
- Actualizarea incrementală reduce tokenii și timpul la fiecare commit.
- La volum mare: pgvector → Qdrant, workeri pe mașini separate, storage S3 pentru artefacte.

## 7. Elemente inovative
1. **Documentație vie:** la fiecare push se deschide automat un pull request care actualizează doar paginile afectate.
2. **Detectarea documentației învechite:** semnalează unde codul nu mai corespunde documentației.
3. **Diagrame C4 cu istoric:** evoluția arhitecturii de la un commit la altul.
4. **Asistent cu citare:** fiecare răspuns trimite la fișier și liniile exacte.
5. **Shorts automate la release:** video scurt din changelog și diff, cu HyperFrames.

Direcție principală pentru partea academică: punctele 1 și 2 (măsurabile).

## 8. Plan de implementare

### Pasul 1 — Fundația
- Proiect Laravel + cluster Kubernetes local (kind pe Podman): Postgres cu pgvector, Redis, aplicația web și workerii Horizon ca resurse separate.
- Autentificare simplă, model `Repository` (url, ultimul_sha, status).
- Formular: introduci URL-ul GitHub → job de clonare.

### Pasul 2 — Analiza codului
- Serviciu tree-sitter (Python sau Node) apelat din job.
- Extrage fișiere, clase, funcții, importuri → salvate în DB.
- Construiește graful de dependențe între module.

### Pasul 3 — Generarea documentației (MVP)
- Integrare LLM prin Prism.
- O pagină Markdown per modul + pagină de overview.
- Interfață în stil Docsify: meniu lateral, căutare.

### Pasul 4 — Diagrame C4
- Generare Mermaid C4 din graful de dependențe.
- Afișare în pagina repository-ului.

### Pasul 5 — Asistentul conversațional
- Împărțire în bucăți + embedding-uri în pgvector.
- Chat RAG cu citarea fișierelor și liniilor.

### Pasul 6 — Actualizarea incrementală (challenge-ul)
- Diff față de ultimul SHA, reprocesare selectivă, propagare prin graf.
- Webhook GitHub; opțional PR automat cu documentația actualizată.
- Detectarea documentației învechite.

### Pasul 7 — Partea experimentală
- Incremental vs. reindexare completă: timp, tokeni (cost), calitate.
- Documentație generată vs. documentație existentă pe 3–5 proiecte open-source.
- Metrici: acoperire, corectitudine (evaluare umană + LLM-as-judge), similaritate.

### Pasul 8 — Bonus și deploy
- Shorts cu HyperFrames la release.
- Deploy pe VPS cu k3s, folosind aceleași manifeste ca local (overlay-ul prod).
- Redactarea lucrării.
