# Native raw ORM recipient ABA diagnostic candidate

This independent fixture successor leaves `23b138b7803f046d0e5a6a04c59d3f45bc905197`
production code unchanged. Use only the existing owned native runner's explicit
`--notice-raw-orm-aba` phase under a separately assigned isolated runtime slot.
The normal 933-check profile is unchanged; this diagnostic cannot satisfy it.

The test enables notifications while explicitly disabling account recovery and
the stop ledger. A real native administrator/session/CSRF and OrganizationService
produce an actual audit/outbox event. The actual NoticeWorker creates the
persistent recipient binding. The baseline authorization must succeed.

The test then reloads PhabricatorUser and calls native `setIsDisabled(1)->save()`
and a separate `setIsDisabled(0)->save()`. It proves both committed states. There
is no UserTransactionEditor, fabricated USER transaction, manual epoch bump,
accountSecret rotation, intermediate notification authorization or snapshot
quarantine. A newly loaded native mail is authorized through the real fresh
request-cache/SQL policy again. Native USER and legacy generation changes are
observed, not invented as a replacement authority.

The required negative assertion is that this OLD binding is refused. If the
suspected raw-ORM gap exists, the assertion intentionally fails. A bounded
failure breadcrumb contains booleans/counters only; no actor, credential, row,
address or raw source text. A true after-ABA authorization is a bug witness,
not a passing negative. No SMTP, global raw-writer closure or product fix is
claimed by this fixture. The existing UserEditor ABA evidence remains a
separate profile. Actual runtime is still required.

The queue/file services and owned native schema/principals follow the existing
fixture cleanup. This new fixture changes no authorization/restore semantics
and does not add a native account hook. Any necessary monotonic tracking or
configuration dependency is a separate production change requiring review.

Source boundary: `PhabricatorUser::update()` is the actual native DAO UPDATE
entry. With account recovery off it delegates directly to Lisk `parent::update()`;
the ordinary `PhabricatorUserDisableTransaction::applyInternalEffects()` also
sets this field, but its UserEditor creates USER transactions. This fixture
intentionally calls the public native DAO path directly rather than claiming a
second existing UI producer uses it. `NoticeMailPolicy::accountDigest()` binds
security facts and latest eligible security USER transaction/legacy epoch; it
does not manufacture a durable raw-DAO generation. These are source-level
observations, not a confirmed runtime ABA finding or authority to modify hooks.
