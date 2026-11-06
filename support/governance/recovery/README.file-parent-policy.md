The File parent-policy consumer is a blocked endpoint candidate. It selects at
most 64 author-rooted Files and 256 observed attachment/transform rows using the
existing isolated owned User RR reader. It invokes actual viewer-aware
`ManiphestTaskQuery` and `PhabricatorFileQuery` with exact single PHIDs, a limit
of two, and policy exceptions disabled on `execute()` (not `executeOne()`).
Foreign, duplicate, stale and malformed results fail closed; native errors
propagate. A current locked account row and the supplied native viewer must both
be approved, enabled native human accounts with matching current flags. Anonymous and omnipotent viewers refuse
before policy queries. This account admission is separate from the File model's
author automatic capability, which alone does not reject disabled accounts.

`captureParentPolicies()` / `finishParentPolicies()` bind the actual selected
File topology, viewer admission, locked Task existence/policy/owner/Space rows,
and native visibility observations. A changed topology, invisible parent policy
or visibility result invalidates the captured review. The returned stable
`review_binding` is suitable for comparing these observations only. The random
private endpoint digest cannot be supplied by a caller. Instances are single
use and do not serialize. File and Task observations remain separate: a visible
File does not imply its Task parent was admitted (the File's author can still
have an independent native grant).

Unsupported parent providers, transforms, absent policy attachments, partial
files, profiles and builtins do not become parent-policy coverage. Task stands
for Task only. Deleted Files are denied. Native queries may consult Spaces,
custom policies or other dependencies; their internal dependency budget and
transaction identity have not been proved. In particular, Lisk caches
connections by database namespace; File and Task queries request their own read
connections. Their results are not the User writer's snapshot. Consequently
`same_transaction_snapshot_proved`, `durable_review_compatible`, `complete` and
`all_sources_complete` remain false, and `execution_blocked` remains true. This
consumer is deliberately not added to the current-writer v3 RestoreAssessment,
its 13 families, or a restoration permit. No optional flag is enabled.

The dedicated CLI phase is `--file-parent-policy`. It starts the existing owned
paired Gorge File service before preparing real users/avatars/Task/File objects,
then calls all six native cases through the focused entry. A selected connection
witness reads actual User/File/Task connection identities and routes, requires
three distinct connections on the fixture server, and reports only booleans.
It does not force connections together or infer a shared snapshot. The observer
must close and disappear before a successful native summary can be emitted.

The independent checker derives six case obligations from exact pinned source
bytes and combines the actual six bootstrap, one File readiness and three cleanup
obligations: **16 checks**, not a SQL statement count. File39 and partial31 stay
unchanged. The native summary binds eleven selected producer/consumer source files;
the checker independently captures those bytes through bounded stable FDs and
rejects changed sources. It also requires an external executable File binary and
SHA256, one owned startup, exact process/private-storage cleanup, source revision,
and all blocker flags. A receipt cannot certify its own binary hash or shorten
the family/blocker set.

A Root-owned fresh signed-source/cold-mapper execution with the existing private
fixture environment may invoke:

```sh
php support/governance/native-organization/test-native-organization-mysql.php --file-parent-policy > "$OWNED_OUTPUT/integration.json"
python3 -B scripts/governance/check_recovery_file_parent_policy.py \
  --receipt "$OWNED_OUTPUT/integration.json" --source-revision "$SIGNED_SOURCE_HEAD" \
  --file-binary "$PAIRED_FILE_BINARY" --file-binary-sha256 "$PAIRED_FILE_SHA256"
```

The caller must separately bind `PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION`,
`PHORGE_GOVERNANCE_GORGE_FILE_BINARY`, `PHORGE_GOVERNANCE_GORGE_FILE_SHA256` and the
existing isolated disposable fixture environment. Output must be an exclusively
owned private file. No invocation, mapper execution or passing receipt is shipped
with this candidate. Host tests exercise actual selector/validator/query-wrapper
code with explicit collaborators and tampered synthetic receipts. They are not
SQL, real File service, physical transaction or mapper evidence. The six native
cases and strict sixteen-check profile are **UNRUN**. Even a future accepted
endpoint receipt cannot make this consumer durable-review compatible, complete
any of the thirteen families or authorize restoration.

The v2 profile binds the current locked SQL `isAdmin` flag as well as approved,
disabled, system-agent and mailing-list flags. Native viewer getters must match
those current flags. A stale admin object is rejected before policy queries;
the native GlobalPolicy admin rule remains unchanged. The account-admission
case group retains disabled/anonymous refusal and now includes a real admin
positive, isolated same-writer SQL demotion with rollback, and a binding drift
case whose native user visibility stays admitted. These SQL cases remain UNRUN.
The v1 sixteen-check receipt, a missing `isAdmin` source/descriptor, unknown
fields and caller-supplied replacement source hashes are rejected by v2.

Native User boolean getters normalize noncanonical stored values to booleans. The selected consumer therefore checks the exact native protected flag value, its getter result and the current locked SQL value together. The source receipt also pins the actual native User implementation. The late-malformed-viewer regression retains SQL isAdmin=1 and changes only the captured viewer object; native GlobalPolicy remains unchanged and truthy, while this bounded review refuses the noncanonical value.
