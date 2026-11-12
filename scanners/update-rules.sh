#!/usr/bin/env bash
#
# Refreshes the community Semgrep rules (rule-sources.txt) into rules/upstream/ and records the date and the
# revision of each source in rules-version.json, which the „Consultanță” page shows ("How current is this").
# Run it with `composer scanners:update`; the Laravel scheduler runs it weekly.
#
# Safe to run again at any time (idempotent):
#   1. every source is cloned (shallow) into a temporary folder and its rule files are copied to a staging folder,
#      without the patterns of rule-excludes.txt;
#   2. each source is checked with `semgrep --validate` on its own; a source that does not validate keeps its
#      previous copy (or is skipped) and is reported, the others go on;
#   3. the previous rules/upstream is moved to rules-previous/ and the staging folder takes its place;
#   4. the whole rules folder (own rules + upstream) is validated once more; if that fails the previous
#      copy is put back and the script exits with an error.
# rules-previous/ stays until the next run, outside rules/, so Semgrep never reads it. Nothing is written
# outside this scanners/ folder and the system temporary folder.
#
# Needs: git, network access to github.com, and Semgrep (composer consulting:install).
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RULES="$HERE/rules"
UPSTREAM="$RULES/upstream"
STAGING="$HERE/rules-staging"
PREVIOUS="$HERE/rules-previous"
VERSION_FILE="$HERE/rules-version.json"
SEMGREP="${CONSULTING_SEMGREP_PATH:-$HERE/.venv/bin/semgrep}"

if [ ! -x "$SEMGREP" ]; then
  echo "Semgrep is not installed at $SEMGREP (composer consulting:install)." >&2
  exit 1
fi

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK" "$STAGING"' EXIT
rm -rf "$STAGING"
mkdir -p "$STAGING"

validate() {
  "$SEMGREP" --validate --config "$1" --metrics off --disable-version-check --quiet >"$WORK/validate.log" 2>&1
}

sources_json=""
failed_sources=0

# rule-excludes.txt: patterns of rules that are left out (low-confidence "audit" and "noisy" sets).
exclusions=()
while IFS= read -r pattern; do
  case "$pattern" in
    path:*) exclusions+=(! -path "${pattern#path:}") ;;
    name:*) exclusions+=(! -name "${pattern#name:}") ;;
  esac
done < "$HERE/rule-excludes.txt"

trim() { local text="$1"; text="${text#"${text%%[![:space:]]*}"}"; echo "${text%"${text##*[![:space:]]}"}"; }

while IFS= read -r line; do
  case "$(trim "$line")" in ''|'#'*) continue ;; esac
  IFS='|' read -r name repo subdir license <<< "$line"
  name="$(trim "$name")"; repo="$(trim "$repo")"; subdir="$(trim "$subdir")"; license="$(trim "$license")"

  echo "== $name ($repo)"
  git clone --quiet --depth 1 "https://github.com/$repo.git" "$WORK/$name"
  revision="$(git -C "$WORK/$name" rev-parse HEAD)"
  committed_at="$(git -C "$WORK/$name" show -s --format=%cI HEAD)"

  mkdir -p "$STAGING/$name"
  (cd "$WORK/$name/$subdir" && find . -type f \( -name '*.yml' -o -name '*.yaml' \) ! -name '*.test.*' ! -path './.github/*' "${exclusions[@]}" -exec cp --parents {} "$STAGING/$name/" \;)
  cp "$WORK/$name"/LICENSE* "$STAGING/$name/" 2>/dev/null || true

  # One rule that this Semgrep version cannot read must not cost the whole source: the files that do not
  # validate are left out one by one and reported.
  if ! validate "$STAGING/$name"; then
    while IFS= read -r rule_file; do
      if ! validate "$rule_file"; then
        echo "   left out (does not validate): ${rule_file#"$STAGING/"}" >&2
        rm -f "$rule_file"
      fi
    done < <(find "$STAGING/$name" -type f \( -name '*.yml' -o -name '*.yaml' \))
  fi

  rule_files="$(find "$STAGING/$name" -type f \( -name '*.yml' -o -name '*.yaml' \) | wc -l)"
  if [ "$rule_files" -eq 0 ] || ! validate "$STAGING/$name"; then
    failed_sources=$((failed_sources + 1))
    echo "   no valid rules from this source (kept the previous copy if there is one)" >&2
    rm -rf "${STAGING:?}/$name"
    if [ -d "$UPSTREAM/$name" ]; then
      cp -a "$UPSTREAM/$name" "$STAGING/$name"
    fi
    continue
  fi

  echo "   $rule_files rule files, revision ${revision:0:7}, valid"

  entry="{\"name\":\"$name\",\"repository\":\"$repo\",\"revision\":\"$revision\",\"committed_at\":\"$committed_at\",\"license\":\"$license\",\"rule_files\":$rule_files}"
  sources_json="${sources_json:+$sources_json,}$entry"
done < "$HERE/rule-sources.txt"

# Swap: the previous copy is kept until the new one has been validated together with our own rules.
rm -rf "$PREVIOUS"
if [ -d "$UPSTREAM" ]; then mv "$UPSTREAM" "$PREVIOUS"; fi
mv "$STAGING" "$UPSTREAM"

if ! validate "$RULES"; then
  echo "The new rules do not validate together with ours; putting the previous copy back." >&2
  rm -rf "$UPSTREAM"
  if [ -d "$PREVIOUS" ]; then mv "$PREVIOUS" "$UPSTREAM"; fi
  exit 1
fi

semgrep_version="$("$SEMGREP" --version 2>/dev/null | head -1 | tr -d '\r')"
printf '{"updated_at":"%s","semgrep_version":"%s","sources":[%s]}\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$semgrep_version" "$sources_json" > "$VERSION_FILE.tmp"
mv "$VERSION_FILE.tmp" "$VERSION_FILE"

echo "Rules refreshed: $VERSION_FILE"
exit 0
