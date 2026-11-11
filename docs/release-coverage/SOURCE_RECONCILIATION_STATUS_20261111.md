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
