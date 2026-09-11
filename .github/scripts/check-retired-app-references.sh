#!/usr/bin/env bash
set -euo pipefail

# Runtime/source references to applications which have already been physically
# removed from this cleanup stack. Historical SQL autopatches are deliberately
# outside this check: they have their own compatibility rewrites and must stay
# runnable for upgrades from old installations.
roots=(src scripts bin webroot)

# Ignore generated library metadata: arc liberate is the authority for it and
# the companion workflow validates/regenerates it separately.
rg_args=(
  --line-number
  --no-heading
  --color never
  --hidden
  --glob '!src/__phutil_library_map__.php'
  --glob '!src/applications/retired/**'
)

patterns=(
  'PhabricatorPaste[A-Za-z0-9_]*|\bPaste[A-Z][A-Za-z0-9_]*'
  'PhabricatorDiviner[A-Za-z0-9_]*|\bDiviner[A-Z][A-Za-z0-9_]*'
  'PhabricatorOwners[A-Za-z0-9_]*|\bOwners[A-Z][A-Za-z0-9_]*'
  'PhabricatorAudit[A-Za-z0-9_]*|\bAudit[A-Z][A-Za-z0-9_]*'
  'PhabricatorDifferential[A-Za-z0-9_]*|\bDifferential[A-Z][A-Za-z0-9_]*'
  'PhabricatorHarbormaster[A-Za-z0-9_]*|\bHarbormaster[A-Z][A-Za-z0-9_]*'
  'PhabricatorDrydock[A-Za-z0-9_]*|\bDrydock[A-Z][A-Za-z0-9_]*'
)

failed=0
for pattern in "${patterns[@]}"; do
  if matches="$(rg "${rg_args[@]}" --pcre2 "$pattern" "${roots[@]}" 2>/dev/null)"; then
    echo "::error::Found runtime references to a physically removed application"
    printf '%s\n' "$matches"
    failed=1
  fi
done

if (( failed )); then
  cat >&2 <<'EOF'

Retired application references remain in live runtime/source paths.
Class-map metadata is checked separately by arc liberate. SQL autopatches are
also checked separately because old installations still need them to execute.
Remove or rewrite the live references, or (only for a deliberate historical
identity boundary) move the minimal compatibility class under
src/applications/retired/ and document why it must remain.
EOF
  exit 1
fi

echo 'No live references to physically removed applications remain.'
