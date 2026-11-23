# MAD4B Host Release Source Preflight v1 — source mirror

This directory contains the audited non-secret implementation running at
`/opt/mad4b-company-provisioning/bin/release-source-preflight-v1` (host-side),
including a **non-authorizing, immutable-commit, post-commit manifest reader**.
It does **not** contain a private signing key, WordPress session, OAuth token,
Owner approval, or a Release Verdict.

## Host incident and repair

A root-owned host configuration was pinned to a deleted worktree and the worker
raised `host_config_path_invalid:repo`. The host config was atomically repaired
with private rollback receipts to an enrolled permanent **no-checkout local
Git worktree**, `worktrees/host-pinned-control-plane-v1`. No WordPress request
or principal was synthesized. The worker returned `UNSELECTED` on both sites.

The preflight originally required `MAD4B-BUILD-PROVENANCE.json` **inside** the
Git commit whose own SHA the file would need to name: an impossible circular
commit dependency for a final exact source SHA. The changed `source_preflight.py`
reads an out-of-tree file only for `local_integration` when the source has no
in-tree provenance. The accepted path is the pinned SHA in:

`<root-owned reports_dir>/manifests/<exact40hex_source_sha>.json`

It rejects symlinks, group/world-readable or writable files, non-root
ownership, contract mismatch, incomplete shipping manifests, incorrect
file hashes, source mismatch, and any field purporting to authorize or certify
Production. All **eligible shipping plugin Git files** must be represented;
site-local MU plugins and excluded tests/docs are not blindly merged into the
signed package. A properly enrolled independent Host signer can subsequently
review a fully reconciled site, but this sidecar is never a signed package.

## What was verified

- New mirror matches the deployed Host `SHA256SUMS` and retains previous worker
  / policy bytes; the host replacement was backed up before atomic update.
- 11 negative / positive source-manifest checks pass.
- Real host preflight for exact Git source `7a618b54e7d50753f2eba3af22160831c9db3e84`
  reported `861/861` matched, `source_commit_matches_pin=true`, and
  `source_only_candidate=true`, while `certified_release=false`,
  `release_authorized=false`, and `NEEDS_RECONCILIATION`.
- Live WordPress sites still run the previously installed signed `rc.128`.

## Deployment authority and rollback

Do **not** copy this tree directly over the Host service as an unreviewed
upgrade. Original Host approval and exact checksums apply; the executor must
hold a root-owned private rollback snapshot and run `sha256sum -c SHA256SUMS`,
non-mutating unit tests, and the Worker again. The Host private issuer key is
not part of this repo and must never be included. WordPress source selection
requires an **actual enrolled admin**, `manage_options`, `update_plugins` and a
nonce-checked POST through the existing selector. SSH/CI source-read rights do
not supply that admin identity or an Owner signature.

Even after a source request is selected, the original Worker signs a bounded
short-lived Host proof only if **all** current reconciliation criteria pass.
The candidate currently has hundreds of differences across the two sites.
No review record or unsigned post-commit candidate manifest can set
`reconciliation_ready=true` or issue a release approval by itself.
