# Isolated native transaction capability map (N05)

`PhorgeGovernanceCapabilityMap` is an unregistered, side-effect-free classifier.
It is not a transaction adapter, policy rule, edit engine, route, grant or target
executor. All results retain `can_execute=false`, `coverage=partial`,
`activation_blocked=true` and `risk_complete=false`. New content capability is not
installed. The four native axes and existing native edit eligibility remain
unchanged. High-risk review classification does not itself approve anything.

## Input and native bindings

`classify(object_class, transaction_class, transaction_type, metadata)` takes an
exact native object class, its transaction **envelope** class, a real native type
constant and exact recognized metadata. For example, both task and Wiki titles
have literal `title` types, but different objects, envelopes and modular handler
classes. A wrong envelope or a handler class supplied as an envelope is blocked.
A future trusted adapter must extract these inputs from the actual object and
transaction. Client-provided class/type labels are not authenticated evidence.

Project description is specifically `core:customfield` with
`customfield:key=std:project:internal:description`. The test builds the real
builtin description field and compares its actual key; no source-text grep is
used as permission proof. Unreviewed custom fields are not content.

Known task title/description and Wiki title/content bind native modular types.
Wiki content can expand into deletion when emptied, and title/content may create,
publish or restore a document. Thus both Wiki candidates remain conservatively
high-risk until a value/creation/expanded-transaction adapter is verified. A label
like "edit body" is not sufficient to prove a harmless change. Other content
candidates' low risk is structural only, not a complete risk determination.

View/edit policies, project join policy, Space, project-member edge, known
hierarchy/owner/column/move and associated-project relationships are conservatively
sensitive/high-risk. Relationship review is not a claim that adding a project tag
changes native ACL. Actual self join/leave exceptions, inverse edge semantics and
automatic capabilities still need an actor/diff adapter. Unknown edge types and
wrong integer/string metadata are blocked. The historical `project:members` type
is recognized as sensitive but explicitly blocked as a current-write mapping.
Task status, comments, arbitrary workboard operations, unreviewed custom fields
and every other unlisted type remain unsupported. This is partial coverage.

`getDefinitions()` exposes fixed mapping IDs, type/handler/field bindings,
candidate consumer IDs, native capability requirements and source kinds. Native
constants and real class definitions are tested with the native framework.
Consumer IDs link to N00; their presence does not certify every route's runtime
behavior. Wiki body candidates deliberately exclude the newer comment-oriented
Web engine and modern edit API as evidence of body-edit coverage. Legacy body
Web/API and their transaction editor are separately referenced.

## Batches and source requirements

`classifyBatch(object_class, transactions)` requires a nonempty sequential list
of at most 100 descriptors. Each descriptor has exactly `transaction_class`,
`transaction_type` and `metadata`; adding a caller risk field is rejected.
Unknown, malformed, mixed-object or historical-only entries block the whole
batch. A sensitive/high-risk entry cannot be diluted by a content entry or
ordering. This does not implement cross-object transactions, object identity
binding, partial writes or rollback: nothing is executed.

`getKnownOperations(object_class)` and
`getRequiredSourceKinds(operation, object_class)` define compatible operation
and object families. Invalid combinations throw rather than yielding an empty
coverage set. Common dependencies are account, application, session, Space, named
policy, organization membership/appointment and project member/parent sources.
The latter are needed even for task/Wiki objects whose policies can refer to
project groups. Project families additionally require project policy, task
families require task policy/owner, Wiki families require policy/parent. A trusted adapter must prove
source enumeration or verified absence; a caller Boolean cannot remove a kind.
N07 binds each actor role and target to the appropriate snapshots. These kinds
are a partial contract, not live source adapters or locking evidence.

The existing `PhorgeGovernanceContentDecision` remains a separate pure contract.
This map does not pass its structural risk off as `risk_verified`, does not set
coverage proofs, and does not invoke that contract to grant execution. A future
integration must validate full values, expansion, native view/Space/application,
automatic capability effects and current source snapshots before producing
trusted inputs. Unknown state stays blocked.

## Verification scope

Native tests exercise constant/class bindings, the actual builtin field builder,
object/type collisions, policies, exact member-edge metadata, historical types,
known relationships, unknowns, bounded/mixed batches and conservative worst risk.
They do not load deployment configuration or connect to SQL. Source mutation
checks or reference matching are not substitutes for permission behavior tests.
All current product entrypoints and real actor qualification remain unregistered
and untested by this slice; new grants, production migration and feature
activation are outside its scope.
