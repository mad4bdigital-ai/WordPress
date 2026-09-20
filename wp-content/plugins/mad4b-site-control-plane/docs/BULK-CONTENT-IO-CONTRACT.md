# MAD4B Bulk Content I/O Contract

Status: governed planning foundation. Provider execution remains deliberately unmounted.

## Authority model

WP All Import and WP All Export are execution providers. They are never content, approval, context, or rollback authorities.

The canonical flow is:

```text
User intent
  -> Site Profile
  -> Content Operations policy
  -> exact provider job resolution
  -> exact configuration/source/schema binding
  -> dry-run / export classification
  -> approval plan
  -> composite provider execution
  -> reconciliation
  -> receipt
  -> optional exact rollback
```

Normal provider cron URLs, cron keys, caller-selected credentials, and raw provider trigger/process endpoints are not public MAD4B abilities.

## Public operation model

The only planned public execution candidates are:

- `wp-import-export/run-import`
- `wp-import-export/run-export`

Provider trigger/process/cancel primitives are implementation details of a composite operation. They must not become independently granted ChatGPT tools.

Execution is not mounted in this PR.

## Execution evidence ladder

Bulk execution readiness is intentionally split into independent evidence layers:

1. **Exact package certification** — exact provider versions and critical-file hashes match the certified repository artifacts.
2. **Transport surface observation** — the exact package exposes the intended server-local invocation surface.
3. **Negative transport canary** — an invalid/disposable request reaches the provider transport and fails closed without content mutation or secret disclosure.
4. **Behavioral execution certification** — a valid disposable provider job executes successfully under the exact current package and produces bounded reconciliation evidence.
5. **Rollback / artifact proof** — import proves exact rollback; export proves governed Artifact Registry ingestion.
6. **Operation receipt and reconciliation** — the composite MAD4B operation closes with an exact receipt and verified final state.

Passing a lower layer never implies a higher layer.

For WP All Import Pro 5.0.8, the exact package exposes the server-local WP-CLI command `all-import`. Repository CI may exercise unknown-job failure behavior, but that negative canary is **not** behavioral execution certification.

For WP All Export Pro 1.9.15, the exact package exposes a server-local `PMXE_Export_Record::execute` surface and no WP All Export WP-CLI command. Package reflection alone is **not** behavioral execution certification.

No cron URL or provider secret becomes a public MAD4B transport at any layer.

### Behavioral acceptance planning

Read-only ability:

`wp-import-export/behavioral-acceptance-plan`

Contract:

`mad4b.bulk-content-io-behavioral-acceptance-plan.v1`

The plan binds one existing provider job to the current provider artifact fingerprint, capability contract digest, candidate/configuration hashes and transport contract. It declares the exact observations that must be collected before behavioral execution can be certified.

The acceptance plan is evidence planning only:

- it is non-authorizing;
- it does not mount `run-import` or `run-export`;
- it does not create or modify a provider job;
- it requires a pre-existing saved provider job explicitly prepared as disposable test data;
- it is non-production only;
- it never accepts or returns provider cron secrets.

Import acceptance requires successful execution, exact outcome reconciliation, dry-run parity and exact rollback. Export acceptance requires successful execution, verified artifact hash, Artifact Registry ingest and unchanged content state.

A successful negative transport canary or a generated acceptance plan cannot produce a behavioral receipt by itself.

## Import exact identity

An import cannot be authorized by numeric job ID alone.

The plan must bind:

- exact provider component/version evidence;
- `configuration_sha256`;
- source artifact `sha256`;
- exact target content type / Content Schema binding;
- identity strategy fingerprint;
- deletion policy fingerprint;
- field-effect policy;
- exact dry-run result;
- Site/Profile/build/approval authority already required by the central MAD4B write path.

If any bound input changes after approval, execution must fail closed and require replanning.

### Import configuration as code

WP All Import templates are treated as executable configuration artifacts.

The raw provider options are not exposed to ChatGPT. The server computes a canonical configuration digest and returns only governed summaries and hashes.

### Source artifact

Local import sources are size-bounded before hashing. The plan records file metadata and SHA-256 without exposing filesystem paths.

Remote/unresolvable sources remain blocked from execution until a provider-safe source identity contract can prove immutable source content.

A filename or URL alone is not execution identity.

## Identity resolution

Every import must have a deterministic identity strategy.

Required cardinality:

```text
0 matches  -> create candidate
1 match    -> update candidate
>1 matches -> FAIL CLOSED
```

Title matching is not an implicit safe default.

If the provider identity expression cannot be confidently determined, the plan returns `wp_all_import_identity_strategy_unknown`.

## Delete policy

MAD4B default is:

```text
allow_delete = false
```

If the provider template can delete or trash objects missing from the source:

- the plan is classified as critical;
- `separate_destructive_approval_required = true`;
- the destructive scope must be separately represented in approval evidence;
- automatic execution remains denied until the destructive contract is certified.

If provider delete behavior cannot be determined, execution fails closed with `wp_all_import_delete_policy_unknown`.

## Dry-run

Import execution is not approvable until an exact provider-compatible simulation can produce:

- create count;
- update count;
- unchanged count;
- invalid count;
- delete/trash count;
- taxonomy changes;
- media changes.

Current contract:

`mad4b.bulk-import-dry-run.v1`

Until that simulation is behaviorally certified against the installed provider version, the adapter returns:

`mad4b_wp_all_import_dry_run_diff_not_certified`

Historical counters from a previous import are not treated as a dry-run.

## Import rollback

Required contract:

`mad4b.rollback.wp-all-import-run.v1`

The run-level rollback must be dependency-aware and must distinguish:

- newly created posts -> trash only after exact lineage/current-state checks;
- updated posts -> restore exact before-state;
- taxonomy relationships -> restore exact prior relation state;
- media created by the operation -> remove only if still exclusively owned by the rollback lineage;
- media metadata or assignments -> restore exact previous state;
- objects changed after the import -> refuse automatic overwrite.

Provider success is not rollback certification.

## Export exact plan

An export plan binds:

- exact provider version;
- `configuration_sha256`;
- field-selection fingerprint;
- filter/query fingerprint;
- data classification;
- retention period;
- exact approval payload.

Allowed classification values:

- `public`
- `internal`
- `sensitive`
- `restricted`

Retention is explicitly bounded to 1–720 hours at the plan contract.

## Export artifact boundary

Provider output URLs and secret-bearing provider paths are not returned as governed artifacts.

Planned destination:

`mad4b_artifact_registry`

Required artifact contract:

`mad4b.bulk-export-artifact.v1`

The eventual registry record must carry at least:

- artifact ID;
- SHA-256;
- size;
- MIME type;
- row count when deterministically available;
- classification;
- creation and expiry timestamps;
- provider operation lineage.

Artifact-registry ingest remains fail-closed until certified.

## Operation ledger and receipts

All future execution must produce:

- `mad4b.bulk-content-io-operation.v1`
- `mad4b.content-operations-ledger.v1`
- `mad4b.bulk-content-io-reconciliation.v1`
- `mad4b.bulk-content-io-receipt.v1`

The operation receipt must bind the plan, approval, exact provider/source/configuration identities, execution result, reconciliation result, child mutation evidence, and rollback state.

A provider "completed" flag does not mean the MAD4B operation is verified.

## Reconciliation

After import:

- expected create/update/delete counts must match actual results;
- identity cardinality must still be valid;
- target objects must match exact planned type/schema;
- taxonomy/media effects must reconcile against the operation receipt.

After export:

- produced artifact must exist;
- artifact hash/size must match registry metadata;
- classification and retention must match the approved plan;
- raw provider secrets/URLs must remain absent.

Any mismatch yields PARTIAL/FAILED verification, never silent success.

## Context and schema

Bulk content operations participate in the same Content Operations authority as direct authoring and translation.

Before execution, content-bearing imports require an exact context receipt and Content Schema binding appropriate to the Site Profile and target content type.

WP All Import does not become a second content authority.

## Current fail-closed blockers

Import:

- provider runtime unavailable, when applicable;
- provider secret missing, when applicable;
- direct execution contract unverified;
- exact dry-run diff not certified;
- run-level rollback not certified;
- operation receipt not certified;
- identity strategy unknown;
- source artifact not hash-bound;
- delete policy unknown;
- destructive delete needs separate approval;
- target content schema unknown.

Export:

- provider runtime unavailable, when applicable;
- provider secret missing, when applicable;
- direct execution contract unverified;
- Artifact Registry ingest not certified;
- operation receipt not certified.

These blockers are intentional release boundaries, not workarounds.

## Release rule

No bulk execution ability may be mounted merely because WP All Import/Export is installed or because a read/plan contract passes.

Mount requires exact-head CI plus live disposable Staging behavioral acceptance for the installed provider version, including failure, replay, drift, reconciliation, and rollback scenarios.
