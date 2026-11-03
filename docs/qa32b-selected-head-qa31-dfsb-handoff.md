# QA32B Selected-HEAD + QA31 / DFSB RC15 — split-authority release handoff

**Scope:** Review-only extension of PR #589. No live plugin, registry, release, credential, order, database, or Production modification. This document describes three separate release/authorization planes, not one shared credential or an implicit staging grant.

## 1. Selected-HEAD continuation (All Royal Egypt Staging)

The existing PR #589 fix covers the WordPress-native `wordpress_native_candidate_upload` channel only. It obtains **verified master release** metadata by the fixed `fetch_manifest(true)` entrypoint, and promotes it only on equality of version, source SHA, ZIP SHA-256, build fingerprint, package-manifest digest and ZIP size, plus all required root trust booleans and a nonzero Release Verdict run ID. A caller's manifest or an orphan Git SHA cannot claim that authority.

**Still required:** exact runtime site identity, a fresh one-use owner approval, OAuth step-up, valid backup, guarded WordPress Upgrader continuation, integrity readback and rollback. A previous attempted approval ticket must not be reused. The Selected-HEAD target is **not** interchangeable with ETG Commerce Woo Lab.

## 2. QA31: ETG Commerce Woo Lab Control Plane

The actual Staging runtime at `https://tours.dev.mad4b.com` is `MAD4B Site Control Plane 0.4.0-rc.118`, while the main GitHub `master` Control Plane is `0.4.0-rc.104`. Installing the older source would be a downgrade. The published RC118 package manifest lists 877 files: 876 match their SHA256 and the main PHP loader has a **266-byte, exact three-line** Staging-only addition. In-memory exclusion reproduces the manifest main-file SHA256 `32d3aa84b15b0fa549c6dd8ffc131c2c292640e5b63991dab1a94f3f0e97cc00` from live `b67a6be43d4057d2061b980cc3abb0e2b2605c954a4fccabf38d6daa682ef4e3`.

The three included modules are **not part of the original package manifest**:
- `includes/class-mad4b-scp-company-tenant-migration.php`
- `includes/class-mad4b-scp-migration-decision-catalog.php`
- `includes/class-mad4b-scp-company-migration-decisions.php`

They exist on the inspected Staging site and passed PHP syntax checks. The new `tools/qa31_rc118_source_bridge.py` performs read-only exact manifest comparison, validates all three files and inclusion occurrences **inside the original Staging guard**, detects unexpected drift, and reports their local hashes. Status `EXPLAINED_UNCERTIFIED_SOURCE_DRIFT` is **not** approval, a new signed package, or proof that GitHub `master` is authoritative for RC118.

### Reproducible read-only inspection

```bash
python3 tools/qa31_rc118_source_bridge.py --plugin-root /path/to/installed/mad4b-site-control-plane
python3 -m unittest discover -s tests -p 'qa31_rc118_source_bridge_contract.py' -v
```

To genuinely reconcile QA31: establish a source-backed RC118 baseline (not a local RC119 draft or old RC104 code), review the precise addition of all three source files and guarded loader lines, build a **new immutable package/version** with source+file provenance, obtain independent release approval, and use a separately governed backup/readback/rollback Staging installation. Do not rewrite the prior signed manifest or remove the three live loader lines to force a green digest.

## 3. DFSB RC15 provider (a distinct package and authority)

Merged PR #580 pins `dynamic-filter-seo-bridge` reviewed candidate `0.4.0-alpha.15-rc.1` / exact source SHA `fbcfed264231a2553d197927617a9cdae67f5567` (signed **review** ZIP SHA-256 `bb7ab6bf995f477d075198e766ba7b41954a0b3f0eb5fb4bef906c5ee73d9b66`). Existing legacy `etg-dynamic-filter-seo-bridge` remains separately identified. The merged registry **does not** contain a certified provider record or certified ZIP fields, and the source is still review-only.

The current Woo Lab Control Plane registry still contains only the legacy source, while neutral DFSB `0.4.0-alpha.14-local.1` is installed with Safe Boot held because independent detached package provenance is not valid. Exact source discovery from merged GitHub data does **not** mean an eligible live Staging install.

The optional, non-authorizing `tools/qa32b_staging_release_fence.py` binds the QA31 read-only report to reviewed-vs-deployed source registry **without overwriting either**. It checks unchanged legacy identity, the exact neutral RC15 review-only pin, empty certified fields, and independently reported blockers. It always outputs `can_install_dfsb=false`, `safe_boot_override=false`, `selected_head_update_authorized=false`, and `production_authorized=false`.

```bash
python3 tools/qa32b_staging_release_fence.py \
  --control-plane-root /path/to/installed/mad4b-site-control-plane \
  --reviewed-source-registry wp-content/plugins/mad4b-site-control-plane/config/cross-plugin-sources.json
python3 -m unittest discover -s tests -p 'qa32b_staging_release_fence_contract.py' -v
```

The independent Provider certification remains [Issue #565](https://github.com/mad4bdigital-ai/WordPress/issues/565): privileged verification of Immutable Releases settings, durable signed release publication, independent digest-bound provider certificate, separately reviewed registry and certified-provider change, Staging-bound backup/readback and separate approval. **A signed review artifact is not an install certificate.** Full Boot and Production remain prohibited until separately authorized.

## Fail-closed PR acceptance and release gates

This PR may be reviewed and merged for source-level contracts when exact-head CI, current-master synchronization and owner attestation pass; the merge itself **cannot** install/upgrade Control Plane or DFSB. Automated hermetic regressions cover unexplained main loader drift, all three include files, guard location, manifest omissions, duplicate or changed registry rows and unreviewed certificate fields. A released installer must independently reject a source/version/package mismatch and must not reuse the Selected-HEAD evidence from an unrelated site or another provider.

Evidence is review guidance, not an artificial certificate. The next implementation requires a **separate authorized, signed and rollback-tested source release** to resolve the RC118 source gap before any site apply.
