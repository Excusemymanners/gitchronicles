#!/usr/bin/env bash
#
# Instalează vocile video-ului în renderers/tts, fără root și fără nimic urcat în git:
#   - mediul Python .venv cu Piper, Kokoro (kokoro-onnx) și edge-tts, cu versiunile fixe din requirements.txt;
#   - modelele, în voices/: Kokoro v1.0 (vocea engleză implicită, ~340 MB), Piper ro_RO-mihai-medium și
#     en_US-joe-medium (vocea română implicită și rezerva fiecărei limbi, ~130 MB).
# Se poate rula oricând din nou: instalează doar ce lipsește (pip sare peste pachetele care există deja).
# Folosire: bash renderers/tts/install.sh   (sau composer media:install, care face și restul uneltelor)
set -euo pipefail
cd "$(dirname "$0")"

PYTHON="${PYTHON:-python3}"
KOKORO_URL="https://github.com/thewh1teagle/kokoro-onnx/releases/download/model-files-v1.0"

# 1. Mediul Python. „python -m pip”, nu .venv/bin/pip: un mediu copiat dintr-un alt folder are scripturi
#    care arată spre folderul vechi, dar python -m pip instalează mereu în mediul acesta.
if [ ! -x .venv/bin/python ]; then
    "$PYTHON" -m venv .venv
fi

.venv/bin/python -m pip install --quiet --no-cache-dir --disable-pip-version-check -r requirements.txt
mkdir -p voices

# 2. Vocile Piper (rezerva care merge mereu, pe procesor).
for voice in ro_RO-mihai-medium en_US-joe-medium; do
    if [ ! -s "voices/${voice}.onnx" ] || [ ! -s "voices/${voice}.onnx.json" ]; then
        .venv/bin/python -m piper.download_voices --download-dir voices "${voice}"
    fi
done

# 3. Kokoro: modelul și vocile lui, verificate cu SHA-256 (un fișier descărcat pe jumătate e șters).
download() {
    local file="$1" sha="$2"

    if [ -s "voices/${file}" ] && echo "${sha}  voices/${file}" | sha256sum --check --status; then
        return
    fi

    curl --fail --location --retry 3 --silent --show-error --output "voices/${file}.part" "${KOKORO_URL}/${file}"

    if ! echo "${sha}  voices/${file}.part" | sha256sum --check --status; then
        rm -f "voices/${file}.part"
        echo "Fișierul ${file} descărcat nu are suma SHA-256 așteptată." >&2
        exit 1
    fi

    mv "voices/${file}.part" "voices/${file}"
}

download kokoro-v1.0.onnx 7d5df8ecf7d4b1878015a32686053fd0eebe2bc377234608764cc0ef3636a6c5
download voices-v1.0.bin bca610b8308e8d99f32e6fe4197e7ec01679264efed0cac9140fe9c29f1fbf7d

echo "Vocile sunt instalate în $(pwd)/voices."
