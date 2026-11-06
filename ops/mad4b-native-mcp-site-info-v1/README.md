# ETG Staging: Original MCP site-info under Native Host Operator

Applied on 2026-11-06 to the existing MAD4B Site Control Plane plugin:
- Tenant: Egypt Tour Gates, Staging, https://staging.egypttourgates.com
- Plugin: mad4b-site-control-plane, 0.4.0-rc.118
- Remote runtime file: includes/class-mad4b-scp-abilities.php
- Before file SHA-256: ba23ad51a5b4cf4ec3c3c1a3be04008f3d6cbee6ed4b78a05143ba52acf08d4e
- After file SHA-256: 14b1011bceae0b9d3e4c4c7ed50227a23f6c9ab0bad79f0ee4ba5582b5d53209
- Remote source-file backup was created outside the web root via existing
  cross-SSH recovery with atomic promotion and boot verification.

## Design

This is a surgical ETG overlay, not a replacement of newer Central code.
The original mad4b/site-info readonly Ability can now use the existing
MAD4B_SCP_CLI_Execution_Context::resolve('authority.read', false) when:
- the caller is WP-CLI with no impersonated WordPress User;
- the process is mcp-adapter serve, not ordinary WP-CLI evaluation;
- the host registry's asserted site UUID, canonical origin and database match;
- the target is Staging and the full pre-existing read context is verified;
- MAD4B_CLI_ALLOWED_WRITE_ENVIRONMENTS=none is explicitly set.

Every other private Ability keeps its existing permission callback, including
read tools and all write tools. WordPress REST permissions and manage_options
have not been altered. Original 305/312/313 are unchanged.

The existing /usr/local/bin/mad4b-vps-mcp-client sends the exact pinned ETG
identity context via the registered Cross-SSH host only for
--identity-mode host_operator, without --user, after the Original
Host Operator and registered target identity checks. Source is kept at
ops/mad4b-faore-v1/integrations/transport-runtime-v1/registry-remote-v1/.

## Live acceptance

Before: mcp-adapter tools/call for mad4b-site-info returned
WORDPRESS_PERMISSION_DENIED.

After:
 /usr/local/bin/mad4b-vps-mcp-client --target hosting-3:staging --identity-mode host_operator --run inspect --read-tool

- initialize PASS; tools/list PASS with 408 tools;
- read_tool_name=mad4b-site-info, read_tool_pass=true, error=null;
- WordPress User not supplied; transport never authorized a write.

Four independent WordPress Ability permission checks passed:
- no Native Host assertions: site-info denied;
- wrong site UUID: site-info denied;
- legitimate Native assertions outside MCP serve: site-info denied;
- legitimate Native assertions in MCP serve: site-info allowed.
For each case mad4b/list-plugins and mad4b/write-execute stayed denied.

Existing recover command now reports
ORIGINAL_MCP_ABILITY_READ_VERIFIED and native_fallback_used=false.
The fallback remains available if MCP/OAuth fails again.

## Scope and promotion

No Production modification, new grant, WordPress admin impersonation,
whole-plugin reinstall, or automatic ETG hotfix rollback occurred.
Do not overwrite this ETG variant with an older or unreviewed Central package.
Promote the narrow Permission Callback to the shared upstream Control Plane
only after native read acceptance tests on other company releases.
