# MAD4B — Dynamic WordPress Admin Operations → MCP

## Preserve both complementary engines

This feature **does not replace** the existing provider-neutral
`mad4b/plugin-update-recovery-*`, certified `mad4b/plugin-package-plan/apply`,
or independent CI-outage native evidence/signature procedures.

The universal `mad4b/manual-workflow-discover` read tool now optionally
combines both inventories:

- `include_plugin_updates=true` — certified plugin update/rollback,
  including CI-outage fallback with genuinely reviewed native tests.
- `include_admin_operation_profiles=true` — source-owned semantic admin
  operations, site-specific typed variables and original planner/executor.

The two flows use the same enrolled Site Profile and audit/approval semantics
but remain different executors. **No parallel installer and no generic
WordPress PHP function dispatcher are introduced.**

## Site-configurable admin workflow profile

New MCP abilities (WordPress Staging only):
- `mad4b/admin-operation-profiles-discover` — read canonical operation
  registry and saved site-specific mappings.
- `mad4b/admin-operation-profile-plan` — plan a profile mutation with exact
  `operation_id`, reason and bounded JSON `profile`, bound to site UUID,
  origin, Site Profile digest, actor, and current profile-store snapshot.
- `mad4b/admin-operation-profile-apply` — the **only** profile-storage write.
  Requires enrolled administrator, current governed Write grant, ChatGPT owner
  OAuth authority step-up, same plan SHA and confirmation
  `SAVE EXACT STAGING ADMIN WORKFLOW PROFILE`. Uses concurrency lock, durable
  option readback, audit and rollback. Saving a profile **never executes** the
  underlying admin action.
- `mad4b/admin-operation-profile-resolve` — read-only conversion of a
  configured admin form/task to a typed, schema-validated input for its
  *original* WordPress MCP planner. It names the original executor and the
  next Ability to invoke, but does not bypass that Ability's own authorization.

The profile maps a **source-registered** semantic operation to a
**registered admin route** and a bounded set of non-secret typed variables:

```json
{
  "operation_id": "wordpress.settings.example",
  "reason": "Staging administrator approved this mapping",
  "profile": {
    "enabled": true,
    "route_slug": "mad4b-example-settings",
    "approval_mode": "owner_confirm",
    "defaults": { "mode": "safe" },
    "variables": {
      "mode": { "type": "string", "required": true, "enum": ["safe", "advanced"] },
      "dry_run": { "type": "boolean", "required": false, "enum": [] }
    }
  }
}
```

This is a **format example**, not a claim that the example operation or route
exists on any given installation. For actual configuration, first discover
available canonical operation IDs and admin routes from this site's runtime,
then plan with the real registered pairing.

Non-secret scalar types: string (≤256 bytes), integer (bounded), boolean,
optional allowed enum. No arbitrary nested PHP, REST endpoints, nonce
forwarding, shell execution, SQL, admin-click replay or caller-supplied
credentials. Passwords, API keys and tokens must use a separately governed
secret reference/vault adapter; they cannot be persisted as free-text
profile defaults.

## Dynamic growth model

An installed WordPress plugin can expose a screen without exposing an
authorized MCP operation. Discoverable UI does **not** mean executable.
For an unrecognized action, report `adapter_required`. Add a source-reviewed
typed adapter or register an existing governed WordPress Ability using the
canonical Operation Registry and descriptor binding. Once that source-owned
adapter exists and receives its ordinary grants, the site-specific profile
can bind its route/variables without introducing a new ChatGPT prompt or
hardcoded route handler.

The runtime profile is **data only**. It cannot select arbitrary callable
names or lower an operation's risk; it can request `manual_only` or
`owner_confirm`, but not arbitrary automatic mutation. Each executable
operation continues to be invoked via the existing MCP client tool and its
own plan/apply grant, confirmation, immutable plan digest, readback and
compensation. Registered WordPress Ability input schemas are consulted to
reject invalid profile-derived planner inputs. The assistant can orchestrate
discovery → resolve → original plan → owner approval → original apply → fresh
readback, without opening wp-admin for supported operations.

For a plugin that only exposes a web form/JavaScript handler, WordPress may
not expose the form's server-side contract to remote MCP. Automatic execution
is **not safe or guaranteed** until a plugin adapter supplies the typed
operation, expected effects, permissions and postconditions. This is a
deliberate boundary, not a reason to grant the model blanket admin access.

## Acceptance and remaining work

CI source tests include
`tests/admin-operation-profiles-runtime.php`, plus PHP syntax checks in both
canonical package workflow definitions. Verify under exact PR #258 HEAD
with PHP 8.3 and the v7 canonical builder. Test stored profile invalidation
after Staging origin/site changes, OAuth step-up refusal, central grant refusal,
concurrency, audit rollback, dynamic variable overrides, original planner
schema denial and read-only handoff. Then deploy to Staging via a separately
certified package, refresh MCP/Write authority, and exercise one real
plugin-specific operation end-to-end.

**Source-delivered does not imply live execution.** The new abilities are not
present on installed All Royal Egypt until a compatible tested package is
installed, and a real third-party adapter is certified. Production updates
remain unauthorized.

## Exact Staging native-candidate toggle — remote parity example (PR #258)

The former wp-admin-only `mad4b_wp_native_candidate_opt_in` action now has
a source-owned pair of registered WordPress Abilities:

- `mad4b/wordpress-native-candidate-opt-in-plan` (read lane): input
  `{"mode":"enable"}` or `{"mode":"disable"}`; outputs exact Site UUID,
  environment, origin, Site Profile digest, current option digest, plan SHA-256
  and the separate confirmation phrase. It cannot update an option.
- `mad4b/wordpress-native-candidate-opt-in-apply` (admin lane): input
  `mode`, `expected_plan_sha256`, `confirmation`. Governed executor must
  claim a distinct one-time ticket after owner OAuth step-up. The callback
  independently checks Staging, user enrollment, WordPress plugin capabilities,
  plan/site/option CAS, confirmation, audit and effective readback.
- `mad4b/wordpress-native-update-status` remains the stable independent
  readback; unlike the plan it does not assume a consent decision.
- The Operation Registry operation
  `wordpress.candidate-channel.consent` and existing
  `native-candidate-mode` alias now resolve to this pair instead of a
  generic manual-workflow adapter.
- Exact Staging-only provider allowlisting is required for the admin Ability;
  the update grants no privileges on Production and cannot execute package
  building or installation automatically.

### Client workflow

1. Discover by goal, operation alias or registered Ability; resolve original
   lane, required step-up and typed variables. Never guess PHP class names.
2. Prepare and read the exact plan through the canonical schema-pinned
   `mad4b/read-execute` dispatcher.
3. If `eligible=false`, report blockers. If `already_current=true`,
   stop and verify status; do not spend an approval ticket.
4. For an eligible state change, obtain exact owner OAuth step-up and the
   existing NHI one-time approval for the **admin apply** Ability; do not
   convert it to a read lane or reuse a ticket from an unrelated operation.
5. Apply through the canonical governed write path; after a timeout,
   read `wordpress-native-update-status` and the audit trail before ever
   considering a fresh plan. Never blindly retry the mutation.
6. Enabling merely permits a *separate* WP-Cron build plan/start. Building
   produces `BUILT_UNVERIFIED`. A later explicit Staging self-approval
   installation has its own ticket, receipt, backup and rollback.

### Bootstrap boundary

Until a plugin package containing these registrations is deployed, the
remote opt-in Ability cannot be called on an older runtime; a catalog entry
alone does not create an executor. A one-time trusted deployment or already
authorized admin action may be required to establish the route. Never spoof
a browser admin nonce, write `wp_options` through arbitrary SQL, or reinterpret
a read grant as consent. After installation, refresh MCP tools and exact
Staging write grants for this newly registered Ability.

This pattern is reusable for **reviewed registered administration operations**,
not automatic execution of unrecognized WordPress UI controls.
