#!/usr/bin/env bash
set -euo pipefail

# Fail when live code still references a class or function this cleanup series
# physically removed.
#
# The removed set is computed rather than described: every symbol the Phutil
# library map declared before the series started, minus every symbol it
# declares now. Matching by application name instead would flag the things the
# series deliberately kept -- the diff engine under src/applications/differential,
# the commit review layer relocated into Diffusion, the Lisk test fixtures --
# all of which still exist and still resolve. Only a name with no class behind
# it is a defect.

readonly BASELINE="${RETIRED_BASELINE:-9130b684cc0d49711cff65b8590634d1d1a84f3c}"
readonly MAP='src/__phutil_library_map__.php'
readonly EXCEPTIONS='.github/data/retired-reference-exceptions.txt'

command -v rg >/dev/null 2>&1 || {
  echo 'ripgrep (rg) is required for retired application reference checks.' >&2
  exit 127
}

command -v php >/dev/null 2>&1 || {
  echo 'php is required to read the Phutil library map.' >&2
  exit 127
}

git cat-file -e "$BASELINE^{commit}" 2>/dev/null || {
  echo "Baseline commit $BASELINE is not available." >&2
  echo 'Check out with fetch-depth: 0 so the pre-cleanup map can be read.' >&2
  exit 127
}

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

git show "$BASELINE:$MAP" > "$work/baseline-map.php"

php .github/scripts/print-library-symbols.php "$work/baseline-map.php" \
  | sort -u > "$work/baseline.txt"
php .github/scripts/print-library-symbols.php "$MAP" \
  | sort -u > "$work/current.txt"

comm -23 "$work/baseline.txt" "$work/current.txt" > "$work/removed.txt"

removed_count="$(wc -l < "$work/removed.txt" | tr -d ' ')"
if [[ "$removed_count" -eq 0 ]]; then
  echo 'No symbols have been removed since the baseline; nothing to check.'
  exit 0
fi

# Word boundaries so "DifferentialDiff" does not match "DifferentialDiffQuery".
sed 's/^/\\b/; s/$/\\b/' "$work/removed.txt" > "$work/patterns.txt"

# Documentation is excluded deliberately: src/docs is prose and Diviner book
# configuration rather than runtime code, and webroot/rsrc/externals is
# third-party, which .arcconfig also excludes from linting. The library map
# itself is generated, and arc liberate is the authority for it.
rg_status=0
rg --line-number --no-heading --color never --hidden --only-matching \
  --glob "!$MAP" \
  --glob '!src/docs/**' \
  --glob '!webroot/rsrc/externals/**' \
  --glob '!src/applications/retired/**' \
  -f "$work/patterns.txt" \
  src scripts bin webroot > "$work/matches.txt" || rg_status=$?

case "$rg_status" in
  0) ;;
  1)
    echo "No live references to the $removed_count removed symbol(s) remain."
    exit 0
    ;;
  *)
    echo 'ripgrep failed while scanning for removed symbols.' >&2
    exit "$rg_status"
    ;;
esac

: > "$work/report.txt"

while IFS=: read -r path line symbol; do
  [[ -n "${symbol:-}" ]] || continue

  # A comment which records what was removed is documentation, not a reference.
  text="$(sed -n "${line}p" "$path")"
  trimmed="${text#"${text%%[![:space:]]*}"}"
  case "$trimmed" in
    '//'*|'*'*|'/*'*|'#'*) continue ;;
  esac

  if [[ -f "$EXCEPTIONS" ]] && grep -qxF "$path:$symbol" "$EXCEPTIONS"; then
    continue
  fi

  printf '%s:%s: %s\n' "$path" "$line" "$symbol" >> "$work/report.txt"
done < "$work/matches.txt"

if [[ ! -s "$work/report.txt" ]]; then
  echo "No live references to the $removed_count removed symbol(s) remain."
  exit 0
fi

echo '::error::Found live references to physically removed symbols'
cat "$work/report.txt"

cat >&2 <<EOF

Each line names a symbol which the library map no longer declares, so the
reference cannot resolve at runtime. Remove or rewrite it.

If the reference is deliberate -- a stored string which happens to look like a
class name, for instance -- write the reason at the reference itself and add
"<path>:<symbol>" to $EXCEPTIONS.
EOF

exit 1
