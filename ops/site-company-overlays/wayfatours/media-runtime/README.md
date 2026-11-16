# Wayfatours — active production media runtime snapshot

This directory is an exact, **non-installable**, versioned copy of the media-specific PHP, CSS, and JS files actually loaded from Wayfatours Production after the manual fixes on 2026-11-16. The files under `wp-content/` are **NOT** a general `wp-content` source tree, not executable by Git, and are not an instruction to reactivate MU files in Tours.dev.

`manifest.json` records the live SHA-256 of every file and whether it matches the shared Git tree and the Tours.dev source. For any future change, reconcile **source ownership and capability migration** explicitly: a missing MU file may already live in an active plugin. Never overwrite a newer company file from a stale shared source.

**Release status:** `HOLD` until explicit owner-aware merge and the original governed release admission are independently verified. Saving a file here is not a deploy grant or a rights license.

The three manually updated original engine files are recorded from the currently deployed runtime. Backups and rollback receipts remain on the VPS outside Git under `/opt/mad4b-media-backups/wayfa-media-engine-hardening-20261116/manual-release-20261116/`.

## Reproducible read-only verification

From the repository worktree, run:

```bash
python3 tools/mad4b_media_source_parity.py \
  --live-wp-content /opt/wayfa-rebuild-20261102/site-copy/wp-content \
  --git-wp-content wp-content \
  --company-overlay ops/site-company-overlays/wayfatours/media-runtime \
  --require-overlay-match
```

An `exact_live_source_mirror` result confirms that all saved media files still
match Wayfatours. It **does not** clear `hold_for_owner_aware_reconciliation`
for the shared release source; these are deliberately distinct checks.
