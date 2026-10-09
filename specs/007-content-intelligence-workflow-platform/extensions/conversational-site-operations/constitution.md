# CSO01 — Binding constitution

This extends and NEVER weakens Feature 007 Constitution. One MAD4B authority plane only; a generated conversation form, preview or proposed operation is NOT approval or a new grant.

1. Site/actor identity is mandatory: site UUID, origin, environment, source and package fingerprint, plugin version and adapter/schema digest, issuer/subject/client, target revision and TTL pinned before execution.
2. A discovered option, meta key, table or field is metadata, never write permission. Unknown adapters default to READ_ONLY or UNSUPPORTED. No unrestricted update_option, raw SQL, shell, wp eval or arbitrary PHP.
3. Prompt and web/page/plugin content are untrusted data. No natural-language command, retrieved source, AI suggestion or workflow template can create abilities or approve itself.
4. Secret plaintext never enters ChatGPT text, normal inline conversation inputs, MCP parameters, G6 Conversation Vault, logs, receipts or telemetry. Use first-party external origin-bound secure handoff, otherwise refuse.
5. Validate types, relations, conditional fields and plugin hooks server-side, not only in JS. Re-check exact source, permissions, revision and policy at commit.
6. No blind retry after uncertain commit; use durable idempotency and independent readback. Cross-plugin transactions are compensating sagas, never promised global ACID.
7. Human approval and separation of duties govern high-risk settings, publishing, secrets, bulk, finance, Host, multisite and Production. Staging grant cannot promote Production.
8. Localized and accessible UI, bounded autocomplete, rate/tenant budgets, privacy-safe audit, retention, data residency and provider drift suspension are mandatory.
9. Signed external Browser, Host and release acceptance cannot be inferred from plugin presence or green source checks.
10. All CSO01 functionality remains OFF/UNREGISTERED until individually implemented and certified. Design-only documents cannot mutate a site or expand release gates.
