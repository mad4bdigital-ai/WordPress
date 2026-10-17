# MAD4B — Governed Dynamic Ability Census
Date: 2026-10-17
Scope: PR #403 / Staging-only Enrollment repair and reusable capability discovery.

## Problem / observed regression
The WordPress Abilities API correctly registered `mad4b/staging-write-owner-attest`, and the ChatGPT capability gateway could search and prepare it. The generic `mad4b/enrollment-discover`/ `mad4b/enrollment-execute` dispatcher only admitted static Remote Operation Parity catalog entries, so this legitimate owner attestation had no operation ID. Under the MCP direct-tool budget, its optional direct tool could also be absent. Adding another one-off name to the catalog would repeat the defect.

## Canonical dynamic path
1. Enumerate `wp_get_abilities()` once per census; hard bound at 2,048 entries and require the exact current `wp_get_ability(name)` object.
2. Classify the declared `mcp.surface`, readonly annotation, direct step-up requirement and prohibition of generic remote administration.
3. Verify both registered WP_Ability callbacks' *actual PHP source path* via read-only Reflection. Both must be first-party `MAD4B_SCP_*` methods in this installed plugin's `includes/` tree. A name, metadata, or namespace alone is insufficient.
4. **Staging broker**: only first-party enrollment mutations declaring `production_mutation_allowed=false` and not `production_allowed=true`. Derive deterministic `core_step_up_…_<12-char digest>` operation IDs; expose through the already mounted `enrollment-discover/info/execute` transport.
5. **Direct-only governance**: independently governed runtime gates declare `direct_only_excluded_from_staging_broker=true`, `production_allowed=true` and `generic_raw_sql_breakglass_configuration=true`. Discover these in the same census but never admit them to generic Enrollment. Execution remains solely through their original direct permission, owner, and runtime policy.
6. The capability gateway's prepared descriptor reports the resolved operation ID if and only if the Staging broker census verifies the current registration. It still requires `enrollment-info`'s exact registration, dispatch-policy and input-schema digests before execute.
7. Per-request execution rechecks site enrollment, no active Breakglass, registered operation identity, exact ChatGPT OAuth CIMD client and `mad4b:authority:step-up`; the target's own `permission_callback`, `WP_Ability::execute`, one-time Execution Fence, exact plan review and Audit v2 remain authoritative.

**No automatic grant, ticket, owner approval, production mutation, source deployment, replay, Developer lane, Breakglass, or release readiness results from discovery.**

## Core metadata requirements
New first-party Staging enrolment abilities do not need a new hardcoded dispatcher mapping, provided they register on `wp_abilities_api_init` and declare:
- `mcp.surface=enrollment`, `chatgpt_direct_step_up=true`, `exact_chatgpt_client_required=true`
- `generic_remote_admin=false`, `production_mutation_allowed=false`
- `annotations.readonly=false`; first-party executable and permission callback methods whose PHP provenance verifies at runtime.
- Use `mcp.owner_intent_only=true` for owner intent receipts; the target must still verify the current plan digest, confirmation, OAuth owner and append-only audit.

Capabilities with high-risk cross-environment control contracts are **not** eligible for Staging Enrollment solely from the above; they require a separately governed direct-only metadata contract and their existing permission callbacks.

## Test coverage
The existing Staging Write Authority CI contract executes `tests/dynamic-first-party-enrollment-runtime.php`, which verifies:
- Dynamic owner attest discovery, deterministic identity and exact `enrollment-info` routing without invoking a mutation.
- Required Step-up and exact ChatGPT OAuth client (negative tests).
- Denial of foreign executable/permission callback sources with spoofed first-party metadata.
- Denial of production-capable and non-step-up registrations.
- Strict separation of direct-only governed runtime controls and Staging enrollment; deny Staging broker in Production.
- Existing PHP 7.4 and PHP 8.3 owner intent, enrollment and transport regression tests remain enforced.

All direct tool projections remain **optional** within the MCP tool budget. The stable named `enrollment-discover/info/execute` transport is the recovery path if a dynamically identified ability is not projected. Connection transport errors must be diagnosed separately, not mistaken for authorization.

## Release and Staging acceptance
Build the exact PR head and run repository CI. Install only on an authorized Staging site with backup, verify `source_commit_sha`, fingerprint and candidate binding. Owner consent is still a separate OAuth step-up decision, followed by Write-only convergence readback, isolated Elementor/JetSmartFilters probe and exact rollback. Do not certify or deploy Production from this change.
