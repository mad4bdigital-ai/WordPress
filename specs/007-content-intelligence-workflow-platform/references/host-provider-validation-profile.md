# Reference — Generic Host Provider Validation Profile (Hostinger First)

Reference profile: mad4b.host-provider-validation-profile.v1
First validation provider: Hostinger
Status: specification profile; capabilities require live discovery/certification per target

## Purpose

Use a concrete hosting provider to validate the generic Host Connector and Tool Execution contracts without making vendor fields part of core domain models.

## Provider decomposition

A hosting brand may expose multiple independent channels. Model each as a separate executor/provider profile.

For the first Hostinger validation profile, discovery MAY identify:
- hostinger.wordpress — WordPress/plugin/MCP-provided operations;
- hostinger.api — official account/hosting API operations;
- hostinger.cli — official provider CLI operations;
- hostinger.account_wp_cli — account-local WP-CLI;
- hostinger.host_runner — MAD4B runner under the hosting account identity;
- hostinger.ssh_bounded — bounded SSH transport where available;
- hostinger.recovery — minimal incident/recovery path.

Availability of one channel MUST NOT imply another exists.

## Capability discovery

For each target account/site, record:
- channel discovered;
- authentication mode;
- target/resource identifiers;
- read capabilities;
- write capabilities;
- documented limits;
- observed limits;
- rate limits;
- latency/timeout behavior;
- environment restrictions;
- provider-side audit support;
- rollback/recovery support;
- runtime/CLI/API version;
- certification evidence.

Unknown fields remain unknown and block affected write capability.

## Mapping examples

Generic semantic operation → possible adapter mapping:

| Generic operation | Possible provider channel |
|---|---|
| host.domain.status | provider API |
| host.backup.list | provider API / panel adapter |
| host.backup.create | provider API if certified |
| host.php.status | WP-CLI / Host Runner / provider API |
| host.cron.list | WP-CLI / Host Runner / provider API |
| host.logs.read | Host Runner / provider API |
| schema.diagnostics.read | WP-CLI / Host Runner |
| plugin.package.verify | WP-CLI / Host Runner |
| plugin.package.apply | Host Runner / WP-CLI-backed operation |
| runtime.provenance.verify | WordPress service / WP-CLI |
| filesystem.hash.read | Host Runner |
| filesystem.patch | Host Runner with Host Write authority |

This table is illustrative; certification decides actual mappings.

## WordPress plugin adapter

If a provider WordPress plugin exposes abilities:
- discover them dynamically;
- classify semantic capability;
- detect read/write/host boundary;
- certify each mapping;
- suppress or federate privileged side channels;
- do not grant Host Execution merely because the plugin runs in WordPress.

## API adapter

Provider API adapter:
- keeps provider resource IDs inside adapter/profile metadata;
- normalizes errors;
- binds credential to account/resource scope;
- obeys rate limits;
- performs readback where write;
- records provider request/operation refs without exposing secrets.

## CLI adapter

Provider CLI adapter:
- is optional;
- is pinned/certified by binary/version/hash where feasible;
- receives structured args from operation registry;
- is never exposed as generic command execution;
- normalizes output to the same contract as API equivalents.

## Account-local Runner

A MAD4B Host Runner may be used where provider API/plugin coverage is insufficient.

It:
- runs under the hosting account identity;
- receives signed/bound semantic jobs;
- accesses only configured zones/resources;
- never exposes unrestricted terminal access;
- can provide WordPress-independent health/recovery operations.

## Authority mapping

Recommended separation:
- hostinger.wordpress read/write → WordPress or provider-specific authority according to target;
- hostinger.api read → Host Read;
- hostinger.api write → Host Write;
- hostinger.cli → Host Execution;
- hostinger.host_runner → Host Execution;
- hostinger.recovery → Recovery;
- interactive/manual SSH → operator Breakglass unless separately governed.

## Certification matrix

At minimum certify:
- target binding;
- auth isolation;
- capability discovery truthfulness;
- read normalization;
- write plan/readback;
- timeout/retry;
- duplicate request/idempotency;
- output redaction;
- path confinement for local executor;
- provider/API drift behavior;
- outage behavior;
- fallback policy;
- audit evidence.

## Portability test

The first Hostinger implementation MUST prove that at least one equivalent generic operation can be remapped to a second executor profile without changing:
- Skill contract;
- ToolOperationDefinition;
- domain object schema;
- approval semantics;
- normalized result contract.

This is the acceptance proof that the architecture is provider-neutral.

## Runner bootstrap validation

The first Hostinger profile MUST discover whether a terminal-independent runner bootstrap is possible through one or more of:
- provider WordPress/plugin integration;
- provider API;
- bounded WordPress filesystem/package capability plus provider/host cron registration;
- provider CLI/SSH bootstrap executor.

No method is assumed merely because the hosting brand is Hostinger.

Evidence records:
- selected bootstrap channel;
- RunnerPackage digest/attestation;
- install root;
- wake-up scheduling mechanism;
- enrollment identity;
- one-time token consumption;
- readback;
- uninstall/rollback path.

If none is certified, the capability is reported unavailable/external-required rather than falling back to generic shell.
