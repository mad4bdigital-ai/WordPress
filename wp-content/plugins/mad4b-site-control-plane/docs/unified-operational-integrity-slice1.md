# MAD4B Unified Operational Integrity — WP Dedicated Slice 1

This code slice integrates existing Site Profile, Context Authority and Content Jobs. It **does not** establish a new grant or automatically publish content.

## Enforced contracts

- Derive scope from `MAD4B_SCP_Deployment_Mode_Resolver`, rejecting missing enrollment, origin/deployment drift and untrusted client-supplied tenant/brand.
- Preserve existing `brand_id` during display-name edits; brand transfer and multi-brand activation require an explicit governed migration, not a rename.
- Quarantine sources without a matching site and brand and refuse source ID overwrites if an existing source is owned by another/unverified brand. Prevent cross-source reading through the existing provider gateway that consumes Context Authority.
- List and load Content Jobs by both site and brand. Reject creation when caller-supplied brand differs from verified Brand Profile. Keep DB schema and existing job identifiers unchanged.
- A read-only Dedicated status call never initiates legacy Site Profile bootstrap migration when a stored v2 option is absent. Enrollment/migration is a separate operator action.

## Intentional fail-closed and migration impacts

- Existing Content Jobs or Context Sources with no valid Brand Context or no enrolled Deployment Binding are hidden/blocked rather than silently assigned to an arbitrary brand.
- Legacy/source records missing `brand_id` remain physically untouched but are inaccessible until an explicit migration with ownership evidence. The older source hash is preserved and collisions reject instead of assigning ownership.
- This slice now fences every Context Authority registry mutation at the common registry lock boundary, with initial Brand Profile enrollment/rename explicitly exempt. Other plugin write surfaces remain independent: workflow executors, asynchronous claims, unrelated plugin management, and custom direct operations require a separate review of their existing policy guards. The status Ability is added to the dedicated read server inventory; actual MCP discovery still needs live readback.
- This slice does not certify production readiness, source rights, external signatures, or WordPress Multisite switching under real traffic.

## Native acceptance

`php -l` on all changed PHP files; `php tests/deployment-mode-resolver-runtime.php` and `php tests/unified-operational-scope-runtime.php` on an exact checkout; the cross-repository compatibility checker; then a non-destructive Staging canary with one enrolled site and two synthetic Brands. Existing operator workflows require migration before sources with unknown ownership are reused.

## Integration closure ledger — 10 October 2026

This note is **source implementation evidence, not live certification**. Existing
`shared_multi_tenant`, `dedicated_isolated`, `dedicated_autonomous` and
`wordpress_dedicated` modes remain distinct. The plugin does not replace or
implicitly select a portable Context Authority deployment mode.

### Integrated source-level hardening

- Context source/asset projections now reject contradictory optional stored
  Tenant/Network/Blog/Environment/Deployment metadata as well as mandatory
  Site UUID and Brand ID mismatches. Missing legacy ownership stays quarantined.
- `MAD4B_SCP_Context_Authority::legacy_reconciliation_census()` is an
  administrator-read-only aggregate with a pre/post Operational Integrity
  checkpoint. It never emits raw record content or performs owner reassignment,
  migration, deletion or a new grant.
- Activity Sync binds approved plans and durable operation journals to a
  trusted operational fingerprint, not solely a mutable Site UUID/Profile slug.
  Direct WP metadata writes and external provider writes require a fresh current
  checkpoint plus effective mutation permission. If identity changes after
  a remote effect, its journal remains `needs_reconcile`; blind retries remain
  forbidden. Pre-existing journals without the new fingerprint require
  separately reviewed legacy reconciliation, not automatic adoption.
- The synthetic acceptance suite includes wrong-tenant/site/brand/multisite
  metadata, switched actor/revision, revoked mutation authority, read-only legacy
  census, ContentJob transactional failures, and provider failure/recovery cases.
  A PHP 7.4/8.3 GitHub Actions matrix invokes these fixtures; the presence of
  this job is **not** evidence that any run succeeded.
- The exact-head offline runner deliberately separates native tests from the
  cross-repository deployment audit; it never grants Production promotion.

### External acceptance still mandatory (not performed by a source commit)

| Gate | Required proof | State |
| --- | --- | --- |
| Cross-repository Context Authority | Pair `#258` with reviewed `#8483` manifest v0.6.2, four-mode compatibility and exact independent seed tests | NOT VERIFIED |
| WordPress/PHP/MySQL | Run exact-head PHP 7.4/8.3 suites and real transaction/parallel-worker races, including crash-after-COMMIT | NOT VERIFIED |
| Legacy remediation | Take backup, compare original row hashes, record human owner approval for each orphan, apply CAS on disposable Staging and independently read back | NOT EXECUTED |
| MCP | Observe `mad4b/deployment-mode-status` discovery, per-user permission and bounded execution on deployed exact candidate | NOT VERIFIED |
| Async providers | Revoke actor/brand mid-job; test idempotent external writes, durable unknown-effect recovery and Drive concurrent edits | NOT VERIFIED |
| Multisite | Switch Blogs/Networks across request, worker, cache and database connections; prove isolation and restoration | NOT VERIFIED |
| Trust & replay | Independently verify provider-signed evidence, rotating trust roots and atomic replay reservations | NOT VERIFIED |
| Browser/setup | Validate RTL/locale, operator recovery steps and UI accessibility on a live enrolled Staging site | NOT VERIFIED |
| Privacy & performance | Measure queries, memory and worker throughput; retention/export/erasure on a disposable dataset | NOT VERIFIED |
| Package rollback | Produce deterministic ZIP from a frozen SHA; verify manifest/SBOM/adapter identity and rehearse deploy-rollback-redeploy | NOT EXECUTED |
| Production | Separate owner authorization plus complete external release gates | NOT AUTHORIZED |

### Safe offline native preflight

With a clean checkout at the reviewed **current** PR head:

```sh
HEAD="$(git rev-parse HEAD)"
python3 tools/run_f007_exact_head_local_acceptance.py --expected-head "$HEAD"
```

A `native_gate=PASS` means only that the listed local fixtures completed.
For the cross-repository dependency audit, provide **both** pinned repositories
and an independently verified exact candidate ZIP to
`tests/deployment-mode-dependencies-contract.py`. Never substitute a static
source check for that separate release gate.

### Safe release rule

Keep #258 Draft until the gates above have real, immutable and independent
readback receipts for the same exact candidate. Do not build or install the final
ZIP, merge to `master`, grant breakglass or promote Production from this report.
