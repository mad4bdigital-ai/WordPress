# Tours.dev live-source reconciliation (2026-11-18)

Tours.dev Staging is the **primary evidence source for installed fixes**, not an automatic authority to overwrite newer Git changes. The attached hash inventory records 62 targeted source files observed on 18 November 2026; the root-only sealed content snapshot is held outside Git and not deployable.

## Reconciliation rules

1. Preserve every live code change before editing its counterpart. Match exact SHA-256, compare by logical behavior and keep newer Git implementation.
2. Never copy live `wp-config.php`, keys, runtime credentials, tenant records, generated caches, backup suffix files, or complete WordPress installations into Git.
3. Integrate narrowly, with explicit regression tests and independent review, into the release source; a live installation never signs its own release.
4. Before packaging, regenerate the Control Plane build provenance from the merged and CI-approved Git commit. Do not reuse the installed source's old manifest: 59 declared files have changed after its build.
5. Deploy an exact verified artifact to Tours.dev Staging, verify without overwriting later hotfixes, and only then reconcile ARE and ETG on Staging with tenant-specific content preserved.

## First integrated slice

Restore the live **company operations + Skills read catalog** visibility, and preserve the existing **External Skill Sources** management actions already on Git. Deliberately exclude the newer OAuth/CIMD client validation and Host Anchor sources until their separate security and execution-gate reviews.

This branch is a source candidate, **not** a release grant or Production deployment.

## Comprehensive source preservation (18 November 2026)

The root-only live source archive captures 995 non-backup code/assets files from
Tours.dev with per-file SHA-256. The public, **hash-only**
`full-runtime-source-hashes.json` is a frozen inventory of that capture: 665
source files matched the then-current `master` exactly, 166 shared files differed,
and 164 source files were not in `master` (including 128 PHP files). The 995-file
set explicitly excludes known key, credential, temporary and backup patterns.
Neither those files nor tenant data are copied to GitHub; the source archive
remains private on the VPS.

Before **any** complete Control Plane update, assess the candidate package with
`python3 ops/source-reconciliation/tours-dev-20261118/check_live_source_parity.py
--inventory ops/source-reconciliation/tours-dev-20261118/full-runtime-source-hashes.json
--candidate wp-content/plugins/mad4b-site-control-plane`.

This is an exact source continuity *preflight*, not a new authorization gate or
a substitute for release CI. The 995-file original reference returns 665 exact,
166 differing and 164 missing against unmerged `master`; therefore a direct
`master` plugin replacement is **not proven safe**. A hash-paired replacement or
retirement requires an independent reviewed decision, and even a successful
comparison explicitly reports `release_authorized=false`.

Live source may change during reconciliation. The first post-capture readback
observed 990/995 identical, **four intentionally absent Host Media providers**
and a newer Host Agent bridge. Preserve the original captured files and the
subsequent signed-off source snapshot separately; never silently rewrite a
frozen inventory or resurrect removed optional providers. Updated Host Agent
code and four absence receipts are preserved in a second root-only supplemental
capture. They require separate company-scope and signed-Host security review.

All local validations and package preflights here are read-only and do not
change the installed WordPress plugin, company site data, or release grants.
