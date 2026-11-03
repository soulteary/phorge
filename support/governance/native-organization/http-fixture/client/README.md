This program proves a bounded TLS/API path in the owned synthetic fixture. It
is not browser acceptance. Trusted-domain browser login, frame messaging,
cookie policy and active-content execution checks remain required.

Run the compiled client with `PHORGE_GOVERNANCE_ISOLATED_TEST=1` and the same
canonical `TMPDIR` used by the TLS proxy. The only argument is the owned private
fixture directory. It reads the 0600 owner, ready manifest and public leaf PEM;
it does not accept a login token or preseed a session. Private credentials,
codes, target URLs and response bodies remain in memory and never enter its
receipt. Discard the PHP server access-log stream; it can contain synthetic
capability paths.

The TLS proxy exports only `tls-leaf.pem`, containing the public certificate.
The client uses the normal system root pool plus that one private, in-memory
trust anchor, standard SAN/time verification, and an exact leaf pin. It does
not disable verification or alter operating-system trust. Real negative TLS
handshakes must reject an untrusted leaf, wrong SAN and wrong pin before login.

Login is derived from the actual native password form's hidden fields, action,
CSRF token and cookie jar, followed by the native validation/finish redirects.
The media flow uses the actual authorize page configuration and native bridge,
prepare, issue, redeem and ready handlers. It checks target/nonce mismatch,
code reuse, cookie attributes, real private bytes, HEAD and active-upload
response headers, then native logout with the media cookie retained. It
cannot prove browser SameSite/CSP enforcement or script non-execution.

The local unit tests cover form parsing and refusal cases, and exact wire
cookie attributes. Passing those tests alone does not certify TLS/API runtime.
