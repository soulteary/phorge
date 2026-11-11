# Q2 Project scope owner lifecycle — isolated source successor

This successor preserves the old characterization package and its unsafe result.
It changes Project Scope bookkeeping and adds a read-only native transaction
generation counter; it adds no Edit grant, policy change,
public entry, generic Editor hook, producer rule allowance or runtime admission.
It is not a physical transaction lease, SQL qualification or postcommit atomicity
proof. All queries in the new test harness are explicit non-SQL observations.

## Release conditions

The existing factory still refuses inherited transactions/locks and an existing
active reservation. It now reserves the same connection before BEGIN, marking
starting/owned/unknown explicitly. A known native `didCommit` or successful
service commit retires transaction ownership but retains the exact reservation
until close. Native commit acknowledgement and local transaction state remain
different facts; no new network-ACK observer has been added.

Close uses the exact active WeakReference owner. An owned depth-one transaction
with at most one owned read/write mode can be rolled back once, only if its
actual native state object and generation still match the frame captured after
BEGIN. Native state objects are reused, so object identity alone is insufficient.
The monotonic generation increments only when native depth increases from zero
to one; nesting and failed START do not increment it. Counter exhaustion refuses
the transition. It observes native bookkeeping, not raw SQL, implicit DDL commits,
reconnects or a physical-session lease. No unknown stack
is while-unwound. After known commit, later transaction/lock state is untouched.
Registry and membership-editor entries are removed only for their exact owner.
A forced later owner is never unregistered or given old cache values.

Temporary cache entries are restored after known successful owner cleanup only
while the reservation belongs to this Scope and the global request-cache object
is still the captured object. Normal success restores prior permission-cache
values. A changed request-cache object is untouched and produces cleanup
quarantine rather than a false clean success. This does not claim detection of
arbitrary code replacing a cache value with an identical value (ABA), nor a
cross-process lease. Other Project scopes remain excluded by the reservation.

The producer fence has its own private SELECT-only connection and never exposes
it as a writer. Scope releases only that private fence. Fence/cleanup failure
remains separate and PHP preserves a pending primary exception as `previous`;
the caller must not report the content rollback merely because cleanup failed.

## Deliberate unknown disposition

COMMIT/ROLLBACK query exceptions, unexpected nesting, depth zero without an
observed `didCommit`, failed fence/cache cleanup or changed cache identity retain
process-local quarantine. Repeated close does not retry SQL or upgrade cleanup.
The reservation key remains even when its WeakReference expires, blocking reuse
of that connection object in this process. It is not durable quarantine across
requests, process exit, reconnects or object-ID reuse.

The generic native Editor rolls back through Lisk directly and supplies no ACK
receipt to Scope. Even if that external rollback actually succeeded, Scope cannot
know it from depth zero; this successor conservatively refuses reuse. Successful
rollback availability and verified connection disposal need real SQL evidence or
a separately reviewed observation design. No profiler/config heuristic or new
generic transaction observer is introduced here.

## What the tests establish

`native-no-db.php` calls actual native transaction stack, Scope `didCommit` and
`close` methods using a fixed query observer and reflected, unadmitted Scope
bookkeeping. It verifies normal rollback/commit/cache cleanup, idempotent close,
later transaction/mode preservation, later registry/cache ownership, changed
cache object, nesting, unknown commit/rollback observations and private-fence
failure with primary exception preservation. Fixture-only cleanup then disposes
its own observer stack; that is not production Scope cleanup or a database ACK.

A genuine `ProducerFence::acquire` call rejects the unadmitted observer writer
before SQL. Service-mode owner, generation, depth and locking mismatches are
refused before physical save; `assertOpen` requires this exact reservation owner.
Actual native state tests cover nesting, reopen and START/COMMIT exceptions;
COMMIT exceptions never certify success. The harness does **not** exercise the actual database rule-count check.
The current supported producer profile requires zero Herald rules. A real
nonzero-rule fixture must be refused as `producers_unsupported`; the old Q2 plan's
proposal to execute a Herald rule within this profile is inapplicable. Keep the
fence unchanged. Positive zero-rule and negative nonzero-rule tests require the
owned MySQL profile, not a mock authority or a new callback.

## Gates before adoption

- Independent review of this exact source window.
- A new real native target, standard mapper, exact inventory/recipe/media source
  bindings and signed-source checks. Existing old-target guards should reject.
- Actual MySQL depth-one rollback and normal commit/cache disposal, plus foreign
  transaction identity/locks observed independently on the same physical session.
- Actual ACK loss/rejection and read-only durable reconciliation; no automatic
  content retry and no inference of absence of writes from a local exception.
- Real zero-rule producer fence admission and nonzero-rule refusal, exact private
  fence cleanup, and normal rollback availability/process disposal assessment.
- Separate postcommit cache, Herald (unsupported-profile refusal first), index,
  publication/child task, worker/delivery and crash outcomes. Persisted intent is
  not delivery, and already committed content cannot be globally rolled back.

Four permission axes and optional-feature defaults remain native/OFF. No SQL,
services, signing, pushing or deployment occurs in this source package.
