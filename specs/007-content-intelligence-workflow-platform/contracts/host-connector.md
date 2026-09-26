# Contract — Host Connector

Contract: mad4b.host-connector.v1

## Purpose
Expose host-level capabilities without widening WordPress filesystem or Developer authority.

## Separate identity
Host Connector requires its own:
- transport subject binding;
- NHI/agent grant coordinates;
- target/environment scope;
- approval/budget policy;
- audit/evidence.

WordPress administrator status alone is insufficient.

## HostTarget
- host_target_id
- provider_id
- environment
- account/site/domain scope
- allowed resources
- status

## Capability families
Read first:
- host.files.list
- host.files.read
- host.logs.list
- host.logs.read
- host.php.status
- host.cron.list
- host.cron.health
- host.process.status
- host.backup.list
- host.backup.status
- host.database.list
- host.database.status
- host.domain.list
- host.domain.status

Future write candidates:
- host.files.patch
- host.php.update-policy
- host.cron.schedule
- host.cron.unschedule
- host.backup.create
- host.backup.restore
- bounded host.database.*
- bounded host.domain.*

## Write requirements
Every write:
- exact target;
- expected current state;
- bounded desired change;
- blast radius;
- reversibility/recovery;
- approval;
- readback;
- evidence.

Arbitrary Production shell is not an ordinary capability.

## Provider adapters
Hostinger is one possible adapter. Generic host contracts cannot depend on Hostinger-specific schemas.

## Governed execution integration

Host Connector is a specialization of `mad4b.governed-tool-execution.v1`.

Host-level operations MUST be registered semantic operations. The connector does not expose generic terminal text.

Required executor/profile types MAY include:
- provider API;
- provider CLI;
- account-local WP-CLI;
- MAD4B Host Runner;
- bounded SSH transport;
- out-of-band Recovery Runner.

A hosting provider brand may implement several of these independently. Discovery of one channel does not prove availability or authority of another.

## Authority separation

Host Connector distinguishes at least:
- Host Read;
- Host Write;
- Host Execution;
- Host Execution Breakglass;
- Recovery;
- Production Host Authority.

WordPress Write, Developer, Developer Breakglass and Full Staging Authority do not automatically satisfy these authorities.

Transport/OAuth permission is never itself a host grant.

## Operation registry

Examples:
- host.files.list/read/hash;
- host.logs.list/read/tail;
- host.php.status;
- host.cron.list/health;
- host.process.status;
- host.backup.list/status;
- host.database.status/integrity;
- host.domain.status;
- plugin.package.verify;
- runtime.provenance.verify;
- schema.diagnostics.read.

Future writes are expressed as semantic operations such as `host.files.patch` or `host.backup.restore`, never as a free-form command.

## Path zones

Host filesystem operations use named allowed zones and canonical real-path validation. They reject traversal, zip-slip and symlink escape. Unrelated sites, SSH credentials and provider secrets are denied unless a separate contract explicitly grants them.

## Provider neutrality

Hostinger is the first validation profile. It may be reached through multiple independently certified adapters such as provider API, provider CLI, WordPress integration, account-local WP-CLI or Host Runner.

Generic HostTarget/operation contracts MUST remain usable for cPanel, Plesk, VPS, container, managed cloud or future providers without schema rewrites.

See:
- `governed-tool-execution.md`
- `cli-host-runner.md`
- `../references/host-provider-validation-profile.md`

## Generic WordPress bridge abilities

A Host Connector implementation SHOULD expose stable semantic control abilities rather than provider-terminal commands:
- host-operation-capabilities;
- host-operation-plan;
- host-operation-apply;
- host-operation-status;
- host-operation-cancel;
- host-operation-receipt;
- host-doctor.

Provider-specific adapter names remain internal evidence/diagnostic metadata.

A WordPress plugin adapter MAY satisfy an operation directly when certified. Otherwise it MAY enqueue an exact HostRunnerJob for a separately governed executor.

The bridge must make `execution_location` explicit:
- wordpress_process;
- host_runner;
- provider_api;
- provider_cli;
- recovery_runner.

This prevents an operator or client from mistaking “requested through WordPress” for “executed inside WordPress”.
