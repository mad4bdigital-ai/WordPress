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
