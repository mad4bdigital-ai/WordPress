# MAD4B native Local Git release execution — live acceptance, 2026-10-31

## What was executed on the central VPS

This is a host-owned, exact-source implementation, not an additional MAD4B package certificate.

### 1. Full installed Content Hub plugin (same-artifact staging replacement)

- Central WordPress site: `https://tours.dev.mad4b.com`; runtime remains Staging.
- Source registry: `/opt/mad4b/operations/central-release/local-git-sources/mad4b-content-hub`; clean Git HEAD `03b2c26d27ef9d74e59b492b06c900ee0e741d0e`.
- Bounded source executor: `/opt/mad4b/operations/central-release/content-hub-local-git-staging-cycle.py`.
- Artifact: `cb22e6eb0703c807390b416642c9ce1f050194f44d5584372b48ccc43fd4d550` (SHA-256).
- Manifest SHA: `92cc4a3b7dd001718eb3f19084bb56a5f1fd294e7575cfd2355d6cb6432982e6`, 50 plugin files, 48 PHP files syntax checked.
- Source-only preflight: PASS, no drift, active WordPress plugin and unchanged site role.
- Host journal record: `central-release-readbacks/cb22e6eb0703c807390b416642c9ce1f050194f44d5584372b48ccc43fd4d550.json`. Signed by the central root-only RSA-3072 key and independently accepted by `MAD4B_SCP_Central_Release_Registry::verified_readback()` in WordPress.
- Journal checks: `install=true`, `smoke=true`, `rollback=true`, `staging_pass=true`, `rollout_scope=staging_verified_only`.
- Independent readback: the host-owned backup and live plugin matched 50/50; installed Content Hub remained active `0.1.0-dev`; central frontend and signed readback API both HTTP 200.
- An additional execution attempt was refused as `receipt already exists - refuse replay`, so the immutable signed journal was not overwritten.
- Root-only backup retained at `/opt/staging/mad4b-content-hub-native-upgrade-20261031/original-plugin`.

**What the test does not prove:** compatibility of any changed future Content Hub source, schema migration/rollback, company rollout or MU modules. The source file declares itself a **Central Controller** and gates functionality to Staging with the central-host secret. It must not be copied as a company Production controller.

### 2. Independent MU plugin lifecycle

- Separate host executor: `/opt/mad4b/operations/central-release/mu-local-git-staging-canary.py`.
- Local Git test source commit: `95489efe07956ea60f5011451a6f51dde33ce7e4`.
- Test file: `999-mad4b-native-mu-git-canary.php`; inert (no business hooks).
- Actual Staging WordPress acceptance: install → WP MU plugin inventory/file hash check → frontend HTTP 200 → remove and verify absent → re-install and verify → final remove and verify absent.
- Final acceptance: `/opt/staging/mad4b-local-git-mu-canary-20261031/acceptance.json`; `install/readback/smoke/rollback/reinstall/cleanup=PASS`.
- No MU canary remains installed. No existing commercial MU module was replaced or changed.
- This acceptance remains `staging_mu_test_only` and does not promote a MU plugin to Production.

## Required distribution split

The original `mad4b-content-hub/mad4b-content-hub.php` main plugin requires the central Staging environment and a host-bound central identity. It is **not** a company-distributable artifact. The new `MAD4B_SCP_Central_Company_Rollout::assess()` therefore requires a signed requirement with a recognized `deployment_role=company_adapter|company_plugin`, and explicitly denies `mad4b-content-hub/mad4b-content-hub.php`, even if another `rollout_scope` is presented.

`mad4b-tenant-sync` is a separate tenant-facing component; its files are currently identical between the central Staging site and Wayfatours Production (17/17) and it is inactive on Wayfatours. `mad4b-jetengine-hub-addon` is active on Wayfatours with its declared JetEngine and Control Plane dependencies active, but the local file trees differ (8 Staging vs 7 Production, with two changed and one missing). A source-diff review was **blocked by a tool security check**; no alternate tool route was used to circumvent it, and no Production change was made.

## Still required for commercial multi-company rollout

1. Promote a **company adapter** or company-specific plugin as an immutable and independently tested Staging artifact. Do not distribute the central controller.
2. Build an exact-source registered executor suitable for upgrading *existing* company plugins, with tenant identity binding, backups, activation-state preservation, concurrency lock, safe dependency checks, exact installed-manifest readback and rollback. The working Content Hub executor is fixed to one staging site and one exact commit; it is not yet a generic commercial installer.
3. Add managed existing-MU-file updates with exact path allowlisting, host-managed backup and restoration. The current tested MU route is an inert install/rollback/cleanup canary only.
4. Connect the company-specific, owner-authorized Production native batch to a pinned, independently verified `company_production` release receipt and a compatible registered company source. Existing signed test-attestation requirements remain in the older Production automation until a tested alternative is integrated.
5. Close required CI gates and perform a real company adapter end-to-end acceptance before changing Production. Per-company Staging must remain optional; a central Staging PASS is not sufficient to ignore company-specific compatibility.

## Protected scope

No Production write, customer content migration, WordPress database mutation request, package certification spoofing, upload of the earlier security-denied module `284`, or removal of company Staging environments has occurred under this release.
