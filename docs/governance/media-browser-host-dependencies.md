# Browser host constructor dependency correction

The aa54 measured13 source and its historical 23ffe artifact set remain intact.
That artifact set's host directory omitted two files consumed by the actual
unchanged engine: `load_runtime_observation()` reads the adjacent
`requirements-linux-arm64-py312.lock`; `FixedPodman.__init__()` reads the adjacent
`seccomp-playwright-v1.64.1.json`. This is a known runtime blocker for that
incomplete host artifact set, not a successful VM attempt.

This correction copies the two exact files from the same reviewed 64234 source,
with fixed SHA256 values. The original engine and owned-container source are
also copied as unchanged, provenance-pinned host inputs so the constructor
regression is self-contained. `build_host_engine_inputs()` emits exactly those
four host inputs; `build_host_dependencies()` emits exactly the two data files.
They are adjacent to `engine.py` in the host package and never added to the
thirteen guest source names. No engine policy or nine-file installer is changed.

The regression imports the real unchanged engine in an owned temporary package,
first proves missing-lock refusal, then missing-seccomp refusal, then successful
construction after both files are present. Engine/runtime observations in that
test are explicit synthetic metadata fixtures; all process calls are trapped.
It proves filesystem dependency resolution and strict constructor execution,
not an observed Podman engine/image, container inspect, install, TLS or browser.

Root must use a separately frozen repaired host artifact set before the actual
probe. The remaining actual asset, schema, source/owner/lease and public-domain
approval gates are unchanged. A signed exact candidate can be independently
prepared and tested before merge; main/merged state is not a prerequisite.
