# Staging Canonical v0.2 — Source-only release candidate

**Status: BLOCKED / NOT CERTIFIED / NOT DEPLOYABLE.** This is an auditable source review, not a request to install or merge. Target `https://tours.dev.mad4b.com` (WordPress environment `staging`) only. Never target Production.

## Included accepted source candidates

| Source in this review folder | Runtime destination after separately authorized release | SHA-256 of VPS-reviewed original |
| --- | --- | --- |
| `260-mad4b-hub-source-import-v02-reviewed.php` | `wp-content/mu-plugins/260-mad4b-hub-source-import.php` | `b8ad8f25cd9a8cdde09f3393a0c35c048f5f4b8fc1bea4d6fd8eb4f5d1dd6f73` |
| `282-mad4b-hub-canonical-gaps-v02-reviewed.php` | `wp-content/mu-plugins/282-mad4b-hub-canonical-gaps.php` | `4fd45a2c46b87be2a0872a59e6d8d3abb47fd6e3387a2241929d0b0d90090c71` |
| `class-mad4b-hub-review-ui-v02-reviewed.php` | `wp-content/plugins/mad4b-content-hub/includes/class-mad4b-hub-review-ui.php` | `c8988ecb37229cdf5d5c75c5fffa4c156e03b9b1c3ee6800534a25dd0511ce0f` |

Original VPS partial candidate: `/opt/staging/mad4b-canonical-v02-rebuilt-20261031/mad4b-canonical-v02-partial-reviewed-staging.tar.gz`.
Archive SHA-256: `f9c2f9f6a5f820693006135dd4e1055b4b6ddd509561b2972252e503cdfec8de`.

## Not included / blockers

- `284-mad4b-hub-property-identity-reviews.php` **is absent**: its attempted transfer previously failed security review. Do not recreate, install, or fetch it through another tool to bypass that denial. Independent source review and a permitted release intake must resolve it.
- `mad4b/plugin-package-plan` returned `mad4b_plugin_package_provider_uncertified`. Do not fabricate a certified provider/package, alter provider guards, or call Apply without an eligible exact plan.
- `tours-dev-existing-compose-adoption-draft` in Coolify is exited and is *not* a verified live deployment surface.
- GitHub merge, CI, WordPress activation, Canonical Sync, Queue mutation, external distribution and Production update are **not authorized** by this PR.
- The three reviewed files are stored under `ops/` for review; this PR **does not install them** at their runtime paths.

## Release sequence (once independently admitted)

1. Review code and source lineage against exact live Staging file hashes; resolve all discrepancies.
2. Review and admit a complete package, including module 284, using an authorized update/release workflow.
3. Verify Staging identity, make versioned file backups, preflight PHP/WordPress, and install via the approved installer; don't use root shell as a bypass.
4. Verify a fresh-request readback before calling native `MAD4B_Hub_Canonical_Gaps_V1::sync('tourism-almallahgroup')`.
5. Expected **only if source unchanged**: 1,414 optional nonblocking diagnostics and 711 evidence-required entries; review 72 native Canonical review pairs. Verify no optional Queue tasks have been recreated and invalid rates remain commerce-blocked.
6. Read back the second sync idempotently; rollback on failure. No Production mutations.

## Live evidence prior to this source-only review

- Staging: `https://tours.dev.mad4b.com`, WordPress environment `staging`.
- Old gap generator still active: 2,125 `needs_evidence` (597 property, 784 price, 31 inventory, 2 rate dates, 596 description, 115 identity hold).
- Optional WAITING_EXECUTOR: 0; Identity Review WAITING_EXECUTOR: 13.
- Full module 284 not installed.
- Three VPS-sourced files passed syntax checks and SHA-256 verification in isolation, not a complete runtime/integration test.
