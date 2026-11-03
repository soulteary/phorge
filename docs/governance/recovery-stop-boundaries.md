# Native stop classification boundaries

The signed component `44eee10c40496c47a642249e673c1ecaec0caf13` passed 214 native pure methods and 740 actual SQL checks, with 6,787 runtime source files matching its signed archive. All 32 registered worker directories, 55 schemas, two runtime principals, the reader, image service and private staging were cleaned. The linked acceptance records apply only to that component.

The tests establish bounded two-session contention on one stop operation: two real sessions wait on the same indexed native User row, then produce one new result and one replay with one ledger/audit pair. Separate cases cover actual administrator revocation, session expiry after authentication while waiting, missing/nontransactional storage, unsupported routing and preservation of a caller-owned raw transaction. The earlier 6bc candidate failed the missing-table fixture classifier; its partial 725-check result remains a failed historical checkpoint.

This candidate explicitly combines that component with protected main `0a43c0211c4ced7749d26187da29798c4439086b`. Its strict obligations are 218 pure methods, 755 SQL checks and 32 workers. These combined runtime results are pending. Host-only checks do not establish supported runtime or SQL success.

The stop classification option remains disabled by default. There is no public registration, restoration permit, dispatcher, migration execution or deployment. Bounded contention does not establish all-writer convergence or completion of the 13 recovery source families. Native permission axes remain unchanged.
