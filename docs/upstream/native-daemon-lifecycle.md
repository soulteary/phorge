# Native daemon container lifecycle

The shared PHP/Apache image inherits Apache's `SIGWINCH` stop signal. That is
correct for the web role. The daemon role runs `phd-foreground`, whose orderly
shutdown handler listens for `SIGTERM` and `SIGINT`. Leaving the inherited signal
in place bypasses that handler until the container runtime's kill deadline.

The daemon Compose service explicitly uses `stop_signal: SIGTERM` and a
45-second `stop_grace_period`. Native `phd stop` may spend 15 seconds on SIGINT
and another 15 seconds on SIGTERM, so the container budget must include both.
This changes lifecycle configuration only: no permission semantics, data schema,
queue ownership or optional feature changes are introduced.

Existing containers retain their configured stop signal until recreated. During
a reviewed rollout, explicitly stop the existing daemon with SIGTERM before
replacing it, for example `docker stop --signal SIGTERM --timeout 45
<daemon-container>` on a runtime supporting that override. Confirm graceful exit
and drain state; do not bypass a forced-exit guard or replay indeterminate work.
Do not rerun completed migration/configuration containers as part of this fix.

Direct non-Compose daemon invocations must likewise specify a SIGTERM stop signal
and sufficient stop timeout. Do not change the shared image default globally,
which would also change Apache web shutdown behavior.

`tests/atomic/daemon_shutdown.py` reproduces the inherited-signal failure and
checks the actual Compose daemon policy in isolated containers using the existing
locked PHP base and a synthetic daemon double. It has no network, host mounts or
production storage and removes only its uniquely labeled fixtures.
