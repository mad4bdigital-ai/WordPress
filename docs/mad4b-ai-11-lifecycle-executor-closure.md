# MAD4B AI — 11 lifecycle domain executor closure (Local Lab, 25 Oct 2026)

## Actual runtime
Existing AI Review Lifecycle has 11 semantic profiles but only Brand Context previously had an installed original authenticated review endpoint. A planner result of review_queued is not an approval or an operational executor.

The new AI Domain Executors module enables original-owner plugins to register exact, source-pinned PHP executors for five content-only domains: content_job, product_catalog, seo_metadata, localization, design_template. Each owner adapter implements descriptor, next_review, exact_evidence, submit_original_review, and verify_original_receipt. Its source file SHA-256 must equal the specific domain pin in MAD4B_AI_DOMAIN_CERTIFIED_ADAPTERS provided by the local host. Every submitted review is bound to original item ID, SHA-256, owner revision, and checked for a durable readback of the original owner's authenticated receipt. The exact source-file SHA-256, operator allowlist pin, owner class and owner method are revalidated on every invocation, not only once at registration; a changed deployment fails closed. A model response alone never authorizes any mutation.

Brand Context retains its existing authenticated review API and has priority in the scheduled worker. Once the Brand queue is idle, the worker can try at most one separately certified content owner; the adapter must be present and the real model and delegated Agent identity must pass preflight. The rotating cursor prevents one busy content domain starving others.

The five protected domains (search_profile, provider_configuration, approval_ticket, plugin_lifecycle, cross_plugin) continue using their original OAuth/approval/ticket/plan/risk workflows. AI cannot auto-enable their grants, approve budgets, activate plugins, publish content or promote Production.

## Integration and visibility
A host-reviewed owner plugin hooks mad4b/ai/register_certified_domain_executors during plugin boot and invokes MAD4B_SCP_AI_Domain_Executors::register using its code-reviewed adapter. Client inputs, Bit Flows workflows and REST are not allowed to register adapters. Bit Flows may request one bounded review through the existing enrolled-admin REST route or WordPress action.

Settings > MAD4B AI Routing shows the closure statuses of all eleven domains. Read-only REST exposes a sanitized domain summary. The Local Lab host cron already runs the common worker every ten minutes and remains blocked from calling an external model until a real AI provider is configured.

## What is still blocked
Brand Context owns three exact approved required documents and keeps its original behavior. The five content-only domains have a dispatch API and strict original-owner adapter interface, but **no certified specialized owner adapter is installed yet**. Their automatic review is therefore not operational. The five protected domains intentionally do not auto-approve. The current Local Lab lacks an enabled credential-ready inference route, so no real model review was performed. This state should be reported as not ready, not "11 of 11 closed".

## Verification
The new deterministic mock-owner regression proves the eleven-profile matrix, refusal of privileged/Production registration, exact source-pinning, hash and owner-revision checks, disabled-model refusal, original owner receipt readback, no automatic publication, and bounded domain rotation. A successful mock is not evidence of a real adapter or model call. Existing Brand Context, Browser, provider, authority and other regressions remain required. No Staging/Production deployment.
