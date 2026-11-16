# Tenant Sync source reconciliation — source-only

The reviewed feature branch source contains all 22 files found on Wayfatours
plus 5 additive source files. Of the original files, 17 have byte-identical
SHA-256 and 5 carry reviewed newer Provider Schema changes. No live files
are removed and no WordPress DB/Plugin code changed during this review.

The exact source candidate is pinned in the data-only
catalog/tenant-sync-source-lineage-v1.json inventory, including hashes of
the protected live baseline and all 27 proposed files. The review CI
fails on silent removals and unreviewed edits.

The new Provider Schema source code uses dynamic, site-bound operation
discovery. Environment strings are validated as bounded labels; staging and
production names do not confer write authority. A genuine original signed
312 schema-migration grant is still necessary.

## Not yet cleared for deployment

The live Tenant Sync plugin directory, includes directory and PHP source
files are owned by UID 33, while Provider Schema trusted_source requires
root-owned, non-group-writable PHP source and directories. Do NOT relax
the runtime trust check or directly chown Production.

Required first-party capabilities: root-protected versioned plugin package,
coherent multi-file HTTP/PHP-FPM cutover, independent Original 312 schema
grant issuer carrying the source declaration SHA, Original 313 finalizer
and protected readback. Until enrolled, deployment remains HOLD.
Source review and offline PHP tests do not attest the Production site.

## Immutable source package preparation (review only)

The generic source-only builder versioned_plugin_artifact.py reads a
40-character immutable Git commit via git ls-tree and git cat-file.
It never packages mutable worktree files and never writes WordPress. Only
regular files can enter a package; symlinks, non-regular objects, oversized
blobs, unreviewed deletion of a live path, injected commit/slug/relative
paths and duplicate names are all denied. It excludes test-only directory
segments (default tests) from runtime packages while retaining all tests
in Git/CI.

A deterministic tarball uses root-owned metadata and normalized read-only
source modes; the archive is created new-only in a root-private directory
with an immutable manifest recording source SHA, live baseline file SHA,
reviewed changes, and all excluded test paths.

The first actual Wayfatours candidate consists of **23 production files**
(17 unchanged vs live, 5 revised, 1 added) and **four excluded test scripts**.
Its source-only tar SHA-256 was
f1bbe125343ab1a89426c46cecf3d12459651c2c3c35b6f2a49383409050c830.
This hash describes source content; it is **not a signed Release Grant or
Original 313 receipt**. Production remains unchanged.

Deployment HOLD remains because the currently installed site/plugin PHP
ownership is UID 33 rather than root; no original authorized Publisher
installation, coherent PHP-FPM/OPcache cutover, original 312 migration proof,
or finalizer certification has been demonstrated. No source artifact should
ever be copied directly into the live plugin folder to bypass those gates.
