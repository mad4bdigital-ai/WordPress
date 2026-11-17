# Central Administrator representation — Staging

The central WordPress Administrator is an **operator acting on behalf of the company**, not a substituted company manager, brand owner, or owner-signed legal agent.

## Resolution
- Bind exact enrolled `company_code` via `company_binding()`.
- Authenticate the original WordPress Administrator; never `wp_set_current_user` to impersonate another company user.
- Return verified company site UUID and binding SHA.
- Do not create Company Manager registrations, group memberships, site ownership or remote company write grants.

## Operations
- Scoped operational intents: company operations / strategy, content planning/update, SEO planning/update, provider reconciliation.
- Request requires exact binding SHA, original admin, purpose and stable event key; persists durable idempotent lifecycle event and a minimal digest-only audit record.
- Native provider receives the handoff through its existing governance; this request is **not** a substitute for the native Ability, verified company owner consent when required, or a successful mutation.
- Company owner approvals are not fabricated. Execution remains blocked if original provider rejects it.
- `PENDING_NATIVE_AUTHORIZATION` is a truthful state, not `VERIFIED`.

## Acceptance
- Verified Wayfatours binding and Administrator WordPress User #2.
- First request and repeated identical request return same event ID and show audit readback; binding SHA mismatch rejected.
- Anonymous identity and internal untrusted hook cannot forge central administrator representation.
- No remote company mutation executed.

Scope: `https://tours.dev.mad4b.com/`, DB `etg_commerce_staging`; Production unchanged.
