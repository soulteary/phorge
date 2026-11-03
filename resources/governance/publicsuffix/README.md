# Offline public suffix rules

`rules.json` is derived from the official Public Suffix List (ICANN and PRIVATE
sections), licensed under the included MPL 2.0 license (trailing whitespace normalized). Its metadata records the
canonical source URL and source SHA-256. The normalized rule set was checked
against publicsuffix/list revision `73f8cc701c084fe07d888ec552b2f0b53281a674`.
`test-cases.json` derives from that revision's CC0 upstream test corpus; Unicode
rules and cases are converted to ASCII A-labels at build time.

The runtime uses no network requests, IDNA extension, or extra package. Origin
configuration accepts ASCII hostnames (use A-labels for international names).
The resolver implements the PSL longest-rule, wildcard and exception algorithm,
including the default `*` rule. It is used only to refuse unsupported cross-site
media configurations; it does not grant file access or implement a cookie jar.

To update, download the canonical list over verified HTTPS, obtain the pinned
upstream list, test corpus, license and revision receipt in a private staging
directory, then run `scripts/governance/build_media_site_rules.py
--upstream-directory <directory>` with Python `idna` 3.19. The script refuses a
normalized mismatch between canonical and pinned sources. Review the source
revision and generated diff, then run the native corpus and exchange tests.
Keep this snapshot current with browser PSL changes. A future divergence may
require a list update; never compensate by broadening cookie scope or weakening
SameSite. Configuration refusal occurs before exchange state is written.
