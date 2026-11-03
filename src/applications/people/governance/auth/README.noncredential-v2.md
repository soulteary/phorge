# Noncredential native identity source v2 candidate

This is a read-only fact source for transfer subjects and responsible people.
It never constructs an actor, authenticates a session, approves a request, or
initializes issuer metadata. It first calls the unchanged native identity source,
which validates the actual physical writer/open transaction, exact native account,
and native account/session/transaction/legacy-epoch InnoDB and MDL prerequisites.
It then reads the actual latest native user transaction ID and legacy role epoch
again through the same locked connection.

The fact set retains user PHID, eligibility, system-admin flag and native security
field digest. It adds `source_version: 2`, the actual native transaction ID and
legacy role epoch. A missing *row* means epoch `1`, matching first credential
initialization. It does not mean a missing table or failed lookup. A first actual
legacy role change creates epoch `2`; subsequent changes advance it. Native user
transactions, identity/security changes and legacy role ABA therefore still change
the bound fact set. Canonical uint64 generation bounds remain mandatory.

Only newly proposed transfer/handover tickets use explicit `*-v2` schema markers.
Stored `*-v1` tickets select the unchanged original source interpretation. They
are not rewritten, migrated, upgraded on read, or normalized retrospectively.
Changing a stored v2 marker to v1 does not transform its facts into v1 facts.

Requester, executor, approver, web/API credential, session expiry, account epoch,
MFA, configuration and native capability checks retain their original strict
sources. The normalized facts cannot substitute for any credential binding.
No native permission axis or membership is changed by this helper.

The helper extracts the already tested fact shape from the separate approved
Project-membership implementation; this new helper and its transfer/handover
integration require their own supported-runtime receipts. Draft pure and native
SQL cases cover first real directory access after proposal, native disable/enable,
legacy agent/mail ABA, restored v1 old behavior, schema-restamping refusal,
missing epoch table and non-InnoDB refusal. Existing T3 159/329 and T4 163/362
receipts do not certify this successor. UI forms are also a separate unverified
successor surface. Features remain off, and no production migration is performed.
