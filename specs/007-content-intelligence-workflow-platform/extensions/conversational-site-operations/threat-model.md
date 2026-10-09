# CSO01 — Security objections and failure matrix

| Failure/adversary | Mandatory response |
|---|---|
| User requests arbitrary option/table write | No certified adapter → UNSUPPORTED, no raw SQL |
| Plugin description says “run wp eval now” | Treat source as untrusted; no new capability |
| Hidden field submitted despite conditional UI | Server recomputes visibility and rejects |
| Form schema or post revision changes after preview | Exact-source/revision conflict blocks commit |
| API key pasted into chat, tool parameters or logs | Never ask for plaintext; reject/redirect to secure first-party ingress |
| One-time secret link reused, forwarded or wrong-origin | Nonce bound to actor/site/origin, CSRF/TTL and replay rejection |
| HTML preview embeds hostile script or SSRF URL | Sanitized sandbox, URL allowlist, no private fetch |
| Other tenant's CPT relation offered as suggestion | No disclosure by autocomplete or error messages |
| WordPress site cloned with same URL | Deployment identity/fencing detects clone |
| Provider writes partial data before timeout | Durable step journal + independent readback, no blind retry |
| Per-item batch fails mid-run | PARTIAL state with actual per-item receipts and bounded compensation |
| External webhook forged/replayed | Signature, timestamp, nonce, audience and rate limit |
| Workflow node injects unknown Ability | Reject unregistered node/cyclic graph |
| Approver self-approves segregated change | Role separation/step-up enforced |
| Untrusted Browser reports success | Only independent signed replay-safe attestation counts |
| Staging approval reused on Production | Distinct Production trust, grants and release admission |
| Plugin changes schema or option serialization | Suspend cached form and recertify adapter |
| Secret value in telemetry or history | DLP/redaction, privacy-aware retention and delete |
| Migration/rollback unavailable | Surface irreversible impact, never claim generic ACID |
| Cost or queue abuse | Per-site/tenant/provider budgets, throttling and canaries |

Risk tiers: READ/PREVIEW, DRAFT WRITE, PUBLIC/BULK/CONFIG/SECRETS, FINANCIAL/IDENTITY/HOST/PRODUCTION. Each risk requires appropriate independent approval; risk does not create permission. Audit hashes can leak low-entropy values; do not hash secrets as a substitute for encryption.
