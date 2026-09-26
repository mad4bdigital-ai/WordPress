# Contract — Security Threat Model

Contract: mad4b.security-threat-model.v1

## Trust zones

Treat as distinct:
- ChatGPT/MCP client;
- Local OAuth authority;
- External authority;
- MAD4B Control Plane;
- WordPress/site providers;
- WorkflowProviders;
- Research/Scrape providers;
- Context sources;
- Host Connector;
- database/filesystem;
- external web content.

Crossing a zone requires authenticated identity, bounded contract and input validation.

## Mandatory threat classes

### Confused deputy / privilege escalation
A low-authority token, Skill, workflow or provider cannot cause a higher-authority resource mutation.

### SSRF / DNS rebinding
All provider/scraper/custom HTTP capabilities classify destination policy.
Private/link-local/metadata/control-plane endpoints are denied unless a separately authorized contract explicitly requires them.
Redirect chains are revalidated.

### Prompt/source injection
External pages, Drive documents, competitor content, webhook payloads and provider outputs are untrusted data.
They cannot redefine system policy, grants, tool instructions, provider selection or approval requirements.

### XSS/content injection
Generated/imported HTML and metadata use context-appropriate sanitization and escaping.
Script/event-handler injection is rejected unless an explicitly certified trusted-content capability permits it.

### SQL/command/path injection
No raw interpolation into SQL/shell/path.
Paths are canonicalized and constrained to allowed roots.
Archive extraction rejects zip-slip/path traversal/symlink escape.

### Replay
OAuth/webhook/workflow callbacks use expiry + nonce/jti/idempotency where applicable.

### Cross-tenant/site bleed
Every read/write/cache lookup carries explicit tenant/site scope.

### Provider compromise
Certification can quarantine provider artifact/capability.
Kill switch can block future execution without deleting evidence.

### Key compromise
Key/credential compromise has revoke/rotate/contain/recover runbook.

## Security evidence

High-risk capabilities require:
- threat classification;
- negative tests;
- security-surface diff on provider update;
- audit/evidence;
- incident/quarantine path.

## Default

Unknown high-risk behavior fails closed.

## Tool execution threats

### Command injection
Process executors use fixed executable + structured argv. Caller text cannot become shell syntax, flags outside schema or nested commands.

### Path/symlink/TOCTOU
Filesystem operations canonicalize allowed roots, reject traversal/symlink escape and revalidate immediately before commit. Atomic replacement is preferred.

### Runner spoofing and replay
Host Runner jobs are integrity-bound to operation, plan, target, candidate and expiry. Lease fencing prevents a stale/zombie runner from committing.

### Secret/environment leakage
Only allowlisted environment values reach a process. Credentials are resolved from opaque handles and redacted from argv/output evidence where possible.

### Output exfiltration
stdout/stderr have byte/line bounds, redaction and data classification. A diagnostic cannot become an unrestricted file/secret dump.

### Privilege confusion
Transport access such as SSH/API/CLI availability does not imply Host Execution Authority. A provider plugin executing inside WordPress cannot silently inherit outside-WP host scope.

### Unsafe fallback
Executor failure cannot fall back to arbitrary shell, broader credentials, Production, raw SQL or another target.

### Queue poisoning
Runner queues validate schema, integrity, expiry, authority, idempotency and target before leasing work. Unknown operation IDs fail closed.

### Provider CLI drift
A provider CLI binary/version change invalidates affected certification and cannot silently alter command semantics.
