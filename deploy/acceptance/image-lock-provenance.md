# Acceptance image lock platforms

`build-lock.json` pins image **indexes**, which let Docker select the native
image for a requested platform. A digest can also identify one architecture's
child manifest. Such a child is immutable but cannot serve both `linux/amd64`
and `linux/arm64` builds.

On 2026-10-08, the public registries were queried directly using their manifest
API with OCI index and Docker manifest-list Accept headers. Five old references
were ARM64 child manifests. Each replacement below is the same version's parent
index and includes the exact previously locked ARM64 child; no dependency
version was upgraded.

- Go `1.27.1-alpine3.24`, `docker.io/library/golang`:
  parent `sha256:8a5910f31396cd4d89662f56c68b3ae31d374308270a1c3bd96672ee5ed43414`;
  retained ARM64 child `sha256:092dd976c2202d32342b9517d6ff22c2dfd89f66c914aed205fed0643ee4a240`.
- Alpine `3.22.6`, `docker.io/library/alpine`:
  parent `sha256:5291449c3df73caf6ed85e649dec1b9e818b39a5d8c871e97afc13e9cd5e8fa8`;
  retained ARM64 child `sha256:2e1a7aa4cbc4e9e5222bb4c24a839aa1a6170ea5492d644777ce7b178824e44f`.
- MySQL `8.0.46`, `docker.io/library/mysql`:
  parent `sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b`;
  retained ARM64 child `sha256:213bbfaf699693a40a20a12bb4342d2589a15a3dc7153db698eaed252a92458e`.
- Redis `7.4.11-alpine`, `docker.io/library/redis`:
  parent `sha256:858f009f9709ce576febc734aa78b8f6d624b82571f9ddb6bda4377c833b3499`;
  retained ARM64 child `sha256:1f09a89a207d794a8c61d9edfc26e7c58427de10ccef7c5d18d638df79a63b85`.
- Meilisearch `v1.12.8`, `docker.io/getmeili/meilisearch`:
  parent `sha256:c9fac23131cca4db95173d41cc50fd5639121ee381795528fdd7522d7978a7b8`;
  retained ARM64 child `sha256:459f539c1f3e14dfddbc7451f3cb3506c998661cc0daef82828260242fe6c71f`.

The existing PHP, S3 (`ghcr.io/soulteary/otterio`) and Elasticsearch references
already identify indexes with Linux AMD64 and ARM64 children and were preserved.
Meilisearch and Elasticsearch use Docker manifest lists; both formats are valid
multi-platform indexes. The Go image contains Go 1.27.1 and satisfies Gorge's
`go 1.27.0` module requirement.

## Repeat the registry check

From the Phorge checkout, with the matching Gorge source checkout available:

```sh
python3 ../gorge/deploy/release/base_images.py \
  --lock deploy/acceptance/build-lock.json
```

This queries the registry and requires AMD64/ARM64 for the Go and Alpine build
images and AMD64 for the acceptance dependencies. The full acceptance entry
executes the same check before any image build or backend startup. Its failure
is recorded as `image-platform-check`. The configuration-only `--check` stays
offline and cannot replace this registry check.

To inspect an index's children independently, use
[`docker manifest inspect`](https://docs.docker.com/reference/cli/docker/manifest/inspect/)
with the exact locked reference. For example:

```sh
docker manifest inspect \
  docker.io/library/golang@sha256:8a5910f31396cd4d89662f56c68b3ae31d374308270a1c3bd96672ee5ed43414
```

The `manifests` array must contain Linux AMD64 and ARM64 entries. The ARM64
entry's digest must match the retained child listed above. The five version tags
can help locate these parents, but the acceptance build uses the frozen index
digests from `build-lock.json`.
