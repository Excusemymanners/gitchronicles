#!/usr/bin/env bash
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

source "$DIR/lib/common.sh"
. ./lib/colors.sh
source "$HOME/.profile"
source "$CONFIG_DIR/settings.sh"

install_tool() {
  log_info "installing"
  bash scripts/build.sh
}

function finish {
  ./bin/run
}

install_tool
finish
