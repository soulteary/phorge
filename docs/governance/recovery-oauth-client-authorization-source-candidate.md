# Native OAuth client-authorization source candidate

This isolated increment binds actual `PhabricatorOAuthClientAuthorization` INSERT
and UPDATE calls to the recovery producer fence and consumes their real rows in
Store Assessment. It is default OFF and does not enable OAuth Server. It is not a
complete API-credential family, restore provider, permit or deployment profile.

`PhabricatorOAuthServer::authorizeClient()` still calls the real DAO. Existing
`PhabricatorOAuthServerAuthController::handleRequest()` still uses
`setScope(...)->save()` for an existing authorization. The DAO overrides its
protected INSERT dispatch and public `update()` (Lisk has no protected UPDATE
record hook). OFF delegates to the original parent; a quarantined producer is
still refused. REPLACE, forced connections and existing outer transactions are
unsupported ON. UPDATE checks the persisted PRIMARY row's owner, client, PHID and
creation timestamp before the native body, so changing a staged owner/client
cannot acquire only a new owner's lock and then move an old authorization.

The current native `PhabricatorOAuthServerScope::getScopeMap()` is empty. This
package does not add any native scope or change the filter. Actual factory/DAO
host controls use the native empty map. A synthetic persisted map change in the
Store collaborator is only a source-drift negative, not a real new scope grant.

The User writer locks the subject PRIMARY before the distinct OAuth writer begins.
Each source acquires MDL before checking InnoDB. Two measured connection IDs and
same-primary identity are rechecked around the native body and terminal methods.
Borrowed reads use the real current User owner and exact OAuth route, bounded
subject rows and scope decoding. Thread freshness is kept in the live participant;
connection IDs do not enter the durable semantic readset (a new owner may have
new threads with unchanged source rows). Owner-end recapture rejects row or thread
drift. Surfaced transport uncertainty retains the owner and preserves its primary
exception; there is no retry, cleanup or rollback authorization for unknown state.
This is not proof against physical ACK loss or native driver's retry behavior.

Assessment schema 6 adds `oauth-authorization` to readset schema 3. Historical
schemas 4/readset1 and 5/readset2 remain separate and strict. Current Store capture
and subsequent source comparison consume five selected credential sources.
`requireCompleteForExecution()` still refuses incomplete families and there is no
production observer/execution factory. No native EDIT, VIEW, JOIN or Space axis is
changed.

## Remaining blocking dependencies

`PhabricatorOAuthServerClient::destroyObjectPermanently()` starts its own OAuth
transaction and deletes the client, authorization rows, tokens and codes. That
DELETE lifecycle is not covered by this INSERT/UPDATE fence. Client secret,
disabled/trust/redirect changes and other client writers are also unresolved
associated sources. They block any complete OAuth authorization family/profile;
they are not claimed safe merely because deletion often reduces access. Raw
writers, all other recovery families and actual restore-lineage execution remain
outside this increment. Do not infer a completion percentage from selected sources.

The six changed business files invalidate current raw source pins. Historical
51-source SQL receipts and the prior 97-check media run are not evidence for this
candidate. A follow-up exact versioned inverse/source coordination and a newly
reviewed current protected-media baseline and real SQL run are required. No old
literal map is refreshed here. Class/path/inheritance graph is unchanged and no
library map has been edited or claimed rerun.

## Local host controls

From the worktree, with an existing Python and PHP runtime:

```
PYTHONPATH=tests/governance PYTHONDONTWRITEBYTECODE=1 python3 -B -m unittest test_recovery_oauth_authorization_source -v
```

The native final DAO and factory OFF path use Lisk's native process-isolated
connection; no database is contacted. The ON core and real Store/Assessment/
participant methods use explicitly named connection, query, identity and profile
collaborators. Network/connect functions are disabled in these PHP children.
These controls do not assert physical isolation, MySQL compatibility, HTTP, CSRF,
File service or production runtime support.

## Next real SQL fixture integration (not executed or yet implemented)

Create a new literal phase and versioned consumer rather than relabeling the
existing authorization-code SQL8 receipt. Reuse the reviewed native fixture's
fresh User/OAuth databases, current owner/observer and one genuine paired File
service. Call actual `authorizeClient([])` for INSERT, then the actual DAO save
path used by AuthController for existing-row UPDATE, and real Store durable
submit/preview/review capture. Observe both physical IDs independently and the
User PRIMARY wait before OAuth BEGIN; verify MDL/engine/same-primary and both
terminal absences. Known commit and rollback cases must show stored row/readset
behavior; mutate a persisted authorization under a subsequent owner and reject
an old durable readset. Include real old-owner/client mismatch, row/byte budgets
and strict owned cleanup receipts. No generic arbitrary PHP/SQL dispatcher,
production factory, optional-app activation or physical fault claim is admitted.

## Current-main SQL source successor

On main0436 the fixed entry is now
`support/governance/native-organization/test-oauth-authorization-owner-mysql.php
--oauth-authorization-producer-owner`. It is source-only and has not run SQL.
It reuses the reviewed fresh native fixture and genuine paired File startup,
with its own worker registry and immutable action schema. Four experiments are
INSERT/UPDATE crossed with owner COMMIT/ROLLBACK; their sixteen obligations are
separate from four durable Store obligations (twenty SQL obligations total).
A strict current raw-source consumer is
`scripts/governance/check_recovery_oauth_authorization_producer_v1.py`; its fifty-six
source pins and thirty receipt checks are new, not a relabeled SQL8 receipt.

Each producer's actual subject ID/PHID, client PHID and authorization ID are
verified against the persisted native row before only digests are reported.
The owner changes a fixed synthetic subject display-name probe in its own
transaction: known COMMIT must persist that probe and known ROLLBACK must retain
the old value. The probe is restored only on the known-success path. It is test
data, not a substitute for the actual native credential factory/save.
UPDATE retains empty native scope, uses a strict bounded next-timestamp deadline,
and requires observed old/new native dateModified advancement; no sleep return
is itself accepted as source change. Unknown owner/child state does not authorize
TERM, KILL, pool rollback/close, directory removal or fixture disposal. Failed
and nonzero-child results cannot pass even if result JSON claims commitment.

The current-main source/cohort and protected media rebind are still pending in
this intermediate source review package. Raw current admission must refuse until
that separately reviewed coordination and actual SQL qualification exist.
