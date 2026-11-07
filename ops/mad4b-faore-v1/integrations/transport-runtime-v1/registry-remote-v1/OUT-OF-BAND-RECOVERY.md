# MAD4B Out-of-Band Recovery, 2026-11-06

This extends the existing registered Cross-SSH + VPS MCP client. It does not
create an additional Broker, WordPress administrator, grant or approval system.

## Purpose

Staging Control Plane or MCP Adapter outages must not block site diagnosis
or exact source restoration. Use original pinned registered host keys,
fixed WordPress roots, and WordPress Core with --skip-plugins.

Enrolled targets: hosting-2:staging (ARE), hosting-3:staging (ETG).
No production writes. Unregistered aliases or arbitrary paths fail closed.

## Read/diagnose while MCP or OAuth is broken

python3 /opt/mad4b-company-transport/registry-remote-v1/registry_remote.py doctor --target hosting-3:staging

/usr/local/bin/mad4b-vps-mcp-client --target hosting-3:staging --identity-mode host_operator --run recover

The recover command tests the original MCP read Ability first, reports the
original permission denial or transport failure, and independently verifies
the signed Native WP-CLI site identity. If signed proof is unavailable, the
Staging Doctor can still check WordPress Core identity and plugin package
state without loading either plugin. It never converts a private MCP 403
into an Ability grant or represents alternative read as tools/call success.

## Surgical Staging source recovery

First create a READ-ONLY plan, not a deployment:

python3 /opt/mad4b-company-transport/registry-remote-v1/registry_remote.py repair-plan --target hosting-3:staging --plugin mad4b-site-control-plane --relative-file includes/class-mad4b-scp-authorization.php

The plan returns target's current SHA, replacement SHA from the existing
Central source and a canonical plan digest. Exact apply command:

python3 /opt/mad4b-company-transport/registry-remote-v1/registry_remote.py repair-apply --target hosting-3:staging --plugin mad4b-site-control-plane --relative-file includes/class-mad4b-scp-authorization.php --expected-plan-sha256 SHA256_FROM_CURRENT_PLAN

Safeguards:
- Pinned SSH, exact Site Profile UUID, Staging environment, canonical URL.
- Same plugin release version; no cross-version single-file replacement.
- Only existing plugin PHP or selected JSON configuration paths.
- Current file SHA and replacement SHA match exact current plan.
- Code symlinks and traversal are rejected; size limit and PHP lint.
- Private backup under hosting user's HOME, not document root.
- Atomic replacement, WordPress boot check and SHA readback.
- Automatic original-file rollback when boot or readback fails.
- No arbitrary SQL, full-plugin overwrite, OAuth grant, user impersonation,
  production mutation or fabricated 305/312/313 receipt.

Broken releases needing cross-version installation must use an approved,
complete matching package and existing recovery mechanism; never
transplant a single file from a different release.

## Acceptance evidence

- ETG original MCP private site-info read denied, out-of-band signed
  Native read verified, no grant and no write.
- ARE original MCP site-info read denied, same native read verified.
- Doctor passed on ARE rc.104 (832/832 matching) and ETG rc.118
  (851/877 matching, 26 changed hotfix files), exact site UUIDs verified.
- Transaction tests with isolated test directories: replacement, private
  backup, automatic rollback, SHA compare and symlink denial.
- ETG read-only repair plan available, not applied (no proven bad file).
- ARE cross-version plan refused as expected.
