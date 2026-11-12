#!/usr/bin/env bash
#
# Instalează uneltele capitolului „Consultanță” în acest folder, fără drepturi de root:
#
#   - Semgrep (bug-uri și probleme de securitate în cod), într-un mediu virtual Python: .venv/
#   - OSV-Scanner (dependențe cu vulnerabilități cunoscute), un singur fișier executabil: bin/osv-scanner
#   - scc (limbaje, linii de cod, comentarii, complexitate), un singur fișier executabil: bin/scc
#
# Fiecare vine din canalul oficial (PyPI, release-urile GitHub ale proiectului), la o versiune fixă, și e
# verificat cu amprenta SHA-256 scrisă aici (sau în requirements.txt pentru Semgrep): dacă fișierul descărcat
# diferă cu un singur bit, instalarea se oprește și nu rămâne nimic pe jumătate instalat în bin/.
#
# Rulare: composer consulting:install (din rădăcina proiectului).

set -euo pipefail

cd "$(dirname "$0")"

OSV_SCANNER_VERSION=2.7.0
OSV_SCANNER_SHA256=160c6253e2922e9e0a6a9771c5c4c40a4b3c6090b34a3cdd5e80c96cc2e4218b

SCC_VERSION=4.1.0
SCC_SHA256=c7328436d3027f4357d3d7853f7dc3ac2bbcb4ca08f1adad91a27c593884079b

if [ "$(uname -s)" != "Linux" ] || [ "$(uname -m)" != "x86_64" ]; then
    echo "Uneltele sunt fixate pentru Linux x86_64; pe alt sistem, pune căile lor în .env (CONSULTING_*_PATH)." >&2
    exit 1
fi

# Descarcă un fișier doar prin HTTPS și îl păstrează numai dacă amprenta lui SHA-256 e cea așteptată.
download() {
    local url=$1 file=$2 sha256=$3

    curl --fail --location --silent --show-error --proto '=https' --tlsv1.2 --output "$file" "$url"
    echo "$sha256  $file" | sha256sum --check --strict --quiet
}

rm -rf downloads
mkdir -p downloads bin
trap 'rm -rf downloads' EXIT

echo "Semgrep (PyPI, cu amprentele din requirements.txt)…"
python3 -m venv .venv
.venv/bin/pip install --quiet --disable-pip-version-check --no-cache-dir --require-hashes --only-binary=:all: -r requirements.txt

echo "OSV-Scanner ${OSV_SCANNER_VERSION} (release-ul oficial google/osv-scanner)…"
download "https://github.com/google/osv-scanner/releases/download/v${OSV_SCANNER_VERSION}/osv-scanner_linux_amd64" \
    downloads/osv-scanner "$OSV_SCANNER_SHA256"
install -m 0755 downloads/osv-scanner bin/osv-scanner

echo "scc ${SCC_VERSION} (release-ul oficial boyter/scc)…"
download "https://github.com/boyter/scc/releases/download/v${SCC_VERSION}/scc_Linux_x86_64.tar.gz" \
    downloads/scc.tar.gz "$SCC_SHA256"
tar -xzf downloads/scc.tar.gz -C downloads scc
install -m 0755 downloads/scc bin/scc

echo "Gata: Semgrep $(SEMGREP_ENABLE_VERSION_CHECK=0 SEMGREP_SEND_METRICS=off .venv/bin/semgrep --version 2>/dev/null | tail -n 1), $(bin/osv-scanner --version | head -n 1), $(bin/scc --version)."
