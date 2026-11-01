# Adaptive Interaction & Recovery Broker — Local pilot (2026-10-25)

**Status:** Local source deployed to MAD4B Site Control Plane 0.4.0-rc.102, with independent fixtures and anonymous REST acceptance. **Not** Production, Staging or general-purpose credential onboarding certification.

## Problem and reuse

A ChatGPT/MCP caller may encounter a WordPress Ability error requiring a missing non-secret field, an exact original-owner approval, a provider connection or an API credential. The existing CSO01 schema/form validator, CSO Gateway, private CSO Secrets handoff, Provider Connection Center and error reason-code registry remain the owners of their respective contracts. This module adds *only* the connection between errors and user/agent interventions.

It is a **core module**, not a new WordPress plugin. Its native read/error hints do not grant an extra permission and do not cause a second attempt of the failed operation.

## Implemented in this pilot

1. Map a finite allowlist of canonical WP_Error codes to `WAITING_INPUT`, `WAITING_AUTH`, `WAITING_SECURE_HANDOFF`, `WAITING_PROVIDER`, `WAITING_APPROVAL` and `WAITING_CERTIFICATION`.
2. Enrich the existing `mad4b.error-response.v1` return with value-free `adaptive_interaction` metadata, so MCP agents discover the recovery path without parsing human-readable error strings.
3. Expose the existing WordPress site-owned REST namespace `mad4b/v1/adaptive-interaction/{plan,status,resolve,resume,fields}` and five corresponding discoverable Abilities. Bound every request to the current enrolled actor, Local environment and exact Site Profile. Only allowed managers enrolled in MAD4B can use it.
4. `plan`: persist a 10-minute, 192-bit random checkpoint containing only a recognized reason, selected provider, optional certified form Ability, site identity and actor ID; **never** passwords, tokens, keys, cookies or input values. Checkpoint storage is bounded metadata, not a write grant or original-operation journal.
5. `status`: recheck the actor/site/revision/expiry; re-evaluate the intervention. A guessed, replayed across actors, stale or unauthorized token is denied.
6. `resolve`: accept only **non-secret** typed fields; delegate to existing CSO01 read-only schema + validator pinned to the current SHA-256 descriptor; store only a validation digest, not submitted values. Returns `INPUT_VALIDATED`; no execution.
7. `resume`: for a validated non-secret form return `READY_FOR_AGENT_REPLAN`; for a known provider, call only the original Provider Connection Center read-only plan to see if `no_action` indicates ready. Never retry a write, promote a draft, refresh a token, consume a native approval or infer that review is execution authority.
8. `fields`: discover a strictly bounded, descriptor-only projection of officially registered, REST-visible, non-protected WordPress `post` metadata for the selected REST-visible CPT. Skip credential-like, price and stock keys, unsupported shapes and all actual values.

## Security and operational limitations

- **HTTPS is mandatory** for credential entry. ETG WooCommerce Local Lab currently has `home_url() = http://localhost:8091`. Therefore the broker correctly marks secret handoff **not ready**, and does not issue a secret-entry form/URL. Do not type real API keys into ChatGPT to get around this.
- `MAD4B_CSO_ENABLED_COMPONENTS` is intentionally unset. Existing opt-in forms and secret lifecycle remain disabled; no silently altered Site Profile, OAuth grant, feature flags or site origin.
- Interactive OAuth consent, MFA, third-party browser sessions and encrypted secret storage remain owned by the corresponding original providers/optional first-party browser service. The broker is not a browser automation or credential vault.
- JetEngine unregistered fields, complex MetaFields, relationships, WooCommerce private pricing/stock meta and WPML write paths are **not certified** by the new generic field projection; require owner-specific adapters and acceptance.
- No generic unattended background job with delegated user identity has been enrolled. An authorized agent can inspect checkpoints and explicitly resume a **read-only** observation; write continuation still follows the original exact approval/execution pathway. Do not treat `READY_FOR_AGENT_REPLAN` as an automatic replay receipt.
- The current Local Site Profile is `write_enabled=false`. This module must not expand native write privileges or change it.

## Evidence / regression

- 28/28 synthetic isolated PHP tests: reason classification, bounded checkpoint, expire/forgery/cross-actor/site-drift rejection, secret input rejection, typed nonsecret validation, read-only resume signal, and WordPress registered-meta projection.
- Four PHP source files passed `php -l`.
- All five REST routes are registered in the actual Local WordPress runtime; anonymous POST is rejected with HTTP 401, and core `/wp-json/` remains HTTP 200.
- Existing MAD4B Audit chain verifies. No provider credential, purchase, booking, price, publication, GitHub merge or Staging/Production configuration was changed.
- Authenticated **real-user HTTP positive end-to-end** and approved first-party HTTPS/browser secret handoff remain outstanding acceptance gates, and the isolated fixture does not substitute for either.

## Acceptance before broad rollout

Require a certified HTTPS first-party origin, one enrolled provider with native secret lifecycle and independent readback, an authorized browser session without exposing values through MCP/ChatGPT, a real signed owner/agent workflow, dynamic field adapters by original plugin owner, two-actor/revision isolation, queue worker delegation and non-replay evidence, performance budget, then independent Staging and Production approval.