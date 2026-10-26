# Runtime Runner Transport — Commercial Acceptance

## Scope and delivered contracts
- `tools/runtime-runner-worker.py`: fixed `runtime.status.read` diagnostic only, RSA/SHA-256 signed request and independently signed receipt; exact enrolled site, plan digest, nonce, target, issued/expiry bounds, durable exclusive nonce claim.
- `tools/runtime-runner-gateway.py`: optional HTTP service bound **only to loopback**. A publicly accessible installation MUST terminate verified HTTPS in an independently governed reverse proxy with strict ingress controls. Never publish the loopback listener directly.
- `includes/class-mad4b-scp-runtime-runner-bridge.php`: WordPress-compatible OpenSSL signer and signed-receipt verifier; pinned HTTPS origin, bounded request/response, no redirects, staging/development/local only.
- Runtime Backend Registry and Developer Host Capabilities expose detection and health independently of authorization. Docker discovery can be refreshed from an explicit configured HTTPS endpoint.
- No Site Private Key, worker private key, bearer tokens, host keys, container socket, or credential identifiers are kept in the WordPress plugin source. Provision keys in secure, non-public locations with restricted owner-only file access.
- Container is a digest-pinned Alpine image with no network, read-only root, dropped capabilities, non-root UID, bounded CPU, memory, processes and wall time. No arbitrary shell or file contents from clients are executable.

## Verified evidence
- PHP runner-bridge OpenSSL signature and tamper-denial tests on the ETG Staging hosting PHP runtime.
- Python protocol tests of forged, replayed, expired, wrong-site and disallowed operation requests.
- Real Docker runner execution and signed receipt validation using PHP and Python (`tests/runtime_runner_crosslang.sh`).
- Real loopback Gateway POST and GET with replay denial (`tests/runtime_runner_gateway_contract.py`).
- Dynamic availability discovery, cooldown and non-authorizing behavior; deactivation removes discovery schedules.
- Governed capability/readiness coverage checked locally with `PASS_COVERAGE_ONLY` against the PR base. This does not authorize a deployment.

## Commercial deployment requirements (not yet accepted)
1. Enroll exact Site Profile, agent, operation and source-version identity; keep authorization policy and OAuth checks in the existing MAD4B control plane.
2. Configure independently trusted HTTPS ingress with certificate verification, strict outbound allowlists, key rotation and revocation, rate limiting, origin and resource bounds; do not expose Docker or SSH management credentials.
3. Install an unprivileged isolated worker with constrained access to an explicitly approved container runtime. A worker controlling a rootful Docker socket has root-equivalent host power and is **not** certified by the diagnostic test alone.
4. Provision per-site request-signing and worker-receipt keys, with the site-private key inaccessible over HTTP and outside the webroot; pin the site's expected worker public key.
5. Connect only an existing governed, read-only MAD4B Ability or an explicit owner-approved queue operation to the Bridge. New work submission, claims and completion require the preexisting authorization and durable-lease contract; do not issue direct Shell, SQL or Developer grants from Registry availability.
6. Verify real `ChatGPT MCP → authenticated WordPress Ability → signed HTTPS Gateway → sandboxed Runner → signed Receipt → WordPress readback` against a deployed Staging environment, including auth denial, network failure and rollback/recovery tests.
7. Ensure GitHub CI passes at the exact final head and obtain owner approval under the normal release policy before promoting the plugin. No automatic Production mutation or Breakglass.

## Unsupported or unproven
The standalone Gateway and contract tests **are not** proof of a live ChatGPT MCP invocation on a deployed WordPress Staging plugin. No public HTTPS transport has been provisioned by these sources, and no signed Runner receipt has been committed through the existing MAD4B WordPress remote work queue in a live site. The control-plane owner grant remains separate. This feature remains disabled by default.
