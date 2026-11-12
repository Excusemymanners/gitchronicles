# Uneltele pentru „Consultanță”

Trei programe care citesc clona unui repository și spun ce probleme are, folosite de jobul
`GenerateConsultingReport` (coada `media`, după ce repository-ul e gata). Niciunul nu rulează codul repository-ului:
doar îl citesc.

| Unealtă | Ce găsește | Unde se instalează |
|---|---|---|
| Semgrep | bug-uri și probleme de securitate în cod, după regulile din `rules/` | `.venv/` (mediu virtual Python) |
| OSV-Scanner | dependențe (din fișierele lock) cu vulnerabilități cunoscute | `bin/osv-scanner` |
| scc | limbaje, linii de cod, comentarii, complexitate | `bin/scc` |

Instalare, o singură dată, fără root: `composer consulting:install` (rulează `install.sh`). Fiecare unealtă vine din
canalul ei oficial, la o versiune fixă, și e verificată cu amprenta SHA-256: Semgrep de pe PyPI (`requirements.txt`,
cu amprenta fiecărui pachet, `pip --require-hashes`), OSV-Scanner și scc din release-urile lor de pe GitHub (amprentele
sunt în `install.sh`). Am ales versiunile cele mai noi care aveau deja cel puțin două săptămâni la instalare: o versiune
stricată sau compromisă apucă să fie observată și retrasă. Ce se instalează nu se urcă în git (`.gitignore`).

Căile se pot schimba în `.env` (`CONSULTING_SEMGREP_PATH`, `CONSULTING_OSV_SCANNER_PATH`, `CONSULTING_SCC_PATH`), de
exemplu pe alt sistem decât Linux x86_64. O unealtă lipsă nu strică raportul: secțiunea ei spune că nu e instalată.

## Ce iese de pe calculator în timpul unei analize

- **Semgrep**: nimic. Regulile sunt fișiere locale (`rules/`), metricile sunt oprite (`--metrics off`) și verificarea
  versiunii noi e oprită (`--disable-version-check`). Documentația Semgrep (semgrep.dev/docs/metrics) spune că, rulat cu
  reguli locale, nu trimite metrici, iar codul sursă nu e trimis niciodată.
- **OSV-Scanner**: numele, versiunea și ecosistemul fiecărui pachet găsit în fișierele lock (de exemplu
  `Packagist laravel/framework 13.0.0`) pleacă spre `api.osv.dev` (Google), apoi id-urile vulnerabilităților găsite,
  pentru detalii. Repository-urile din GitChronicle sunt publice, deci aceste date sunt deja publice. Nu se trimite
  cod: rezolvarea dependențelor tranzitive (deps.dev) e oprită (`--no-resolve`), la fel analiza apelurilor
  (`--no-call-analysis=all`, care pentru Rust ar rula scripturi de build) și pluginurile care trimit amprente de fișiere
  (doar `--x-plugins lockfile`).
- **scc**: nimic.

## Regulile Semgrep (`rules/`)

Sunt scrise pentru GitChronicle, puține și explicate în engleză (limba rapoartelor generate): PHP și Laravel, JavaScript/TypeScript și React, Python
și secrete lăsate în cod (chei private, chei AWS, tokenuri GitHub; mesajul nu repetă niciodată secretul). Fiecare are
`metadata.category` (`security` = vulnerabilitate, `correctness` = bug) și `metadata.title`.

Nu folosim pachetele de reguli din Semgrep Registry (de exemplu `p/default`) din două motive: se descarcă de pe
semgrep.dev la fiecare analiză (cu metrici trimise, dacă nu sunt oprite), iar licența lor („Semgrep Rules License
v1.0”) le permite doar „pentru scopurile interne ale firmei tale” și interzice explicit să fie oferite altora „ca
serviciu” — exact ce face GitChronicle cu repository-urile altora. Regulile noastre sunt mai puține, dar fiecare
rezultat se poate explica.

## Licențe

| Ce | Versiune | Licență | Sursa |
|---|---|---|---|
| Semgrep (Community Edition, rulat ca proces separat) | 1.182.1 | LGPL-2.1-or-later | https://github.com/semgrep/semgrep |
| OSV-Scanner | 2.7.0 | Apache-2.0 | https://github.com/google/osv-scanner |
| scc | 4.1.0 | MIT | https://github.com/boyter/scc |
| Baza de date OSV (interogată prin `api.osv.dev`) | — | datele: licența fiecărei surse (GitHub Advisory Database, PyPI, …) | https://osv.dev |
| Regulile din `rules/` | — | codul acestui proiect | — |
