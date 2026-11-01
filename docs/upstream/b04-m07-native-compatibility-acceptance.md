# Native legacy project route acceptance

This closes the bounded native compatibility scope of M08/M09 without adopting
a successor permission model, removing the warning route, or migrating slugs.
The full permission fixture restores its owned snapshot before testing, then
checks both the original and revoked states with all ten existing subjects.

Four synthetic projects have additional historical aliases containing literal
colon path characters. The normal application query and controllers resolve
those aliases; no test-only application route bypasses authorization. Visible
objects redirect to their stored canonical slug. Hidden objects may redirect to
the normalization of the caller's supplied spelling, then deny or 404; this is
not evidence that the stored canonical slug was disclosed. Uppercase view/board
spelling and unknown view/board slugs receive separate checks. Percent-encoded
spelling is not asserted interchangeable with the stored historical alias.

The old subproject warning requires both native visibility and edit capability.
An authorized GET exposes the native conversion confirmation and CSRF field.
An invalid-CSRF POST returns the malformed-request HTTP 400 response. A valid
POST redirects only to the native child creation form; the fixture does not
follow it or execute conversion. Project records, membership edges, all slug
rows and project transaction counts must remain unchanged across this suite.
The redirect-stopping client copies only the owned synthetic session cookies
and retains the existing same-origin, loopback connection restriction.

The separate version-1 compatibility receipt requires 392 unique assertions:
per state, 40 warning GETs, five CSRF fields, five invalid-CSRF POSTs, five valid
POST redirects, 40 historical aliases, 80 noncanonical routes, 20 unknown routes
and one no-mutation fingerprint. Four new receipt/client tests bring permission
unit tests to 78. The existing v7 boundary receipt retains its 1,908 assertions;
these 392 checks have their own explicit field rather than silently changing
historical receipt meanings. Native methods remain 148 and source catalog
coverage remains 78 reviewed files / 43 literal membership-reference files.

This does not certify arbitrary legacy encoding, all compatibility transitions,
new role semantics, direct-edge corrections or broad custom policy concurrency.
Four axes retain native behavior; optional models and production features remain
off. Shared receipts contain counts and fixed scenario names, not raw aliases,
database rows, session cookies, addresses or credentials.
