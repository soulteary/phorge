# Prepared media targets and bounded maintenance candidate

This slice adds no public route, cookie writer, file authority, notification behavior or deployment activation. The backend remains disabled by default. It follows the independently reviewed external-issuer prerequisite.

## Target binding

Preparation requires an immutable target parsed against the configured native instance, a real native request transport address and a 32-byte client nonce. Data, forced download and transform routes have distinct canonical representations. The native file PHID, capability key, transform, instance and canonical UTF-8 decorative filename are retained as structured fields. No arbitrary host, query, fragment or tokenized alternate route is admitted. Noncanonical or ambiguous encodings fail closed. These syntactic checks are not file authorization.

The preparation row stores canonical target JSON, its digest, and a purpose-separated HMAC of the client nonce. Issuance and redemption must match the prepared target and nonce exactly. Legacy rows without these bindings cannot issue or redeem. Private fixture helpers explicitly supply all bindings; no runtime synthetic default target is provided.

## Admission and cleanup

Preparation uses persistent transactional fixed-window counters: 600 global attempts, 120 per native transport peer address, and 6 per browser per 60 seconds. A fixed capacity mutex shared across issuer incarnations is locked before counters and browser/exchange rows. Both preparation and redemption use it when adding ledger rows. The counts include all rows about to be added, including new browser buckets, browser slots, exchanges and redeemed credentials. Rejected admissions roll back their quota and ledger writes. Client addresses come from the native request REMOTE_ADDR source, not an unverified forwarded header. Shared proxy/NAT behavior still requires actual gateway review before HTTP exposure; these are candidate safety bounds, not a production availability promise.

Only one unissued preparation per browser is retained. Issued codes and consumed rows are not overwritten by this pruning. A separate total ledger capacity of 100,000 rows fails closed until bounded maintenance frees space. Each media transaction uses a next-transaction-only READ COMMITTED view so counts after mutex waits see preceding admissions; the pooled connection SESSION isolation and the next ordinary native transaction remain unchanged. Capacity counts include expired rows so stale data cannot evade the bound. No native session, account, API token or account epoch is pruned.

Explicit maintenance deletes at most 500 expired rows per table per invocation through expiration indexes, in one owned transaction. Smaller requested bounds remain meaningful. It is not an autonomous scheduler and does not promise physical erasure precisely at TTL. Cleanup failures roll back; old incarnations are never promoted. Restore rules still require externally held issuer configuration and all serving-worker reloads.

## Evidence status

The original target checkpoint passed 138 pure PHP methods and 209 real MySQL/Gorge checks and is preserved under `evidence/media-targets-checkpoint-1ab1305`. After explicit integration with current main, a new full run passed 142 pure methods and 245 actual MySQL/Gorge checks; its artifacts are under `evidence/media-targets-combined-20261103`. Nine independent fixed-action workers were registered and cleaned; 55 owned schemas, two runtime users and the reader were removed without failures. The strict receipt checker also checks the actual paired file binary digest.

Actual SQL covers schema comparison, Unicode download binding, target/nonce mismatches, legacy unbound API refusal, browser/client/global quotas, two independent preparers contending for the last browser slot, two new-browser preparers contending at 99,997 total rows (exactly one fits at 100,000), full-capacity redemption rollback followed by successful redemption after freeing capacity, DB-clock window transition/regression, and a later-table DELETE trigger fault undoing earlier cleanup deletions. A following ordinary native transaction retained real RR snapshot semantics. The old two-worker code-redemption test now waits on the actual capacity mutex and still proves one commit and one rejection.

The 245-check receipt is for its historical combined directory/issuer/target source snapshot. It supersedes the original 209-check checkpoint for that combined source; future source changes still require their own validation. Public HTTP routes, cookies, file authorization/cache/active-content guards, notification producers, browser behavior and deployment remain unproved and disabled. No native permission axes or native session/API lifecycle semantics were changed.


## Pending HTTP and current-main combination

The HTTP source checkpoint `28aa3bde87` is now explicitly merged with main
`6c12461b15`. Neither the historical 144-method/273-check component and 24 TLS
checks, the current HTTP-only 146-method run, nor main's 147-method/297-check
receipt certifies this combined source. The generic current receipt is deliberately
pending and rejected by the strict gate until a fresh full run supplies actual
scenario IDs, summaries, cleanup, paired-service input, and source hashes.

The proposed gate pins every main scenario plus 66 explicit current HTTP/file/
image obligations, including the persisted long Unicode native file URI and four
bootstrap/cookie-firewall checks. This is an expected literal scenario set, not an
actual passing count. The static catalog contains 50 explicit sources, 40 links,
and 55 reviewed runtime files; it remains partial, non-authoritative and activation
blocked. No unknown source is accepted by a union fallback.

Current candidate scope includes fixed-origin native exchange controllers,
wire-cookie isolation before native session bootstrap, actual viewer file policy,
protected cache/range/HEAD behavior, guarded derived files, active-format download
responses, fixed default favicon sources, paired image generation and session-bound
page bootstrap. Full wire/browser verification, custom branding, complete guard
coverage, notification integration, production rollout, and optional feature
activation remain separate unresolved gates. Native permission semantics remain
unchanged.
