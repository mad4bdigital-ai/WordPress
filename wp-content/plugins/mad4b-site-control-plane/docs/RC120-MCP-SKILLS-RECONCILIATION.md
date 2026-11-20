# rc.120 Staging source reconciliation

The source is the clean merge of the rc.119 cache/native MCP handoff and the
MCP dynamic catalog plus Managed Skills fixes. It preserves the newer live
Core Web Vitals admin section from tours.dev.

- MCP: compatibility for legacy JSON object properties, single-tool quarantine,
  unchanged execution permission callbacks, and no total catalog count/byte cap
- Skills: scan all managed skills, optional paged skills-list, canonical seed
  content-jobs-ui-reconcile included in tracked source and package QA
- Existing rc.119: runtime cache convergence, native transport read support,
  MCP Adapter 0.7.1 profile and release tests retained
- Production: unchanged; this release is a Staging-only candidate until an
  exact artifact, site identity, readback and rollback plan is authorized
- Original control plane self-update must not be skipped on sites without
  the appropriate write authority; a staged archive is not an applied release

All external grants, OAuth scopes, Developer/Breakglass authority, and PHP
permissions are unchanged.
