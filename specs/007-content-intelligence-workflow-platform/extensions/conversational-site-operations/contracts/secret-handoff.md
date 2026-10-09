# Secrets and Credential Lifecycle Contract

Never collect an API key, password, OAuth code or encryption key in ChatGPT messages, inline form fields, normal MCP payloads, G6 Conversation Vault, logs or receipts. Instead request a one-use, actor-bound first-party HTTPS handoff that the user deliberately opens. Bind it to exact site/tenant/origin, provider, field, consent audience, nonce and short expiry. Enforce TLS, CSRF, same-site cookies, strict CSP, no external scripts/analytics and no secret in URL/Referer/browser history.

The trusted provider-specific input service writes through a supported managed secret store or certified provider adapter. Some plugins require plaintext in wp_options; this cannot be made universally safe by calling it a Vault. If such storage is unapproved, return UNSUPPORTED without collecting the key. Chat receives only configured/verification state, timestamps and opaque receipt reference. Never expose actual key or low-entropy digest.

Rotation: create or update new secret → verify provider → cut over → revoke old → independent readback. Failure yields PARTIAL/UNCERTAIN, with explicit provider owner recovery. A replayed, forwarded, expired, mismatched-actor or mismatched-origin link MUST be rejected. No automatic host/Production secret transfers.
