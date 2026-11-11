# Commerce Composition — additive advance/requests lifecycle source recovery

Date: 2026-11-11. Isolated Git candidate; no WordPress deployments.

## Source reconciliation

Current Tours.dev Commerce Composition has eight executable PHP/JS files absent from the full Git candidate. All eight are imported byte-exact and reviewed against the local plugin source map: accommodation-policy.js, AccommodationPolicyComponent, CompanyLegalRules, CompanyPolicyLibrary, RequestAdvanceAccounting, RequestAdvancePayments, RequestLifecycle and SingleFluentPaymentRouter.

The three code paths that integrate queued workflow execution (WorkflowJobs, WorkflowActions and FluentBoardsTaskBridge) are replaced by the corresponding exact live PHP sources after code/contract review. They provide request lifecycle job validation, source Form submission ownership, Woo advance order binding, mail idempotency receipts, and one native Fluent Boards task per original request; the new task bridge expects the source-owned Early Payment Benefits claimState/boardLinked API, which must be available before any runtime deployment.

The plugin entrypoint is intentionally NOT overwritten by the live site. It includes an exact additive bootstrap for CompanyPolicyLibrary, RequestAdvancePayments, RequestAdvanceAccounting, RequestLifecycle and SingleFluentPaymentRouter, while preserving all existing QA modules and bootstrap calls for CommercialQuotePlan, CommercialQuoteReadiness, CommercialFinalQuoteLifecycle, NativeCptOrderItemIdentity, PropertyUnitSourceCertification and CommercialExternalChargeGate. Forty-nine other candidate-only source paths were not deleted. No published commercial quote authority is inferred.

Every reviewed path has source SHA-256, Git blob and (for previously existing source) Git preimage digest in commerce-composition-additive-reconciliation-20261111.json.

## Acceptance and open gaps

- Eleven PHP entrypoints and imported classes passed PHP CLI syntax validation.
- accommodation-policy.js passed Node syntax validation.
- Four new source/provenance tests passed; 147/147 Python source tests passed at this checkpoint.
- No Woo order, SQL, WordPress option, Form6 submission, email, Stripe charge, background job or site code was changed.

Integration is NOT complete: the live source depends on MU Early Payment Benefits and WooCommerce email renderer/template, presently absent from the Git candidate. Additional 26+ existing file semantic differences in Commerce Composition remain unreconciled; a wholesale clone would lose candidate-only pricing and Final Quote modules. Do not turn on background lifecycle processing until the complete module/permissions/template dependency chain is checked and a valid source/release authority exists. Global release remains HOLD.
