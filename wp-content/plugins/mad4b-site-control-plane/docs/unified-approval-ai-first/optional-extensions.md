# Optional provider extensions (not bundled into the Control Plane core)

The core contains **only descriptor discovery**, not a new authentication authority.

Existing verified MAD4B identity methods are supported through `MAD4B_SCP_Identity_Context`: OAuth bearer, bound WordPress Application Password and governed passwordless bearer. Independent optional plugins may be built for signed workload identity, service accounts / API keys, mTLS and browser authorization, but require **original Site Profile owner governance** and a separately certified upstream identity verifier before those methods can authenticate an Agent.

The registry `MAD4B_SCP_Unified_Approval_Extensions::register(kind, id, descriptor)` accepts only `service_identity` or `browser_authorization` descriptors, only after checking the exact `MAD4B_APPROVAL_CERTIFIED_EXTENSIONS` source SHA-256 allowlist and plugin path. Registration **does not** authorize execution, register any subject context, access cookies, or enable browser sessions.

For a future service-identity extension:
1. Implement the verification and token rotation in the external, independently reviewed transport service.
2. Map a genuinely verified transport to MAD4B's original authenticated request context, with stable subject fingerprint and site-scoped enrolled Agent.
3. Reuse exact original Agent Ability grants and policy decision/receipt checks; no user impersonation.
4. Pin the extension source and certify it in the original Control Plane governance.
5. Expose only descriptive availability through the registry; do not store raw API secrets in WordPress settings.

For a future Browser Authorization extension:
1. Use a managed browser engine and separate encrypted Vault outside the AI model context.
2. Require a genuine user to complete MFA / WebAuthn / provider consent where mandated.
3. Issue short-lived, target-scoped runner leases; do not give the model raw cookies, passwords or refresh tokens.
4. Expire and revoke sessions with Audit receipts and human takeover (`WAITING_AUTH`).
5. Respect provider permissions and never substitute simulated MFA or scraped sessions for authorization.

**No optional extension is installed or active in this core-merge package.**
