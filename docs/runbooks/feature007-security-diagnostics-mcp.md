# Feature 007 — Governed Remote Security Diagnostics (source-only)

## Purpose and trust boundary

Read-only, bounded, site-local WordPress triage for fake verification pages, suspected JavaScript injection, and malware-scanner findings. This is NOT antivirus, Hostinger account auditing, browser attestation, server disk forensics, or malware cleanup. It cannot inspect the operator's Windows computer, the hosting-provider logs, DNS/Cloudflare account rules, or browser extensions.

Indicators from HAR must be provided as optional operator inputs. Never commit user HAR, cookies, credentials, or user-specific malicious domains to this universal plugin.

## Governed Abilities

All are registered using WordPress Abilities API in category mad4b-read, with non-public MCP read metadata and a common fail-closed callback. Execution requires manage_options, MAD4B governed Read, a connected user, and an enrolled/matching Site Profile.

| Ability | Input | Coverage |
| --- | --- | --- |
| mad4b/security-site-posture | Empty object | Version and configuration signals; explicit unverified states |
| mad4b/security-file-indicators | Fixed scope: uploads, mu_plugins, plugins, themes, root; optional indicator_host | Up to 240 visited entries; 120 eligible files; 8192 bytes/file; depth 4; 20 findings |
| mad4b/security-file-integrity | Fixed target: index.php, wp-settings.php, .htaccess, .user.ini; optional expected_sha256 | Exact SHA-256 comparison with a user-supplied baseline (baseline trust NEVER attested) |
| mad4b/security-database-indicators | Fixed surface: options, posts, postmeta; optional indicator_host | Latest 100 records, 4096 characters/record, 20 redacted findings |

Heuristic indicators include script tags, dynamic scripts, PowerShell execution references, blockchain RPC markers, JavaScript obfuscation and redirects. Benign code can trigger these signals. No findings do not imply safety; all responses state their incomplete scope.

## Remote workflow

1. Confirm exact running package/site identity. Discover using existing governed ChatGPT projection discovery or read tool discovery with search query security.
2. Inspect the registered schema and execute through the governed Read lane; discovering an Ability does not grant authority.
3. Read posture; inspect files one fixed scope at a time; inspect options, posts and postmeta separately.
4. Optionally submit only the bare hostname seen in an independent HAR (no scheme, path or wildcard) as indicator_host.
5. Correlate the returned evidence with clean off-host HAR/HTML captures and Hostinger/Cloudflare logs. Validate core files independently against official trusted checksums.
6. Preserve evidence before remediation. No file/DB writes, quarantine, credentials, cache purge, package update or Host Runner operation is performed by these tools.

## Safety limits

No arbitrary filesystem paths, symlink traversal, network calls, shell commands, SQL writes, user SQL fragments, raw document content, secret values or raw database labels are returned. Detection is limited to supported local WordPress access and strict runtime budgets. The fixed-file integrity tool cannot prove the supplied baseline trustworthy: passing a compromised file's current SHA back as the baseline proves nothing about compromise.

## Acceptance and release

Native fixture: php -l on implementation and test; then run the tests/security-diagnostics-runtime.php fixture. Feature 007 Spec CI runs this in its PHP 7.4 and 8.3 matrix. A later explicit Staging installation requires fresh exact package identity, live MCP discovery, permissions, read execution, revocation checks and out-of-band security verification. PR #258 stays Draft: source changes alone neither remediate the incident nor prove installed runtime or Production safe.
