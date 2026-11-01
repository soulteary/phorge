# Phorge signed immutable publication

Publishing remains manual. Create a new dated source tag only after required
PR/main checks and final-head review pass. Dispatch `container-image.yml` from
that same tag, passing its name as `release_tag`. The tag checkout, tag target,
event ref and event SHA must agree; a main-branch dispatch cannot publish a tag
by substituting a checkout alone.

The registry gate reads the dated version and full source-SHA aliases. Workflow
credentials are passed through step-scoped environment variables solely for the
fixed GHCR token exchange, supporting private packages without new credentials.
Anonymous reads remain available to external verification callers. Only an
explicit HTTP 404 means absent. Authentication, timeout, throttling, server or
metadata failures stop publication. Existing aliases must resolve to the same
index and match source/version labels and both Linux AMD64/ARM64 configurations.
An existing complete publication is not rebuilt or overwritten; it can resume
runtime verification, provenance attestation and signing. An incomplete alias
set or mismatching identity requires investigation, not automatic replacement.
The global workflow lock serializes source/version publication across versions.

New images must pass the locked PHP security checks on both architectures
before publication. The exact registry digest must equal this build's digest,
or the unchanged preflight digest on a retry. Both published architectures are
then checked again, including PHP 8.5.12 and disabled JIT. The verified digest
receives GitHub provenance attestation and keyless Cosign signing. No `latest`
tag is emitted. Platform, child-manifest and config identities are read from
registry bytes with digest checks; registry credentials never reach a redirected
blob-storage origin.

Download `phorge-release-evidence` only from a successful run. Independently
verify Cosign identity against the tagged workflow and GitHub's OIDC issuer,
verify GitHub attestation source ref/digest, inspect the actual deployment
architecture and source revision, and pair the result with a reviewed Gorge
release manifest. Deploy by digest. A published tag is never sufficient evidence
by itself, and these workflow guards are not a claim that a registry administrator
cannot modify a tag outside this workflow.

A signing or post-publication failure leaves the image unapproved for deployment.
Rerun the same immutable source/tag after investigating; do not move source or
image tags. This procedure does not enable optional features, install optional
XLSX dependencies in production, change permission semantics or migrate data.

The isolated regression fixture pins Composer 2.11.1. This replaces the former
2.8.12 tool affected by GHSA-f9f8-rm49-7jv2; no Composer dependency or autoloader
is installed in the production image. The existing XLSX package lock is unchanged.
