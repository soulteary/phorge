# Versioned transfer and handover candidate

This branch starts from the separately verified O6 history baseline. Its new
transfer work is not covered by the O6 147-method/297-check evidence.

The first slice defines four People-owned, versioned workflow tables, exact
intent payloads and calendar previews. It does not register a transfer writer,
route, approval executor or dispatcher. The DAO mutation methods refuse public
writes; future mutations must use the actual current native identity and the
same locked People transaction. Fixture inserts are synthetic storage setup,
not a bootstrap authority or proof of approved transfers.

Calendar previews default to 14 local calendar days, allow an explicit immediate
0-day transition and cap one interval at 30 days. The IANA zone and UTC/local
instants are explicit. A repeated local deadline needs an explicitly selected
valid offset; a nonexistent wall time is refused and needs a new preview with
a valid start time. Extensions always require fresh independent approval;
cumulative periods above 30 days also require a distinct exception reason.

The next slice must bind current native account/credential sources, the exact
original primary-department and unit versions, an independently authenticated
current system administrator, explicit preview acknowledgements and operation
replay to one physical native transaction. The existing generic cross-department
assignment block remains unchanged. A responsible person is descriptive only.

Per-project keep/remove/review records will require the actual current native
Project viewer. They are manual disposition receipts, not permission mutations
or exhaustive discovery of hidden projects. The minimum outbox contains event
and protected workflow references/source versions only. No dispatcher is
registered. Due clocks alone never revoke access, extend a period or erase history.

The supported PHP 8.5.12 run passed 156 native test methods and 307 real
isolated SQL checks, including the four new schema comparisons and six storage
checks. All 5876 scoped PHP/SQL files match the immutable-main/explicit-overlay
stage; unknown working-tree files were excluded. Cleanup removed 55 owned
schemas, two runtime users and the reader without failures. The generated map
was reviewed as exactly eight new class and inheritance entries, preserving all
old mappings/functions, and imported unchanged from the tested stage. Governance
mutation tests (88), permission tests (84), and the strict paired-Gorge receipt
gate also passed.
These results prove storage/calendar intent foundations, not T3 execution.
Approval, primary switching, extension/termination,
protected worklists, native UI/Conduit, concurrent execution and fault coverage
remain activation blockers. Native permission axes and optional defaults remain
unchanged; no deployment or live migration is performed.
