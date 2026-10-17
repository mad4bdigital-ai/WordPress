# MAD4B — Universal Upload Execution Guard (P0)

**Status:** Source-only governed Read/Plan. No deployment, server mutation or protection attestation. Separate source slice dependent on Feature 007 PR #394.

## Objective

Prevent uploaded PHP-like executables from being served through the public web server's PHP handler without assuming a WordPress install path or a specific hosting platform. WordPress cannot itself attest Apache/Nginx/IIS enforcement. An out-of-band Host and Origin/Browser authority is required before marking a site protected.

## P0 implemented

- **mad4b/upload-guard-discover**: current-site WordPress upload root and URL, relative path, bounded local .htaccess metadata, server hint and explicit unverified status.
- **mad4b/upload-guard-policy-plan**: non-authorizing candidate routing for Apache/LiteSpeed, Nginx, IIS and unknown servers.
- Both abilities register as non-public governed MAD4B Read, with WordPress admin capability, connected user and enrolled Site Profile gates.
- No request-supplied filesystem paths, shell commands, PHP evaluation, network probes or file writes.
- Symlinks, unknown web mappings, external CDNs and custom upload roots fail closed to host review. Multisite inventory is limited to the current site.
- A marker in a local .htaccess file is not evidence that the web server honors it. Server software banners are hints only.
- No dependency on Query Monitor, Wordfence, Site Health Tools or Plugin Check.

## P1 governed execution — NOT IMPLEMENTED

1. Obtain host config and real PHP handler/mapping through a separately approved Host Adapter, with exact site/environment provenance.
2. Create a hash-pinned immutable plan that retains existing config and defines rollback, review, idempotence and the least-privilege server-specific policy.
3. Apply only under independently granted Staging host authority. Production requires explicit Production authority; discovery never grants mutation.
4. Use a separately certified origin-attestation runner for a disposable, harmless PHP canary; test static images/PDF and nested paths, verify no PHP evaluation, clean up reliably, prevent caching and return signed evidence. A CDN 403 alone does not prove origin enforcement.
5. Support Multisite networks, plugin-defined storage and remote object stores via separately discovered provider capabilities. Never claim complete coverage from a single WordPress upload root.
6. Track policy drift, compare before/after, log durable action receipts and verify compensating rollback. Include/require execution from a separate compromised PHP process needs distinct protection: blocking direct HTTP execution cannot guarantee it.
7. Security scanners and third-party plugins are optional evidence providers, not required dependencies.

## Acceptance

Run PHP syntax checks and the disposable fixture at wp-content/plugins/mad4b-site-control-plane/tests/upload-execution-guard-runtime.php. The fixture checks deny-by-default, non-public Abilities, WordPress and Site Profile authority, read-only planning, upload path and origin mapping, CDN routing, Nginx, Multisite scope, symlink rejection and unverified marker semantics. Native tests are not an installation certificate.

## Integration boundary

Reuse existing Security Diagnostics, Operational Remediation, Provider Compatibility and Browser Acceptance lanes. Do not add direct host configuration writes or modify the six protected Feature 007 workflows. The parent PR remains independently gated; this slice must obtain its own source, CI, review and Staging runtime acceptance evidence.