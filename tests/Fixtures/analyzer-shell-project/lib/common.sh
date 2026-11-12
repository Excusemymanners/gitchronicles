#!/bin/sh
log_info() {
  printf '%s\n' "$1"
}

function warn {
  printf 'warning: %s\n' "$1" >&2
}
