# Contract — Governed Tool Execution Plane

Contract: mad4b.governed-tool-execution.v1

## Purpose

Define one provider-neutral execution model for tools that may run through:
- MCP abilities;
- WordPress-native services;
- WP-CLI;
- a standalone MAD4B CLI;
- a Host Runner;
- provider APIs;
- provider CLIs;
- bounded SSH/recovery transports.

The semantic operation is authoritative. The command, API route, CLI syntax or transport is an adapter detail.

This contract prevents the platform from accumulating one-off shell snippets, vendor-specific control paths or privileged side channels.

## Core rule — operation before command

Callers request a semantic operation such as:
- schema.diagnostics.read;
- plugin.package.verify;
- plugin.package.apply;
- host.logs.read;
- host.files.read;
- host.files.patch;
- host.cron.health;
- host.backup.create;
- host.backup.restore;
- database.integrity.read;
- runtime.provenance.verify.

Callers MUST NOT submit arbitrary shell text as an ordinary capability.

A semantic operation MAY map to different executors on different sites without changing the caller contract.

## ToolOperationDefinition

Every executable operation has a versioned definition containing:
- operation_id;
- operation_version;
- capability family;
- read/write classification;
- risk class;
- supported environments;
- accepted target types;
- input JSON schema;
- normalized output schema;
- executor requirements;
- required capability traits;
- authority family;
- approval policy;
- idempotency model;
- expected-state requirements;
- plan requirement;
- readback requirement;
- rollback/forward-fix policy;
- timeout;
- output byte/line budget;
- concurrency/fencing policy;
- filesystem zones;
- network policy;
- secret-handle policy;
- evidence requirements;
- certification requirements;
- Production policy;
- Breakglass policy.

Definitions are content-addressed. A stale operation definition invalidates a prepared write plan.

## ExecutionTarget

Execution target is explicit and canonical:
- tenant/business;
- site_uuid;
- environment;
- host_target_id optional;
- WordPress root identity optional;
- hosting account/resource identity optional;
- provider target coordinates optional;
- target fingerprint/revision where available.

No target may be inferred only from current working directory, ambient shell state or default hosting account.

## Executor kinds

Supported executor classes MAY include:
- wordpress_service;
- wp_cli;
- mad4b_cli;
- host_runner;
- provider_api;
- provider_cli;
- ssh_bounded;
- recovery_runner.

Each executor publishes a ToolExecutorProfile and must be separately certified for the operations it claims.

Transport availability is a fact, not authority.

## ToolExecutorProfile

A profile declares:
- executor_id/version;
- executor_kind;
- provider_id optional;
- local/remote;
- requires_wordpress_boot;
- requires_host_process;
- supported operation IDs/versions;
- synchronous/asynchronous;
- max runtime;
- output limits;
- cancellation semantics;
- retry semantics;
- idempotency support;
- working-directory semantics;
- filesystem reach;
- network reach;
- secret access class;
- privilege identity;
- recovery availability;
- health/readiness evidence;
- site/runtime compatibility;
- certification state.

## Resolver

Tool executor resolution is deterministic and non-authorizing.

Inputs:
- requested semantic operation;
- target/environment;
- required executor traits;
- current certification;
- health;
- locality;
- recovery requirements;
- policy preferences.

Output:
- eligible executors;
- rejected executors + reason codes;
- selected executor or none;
- resolution fingerprint;
- non_authorizing=true.

A resolver MAY prefer native WordPress execution, WP-CLI, a host provider API or Host Runner according to policy, but MUST NOT silently downgrade to a broader or less-governed executor.

## Lifecycle

### Read operation

discover
→ resolve executor
→ authorize read
→ execute bounded operation
→ normalize output
→ redact
→ evidence receipt.

A read operation MAY omit a separate plan when policy classifies it as low-risk and non-mutating.

### Write operation

discover
→ resolve executor
→ observe exact current state
→ generate ToolExecutionPlan
→ authorize exact plan
→ approval when required
→ commit guard / revalidate dependencies
→ execute
→ readback
→ verdict
→ evidence
→ rollback/forward-fix when required.

No write may jump directly from a user string to process execution.

## ToolExecutionPlan

Plan includes:
- plan_id;
- operation definition fingerprint;
- selected executor fingerprint;
- exact target;
- observed current-state fingerprint;
- requested desired change;
- normalized argv/API request summary;
- path/resource scope;
- network destinations where material;
- expected side effects;
- mutation class;
- blast radius;
- timeout/resource budget;
- idempotency key;
- rollback/forward-fix;
- required approval;
- candidate/build/site bindings;
- expires_at;
- plan_sha256.

Plan output MUST redact secrets and MUST NOT expose reusable credentials.

## Command execution rules

Where an executor ultimately launches a process:
- use structured executable + argv, not interpolated shell strings;
- do not pass caller-controlled shell syntax to `sh -c`, `bash -c`, PowerShell expression evaluation or equivalents;
- shell metacharacters never create additional commands;
- executable is selected by the operation definition, not the caller;
- working directory is canonical and policy-bound;
- environment variables are allowlisted;
- secrets are resolved from opaque handles only at execution time;
- stdin is closed or schema-bound unless explicitly required;
- stdout/stderr are bounded, redacted and separately classified;
- timeout and kill policy are mandatory;
- child-process creation is denied unless operation definition permits it;
- interactive TTY is denied for ordinary execution.

## Path governance

Filesystem access uses named zones rather than caller-defined roots.

Example zones:
- wordpress_root;
- plugin_root;
- uploads_root;
- mad4b_workspace;
- package_staging;
- logs;
- backup_root;
- provider_workspace.

Before filesystem mutation:
- canonicalize using realpath-equivalent semantics;
- verify target remains inside allowed zone;
- validate parent path;
- reject `..` traversal;
- reject archive zip-slip;
- reject unsafe symlink/reparse-point traversal;
- revalidate target after staging and immediately before commit;
- use atomic replacement when supported.

Sensitive zones such as SSH keys, hosting credentials, unrelated sites and operating-system configuration are denied unless a separately specified operation and authority explicitly permits them.

## Database governance

Ordinary database operations are semantic and bounded:
- schema diagnostics;
- structured read;
- structured update;
- migration/repair operation;
- backup/export/import;
- integrity verification.

Generic raw SQL is not part of ordinary Tool Execution authority.

Raw SQL remains a distinct Breakglass family with separate authorization and evidence.

## Network governance

Operations that contact external endpoints declare:
- destination class;
- allowed schemes/ports;
- redirect policy;
- SSRF/private-network policy;
- expected provider/resource;
- credential binding;
- timeout/retry limits.

No tool may inherit unrestricted outbound network authority from the PHP/host process.

## Authority classes

At minimum distinguish:
- WordPress Read;
- WordPress Write;
- Developer;
- Developer Breakglass;
- Host Read;
- Host Write;
- Host Execution;
- Host Execution Breakglass;
- Recovery;
- Production Host Authority;
- raw-SQL Breakglass.

Full Staging Authority does not imply Host Execution Authority.

OAuth scope does not itself create these grants.

## Read-only diagnostic family

The generic diagnostic surface SHOULD include:
- schema.diagnostics.read;
- runtime.status.read;
- runtime.provenance.verify;
- package.integrity.verify;
- filesystem.inventory.read;
- filesystem.hash.read;
- logs.tail.read;
- php.status.read;
- database.status.read;
- database.integrity.read;
- cron.list;
- cron.health;
- process.status.read;
- backup.list;
- backup.status;
- disk.capacity.read;
- provider.runtime.read.

Diagnostics MUST report `mutation_performed=false` and provide stable reason codes.

## Write families

Examples of governed writes:
- plugin.package.stage;
- plugin.package.apply;
- plugin.package.rollback;
- filesystem.patch;
- cron.schedule;
- cron.unschedule;
- cache.purge;
- backup.create;
- backup.restore;
- database.migration.apply;
- provider.runtime.reconcile.

Each write family requires its own recovery and blast-radius contract.

## No generic shell

The following MUST NOT exist as ordinary capabilities:
- shell.execute;
- bash.execute;
- powershell.execute;
- php.eval-arbitrary;
- wp.eval-arbitrary;
- execute_command(command_string).

Developer/Breakglass operations that need code execution require a separate narrowly specified capability and authority. Production arbitrary shell remains denied by default.

## Host Runner queue semantics

When execution is asynchronous or cannot safely run in the HTTP request:
- create a durable signed/hashed job envelope;
- bind operation/plan/target/candidate;
- acquire a fenced lease;
- execute exactly one operation definition;
- checkpoint;
- write bounded result/evidence;
- mark terminal state;
- dead-letter unrecoverable work.

Retries preserve idempotency and cannot widen operation arguments.

## Failure classes

Normalize at least:
- executor_unavailable;
- executor_uncertified;
- target_state_stale;
- authority_denied;
- approval_required;
- path_policy_denied;
- command_policy_denied;
- network_policy_denied;
- timeout;
- output_limit_exceeded;
- process_failed;
- provider_failed;
- readback_failed;
- rollback_failed;
- evidence_persist_failed;
- dependency_changed;
- recovery_required.

Raw stderr is evidence, not the user-facing stable reason code.

## Evidence

ToolExecutionReceipt records:
- execution_id;
- plan_id optional for read;
- operation ID/version/fingerprint;
- executor ID/version/fingerprint;
- target identity;
- actor/NHI;
- authority decision refs;
- approval refs;
- start/end;
- exit/result class;
- bounded stdout/stderr evidence refs;
- pre/post state fingerprints;
- mutation_performed;
- readback verdict;
- rollback state;
- correlation ID;
- receipt_sha256.

## Certification

An executor/operation mapping is eligible only after relevant tests:
- structural mapping;
- exact argv/API construction;
- path confinement;
- secret redaction;
- timeout/output bounds;
- denial-path tests;
- idempotency/retry;
- readback;
- rollback where write;
- crash/lease recovery where async;
- site/runtime compatibility.

Cross-adapter conformance MUST prove that two executors implementing the same semantic operation produce equivalent normalized results and authority behavior.

## Portability

Domain workflows and Skills depend on operation IDs and traits, never on:
- `wp` binary syntax;
- Hostinger CLI syntax;
- SSH command strings;
- cPanel/Plesk/Hostinger-specific resource schemas.

Provider-specific details remain in adapter profiles.

## Production

Production use requires:
- Production-specific executor eligibility;
- Production Host Authority where host scope is involved;
- exact release/target binding;
- explicit Production approval policy;
- readback/recovery evidence.

A Staging-certified operation is not automatically Production-certified.

## Execution-location truthfulness

Every execution declares where the authoritative side effect actually runs.

Allowed normalized classes include:
- wordpress_request;
- wp_cli_process;
- host_runner;
- provider_api;
- provider_cli;
- bounded_ssh;
- recovery_runner.

The selected `execution_location` is part of ToolExecutionPlan and ToolExecutionReceipt.

Rules:
- a WordPress/MCP request that only validates/enqueues a HostRunnerJob records control/enqueue location separately from authoritative execution location;
- `wordpress_request` MUST NOT be reported when the side effect actually occurred in Host Runner/provider API/CLI;
- executor resolution binds the expected execution location before approval;
- changing execution location after approval is a material dependency change requiring replan/reapproval unless the exact fallback set was explicitly bound;
- evidence records both submission/control location and authoritative execution location where they differ;
- Production eligibility may constrain allowed execution locations independently from Staging.

This prevents a host-side mutation from being mislabeled as an in-process WordPress action and prevents an apparently harmless WordPress bridge from obscuring Host Execution authority.

## Bootstrap executor boundary

Tool Executor discovery distinguishes `bootstrap_capable` from steady-state operation eligibility.

A bootstrap executor may install/enroll one exact Host Runner under a separately governed BootstrapTransition, but that does not certify or grant the resulting runner.

Runner package identity, target root, scheduling mechanism and one-time enrollment evidence are commit-guard dependencies.

Unknown runner package, unexpected install root, reusable enrollment credential, or bootstrap request that contains caller-defined shell fails closed.
