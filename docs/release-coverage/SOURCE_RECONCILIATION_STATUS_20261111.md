# MAD4B full WordPress source reconcile — 11 November 2026

## Source-only integration result

This local Git integration source is not an approved or installed platform release.

- Original WordPress composite base includes PR #797 and PR #787 histories.
- Tenant Sync PR #798 and Original313 PR #800 exact-blob additions were staged without replacing files that already existed; the signed 313 active Worker remained unchanged.
- 19 previously absent live source files (17 Portfolio Manager and 2 Control Plane regression fixtures) were copied into the local Git integration branch as add-only, after verifying source SHA, file size, type, ownership and basic secret indicators. The exact-sha manifest lives at portfolio-live-additive-20261111.json.
- Six later-on-live Control Plane PHP changes have been merged against rc128 file content into the integration branch, while preserving declared classes and methods. The exact source, base and result SHA hashes live in control-plane-rc128-three-way-20261111.json.
- Company sender policy, company communication, email routing and Google Drive context were not automatically merged. The Google Drive live file would remove an existing named method relative to the rc129 candidate.
- 38 focused Python source tests passed. The 17 added PHP files and six three-way source PHP files passed syntax lint. JavaScript syntax check passed. Offline reconnect runtime fixture succeeded both with certified adapter 0.6.1 and a temporary fixture using 0.7.1. The separate session-safe runtime diagnostic requiring a missing external service did not run successfully and is not claimed as accepted.

## Snapshot of code parity against Tours.dev filesystem

The code-parity readback compares files ending with .php, .js, or .css; it does not compare data, WordPress options, loaded classes, binary or JSON artifacts.

| Plugin | Files live | Same source SHA | Divergent source SHA | Live paths missing in candidate |
| --- | ---: | ---: | ---: | ---: |
| Control Plane | 779 | 733 | 46 | 0 |
| Company Portfolio Manager | 73 | 57 | 16 | 0 |
| Tenant Sync | 26 | 26 | 0 | 0 |

The integration candidate contains additional plugin source files that are not deployed on Tours.dev. Adding code to source does not imply it is included by the current bootstrap, connected to WordPress, or safe for activation. See ACTIVE_CODE_PARITY_20261111.json for file-level discrepancies.

## HOLD / Required closure

1. Resolve 46 Control Plane and 16 Portfolio source differences by module ownership, semantic behavior and signed release policy; preserve verified rc129 advancements.
2. Verify Portfolio bootstrap requires/includes each newly added class correctly; no automatic authority registration or implicit grants.
3. Validate latest Original313 licensed and receipt-only Worker against current exact live SHA; do not run stale provider promotion or misrepresent Original313 proof.
4. Recover separate Lifecycle, Content Hub, Platform and Render Governor live source paths and tests; preserve company overrides and runtime site identity.
5. Complete Original318 governed publication via approved release channel, first Anchor issue/revoke ledger, independent revision/high-water and genuine signed Original313 readback.
6. Full WordPress staging acceptance and target company-specific regression checks remain unexecuted for this merged release. No Production rollout.

**GLOBAL RELEASE HOLD.** No site content, WordPress runtime, Company Registry, Tenant Options, Grants, Jobs or Production code were modified by this integration.

## 2026-11-11 additional Portfolio Bootstrap and read-only ownership reconciliation

- The added 10 native Portfolio Manager class files were previously unreferenced by the PR #799 candidate bootstrap. The reconciled source now loads their guarded classes and registers existing site-operation boot callback once, following the live functional load order on Staging.
- The legacy hardcoded Wayfatours Production MU owner launcher remains intentionally **unregistered**. That class embeds a fixed site UUID, a fixed Tours.dev host, a fixed SHA and a fixed company route. Do not generalize its authorization by removing one if-condition; replace the fixed input set with verified dynamic operation catalog and exact signed provider scope before registration.
- Production-specific owner-approval panel from the live plugin is also not blindly merged: it hardcodes All Royal Egypt and changes production-delegation semantics. Preserving currently authorized company identities and explicit rights takes precedence over raw file parity.
- Native provider registration does not itself issue signed Original 305/312/313 proof; the standalone provider hook harness proved source class loading and **zero** WordPress writes in an isolated PHP container.
- Presentation Ownership and Start Company Readiness now include all read-only fields/JetEngine/host-topology readiness alerts added in the live source. Exact source differences were additive-only, 67 extra lines, no original content removed. Brand copy stays company-target-owned and WPML-localized; readiness cannot claim signed target proof.
- The stale fixed 24-field acceptance assertion was replaced with semantic owner-field assertions so new valid brand fields do not cause false regression failures.
- None of these source additions, alone, certify source runtime compatibility on Production or activate Tenant or Company operations. The current shared platform rollout HOLD remains in force.
