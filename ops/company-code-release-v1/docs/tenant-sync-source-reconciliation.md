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
