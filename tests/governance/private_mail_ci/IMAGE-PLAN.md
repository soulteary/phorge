# Isolated PHP/Python wire image — prepared, NOT BUILT / NOT RUN

The inspected existing dedicated test container uses immutable image ID
`sha256:6ea74a1a8b32951a03847839597c03e82a3935bb151dc85d728c6e58b6170608`.
Image metadata says Linux/arm64, source revision
`5298239e0fe364046b2a60d5f76ab733a284fcd0`. Reading `/etc/os-release` from a
never-started temporary image container confirmed Debian13.7 (trixie). That
metadata container was removed. No live-container filesystem/config was copied.

The test-only Dockerfile derives FROM that local image ID (build with
`--pull=never`). It checks PHP8.5.12, Debian13, the existing Git binary and the
inherited pinned Debian20261028 snapshot before adding only `python3` plus its
required stdlib dependencies. No pip, venv, database, Go toolchain or production
configuration is installed. Package versions are recorded inside the derivative.
No application source or private data is copied. The production entrypoint is
cleared: the default command prints Python's version and exits. Build and inspect
its resulting immutable ID only in an assigned free resource slot; no build has
been performed for this candidate.

## Source and binary mounts

Use `prepare_source.py` after the selected source checkpoint is final. It creates
an independent mode0700 standalone Git clone with detached exact HEAD, no
hardlinks/alternates, no inherited hooks and no origin URL. It clones committed
objects only, not untracked/private files. Reject any committed deployment
selector files. A linked worktree's `.git` pointer is never mounted directly.
Preparation has not yet been executed against the full candidate.

Run the test container as the clone owner's numeric UID/GID. Mount the clone at
`/source:ro`, the harness at `/harness:ro`, and each of the five explicitly hashed
binaries individually read-only under `/binaries`. Use a separate private
mode0600 binary manifest with those container paths and immutable source
revisions. Generate the input config **inside** the container so all path/hash
checks refer to its actual mounts. Configs and output logs live only in its
writable private tmpfs, never the source volume. Pass the disposable database
password only through its named environment key, not in argv or image layers.

Set `GIT_CONFIG_NOSYSTEM=1`, `GIT_CONFIG_GLOBAL=/dev/null`,
`GIT_OPTIONAL_LOCKS=0`, `HOME=/tmp`; no safe.directory bypass is required when
UID/GID ownership matches. The source is read-only, so no index refresh writes
should occur. Probe mount access and `git rev-parse HEAD` before any DB fixture.
On a remote/desktop engine, filesystem UID mapping must be measured; do not
loosen the0700 source permissions to hide a failed mapping.

## Runtime placement / resources

A later explicitly assigned run joins the existing **owned disposable** pod and
reuses its MySQL over127.0.0.1. It starts no additional database and publishes no
ports. All PHP/Go/Owlmail listeners remain loopback-only. Override the image
entrypoint with the explicit test command, read-only rootfs, a writable private
tmpfs, no new privileges and minimum capabilities. The committed source-root and
all five binary hashes must match before and after execution.

The ordinary Python driver is Linux-local and does not require a container
engine. This derivative is only for local reproduction where the existing PHP
test image lacks Python. CI can use its existing setup-php/Python environment
and MySQL service directly. Never `podman commit` a live container or copy its
runtime configurations. Current full four-case wire and139-case gates remain
unexecuted; the previously passed11 Linux ownership unit tests do not replace
those wire gates. Full139 security/fault matrix portability remains unfinished.

## Actual isolated validation

The Dockerfile-only stdin context built successfully with `--pull=never` from
the pinned base; derived Linux/arm64 image ID is
`41d8fb4b4a3acf9e337877c99f5cb83750da18e722c342d669dd25ada8295e07`.
The isolated probe confirmed PHP8.5.12, JIT disabled/buffer0, Debian13.7,
Python3.13.5 with pidfd APIs. Desktop host bind metadata was unavailable; a
new owned engine volume was populated solely from the verified standalone
committed clone over stdin, then mounted read-only. No private runtime config
was copied. This preparation supported four actual local-driver wire cases;
all owned runtime volumes/container were removed afterward. It does not prove
GitHub-hosted CI or the full139-case matrix.
