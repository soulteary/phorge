# B04/M07 v5: HTTP XLSX, mid-export revocation and hierarchy changes

This extends the v4 isolated recovery evidence. Four permission axes retain
native semantics. Candidate models and optional production features remain off;
no live access, permission migration or production dependency installation is
part of this change.

## HTTP XLSX

The fixture explicitly preloads the existing locked PhpSpreadsheet 5.10.0
installation only into a disposable loopback PHP server. It executes the native
Maniphest export confirmation and CSRF-protected POST, follows only a same-host
file redirect, and completes the native main-domain download confirmation.
Actual attachment bytes are parsed independently as XLSX ZIP/XML. Owner and
outsider workbooks have exactly two and one visible task rows respectively;
private title exclusion, MIME type and attachment filename are checked. Invalid
CSRF and anonymous export requests must not return a workbook. No browser UI,
Office reader, alternate-file-domain topology or capability-link secrecy claim
is implied by these loopback HTTP checks.

## Mid-export revocation

A test-only format delegates to the real Excel formatter. It pauses after the
first object is rendered, after a 1,000-object query page has loaded. Another PHP
process uses native transactions to revoke both that object and the oldest
object, which is outside the loaded page. Releasing the exporter characterizes
native admission: the loaded object remains in the current export, whereas the
next-page revoked object is omitted. The in-flight workbook contains exactly
1,001 rows, and a fresh export contains the exact remaining 1,000 rows. A finished
private export snapshot remains readable by its author; another viewer cannot
query the file. This is not global retroactive cancellation or revocation of
already downloaded bytes. No production exporter policy is changed.

## Member and Space hierarchy changes

Native project editors create a grandparent, parent, two ordinary leaves and a
milestone under one leaf. Native membership transactions add/remove a leaf member
and check materialized ancestor aggregation, milestone membership and sibling
exclusion. Moving the leaf to a restricted Space checks leaf/milestone denial,
stored milestone Space synchronization and unaffected parent/sibling visibility.
Restoring the default Space restores visibility. Moving the parent to the
restricted Space checks ancestor visibility denial for both branches and the
milestone, with the grandparent unaffected. These are bounded native transitions,
not arbitrary reparenting or a new permission model.

## Evidence scope

The v5 receipt additionally requires positive HTTP workbooks, exact in-flight
and fresh row counts, native snapshot retention, all hierarchy assertions and
HTTP process cleanup. Missing or altered evidence is rejected by unit tests.
Existing v4 crash/queue coverage continues to run. All dependencies stay outside
the source tree in the disposable environment; ordinary processes remain without
optional XLSX support. Shared records contain counts, booleans and source hashes,
not request cookies, download capability URLs, workbook contents, raw database
fixtures, deployment addresses or credentials.

Remaining scope includes isolated file-domain HTTP
coverage, arbitrary hierarchy/reparenting concurrency, broader policy-rule cache
interleavings, queue fencing/partitions, Redis and mixed-worker deployment. The
32 complete ADR designs remain partially covered rather than certified wholesale.

The extension adds 48 native boundary assertions (1,860 cumulative) and four
receipt/parser unit tests (70 permission unit tests total). The unchanged
source-only consumer catalog contains 75 files and 43 literal-reference files.

## v6 client-error classification

Malformed requests, including invalid CSRF submissions, now return HTTP 400 in
both the application exception handler and the early standalone response. The
same native CSRF validation and rejection content are retained; unexpected
server failures still return HTTP 500. The HTTP fixture requires the specific
malformed-request exception and CSRF content, not merely a non-workbook response.
The v6 finite receipt explicitly records `invalidCSRFStatus: 400`; historical
v5 evidence characterized the former 500 behavior and is not rewritten.

Three new native request unit methods and four existing request methods are
registered in D01 (148 native methods total). The source-only catalog adds the
exception and both response boundaries (78 files, still 43 literal membership
references); this is not broader runtime authorization certification. No new
model, candidate activation, dependency or production configuration is introduced.

## v7 independent Go XLSX reader

The approved release scope replaces desktop Office acceptance with Go parsing.
A bounded standard-library-only Go 1.27.3 reader independently checks six actual
native XLSX files: owner and outsider CLI exports, their HTTP attachment bytes,
and the in-flight and fresh exports after revocation. Exact data-row counts are
2, 1, 2, 1, 1,001 and 1,000. Forty-eight assertions check viewer-specific rows,
coordinates, string cells, date styles and absence of formulas. Formula metadata
is never evaluated. The reader rejects malformed ZIP/XML, external worksheet
relationships and inputs exceeding its explicit resource budgets.

This adds 48 boundaries (1,908 cumulative) and four Python receipt tests (74
permission unit tests total). Ten Go test functions cover valid and rejected
workbooks. CI builds the reader outside the source tree; no Go runtime or new
production dependency is added to the application. Desktop Excel/LibreOffice
rendering or arbitrary third-party XLSX compatibility is not a release claim.
Historical v5/v6 receipts retain their original counts and scope.
