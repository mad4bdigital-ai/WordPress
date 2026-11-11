# Control Plane / Company Manager — held source differences (2026-11-11)

## Verified low-risk read-only UI reconciliation

The original Performance admin view already contains private render_selective_cache_release() and render_css_strategy_status() methods. The live version calls both; the local composite did not. The two guarded display calls are now present in Git source only. The method bodies read WordPress options, cache-release evidence and CSS diagnostics. They do not activate the policies or bypass the explicit admin index-maintenance nonce. A three-test static contract checks invocation counts, method declarations and maintenance authorization. Live deployment has not been performed.

## High-priority conflicts deliberately NOT automatically merged

- Brand Context Builder: live relaxes the requirement for existing brand_owner_evidence. The rc129 candidate fails closed on missing ownership proof. Preserve the stricter behavior until a compatible site-bound evidence adapter is verified.
- Skills Adapter / Skill Abilities: live removes portions of candidate skills registration. Preserve the newer dynamic-skills lifecycle and independently test runtime registration.
- Execution Commit Guard, Authorization and Server Write Catalog: revised authority state machines and evidence contracts; do not overwrite with live code solely to achieve byte parity.
- Google Drive Context: the automatic three-way merge would remove the candidate scan_grant_fingerprint_v1 helper. Retain existing permissions and prove scanner readback separately.
- MCP Catalog Budget: the rc129 contract avoids dropping eligible Abilities due to a historical fixed tool-count ceiling.
- Company Communication, Sender and Email Routing: classes with identical names exist in Core and the Company Manager. The Core early fallback is conditional on Production without the Company Manager. The local Manager source now includes staging and production early hook ownership with guarded class declarations; real Production acceptance is still required before deployment. Administrative mail must not depend on company marketing permission.
- Company Native Plugin Install, Experience Owner Launch and Production approval panel: historical inventory reconciliation is not a fresh Original313 signed receipt; hardcoded company approvals must not silently grant write.
- Content Readiness Alerts: substantive pricing and event lifecycle additions require real owner validation, FluentBoards schema and independent readback.
- Firecrawl Provider: a new URL research entrypoint needs private-network, redirect and host allowlist verification before porting.
- Admin Query Performance UI: only the two existing display calls were ported; no maintenance action or cache configuration was enabled.

## Governance

Source review only. WordPress content, Pricing, SMTP credentials, company-specific overrides, runtime grants, Original318 v82 and Original313 signer remain untouched. The attempted offline behavior-test creation for the earlier five-file source batch was refused by the execution channel; PHP syntax/provenance verification is not a substitute. Global release is HOLD.
