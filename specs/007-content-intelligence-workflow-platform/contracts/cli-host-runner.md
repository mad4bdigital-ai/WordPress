# Contract — CLI and Host Runner

Contract: mad4b.cli-host-runner.v1

## Purpose

Provide durable local execution alternatives to manual hosting terminals while preserving the same semantic Tool Execution contracts used by MCP.

The CLI and Host Runner are adapters over core MAD4B services. They are not independent authority systems.

## Canonical command surfaces

### WordPress-local CLI

Preferred namespace:

```text
wp mad4b <family> <operation>
```

Examples:

```text
wp mad4b status
wp mad4b diagnostics schema
wp mad4b diagnostics runtime
wp mad4b diagnostics filesystem
wp mad4b package verify
wp mad4b provider diagnostics
wp mad4b staging-authority plan
wp mad4b recovery status
```

### Standalone CLI

A standalone `mad4b` CLI MAY exist for operations that must not require WordPress bootstrap.

It MUST use the same:
- operation registry;
- target identifiers;
- policy engine;
- plan fingerprints;
- receipts;
- reason codes.

CLI syntax is not a second domain contract.

## Shared service rule

MCP Ability
→ CLI command
→ Admin UI action
→ scheduled Host Runner job

must call the same application service/operation definition rather than copy business logic.

A behavior difference between frontends is a defect unless explicitly contracted.

## CLI discovery

Commands support machine-readable discovery:
- operation ID/version;
- read/write/risk classification;
- required authority;
- input schema;
- executor compatibility;
- JSON output version.

Human-friendly output is optional presentation over the machine contract.

## Read-only execution

Low-risk read commands:
- require explicit target/environment;
- run without mutation approval when policy permits;
- always emit structured JSON on request;
- record bounded evidence;
- return `mutation_performed=false`.

## Write execution

Write CLI commands MUST follow the normal:
plan → authorize → approve → apply → readback → receipt

model.

A CLI flag such as `--force` MUST NOT bypass governance.

## Host Runner

Host Runner is a non-interactive executor process running under a bounded operating-system identity.

Deployment modes MAY include:
- cron-driven account-local runner;
- long-running service/daemon;
- container worker;
- remote managed runner;
- Recovery Plane runner.

The operation contract is unchanged across deployment modes.

## In-band vs out-of-band

### In-band runner

May depend on:
- WordPress database;
- WordPress configuration;
- plugin code.

Useful for ordinary Staging/Production asynchronous host operations.

### Out-of-band Recovery Runner

Must remain usable when:
- plugin boot fails;
- WordPress HTTP is unavailable;
- the Control Plane package is broken;
- MCP route is unavailable.

It uses minimal independent code/identity and only recovery-class operations.

Recovery Runner cannot publish content or inherit normal WordPress/Host writes.

## Job envelope

HostRunnerJob contains:
- job_id;
- operation ID/version/fingerprint;
- ToolExecutionPlan hash where write;
- target identity;
- candidate/build identity;
- input hash;
- opaque secret refs;
- created/expires timestamps;
- actor/authority refs;
- approval refs;
- idempotency key;
- required runner profile;
- envelope signature/MAC or equivalent authenticated integrity;
- correlation ID.

A runner rejects:
- unknown operation;
- expired job;
- stale plan;
- target mismatch;
- candidate mismatch;
- invalid integrity;
- missing authority;
- replay outside idempotency rules.

## Durable execution

Runner state:
QUEUED
→ LEASED
→ RUNNING
→ VERIFYING
→ SUCCEEDED | FAILED | RECOVERY_REQUIRED | DEAD_LETTERED

Lease contains a fencing epoch/token. A zombie runner cannot commit after lease loss.

## Working directory

The runner never trusts ambient cwd.

Every operation maps to one named execution root:
- wordpress_root;
- mad4b_workspace;
- package_staging;
- backup_root;
- provider_workspace;
- recovery_root.

Root identity is part of target evidence.

## WP-CLI adapter

WP-CLI execution MUST:
- bind exact WordPress root;
- use explicit `--path`;
- declare plugin/theme loading policy;
- avoid arbitrary `wp eval` as ordinary tooling;
- prefer versioned `wp mad4b` commands;
- bound PHP/DB/runtime dependencies;
- normalize WP-CLI errors into Tool Execution reason codes.

For diagnostics requiring minimal bootstrap, commands MAY use `--skip-plugins --skip-themes` and load one exact MAD4B diagnostic component, provided the operation definition proves no mutation path.

## Provider CLI adapters

Provider CLIs such as hosting CLIs are optional executor adapters.

Rules:
- exact CLI binary/version is observed;
- authentication comes from a provider credential binding;
- arguments are generated from semantic operation input;
- callers cannot inject flags outside the operation schema;
- output is normalized;
- provider CLI is not assumed to exist on every site;
- API and CLI channels are independently certified.

## SSH

SSH is a transport, not a semantic capability.

Ordinary use requires:
- exact target host/account;
- known host identity;
- bounded OS identity;
- operation-generated executable/argv;
- no caller-supplied shell program;
- timeout/output limits;
- audit.

Interactive SSH remains an operator/manual Breakglass path unless separately specified.

## Hostinger validation profile

Hostinger is the first hosting validation profile, not a core dependency.

Potential channels are treated independently:
- Hostinger WordPress/MCP integration;
- Hostinger API;
- Hostinger CLI;
- account-local WP-CLI;
- account-local Host Runner;
- SSH/manual recovery where available.

Capability discovery/certification decides which channel is usable for each site/account. No channel is assumed by brand name alone.

## Fallback

Fallback is deterministic and explicit.

Example policy for a read diagnostic:
1. certified WordPress-native read;
2. certified WP-CLI mapping;
3. certified Host Runner mapping;
4. certified provider API/CLI mapping;
5. Recovery Runner when incident policy permits.

Fallback never:
- widens authority;
- changes read into write;
- changes target;
- enables raw shell;
- bypasses certification.

The selected fallback/executor is written into evidence.

## Scheduling

Runner scheduling may use cron or a service supervisor, but scheduled work references semantic jobs, not hardcoded maintenance shell strings.

Scheduling health includes:
- last pickup;
- queue age;
- lease health;
- failed/dead-letter count;
- runner version/profile;
- clock skew;
- capacity.

## Resource controls

Per execution:
- CPU/runtime bound;
- memory expectation where enforceable;
- output budget;
- disk-write budget;
- file-count/archive budget;
- network request budget;
- DB statement/row budget where applicable.

Exceeding budget fails closed and creates evidence.

## Secret handling

Runner jobs never persist plaintext reusable credentials in job payloads.

Use:
- opaque secret reference;
- least-privilege runtime retrieval;
- short-lived token where possible;
- redacted environment/logging;
- no secret echo in command evidence.

## Upgrade and compatibility

CLI/Runner versions declare supported operation-contract versions.

Rolling upgrade behavior:
- old runner may finish a leased compatible job;
- new jobs require a compatible current profile;
- incompatible queued jobs remain blocked, not silently transformed;
- runner rollback does not roll back operation/evidence history.

## Health and Doctor integration

Doctor reads:
- executor availability;
- version drift;
- stuck leases;
- orphan jobs;
- stale queue;
- target-root mismatch;
- filesystem permission drift;
- CLI binary drift;
- provider-auth readiness;
- recovery-runner availability.

Doctor produces RepairPlan only; it does not invoke arbitrary host commands.

## Acceptance

The CLI/Runner layer is acceptable only when:
- one read-only diagnostic executes equivalently through MCP and CLI;
- one async read executes through Host Runner;
- one reversible write proves plan/apply/readback/rollback;
- shell/path injection fixtures are denied;
- runner crash/lease fencing is proven;
- WordPress-unbootable Recovery Runner can read package/runtime health and restore a known-good package without acquiring content publication authority.

## WordPress-to-host bridge

WordPress MAY expose governed host-operation abilities, but the PHP request is a control/enqueue surface, not an unrestricted shell process.

Recommended generic ability family:
- `mad4b/host-operation-capabilities` — read discovered semantic operations/executor eligibility;
- `mad4b/host-operation-plan` — read-only exact plan;
- `mad4b/host-operation-apply` — submit an already authorized exact plan;
- `mad4b/host-operation-status` — read job/lease/result state;
- `mad4b/host-operation-cancel` — bounded cancellation request;
- `mad4b/host-operation-receipt` — read normalized evidence;
- `mad4b/host-doctor` — read-only executor/queue/path/runtime diagnostics.

Inputs contain semantic operation arguments only. They do not contain shell scripts, command strings or arbitrary executable paths.

### Normal route

```text
ChatGPT / Operator
  → MAD4B MCP ability
  → policy + exact ToolExecutionPlan
  → approval
  → WordPress Host Bridge
  → durable HostRunnerJob
  → Host Runner
  → fixed operation adapter
  → host/provider
  → readback
  → ToolExecutionReceipt
  → MCP status/readback
```

### Queue backends

The bridge may use one certified durable backend:
- WordPress/MAD4B database queue;
- protected filesystem spool;
- external broker/control service.

Backend choice is an implementation profile, not an operation contract.

A database-backed queue is not sufficient as the sole Recovery Plane if the database/WordPress bootstrap itself may be unavailable.

### Apply semantics

`host-operation-apply` only commits/enqueues the exact reviewed plan. It may not alter operation, target, executor, arguments, authority or recovery policy during submission.

The runner revalidates:
- plan hash;
- target;
- candidate/build;
- expiry;
- authority;
- approval;
- operation/executor fingerprints;
- commit-guard dependencies.

### Cancellation

Cancellation is cooperative unless executor profile proves verified cancellation. A cancel request never means the external side effect definitely stopped; terminal state requires reconciliation/readback.

### Hostinger-specific bridge

If a Hostinger WordPress plugin exposes useful host capabilities, a `hostinger.wordpress` adapter maps those provider calls into the same semantic operations.

If a required operation needs account-local shell/CLI behavior not safely exposed by the provider plugin/API, WordPress enqueues a bounded Host Runner operation. It does **not** call generic `shell_exec()` / `exec()` with caller input.

This is the supported meaning of “host shell execution from inside WordPress”: WordPress authorizes and submits a semantic job; a separately governed host executor performs it.

### Execution-location evidence

The WordPress Host Bridge is a control/enqueue plane.

For a queued job:
- `submission_location=wordpress_request`;
- `execution_location=host_runner` (or the exact provider executor selected by plan);
- the receipt records the runner/provider execution identity.

A direct certified provider-plugin/API mapping records its own actual execution location rather than pretending all mutations execute inside WordPress.

Status/readback MUST expose the normalized execution location without exposing secrets or host credentials.

## Runner bootstrap and enrollment

A Host Runner MUST have a governed first-install path; the platform MUST NOT assume an operator already has an interactive hosting terminal.

### RunnerPackage

Bootstrap input is an attested `RunnerPackage`:
- package/version;
- source/build identity;
- package SHA-256;
- manifest/SBOM/attestation refs;
- supported operation-contract versions;
- required runtime profile;
- entrypoint identity;
- allowed installation zone;
- rollback package identity.

Filename or download URL alone is not package identity.

### Bootstrap channels

Eligible bootstrap channels are independently certified and ordered by policy, for example:
1. provider API or provider WordPress integration able to install/schedule the bounded runner;
2. existing governed WordPress filesystem/package operation confined to the MAD4B runner zone;
3. already-certified provider CLI/SSH bootstrap executor;
4. explicit manual operator bootstrap as last resort.

The bootstrap channel does not become the steady-state execution channel automatically.

### No circular authority

Runner bootstrap is a bounded BootstrapTransition, not ordinary Host Execution.

It MAY create only:
- the exact runner package/files in the dedicated zone;
- the exact cron/service registration required by the selected runner profile;
- runner enrollment identity/key material;
- minimum queue/spool endpoints;
- health/readiness metadata.

It MUST NOT create:
- generic shell authority;
- Production Host Authority;
- content publishing grants;
- raw-SQL Breakglass;
- arbitrary cron commands;
- access to unrelated site/account roots.

### Enrollment handshake

First start:
1. runner validates its own package/manifest;
2. generates or activates a runner-specific identity;
3. consumes a single-use, target-bound, expiring enrollment token/envelope;
4. proves host target/root/runtime profile;
5. advertises supported executor/operation-contract versions;
6. Control Plane verifies expected package/target and records RunnerEnrollment;
7. bootstrap credential is revoked/consumed;
8. runner remains non-eligible for writes until certification/readiness gates pass.

Enrollment identity is not itself a grant.

### Scheduling profile

Shared-hosting profiles MAY use an account cron entry that invokes one fixed runner entrypoint.

The cron entry:
- contains no arbitrary operation arguments;
- only wakes the runner;
- does not carry reusable secrets;
- is exact-path bound;
- is read back after creation;
- has a deterministic remove/repair path.

Daemon/container/service profiles follow equivalent exact service-unit/image identity rules.

### Update and rollback

Runner self-update is NOT an implicit capability.

Upgrade:
plan exact current package → stage attested candidate → health preflight → atomic activate/switch → verify runner identity/version → retain bounded rollback → retire old package after policy.

A failed update must not strand Recovery Runner trust if the Recovery Runner is a separate package/profile.

### Acceptance

Bootstrap/enrollment evidence proves:
- no interactive terminal was required for at least one supported hosting profile;
- package bytes match attestation;
- target/root is exact;
- bootstrap scope is one-time and bounded;
- cron/service registration is exact;
- enrollment token cannot replay;
- runner identity cannot enroll against another site/environment;
- unapproved runner package is rejected;
- bootstrap does not create standing Host Write/Execution authority;
- uninstall/decommission removes scheduling/enrollment material without erasing audit evidence.


### Control Plane deployment operation

`wordpress_plugin_deploy` is a fixed Host Runner semantic operation implementing the WordPress deployment handoff without interactive Hostinger Terminal or caller-supplied shell.

The Host Runner itself remains network-disabled. A separately governed deployment connector stages the exact General Distribution bundle into the runner's fixed `package_staging/{source_commit_sha}` location. The signed job contains only the server-generated deployment plan; it never contains a caller path or URL.

The runner validates the staged bundle and installed current package before crossing the mutation boundary. It preserves the existing active plugin state by replacing only the exact plugin directory, retains a rollback directory, performs same-cycle package/provenance readback, and emits the normal Host Runner mutation journal and durable receipt.

Exact replay is permitted only while the installed package still matches the verified postcondition. Drift after a successful receipt returns reconciliation-required rather than re-executing the deployment.

The first deployment of a Control Plane version that introduces this operation still requires an already-authorized external deployment connector, because an older runtime cannot self-bootstrap a Host capability it does not yet contain. After that one-time bootstrap, ordinary subsequent Control Plane deployments may use the same governed Host Bridge → Host Runner path.
