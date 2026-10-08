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

The existing `feature007-manual-preflight.py` and the GA/GB PHP 7.4/8.3
workflow matrix now include
`tests/assistant-entrypoint-registration-runtime.php`. This fixture
exercises both hook callbacks, adapter inventory, actual ability
registration metadata, duplicate registration denial, and the fail-closed
registration-witness behavior. It also verifies the source entrypoint
wiring. It is a **hermetic test**, not a real WordPress/MCP acceptance run.

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
