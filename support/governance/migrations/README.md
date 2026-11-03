# Historical push-event grouping regression

`push-groups-wrapper.py --profile push-groups-regression` requires the existing
explicit isolated fixture environment and loopback disposable SQL control
credentials. It creates a random namespace, exact ownership marker and restricted
setup principal; restores only actual historical repository DDL; executes the
actual PHP migration on three synthetic rows; and removes its owned schemas and
principal. It refuses deployment selectors and local deployment configuration.

The original query must actually raise 1055 under ONLY_FULL_GROUP_BY. The fixed
migration must use the earliest native ID's metadata for two rows sharing a
transaction key, preserve an existing event, and produce exactly two events.
The supervisor retains console bytes only as a hash; it emits a sanitized
receipt. This is a writable migration test, not a production migration tool or
a claim of complete historical archive compatibility.
