# Autonomous Assistants — GA/GB repository foundation

Contract: `mad4b.assistant-plan.v1`  
Integration Hub: [Feature 007 PR #258](https://github.com/mad4bdigital-ai/WordPress/pull/258)  
Status: **REPOSITORY FOUNDATION ONLY — NOT STAGING CERTIFIED**

## Purpose

A capability-first, non-authorizing desired-state planner for the intelligent
site assistant. This addition does not turn reasoning output into permission:
the existing MAD4B Site Profile, policy, exact grants, runtime generation,
approval tickets, plugin lifecycle, restoration and audit lanes remain owners.

The adapter registers **one READ ability** (`mad4b/assistant-plan`). Input
contains `expected_profile_digest`, `expected_runtime_generation`,
`desired.capabilities`, optional `desired.facts` and optional
`observed.capabilities`. The ability captures live
`MAD4B_SCP_Adaptive_Operations_Context::current()` and rejects stale
fingerprints. Its pure planner also requires a bounded runtime binding.

### Example non-authorizing request

```json
{
  "expected_profile_digest": "<live 64-character SHA-256>",
  "expected_runtime_generation": "<live 64-character SHA-256>",
  "desired": {
    "capabilities": [
      {"capability": "search.intelligence", "required": true},
      {"capability": "workflow.automation", "required": false}
    ],
    "facts": [
      {"key": "audience.market.country", "value": "US", "provenance": "operator"},
      {"key": "audience.language", "value": "en", "provenance": "operator"}
    ]
  },
  "observed": {
    "capabilities": [
      {"capability": "search.intelligence", "state": "missing"},
      {"capability": "workflow.automation", "state": "missing"}
    ]
  }
}
```

The response routes each proposal through a typed **discovery, configuration, certification or supervisor** role; these are descriptive task proposals, not grant-bearing agent identities. Only the bounded `audience.market.country` and `audience.language` facts can appear as `configuration_proposals.audience`, always with `apply_allowed=false` and an explicit unverified provenance marker. Other caller facts are used for conflicts/input digests, not echoed as sensitive configuration values.

The observation and provenance strings are **caller assertions only**. A
reported active provider is not automatically certified. `plan_sha256` binds
the site/runtime/artifact/restore epoch and the hash of the exact desired and
observed inputs, but is **not** a signed execution permit.

## Boundaries

- No writes, execution, credential reads, outbound calls, plugin installation,
  activation, migration, provider spend, scheduled jobs or new grants.
- Missing Search Intelligence country/language becomes `REVIEW_CONTEXT`; the
  WordPress timezone and the site's physical destination are not audience
  market evidence.
- Required missing capabilities produce `DISCOVER_ALTERNATIVES`; optional
  missing capabilities produce `OPTIONAL_NO_INSTALL`.
- Even an asserted active capability yields `VERIFY_BEHAVIOR`.
- Conflicting facts are surfaced for independent review; they are never
  automatically reconciled or applied.
- Untrusted PHP objects, deep/oversized payloads, malformed identifiers,
  duplicate capabilities, non-list indices and stale runtime generations fail
  closed.
- The output contains a plan, not a durable task queue, signed approval,
  provider certification, installer or autonomous executor.

## Verification

Fixture:

```bash
php -l wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-assistant-planning.php
php -l wp-content/plugins/mad4b-site-control-plane/tests/assistant-planning-runtime.php
php wp-content/plugins/mad4b-site-control-plane/tests/assistant-planning-runtime.php
```

The fixture does not load WordPress or contact external providers. Native
PHP 7.4/8.3 matrix, adapter registration in real WordPress, GitHub CI, browser
journeys and Staging governed readback remain **unverified**.


## Comprehensive adversarial review (2026-10-08)

The proposal engine is a **bounded preview**, not an enrolled autonomous
operator. Code review of the actual bootstrap, Adapter Registry, Policy and
Adaptive Operations Context confirms the existing read permission and runtime
evidence service remain authoritative.

Hardening added in this follow-up:

- Advertise concrete JSON Schema for nested capabilities, facts and observed
  providers. Enforce list bounds at protocol and PHP layers.
- Reject unknown, executable or oversized inputs *before* expensive current
  runtime-binding lookups. Hash only inert data.
- Derive proposal-only task identifiers from exact site, profile, runtime,
  artifact, restore epoch and entire input digest. This does not create a
  persistent task identity or signed ticket.
- Keep an explicit `readiness=NOT_EXECUTABLE` in every returned proposal.
  Distinguish context review and conflict review from ordinary preview, and
  report why independent verification is still necessary.
- Validate every country/language fact, not only the first conflicting value,
  and reject unsafe site UUIDs.

Important limitations requiring independent work:

1. **Bootstrap boundary:** the separate read-only
   `mad4b/assistant-bootstrap-diagnostic` now reports enrollment, origin and
   restore prerequisites without trying to create them. `assistant-plan` still
   requires the exact enrolled runtime binding. Neither Ability bypasses the
   existing read permission or grants bootstrap execution.
2. **Unverified observations:** caller claims cannot be promoted into provider
   certification, package recommendation or an installation decision. Durable
   evidence envelopes need source IDs, timestamps, provider signatures,
   schema/version provenance, TTL, budget and an independent verifier.
3. **Stateful orchestration:** no durable task CAS journal, retry budget,
   deduplication across process restarts, single-writer mutation lane,
   task cancellation, lease or exact idempotent external effects yet exist
   in this new assistant layer.
4. **Read-side operational safety:** verify that the WordPress Ability is
   actually mounted, registered once and discoverable through the deployed
   MCP read catalog. Static registration is not a live-site certification.
5. **Release acceptance:** no exact-head native PHP 7.4/8.3, actual
   WordPress/MCP browser execution, production benchmark, Plugin Check,
   signed release artifact or Staging approval is implied by source tests.

No UI, external AI call, secret lookup, package download/install,
configuration persistence, grant change or production mutation is made.

## Enrollment-unready bootstrap advisor

The new read-only `mad4b/assistant-bootstrap-diagnostic` returns a bounded
prerequisite state even when the exact Site Profile is unconfigured or the
external restore epoch is missing. It does not call the exact runtime binding
on an unconfigured or origin-unbound site. It only reads current runtime
evidence when enrollment and origin have already passed.

- It does not enroll, repair, install dependencies, initialize an epoch,
  grant access, run providers or enable Production writes.
- It reports actionable **review codes** without secrets or absolute host
  paths.
- It remains protected by `MAD4B_SCP_Policy::can_read`. A site with no usable
  OAuth identity may only be able to view it through an enrolled WordPress
  administrator. No unauthenticated transport is created.
- Its `preview_eligible` field means **only** that the existing exact read
  planner can be considered. It does not mean implementation or mutation
  eligibility.
- The hermetic fixture covers unconfigured, unbound, restore-epoch-blocked,
  ready, unknown-input and denial boundaries. Actual native 7.4/8.3 CI and
  WordPress integration remain release gates.

Durable task CAS storage, independent provider attestations, signed
Desired-State authority, safe package solvers, governed configuration apply
and browser/host acceptance remain separate implementation groups.

## Next reviewed child groups

1. GA: durable typed task envelopes, CAS journal, provenance, task ownership
   and single-writer arbitration; independently verify persisted observations.
2. GB: Site Profile desired-state registry with explicit operator confirmation,
   provenance revision, conflict resolution and configuration diff proposals.
3. GC: certified provider/dependency solver and quarantine-only archive
   preflight; no implicit installation from a missing capability.
4. GD: independently approved install, activation and configuration phases
   through the existing Plugin Package/Lifecycle adapters.
5. GE: provider-local certification, fault-injection, compensation and
   external recovery acceptance.
6. GF: interactive site-assistant UI with explainable approvals, evidence,
   task progress and observability.

Do not merge the hub into master or install on Staging until its existing
release gates and exact-head acceptance are met. PR #290 covers the separate
Search Intelligence audience editor and should not be merged implicitly here.

## Explicit WordPress entrypoint and decision classification (2026-10-08)

The plugin entrypoint now explicitly `require_once`-loads and calls
`boot()` for both `MAD4B_SCP_Assistant_Planning` and
`MAD4B_SCP_Assistant_Bootstrap_Diagnostic` before WordPress Abilities
registration. The Agent Registry no longer has hidden load-time bootstrap
side effects. The Adapter Registry continues to lazily register the
`assistant-planning` and `assistant-bootstrap` read adapters during the
existing `mad4b_scp_register_adapters` hook.

The bootstrap diagnostic reports a passive
`assistant_read_registration` witness for both hook bindings, local
WordPress Ability visibility and existing adapter inventory. The witness
deliberately reports `external_mcp_catalog_verified=false`; it is not a
live ChatGPT/MCP handshake, provider capability certificate or mutation
authority. A missing post-lifecycle registration remains a blocker.

The planner classifies unverified observed states without conflating them:

| Caller-reported state | Proposed review action | Safety boundary |
| --- | --- | --- |
| `missing`, required | `DISCOVER_ALTERNATIVES` | No automatic package selection |
| `unknown`, required | `VERIFY_EXISTENCE` | No absence inference |
| `degraded` | `REPAIR_CONFIGURATION` | No replacement or repair dispatch |
| `active`, provider reported without independent certificate | `CERTIFY_PROVIDER` | Never treat caller certificate as trust |
| `active`, no provider reported | `VERIFY_BEHAVIOR` | Read-only attestation needed |
| `missing`, required, dependency reported unmet | `RESOLVE_DEPENDENCY` | Dependency hint is not trusted evidence |
| optional unavailable | `OPTIONAL_NO_INSTALL` | No optional installation |
| conflicting/unbounded audience | `REVIEW_CONTEXT` | No silent correction |

All task records still carry `execution_allowed=false`,
`authority_expansion_allowed=false`, and `observation_trust` as unverified.
The new observation fields `dependency_state` and
`certification_state` accept explicit bounded enum values but never
upgrade caller claims to signed native capability attestations.

### Exact-head offline checks

The existing `feature007-manual-preflight.py` and the PHP 7.4/8.3
workflow matrix now include `tests/assistant-entrypoint-registration-runtime.php`
and `tests/assistant-convergence-runtime.php`. These hermetic fixtures
exercise all three read hook/adapter pairs, ability metadata, duplicate
registration denials, the fail-closed registration witness, dependency
ambiguity/cycles and pure CAS-transition denials. They verify the main
entrypoint wiring but are **not** live WordPress or MCP acceptance.

External closure must separately prove the deployed site, build and
`initialize`/`tools/list` match, actual read calls, authorization denial,
revocation, native PHP/DB concurrency, and browser interactions.

### Durable execution, provisioning and recovery

No new assistant-specific task journal or general-purpose autonomous plugin
installer is claimed by this foundation. The existing Control Plane
`MAD4B_SCP_Operation_Journal`, `MAD4B_SCP_Durable_Execution`, and
`MAD4B_SCP_Dependency_Manager` have separate governed ownership and
release gates. An Assistant-to-Executor bridge must demonstrate exact
site/generation/artifact/restore binding, independent provider observations,
versioned dependency solver decisions, a CAS-journaled task identity,
single-use approval, native executor readback, safe rollback, and an
externally proven no-effect reconciliation path. Until certified, the
planner never delegates `Install → Activate → Configure`, changes grants,
or marks native G4/G6/G9 capability coverage complete.

## GA–GF bounded convergence kernel (2026-10-08)

The existing `mad4b/assistant-plan` and read-only bootstrap diagnostic are now
extended by an additive **`mad4b/assistant-convergence-preview`** read Ability
and adapter. The entrypoint calls the current runtime-fenced planner itself;
it does **not** accept an unverified caller-provided task-plan SHA as permission.

The new contract `mad4b.assistant-convergence-preview.v1`:

- Accepts at most 32 unverified provider candidates; each has canonical ID,
  bounded capability list, up to 12 provider dependencies, source class,
  and optional 64-digit SHA256 package fingerprint
- Calculates a deterministic topological dependency ordering and flags
  missing requirements, cycles, missing package digest and provider ambiguity
- Requires owner choice for competing providers; never chooses a single vendor
  for an ambiguous capability
- Rejects unknown fields, objects/resources, oversized structures, duplicate
  provider/dependency names, invalid IDs and forged authorization bits
- Preserves the exact planner task ID, keeps all candidate trust
  `CALLER_ASSERTED_UNVERIFIED` and always returns `apply_allowed=false`,
  `automatic_install_allowed=false`, `authorizing=false`
- Provides the read-only task-state reducer
  `mad4b.assistant-task-transition.v1` with exact expected revision +
  preceding-event hash. This reducer produces a **NOT_PERSISTED candidate
  state**, never an actual durable CAS write or permission to execute

This deliberately **reuses** existing MAD4B Durable Execution, Remote Work
Queue, provider certification and Plugin Package governance rather than
adding an unaudited competing executor. Durable staging CAS persistence,
independent third-party provider certifications, signed Desired State authority,
governed package selection/apply and G7/G8/G9 recovery/host canaries still
require separate acceptance; none are implicitly created by this preview.

The focused fixture `tests/assistant-convergence-runtime.php` checks
dependency cycles, missing providers, ambiguity, digest/path denials, read-only
ability registration, tainted authorization/approval denials, and stale/terminal
CAS transitions. CI runs this on PHP 7.4 and 8.3. **Repository tests are not
Staging/provider/runtime acceptance**, and CI cannot be marked PASS until an
actual exact-head runner executes.

### Exact WordPress integration probe (read-only, not yet executed)

After deploying an exact, certified build to a disposable WordPress instance,
run under WordPress CLI with this plugin loaded:

```bash
wp eval-file wp-content/plugins/mad4b-site-control-plane/tests/assistant-wordpress-integration-readonly.php
```

It fails if the native WordPress Abilities lifecycle did not run or any of
the three read Abilities/Adapters is missing; verifies invalid inputs are
denied and the registered scopes remain private/read-only; records the
current PHP, WordPress and plugin entrypoint source SHA. It never
initializes a missing enrollment, triggers provider work, or mutates
WordPress settings. `LOCAL_NATIVE_REGISTRATION_PASS` is **not**
independent MCP `initialize`/`tools/list` proof, a site deployment
certificate, an authorization grant or a release approval.

## Durable *read-only* assistant work — existing queue integration

The GA–GF preview now also registers three narrowly scoped semantic work
operations in `MAD4B_SCP_Remote_Work_Queue`:

- `assistant_provider_catalog_snapshot`
- `assistant_dependency_readback`
- `assistant_configuration_diff`

All three use the existing queue's durable idempotency, exact-build identity,
leases, cancellation and uncertain-execution reconciliation. They do **not**
create a new worker, automatically enqueue tasks, invoke a model, purchase a
plugin, call an installer, grant authority or run shell/SQL.

The assistant payload has only `task_id`, `plan_sha256`,
`binding_sha256`, bounded canonical capability/provider IDs and a
purpose-specific read-only operation. The queue refuses payload/operation
mismatches, unknown fields, URLs, PHP/SQL/command arguments, object values,
and any live identity other than **Staging**. The binding fingerprint is
the SHA256 of PHP serialization of these *ordered* current-context fields:
`site_uuid, environment, profile_digest, origin_sha256, runtime_generation,
artifact_sha256, restore_epoch, external_record_sha256`. It is rechecked
against `MAD4B_SCP_Adaptive_Operations_Context::current()` at enqueue, not
trusted from caller input. A changed restore epoch, origin, runtime generation,
source artifact or environment makes the job fail closed.

These are semantic work **registration contracts**, not evidence that a
compatible external browser executor has actually processed any job. A
separate governed dispatcher/actor must still obtain the required permissions,
supply a certified read-only executor, prove postconditions, and close G7/G8/G9
native acceptance. Unfinished leases cannot be blindly retried. The included
hermetic regression checks `tests/assistant-read-work-runtime.php` cover
operation/purpose mapping, stale identity, Production denial, unsafe payload
fields and absence of privileged operations.

## GF read-only Assistant Review workspace

The WordPress Tools subpage `Assistant Review` is now registered under the
existing MAD4B Control Plane admin menu, restricted to `manage_options`.
The view reports only enrolled/bootstrap readiness, bounded known blockers,
and counts for the three semantic assistant read jobs. It deliberately
does not render provider payloads, secrets, OAuth tokens, plan approvals,
write buttons or HTML supplied by diagnostics. It never changes any setting.

The workspace distinguishes **preview eligible** from **execution ready**;
provider certification and authorization remain separate. A failed Site
Profile/runtime read displays `bootstrap_read_unavailable` rather than
claiming an operationally ready assistant. The hermetic
`tests/assistant-operator-workspace-runtime.php` covers admin capability,
HTML escaping, absence of mutation controls and read-only authority.
Real WordPress browser/keyboard/accessibility and production performance
acceptance are still independent release gates.
