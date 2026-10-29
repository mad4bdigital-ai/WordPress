# QA38: Commerce Lifecycle Readiness — Woo Lab handoff

Date: 2026-10-28. Environment: ETG Woo Lab only, WordPress local, DB etg_woocommerce_lab.

## Installed behavior
- A read-only LifecycleReadiness inspector was installed in the existing Commerce Composition plugin, without a new pricing engine, registry, scheduler, webhook, or order writer.
- An operator-only REST GET endpoint at /commerce-composition/v1/request-reviews/{request_id}/lifecycle and a Request Inbox link provide a 12-gate state/owner/next-action matrix.
- Gates cover original submission, CPT product identity, Rate, operator review, FluentBoards assignment, final quote, manager approval, traveler acceptance, Woo order, supplier booking, event binding and accounting.
- Site adapters may supply bounded diagnostic observations, but cannot grant checkout, payment, supplier or accounting authority.
- General inquiry Form 7/8 qualification options now reuse server Rate eligibility to hide stopped, expired, cross-service and QA-only Rates before selection.

## Verified on VPS through WP-CLI
- Lifecycle tests: 17 of 17 passed (admin/reviewer/anonymous, REST, UI, data redaction, adapter cannot elevate, no mutation).
- Rate option tests: 10 of 10 passed (valid Rate, exclusion of stopped/foreign/fixture, REST/role boundary, rendered dropdown).
- All four plugin PHP files pass lint; source/deployed paths compare equal; local HTTP response 200.
- Business row counts unchanged by the tests: 9 Commerce requests, 3 FluentBoards tasks, 1 existing Woo Order and 0 qualification claims.

## Still blocked (not silently enabled)
- Final commercial Quote lacks complete age, cost, availability, tax/FX and supplier inventory certification.
- Independent manager price approval, first-party traveler acceptance and certified order/booking writes are not implemented.
- Native tasks remain unassigned until an authorized owner is chosen; one previously failed Workflow Job requires source-claim reconciliation, not replay.
- Event Bridge site-binding HMAC is mismatched. Credentials and tenant identity require approved remediation.
- CPT Woo mapping does not yet enable properties/transportations. Existing legacy operator permissions require a dependency-aware RBAC migration.
- Payment gateways are disabled, WP ERP not active and accounting posting has no certified provider.

## Source ownership and rollback
The actual code and scoped unified patch are retained on the VPS at /opt/commerce-components/evidence/etg-lifecycle-gates-20261028/QA38-Commerce-Lifecycle-Readiness.patch.
Patch SHA256: 2b6eef8bff31ea54b6d7815b6c5cf8d762ac03e3eea97bab056f0b71f260feaa.
New class SHA256: 65a17f2d5eccf3797dffb5404e9d4c017b40c24b592132a5e28218a06a0721de.
Backups are stored in the same VPS evidence directory. These notes do not constitute a deployable plugin source: full Commerce Composition source has not been onboarded under an authorized GitHub release owner.

## Acceptance gates for a multi-tenant release
1. Import the complete Commerce Composition source under a reviewed repository and create a product release manifest.
2. Run provider read-only certification per tenant with Form IDs, CPT mapping, rates, language and site identity resolved from its own Site Profile.
3. Route staff tasks only after confirming actual authorized assignees, and reconcile preexisting failed jobs with original source fingerprint.
4. Keep financial writes closed until final quote receipt, manager action, traveler acceptance, payment/order policy, supplier confirmation contract and rollback/reconciliation tests are independently certified.
5. Use isolated Staging rollout and explicit release approval. No Production mutation was made in this QA.
