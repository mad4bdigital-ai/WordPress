# Wayfatours — Elementor Copy Coverage → MAD4B Content Readiness

**Status: review-only proposal. Production validator and admission policy are unchanged.**

## Problem

The native Copy Coverage scanner already runs on Wayfatours via a 30-minute, out-of-request WP-CLI/systemd scan and currently reports zero issues under its existing rules. However, Wayfatours Content Readiness does not admit `mad4b-elementor-copy-coverage` as a trusted, bounded advisory source. Tours.dev Staging already has this bounded admission and its producer; synthetic in-memory tests there proved a positive finding enters Content Readiness without persisting a fabricated issue.

## Proposed changes

1. Review and apply `content-readiness-validator.patch` to the **correct Wayfatours control-plane PHP file** only through the approved company release workflow. The patch is relative to the VPS's Wayfatours production snapshot on 2026-11-08, not to this repository's root. Verify the target source/version before applying and resolve any drift rather than applying blindly.
2. Once the contract is approved, deploy `mad4b-elementor-copy-readiness-producer.php` into the company's approved MU-plugin release manifest. Merely merging this proposal document **does not deploy or activate anything**.
3. Verify on staging or a permitted test target before Wayfatours Production; retain a rollback copy of the original validator and MU-plugin state.
4. On the authorized production release, verify a synthetic finding **in memory only**, then confirm that the snapshot's real issue count is zero and that no fabricated records were persisted.

## Security and behavior boundaries

- Accept only source `mad4b-elementor-copy-coverage` with precisely enumerated issue codes and languages.
- Only published `elementor_library` objects are eligible; require the exact expected fingerprint, label, severity, and priority.
- The producer reads **saved scan output only**. It does not scan on frontend requests, publish or translate content, create jobs, change SEO metadata or change any authorization, broker or execution policy.
- Existing sources, rejections and role protections remain in place.
- Wayfatours Production write admission was not available through the connected governed WordPress operations when prepared. Do not use this PR as permission to bypass that constraint.

## Validation evidence (2026-11-08)

- Tours.dev: source admission + producer registered; read-only synthetic in-memory finding accepted by `MAD4B_SCP_Content_Readiness_Alerts::discover()`, which reported `complete=true`.
- Wayfatours: proposed full PHP validator and producer both passed `php -l` **in the Wayfatours PHP container**, without editing the live validator.
- Live Copy Coverage: 909 Elementor records on Tours.dev, 387 on Wayfatours, zero actionable copy issues under current rules.
- Both sites: zero Hero Readiness alerts, including mobile/Elementor/WPML title and subtitle parity.
- Scheduled scanner: `mad4b-elementor-copy-coverage.timer`, every 30 minutes; `Result=success` on its first observed run.
- Previous reports and rollback evidence on the VPS under `/opt/mad4b-company-provisioning/reports/elementor-content-coverage-20261108/`.

## Acceptance criteria for authorized deployment

- `php -l` passes both affected files; deployment log has explicit target and rollback path.
- After deployment, source producer is registered with `has_filter('mad4b/content_readiness/external_issues','mad4b_copy_coverage_readiness_advisories')`.
- Readiness scan with a **temporary in-memory** valid fixture accepts exactly one expected advisory, and an invalid code, locale, priority or unpublished template is rejected.
- `MAD4B_SCP_Content_Readiness_Alerts::discover()` returns `complete=true` on the actual site's clean snapshot.
- Homepage, Spanish homepage and tours archive remain HTTP 200; filtering, H1 and subtitle parity remain unchanged.
- No real issue or job is created during the fixture test; rollback restores original validator and removes the producer if checks fail.

## Deployment state

**NOT APPLIED TO WAYFATOURS PRODUCTION.** This branch and PR serve as a review and approval handoff only.
