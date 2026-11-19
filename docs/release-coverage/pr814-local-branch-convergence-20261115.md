# MAD4B PR814 local branch convergence — 2026-11-15

Scope: read-only source review, local Git reconciliation and CI-only
regressions. **Not a signed Original313 release, PR merge, GitHub push,
Production promotion, Owner attestation, or fleet auto-deployment.**

## Source lineage

- Base: PR814 c2214ebb92bf9f007b9b2c82bff67c8dfdb5fa5f
- PR821 Brand Integrity reviewed head
  c914c7d6c3a9af29f77a26af8d7736e7f660711e, merged in
  aabe9cd381; its source detector and publication guards are retained
- PR813 latest reviewed Tour Journey head
  cd152e8f951e55c008980cb097f915141a83c562, merged in
  8ed2b3c5ff
- PR815 head 4c04a91ff79cd1f3507aae1f15ae51aa7648ed9a
  was already an ancestor, so it was not merged again
- Local Wayfatours-specific Content Readiness patch and exact historical
  live-canary SHA/rollback manifest were cherry-picked from original
  local-Git production maintenance commits, without replacing them
- The immutable PR808/PR813 historical review reports remain unchanged

## Intentional conflict resolution

1. Native destination template CAS migration retains safe PHP 7.4
   strpos behavior and backup-directory containment, not an older
   PHP 8-only str_contains/str_starts_with implementation
2. Native Tour Highlights Presentation retains **cacheable external**
   first-party Journey JS assets with exact UI dependency chain, matching
   live Tours.dev and Wayfatours; no duplicate inline frontend loader
3. Existing JetEngine cards and approved WPML/tour context remain the only
   data source. Default Split is company-scoped, not universally forced
   into unrelated business models
4. Retains plugin lifecycle hook guard and manual/assisted copy fallback
   when JetEngine's AJAX callback is empty, instead of restoring retired code
5. Preserves live-only four-line itinerary accent outline in Split CSS;
   the resulting CSS equals both live site files by SHA256
6. Source history is verified against immutable ancestor Git objects;
   eight successor files are separately pinned to exact source bytes
   and non-authorizing reviews. Historical manifests are not edited
   to match new source hashes

## CI modernization, with no alternate source of authority

- Packaging and Runtime Release Set now reuse the existing certified
  MCP Adapter Profile resolver in **CI-only** candidate mode, never the
  retired runtime-release-policy.target_adapter_version.
- The local standalone builder verifies its supplied ZIP against
  exactly one certified SHA256+byte-size Provider profile. Absent or
  ambiguous profile, corrupt ZIP, changed policy or source HEAD drift
  still block source building.
- Connection Governance tests read-only official MCP provenance and
  complete MU bootstrap pinning; no retired canonical_runtime verdict
  or unrequested privileged recovery.
- Adapter Coverage tests correctly mark unbound source-checkout
  repository evidence as not ready and non-authorizing.
- Unified Source Nonregression now invokes newer Journey, Brand,
  certified Adapter, CI trust, and local deterministic build tests.
- These are **source and fixture fixes**, not proof of the multi-service
  WordPress GitHub CI workflow; rerun CI on the published exact HEAD.

## Branch inventory and live-source boundaries

VPS read-only local Git inventory (not automatically merged):
/opt/mad4b-company-provisioning/backups/pr814-local-branch-audit-20261115.json

143 local branches: 3 already contained, 1 current integration branch,
**139 divergent**. Of the 139 divergent refs, 135 overlap source paths
changed in the integration and 107 carry paths outside the currently
integrated branch changes. They remain review candidates, not proof
that all remaining code should be reintroduced. Do not bulk-merge,
force-push or delete until semantic successor and source-owner review.

The deployed Brand Integrity detector, Tour Highlights Presentation PHP
and Split CSS equal the integration source on both sites. The WordPress
Servers PHP file differs on both; Wayfatours Content Readiness has
site-specific functionality absent in the source. Tour Highlights engine
source retains newer zero-touch / PHP 7.4 / manual fallback safeguards.

Private live readback snapshots:
/opt/mad4b-company-provisioning/backups/pr814-branch-convergence-20261115/
Do not overwrite active PHP files wholesale. Company-specific overlays
must be reconciled by fingerprints and independent readback.

## Evidence and unresolved holds

The local historical source verifier returned HISTORICAL_SOURCE_6_TESTS_PASS
but reported original_host_authority_verified=false,
production_authorized=false, release_authorized=false. GitHub Runner
lacked original ancestors during the previous check; a local PASS is
not a GitHub CI PASS.

The last clean-head Production Readiness assessment returned
**499 uncovered items** (399 P0; 100 P1) and FAIL_UNCOVERED_CHANGES.
Only five successor-specific reviewed code changes were accepted
for CI source coverage; all retained production_authorized=false.
The other 499 require independent evidence and reviewer attention.

High-risk unresolved successor:
wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-servers.php
contains materially different VPS-local CLI/transport admission from
older reviewed blobs. No fabricated reviewed_changes entry was created.

Release blockers remain exact-head Owner attestation, Original313 signed
site-specific publisher/receipt, runtime release-set pair admission,
Production Readiness, and complete GitHub Actions verification.
No Production deployment was initiated by this branch-convergence work.
