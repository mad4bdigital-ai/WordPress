# MAD4B — Dynamic Progressive Operation Conditions

Feature 007 / PR #258. Status: **implemented in source, not certified on live WordPress Staging**.
No Production installation, no source/credential cloning, and no automatic bypass of a native permission or approval.

## Intent

Different operations must not require a new hard-coded plugin-specific workflow.
Every attempt observes the live source of truth and works on **only what is still missing**.
A package install is an effect tied to one exact source SHA/build identity; it is
not a task automatically repeated with each diagnostic retry. A previous install
can be skipped only after an independent fresh installed-package identity readback.

## Automatic catalogs and variable schemas

- `mad4b/progressive-requirements-discover` reads the existing canonical
  `MAD4B_SCP_Operation_Registry::status()` and registered first-party provider observers.
- It also discovers currently registered **WordPress Abilities** from
  `wp_get_abilities()` as untrusted-for-write candidates. Installing a new plugin
  makes its registered Abilities discoverable on a fresh request, but does **not**
  create MAD4B approval or a custom executor.
- The exact registered planner's native input schema is inspected via
  `wp_get_ability()->get_input_schema()`. Discovery returns variable
  **name/type/required only**, never stored input values or secrets.
- The engine supports Unicode intent. An intent must resolve to exactly one
  candidate before automatic planning. Zero or multiple matches stop and ask
  for the exact discovered ID; there is no guessing of executable code.
- A registered canonical operation derives its changing required and optional
  stages from the original `MAD4B_SCP_Operation_Pipeline::compile()` at
  request time, including read, planner, approval, executor and postcondition
  bindings. Missing stage bindings stay unresolved, not silently removed.

## Two separate modes

| Mode | Contract | Allowed impact |
| --- | --- | --- |
| `detached` | Read-only current-stage prerequisite plan | No mutation or approval |
| `linked` | One exact SHA-bound pending governed handoff | May record a pending work item, but does not invoke a target executor or approve it |

The `linked` handoff is stored idempotently by **site + exact operation identity**,
not retry number. On a new observation with the same operation, a stale queued
handoff is reported for reconciliation, not recreated. A separately approved,
registered native operation must later perform the actual effect and independent
readback. Linking an action is never a substitute for the original effect approval.

## Read-only examples

Discover by intent (no operation-specific code changes):

```json
{"intent":"content.create_draft","limit":20}
```

Resolve one unique registered operation automatically:

```json
{"operation_id":"auto","intent":"content.create_draft","mode":"detached","attempt":1}
```

Inspect an ability from a third-party plugin:

```json
{"operation_id":"registry.ability","target_ability_name":"vendor/create-cruise","mode":"detached"}
```

Independent selected-HEAD observation:

```json
{"operation_id":"wordpress.selected_head","mode":"detached","candidate_source":{"repository":"mad4bdigital-ai/WordPress","type":"pull_request","reference":"258"}}
```

For a linked handoff, run `mad4b/progressive-requirements-plan` with
`mode=linked`, then separately approve `mad4b/progressive-requirements-link`
with `expected_plan_sha256`, the exact same input, and confirmation
`QUEUE EXACT GOVERNED WORK HANDOFF`. Its output is **pending**, not installed
or published.

## Dynamic condition semantics

- `hard`: cannot be dropped. Must be satisfied by fresh canonical source/Host/
  policy evidence. An unverified or missing provider still blocks.
- `revalidatable`: a fresh unmodified source-bound receipt may be reused after
  independent verification; a changed source invalidates it.
- `advisory`: only explicitly source-declared nonessential actions can defer.
  Unknown/invalid classifications default to denial.
- `postcondition`: checked after an actual authorized effect; successful
  preparation is not proof of postcondition acceptance.
- All observations must match the current site identity, operation target,
  compiled pipeline/catalog revision, and exact authorization boundary.

Changing the retry number alone **never clears** a condition. Every retry runs a
fresh catalog/identity read; it only reduces the remaining work after a canonical
upstream condition has actually changed.

## Generic operation coverage

1. The existing MAD4B Operation Registry and its Operation Pipeline provide
   dynamic, typed planner/executor/stage metadata for arbitrary registered
   operations: content, CPT/meta, translations, plugin actions, automation,
   administrative tasks, supplier/media workflows, and new certified providers.
2. The WordPress Abilities API provides dynamically discoverable **observations**
   for registered third-party capabilities. Without a canonical operation
   profile and original governed approval, the engine may not mutate them.
3. First-party selected-HEAD and Brand Core observers retain their specialized
   trust policies and exact existing state/authority verification; this engine
   is their prerequisite coordinator, not an alternative authority root.

## Operational acceptance still required

- Test native PHP on 7.4/8.3 and verify an actual WP/MCP catalog on Staging.
  A JavaScript/string structural check is not a substitute for real PHP lint.
- Validate multi-provider registration changes during the same request, ambiguous
  intent, missing native methods, schema evolution, wrong site/Host fingerprint,
  expired approvals, crash/recovery and same-operation concurrent linked requests.
- No user-supplied callback, REST route, executable, option key, arbitrary ZIP,
  alternative Host key or unsigned release input is accepted as an operation.
- On All Royal Egypt, installed rc.96 was observed at source
  `acdfa1002611e6c3553e726fe721700f19b09ed2`. These new PR #258 features
  are not in that runtime. Exact-head release artifact certification, Host
  enrollment/opt-in and governed install/readback remain separate prerequisites.
- PR stays Draft; no claim of a published Nile cruise draft or deployed package.
