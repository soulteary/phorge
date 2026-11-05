# Application configuration facts — isolated candidate

This component is not wired into production recovery and is invoked only by tests.
It is a necessary selected configuration
source-dictionary read-set collector, not completion of the application-config family or a restore
permit. It adds no route, enable action, deployment migration or feature activation.

It reflects the actual Env ordered stack and exact native source objects already
in memory. Dictionary, Default, Local and Database sources must use exact native
Dictionary backing. FileSource executes PHP and is refused before touching it;
unknown sources/proxies and object (including Closure), resource and float values
are refused. Primitive strings/arrays that could name callables remain data: they
are never executed or certified as trusted dependencies. No is_callable check is
used, so this collector does not trigger autoload to classify such data.
No new provider/extension is constructed, and no source getter is invoked. Native
LocalSource reads JSON, not PHP; its cached dictionary is not a current file proof.
DatabaseSource cached values are not a current SQL row read or absence proof.
DefaultSource may already contain external options; inspecting it is not evidence
that those option providers were trusted or that their dependencies are complete.

The fixed ten selected dependencies cover native approval/email/MFA requirements,
account editing, minimum password length, transport and recovery configuration.
Every supplied value is bounded and privately bound, including unselected keys;
all selected keys must exist with exact types. These ten are not an exhaustive
policy/config dependency closure. Dynamic/external provider dependencies remain
unsupported and block family completion; no caller can add options or set complete.

First source wins, including explicit NULL. Missing differs from NULL; NULL differs
from empty. Stack order, actual object identities and typed private values are
bound with a fresh in-memory HMAC key. Source objects remain referenced so object
IDs cannot be recycled. No value, key, raw file path, or binding digest is returned.
Public output contains fixed scope/reason, source/selected-option counts and false
completion/authority fields. Debug and serialization do not reveal private binding.
Any finish failure is terminal, and success is single-use. Ordinary native config
behavior remains unchanged.

Two equal observations do not prove no intervening mutation/ABA, grant protection,
loaded-code provenance, opcache consistency, deployment immutability or producer
coverage. The collector takes no lock. Adoption remains blocked until trusted
runtime/source-body provenance and each actual config producer/absence lease are
implemented. It is deliberately not wired into RestoreAssessment yet.

Host controls require the real native classes directly, avoiding the normal
bootstrap's PHP-version check and arbitrary provider loading. Only DefaultSource
constructor is bypassed to install an explicitly synthetic dictionary; this is not
proof of real defaults/provider loading. Tests cover precedence, NULL/empty,
missing/type refusal, unknown getter-not-called, dynamic PHP refusal, object
replacement and terminal failures. Supported PHP 8.5.12 native map/unit execution and SQL execution remain pending;
no map is hand-edited. The successor adds explicit static partial N00/B2 entries.

## Next executable isolated fixture

Use fresh owned fixture configuration before flags or DAO connections. Bootstrap
all synthetic principals and config rows using existing reviewed migrations, not
hand-made DDL. Preserve and restore the exact original Env stack/cache in finally.
Register cleanup before any owned row/principal mutation and persist the private
external owner lease before CREATE. Keep diagnostics fixed enums without values.

1. On actual supported bootstrap, record actual ordered native source classes and
   selected typed results privately; compare native precedence through known native
   dictionary methods in tests only. Refuse unexpected stack/classes/dependencies.
2. Actual ConfigEntry/ConfigEditor commit and rollback changes: a newly constructed
   native DatabaseSource sees committed values while this collector must refuse
   stale/replaced or changed in-memory inputs. Do not call cached equality SQL proof.
3. Add/delete/NULL/empty/type-invalid config entries and missing selected defaults;
   exact known errors, not catch-all denial. Unknown provider classes are never run.
4. Two real writers change/insert/delete selected config rows while an eventual
   source lease is held. Current RC observations alone must not pass absence;
   same-writer atomic row/range and metadata/grant gates are separate dependencies.
5. Test file replacement, environment override and source/provider change; lack of
   an independently trusted immutable deployment profile must continue to block.
6. On every fault close owned sessions, prove exact session/transaction termination
   independently, clean owned rows/principals/directories, and retain primary and
   cleanup errors separately. Never widen privileges or infer owner from a name.

These are pending real SQL obligations, not executed results or a new permit.


## Integration successor (execution pending)

The candidate now registers one native pure method (262 total/44 classes derived
from the 261/43 base) reusing 13 real-class synthetic-dictionary controls. This is
registration, not supported PHP execution. No generated map is hand-edited.
The normal 934 SQL checks are preserved; this focused profile is separate.

Actual Env::buildConfigurationSourceStack may install the final persistent
PhabricatorDeploymentConfigSource after local/database sources. This source is
explicitly refused today, not silently omitted. Future support must bind its actual
backing and private provenance/owner/immutable trust, not simply allow its class.

Env::cache is separate mutable state: native getEnvConfig can return a cached value
different from the source dictionaries. The collector neither reads that cache nor
calls getEnvConfig. Its summary says native_Env_cache_bound=false and selected
source dictionary facts only. Before any recovery wiring, exact private cache facts
and invalidation/lifecycle handling are mandatory. This is not an effective-config
attestation, and mutable process config cannot provide a continuous or ABA lease.

The new --application-config-facts entry uses an explicitly fixture-owned namespace
in the existing config schema. It creates real ConfigEntry rows with native DAO
hooks, constructs actual ConfigDatabaseSource instances, and tests committed,
rolled-back, deleted, NULL and stale cached-dictionary facts. An independent setup
connection proves committed row values on the same primary; it is a read observer,
not the runtime writer. All rows remain inside the disposable schema. The existing
outer driver owns guard/env/cache cleanup, principal/schema cleanup and receipt.
Supported map/pure and this SQL profile are unexecuted until Root leases them.


Focused invocation after a supported native map build and the ordinary reviewed
isolated fixture environment is established:

```sh
php support/governance/native-organization/test-native-organization-mysql.php --application-config-facts > config-facts.json
python3 -B scripts/governance/check_recovery_application_config.py --receipt config-facts.json --source-revision "$PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION"
```

The exact intended 19 checks are six bootstrap, ten focused SQL and three cleanup.
There are zero child workers and no paired file daemon in this profile. Normal934
is a different profile. A separate cold runtime helper, source signature, supported
map registration and Root lease are still required before running this candidate.
The fixture covers native ConfigEntry DAO hooks, not ConfigEditor transaction
producers or every deployment/source provider. No counts in this document are new
SQL or supported-PHP test results.
