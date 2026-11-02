# MAD4B synchronous translation provider contract (v1)

This is a generic capability for *all enrolled WordPress sites/companies*, not a per-site URL/ID switch. Installed and exercised first on ETG Staging. The active ChatGPT MCP session is always the preferred execution provider; WordPress does not invoke the ChatGPT conversation model on its own.

## WordPress Abilities

- `mad4b/translation-provider-plan` (read): `{"issue_id":"<64 lowercase hex>"}`.
- `mad4b/translation-provider-dispatch` (governed write): same input. In an **authenticated live MCP request** returns `ACTIVE_CLIENT_HANDOFF` with the source/job/source SHA so the ChatGPT client can translate and call governed WPML writeback in the same conversational task. This does **not** claim the WordPress backend invoked the model. Outside MCP, selects a code-registered authorized synchronous provider if one is connected, or returns `HOLD_PROVIDER_UNAVAILABLE`.

A CLI inspection command `wp mad4b translation-route plan --issue_id=<hash>` is read-only. It cannot fabricate an active MCP session; a WP-CLI call reports `not_in_current_request`. The `fixture` subcommand tests selection without network or database mutation.

## Server-owned priority order

1. `chatgpt_mcp_session` (priority 0), but ONLY if the exact current request passes authenticated MCP protocol context, connected WordPress user and Site Control Plane read policy. Never accept caller-supplied `mcp_session_active`.
2. Registered external synchronous API adapters ordered ascending by `priority()`, then `provider_id()`. WordPress plugins register audited adapter instances through `mad4b/translation/synchronous_adapters`. Each implements `MAD4B_Translation_Synchronous_Adapter`:
   - `provider_id(): string` — unique namespaced ID.
   - `priority(): int` — integer 1..100; lower precedes higher.
   - `preflight(array $job): array` — current provider and site state. Ready **only** with `site_uuid` matching enrolled company/site, and all six exact booleans: `connected`, `authorized`, `synchronous`, `budget_allowed`, `idempotency_enforced`, `language_supported`.
   - `translate(array $job): array` — synchronous bounded translation; receives immutable `idempotency_key`, `source_sha256` and source content; returns `{source_sha256, language, text}`. Its own adapter owns credential secret lookup, allowed outbound endpoint, token/cost budgets, provider request idempotency and timeout.
3. No eligible provider: `HOLD_PROVIDER_UNAVAILABLE`. Never queue a default translation/model task.

An external provider is NOT registered merely because `ai-provider-for-google`, `ai-provider-for-openai` or WPML Translation Management plugin is active. A certified adapter must prove actual connection, consent and budget. An external provider API does not inherit the ChatGPT OAuth/MCP credentials.

## Fallback rules and irreversible side effects

Safe failover only when the current provider is **known not invoked**: session not active, provider not connected/authorized, or the reviewed adapter explicitly returns `provider_unavailable_before_execution`. After a provider call could have run, failures, timeouts, account faults, invalid translation, changed source/revision or uncertain costs => HOLD for manual reconciliation; never blind retry or fail over to another billable API.

Before calling a connected external API, the router atomically claims a stable `mad4b_translation_attempt_<sha256>` WordPress option based on intent (source, issue ID, source SHA, provider), protected with a DB named lock. It stores only status/receipt hashes and does not retain credential values or full translated content. A second attempt with the same key does NOT execute again. `CLAIMED`, `UNKNOWN`, `HELD`, `INVALID_RESULT` and `PROPOSAL_RECEIVED` require an explicit owner reconciliation path. Only `NOT_STARTED` permits selection of another provider for the same request.

## Publication boundary

The router is source read + provider selection + optional external proposal. It does not grant editorial QA, WPML writeback or publication permission; the existing governed WPML and MAD4B Auto Qualification layers must separately validate/commit and run URL/Canonicals. The returned `TRANSLATION_PROPOSAL_RECEIVED` is a proposal, **not a published translation**.

## Current live acceptance

Verified on ETG Staging with the existing source-linked museum WPML issue `00644c9582cebb93cbd25d747ddbd8bf4864de19c0918e3917e017af796cbeea`. Test fixtures register only local in-process test adapters, not real accounts. PASS: ChatGPT first, authorized API fallback, no consent and no provider idempotency both held, duplicate adapters rejected, no default queue; no actual external request was made. The WordPress MCP connector returned internal errors during this session, so successful cross-transport ChatGPT handoff/writeback is not yet demonstrated. All Production changes remain out of scope until source PR review.
