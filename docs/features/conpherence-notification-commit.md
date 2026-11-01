# Publish room notifications after commit

Conpherence published its `message` notification in `applyFinalEffects()`,
inside the application transaction. A connected observer could receive that
notification and load the room while its new message was still uncommitted.
The first refresh initializes its known transaction ID from the previously
displayed state; an old response can therefore complete without another refresh.
The message is saved, but that observer can remain stale until another update
or reload.

Publication now runs in `didApplyTransactions()`, after the normal editor
transaction commits. Participant read markers and cache invalidation remain in
the original final-effects phase. The payload, subscription PHID, unique ID,
notification transport and Gorge service boundary are unchanged. Empty edits
do not publish, and a rollback never reaches the publication hook.

## Regression coverage

`tests/contract/conpherence/notification-commit.php` uses a disposable InnoDB
database and a local HTTP notification consumer with a separate DB connection.
It deliberately holds the edit transaction open while invoking final effects:
the old implementation publishes before the consumer can read the new row;
the corrected implementation publishes once after commit and the consumer sees
the row. It also checks rollback, empty edits, disabled notifications, and wire
compatibility, including the last transaction ID of a multi-transaction edit.

The paired runtime workflow runs the contract with the default file origin and
with an alternate resource origin. The baseline failure reproduces without an
alternate file origin. Resource routing is consequently not a prerequisite for
this race. This does not establish the cause of every historical missed update:
the original observer state was not retained, and no production traffic capture
is used as evidence.

Run only against an owned disposable MySQL server:

```sh
php tests/contract/conpherence/notification-commit.php
php tests/contract/conpherence/notification-commit.php --file-origin
```

Set `GORGE_TEST_MYSQL_PORT` and `GORGE_TEST_MYSQL_PASSWORD` for that server.
The contract creates and drops only its randomly named database.

Browser acceptance must use an already-open room observer and a separate sender.
Check that distinct messages appear without refreshing the observer, repeat after
leader-tab turnover, and check the browser console and existing service counters.
Retain the historical miss separately from the reproducible transaction defect.
