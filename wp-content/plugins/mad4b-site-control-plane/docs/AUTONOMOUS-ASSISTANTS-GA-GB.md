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
