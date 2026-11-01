# Central Local Git acceptance — 2026-10-31

## Verified operational canary on central Staging

- Central WordPress origin: `https://tours.dev.mad4b.com` (Staging).
- Bounded, inert test plugin: `mad4b-deploy-canary/mad4b-deploy-canary.php` (installed, inactive, no hooks, version 0.1.0).
- Root-owned registered Local Git commit: `28125a224b57b85ff0dd465c492e45151b24076a`.
- Artifact SHA-256: `a8e26c75fae168099f83bd97d8f7589574aaff1f69559f7d82610b5e995d3a4a`.
- Source/installed main PHP SHA-256: `bcedbc16ba01eb228cd1d2a617e3b926d0b17d0fbeb37c7d54eaaa10f4015c80`.
- File manifest SHA-256: `9bcbfc2f13ce54d5d16ba1e54ee7a264c5ee63fde4fe9ae46fd7528a9ddd3dfa`.
- Host executor code path: `/opt/mad4b/operations/central-release/local-git-staging-canary-executor.py`.
- Source registry: `/opt/staging/mad4b-local-git-canary-20261031/source`, exact Git HEAD, read-only at runtime except host-owned provisioning.
- The bounded executor performs real WP-CLI install → independent file/version/activation readback → plugin delete/absence check → reinstall exact artifact → independent readback → root-key RSA-SHA256 signing.
- A signed record exists in the protected central journal and was successfully independently verified by `MAD4B_SCP_Central_Release_Registry::verified_readback()` inside WordPress. Public REST endpoint returned HTTP 200 for that exact SHA.
- A repeated attempt to execute the one-time canary correctly returned `Canary plugin already installed; never overwrite`. This is not a failed install readback; do not repeat the operation or overwrite the signed journal.
- Canary is never approved for company Production. An explicit `rollout_scope=company_production` is mandatory for commercial plans; the canary's older immutable signed record omits this scope and therefore fails closed.

## Real private Content Hub source baseline (not released)

- Source: exact file-for-file snapshot of the active `mad4b-content-hub` plugin from the central Staging webroot, 50 files, 48 PHP syntax checks PASS.
- Local Git repo: `/opt/mad4b/operations/central-release/local-git-sources/mad4b-content-hub`
- Exact source commit: `03b2c26d27ef9d74e59b492b06c900ee0e741d0e`.
- ZIP artifact: `/opt/staging/mad4b-content-hub-local-git-artifact-20261031/mad4b-content-hub-03b2c26d27ef9d74e59b492b06c900ee0e741d0e.zip`.
- ZIP SHA-256: `cb22e6eb0703c807390b416642c9ce1f050194f44d5584372b48ccc43fd4d550`.
- Manifest SHA-256: `92cc4a3b7dd001718eb3f19084bb56a5f1fd294e7575cfd2355d6cb6432982e6`.
- Host source registry sets `release_state=baseline_only_not_production_released`, `production_promotion_allowed=false`, `package_certification_required=false`.
- This was **file parity and packaging only**, not Content Hub install/smoke/rollback acceptance. No Content Hub release receipt was signed and no company update was authorized.

## Wayfatours Production preflight (read only)

- Live Production origin: `https://wayfatours.mad4b.com` (WordPress-enrolled `production`).
- WordPress 7.1.3, PHP 8.5.12; WooCommerce active.
- The installed Content Hub plugin is inactive, version `0.1.0-dev`, with **3 files** vs the central source's **50**. Two files match byte-for-byte, one differs and 47 are absent.
- Its WordPress HTTP API successfully fetched the signed canary readback from central Staging with status 200. The company plugin currently has **no pinned central public key and no central rollout ability**.
- The central Staging assessor was exercised with the Production site's measured profile and genuine signed canary readback. It returned `company_staging_required=false`, `production_mutation_authorized=false`, and blocker `central_release_promotion_scope_not_granted`. This was a read-only staged compatibility evaluation, **not** an authorized Production update.
- Default WP-CLI with the current 256 MB limit failed to bootstrap this Production WordPress. A command-local 512 MB PHP memory override enabled the read-only inventory; site PHP configuration was not modified.

## Not yet complete

1. A generic registered private-source executor capable of safe full plugin upgrades (and separately MU plugin updates) with backup, active state preservation, readback and rollback is still required. The validated Staging canary executor supports **only** its one inert plugin.
2. Content Hub needs a real full-artifact installation/smoke/rollback acceptance on central Staging before issuing a `company_production` release receipt.
3. Site Control Plane Production automation must consume trusted central readbacks and exact target plans through a separate owner-authorized company deployment; its legacy `test_attestation` requirement is still enforced.
4. Per-company staging is optional in the new planning model but is **not globally disabled** in the old release pipeline. Never claim this policy is fully migrated.
5. The source PR is still Draft. Existing Release Verdict and governance checks must pass before broad deployment; no Production mutation has been performed.
