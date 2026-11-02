# N05 isolated native observations

`PhorgeGovernanceNativeObservationVerifier` is lazy and unregistered. Construction
requires the supported PHP runtime, CLI test opt-in, no selected deployment
environment and no local deployment configuration. It never initializes the
application environment, opens a database itself, grants a new content role or
registers a transaction executor. Every outcome has `can_execute=false`,
`risk_complete=false` and `full_expansion_verified=false`.

## Fixed local editor methods

The verifier invokes only these fixed native methods on newly constructed exact
final editor classes through Reflection:

- Project: `expandTransactions`, restricted to known mapped description, policy,
  membership, parent and milestone transaction shapes. Its current implementation
  iterates base local expansion and updates editor-local milestone/parent context.
- Task: **single-item** `expandTransaction`, only title, description and owner.
  A changed owner adds a native subscriber transaction for the old owner. Full
  `expandTransactions` is intentionally not invoked: it can claim a closing task,
  add author subscriptions and load project information for notifications.
- Wiki: **single-item** `expandTransaction`, only existing title/body/move-to.
  Empty existing body adds native deletion; move-to adds destination view/edit
  policies. Creation, restore/publish validation and final effects remain outside
  the supported closure. A destination must be the exact native document class.

Objects and transaction envelopes are exact native classes. Existing-object ID
is required, new transaction ID is required, and an existing objectPHID binding
must match. The private native `isNewObject` phase field is set only on the
throwaway editor to `false`, matching the existing-object phase without calling
its actual pipeline. This is test-context preparation, not a saved object or a
production preview. It does not prove the provided object was just loaded from
storage. Inputs are cloned and supported scalar containers are detached. Text must be a string; owner and project-parent
values are null or the correct PHID type; policy values are nonempty bounded
strings; member deltas require arrays. These are minimum input-shape gates, not
native value validation. No
arbitrary injected methods, serialization hooks or custom classes run.

There is no call to `applyTransactions`, preview, rollback-based safety, support
expansion, implicit CC, file attachment extraction, validation, Herald, mail or
final effects. Output lists only types, value digests and the relevant deletion/
no-effect flags, not raw private content. Unknown types or mixed unsupported
batches block before local invocation. The input/output traversal budget is
shared across the whole batch: 20 transactions, depth 16, 5000 nodes and 1 MiB
including keys and repeated input/output values; individual arrays have at most
100 entries. Exceeding this representative test scope blocks, not a claim that
native content itself has these production limits.

## Actual PolicyFilter versus automatic object claims

`observeCapabilities(viewer, object, capabilities)` separately calls actual
`PhabricatorPolicyFilter::hasCapability` and the actual object's
`hasAutomaticCapability`, for view/edit/join where supported. A recognized native
object with an ID and PHID is required so application checks are not skipped for
an anonymous template. This evaluates a **provided object**, not a freshly locked
native authorization read set. Missing attachments, unsupported environment or
other native errors are `unknown`/blocked, never fake deny or allow.

Omnipotent, anonymous, unapproved, disabled, mailing-list and system-agent viewers are refused
before automatic claims are treated as admission evidence. A task owner's
automatic claim may be true while PolicyFilter denies because of Space or
application constraints. An ordinary system administrator is not an omnipotent
object-policy bypass. Request caches and object attachments remain native
behavior; this verifier does not make cross-process cache or revocation promises.

The pure tests use in-memory native objects to observe these local native
methods. PolicyFilter positive/negative observations belong to the separately
owned synthetic native-schema fixture: its SELECT-only native rows, real
attachments and real Policy/Space queries provide stronger evidence, but still
not production freshness, complete transaction closure or writer-lock coverage.
The fixture may inject the native environment source stack in memory; it must
not bootstrap a deployment or substitute a stub PolicyFilter.


## Capability SQL context ownership

Capability observations additionally create a private
`PhorgeGovernanceNativeCapabilityContext`. It only accepts an exact native
Dictionary+Default stack whose effective routing and environment-cache values
agree. Cluster routing and the Gorge DB endpoint are refused. Namespace/host/
port/credentials are validated by the native source reader's real SELECT-only
grant and owned-marker check, not by a caller boolean. An inherited Lisk pool, namespace stack, object-forced namespace or connection,
and any process/transaction effect-isolation mode are refused without closing
other contexts or substituting virtual SQL for a real observation.

After all proofs succeed, the context temporarily isolates the entire native
request cache so old DatabaseRefs, Space or policy cache entries cannot escape
the checked routing. It closes only the native connections created after the
initial pool was proved empty, restores the prior request cache and mysqli
report mode, and rejects routing, scope-selector, object-connection or isolation-mode changes
before returning. Local expansion methods do not need
or create this SQL context. This remains an isolated observation facility, not a
general production factory or proof of locking through a business write.

## Batch-4 bounded expansion additions

`observeWikiBatchExpansion()` invokes the exact native Wiki batch prelude on
an existing document with explicitly attached native `PhrictionContent`. Its
stored content identity and document binding must match. The native uint32
version accepts a positive integer or canonical decimal string in 1–4294967295;
leading zeroes, signs, floats, booleans and overflow are refused. The public
version summary is normalized to an integer without mutating the input. The prelude records old content in the throwaway editor before the
native per-item expansion. Tests cover combined move-policy and empty-content
delete ordering. Returned context includes only version and identity digest;
attached body text is not exported or modified. Attachment consistency is not
proof that the provided objects are fresh database rows.

Task and Wiki view/edit policy items may now be observed through the exact
local per-item method. The inherited method passes these items through; that
observation neither validates policy semantics nor grants approval. Project
observations expose only milestone state and a digest of the editor-local
parent context. Parent/membership policy validation, support expansion and
Herald remain outside the observed closure.

Maniphest batch expansion is still unsupported: current native code reads
status/configuration and may load notification project state, with auto-claim
and creation paths requiring additional prerequisites. No full editor pipeline
or final-effects method is invoked. All observations continue to report
`can_execute=false`, `risk_complete=false`, `full_expansion_verified=false`.
