# MAD4B Manual Workflow Autopilot — capability-based assistant handoff

## Goal

Let a connected assistant discover tasks that otherwise require WordPress
admin clicks or manually coordinated API calls, identify the *actual* governed
executor, and complete supported Staging tasks via MCP after the applicable
owner approval. The mechanism must expand as the canonical operation registry,
providers and certified remediation lanes expand. It must **not** become
an arbitrary WordPress function caller, shell, direct SQL tool or bearer of
unreviewed privileges.

## Discovery contract

`mad4b/manual-workflow-discover` accepts optional `operation_filter` and
`include_remediation`. It combines three already authoritative sources:

1. Exact enrolled Site Profile and native candidate-update status.
2. `MAD4B_SCP_Operation_Registry::status()`, including registered planner,
   executor, risk class, descriptor readiness and availability.
3. Optionally the bounded `MAD4B_SCP_Operational_Remediation::status()`
   reducer of current Staging blockers and canonical remediation paths.
4. Definition-only `MAD4B_SCP_Admin_Route_Registry::routes()` so newly registered
   MAD4B admin screens are visible as *manual, non-executable* candidates to
   enrolled administrators. A route URL, capability string or nonce form never
   serves as an MCP execution credential.

Unknown manual routes or capabilities are returned as **missing a certified
adapter**, not opportunistically executed. Discovery itself never creates
an option, changes a grant or performs a network installation. As provider
registrations and the reviewed `config/operation-registry.json` expand, the
assistant's supported discovery surface expands **without hardcoded
conversation prompts or hostname-specific logic**.

## Independent authorization and action lifecycle

`mad4b/manual-workflow-plan` resolves a semantic operation and reason to an
immutable SHA-256 bound to Site UUID, Site Profile digest/revision, canonical
origin, installed source commit, OAuth user ID, current value, required exact
confirmation and deny-first Staging policy. The plan is **read-only**. For
other canonical registry operations, the plan delegates to their own exact
registered governed planner/executor and *never* invokes a generic callback.

`mad4b/manual-workflow-apply` currently implements **only** these
source-reviewed operations:

- `wordpress.native-candidate.enable`
- `wordpress.native-candidate.disable`

These replace the manual Site Profile admin form with an equally restricted,
source-owned, auditable WordPress-only MCP path. The user explicitly confirms
the plan in the conversation; execution requires **all** of the following:

- WP `manage_options` **and** `update_plugins`, exact enrolled admin;
- current Staging Site Profile with matching origin and no drift;
- effective governed Write policy, **current ability-specific central grant**;
- same verified ChatGPT OAuth client and authority step-up scope;
- the exact current plan SHA, operation-specific consent phrase and unmodified
  state; a WordPress option transaction lock with fail-closed contention;
- read-your-writes and exact Site Profile revalidation, an auditable record
  and rollback of the option if persistence/audit fails.

Do not treat this as permission to update any plugin or to publish a website
without a separate, source-bound installation plan, certified artifact and
dedicated `mad4b/control-plane-selected-head-apply` approval. Production,
Developer, Breakglass, arbitrary host work and new credentials are not enabled.
No approval is obtained merely by asking the discovery or planning tools.

**MCP assistant workflow**:
1. `mad4b/manual-workflow-discover` (optionally include remediation).
2. `mad4b/manual-workflow-plan` with exact `operation_id` and reason.
3. Display the exact target, impact, state and confirmation; get affirmative
   user authorization before effectful action.
4. Call `mad4b/manual-workflow-apply` with the *same* operation/reason,
   `expected_plan_sha256` and matching `confirmation`.
5. Verify through `mad4b/wordpress-native-update-status` on a fresh request.
6. Use the normal `mad4b/control-plane-selected-head-plan` only after the
   selected HEAD receives an immutable certified GitHub package; install from
   that separately reviewed plan, never from an arbitrary URL.

## Extending to new workflows

A new capability MUST have a source-reviewed canonical operation descriptor,
specific plan/executor Abilities, exact write authorization, bounded input,
idempotency/conflict/retry semantics, audit/readback/compensation policy,
privacy-safe output and failure tests. Add its semantic operation to the
source-owned `config/operation-registry.json`. The bridge then discovers it
dynamically and directs the assistant to the original authoritative
plan/executor. For an operation to gain *one-step bridge execution* later,
implement an explicit, code-reviewed recipe in the bridge or an equally
governed adapter, with no arbitrary method names, URLs, filesystem paths,
database table names or PHP callbacks from discovery results.

Providers may add discovery suggestions, but a provider-supplied descriptor
alone cannot mint write approval or add a new executable recipe. This is
intentional: self-learning may expand proposals and safe reads automatically,
but must not expand privileges automatically.

## Acceptance

- Lint `includes/class-mad4b-scp-manual-workflow-bridge.php`.
- Run `tests/manual-workflow-bridge-policy-runtime.php` on exact source;
  test denies Production, site-clone/origin mismatch, stale plan, duplicate
  action and arbitrary op dispatch.
- Run both canonical package workflows and the existing WordPress-native
  12-case policy fixture. Preserve G9 exact source/delivery fingerprints.
- On Staging verify Abilities registration, owner authorization, fresh readback,
  audit-chain consistency and mode enable/disable/rollback in isolated
  acceptance. The existing installed plugin **will not gain this new Ability**
  until the newly certified package is actually installed on Staging.

**Status at code handoff:** source implementation, policy fixture and CI
wiring only. No claim of successfully running live effectful MCP apply, global
Staging acceptance, or changing Production. In a cloned WordPress instance,
site-local storage alone cannot supply the independent host authenticity
provided by the existing Host-bound mode.
