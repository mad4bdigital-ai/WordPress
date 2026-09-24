# Contract — Supported Runtime Profiles and Compatibility Strategy

Contract: mad4b.runtime-profile-compatibility.v1

## Problem

Cartesian testing across PHP × WordPress × DB × MCP × providers × auth modes × languages × models is unbounded.

## SupportedRuntimeProfile

A profile declares an intentionally supported combination:
- PHP version/class;
- WordPress version/class;
- DB engine/version class;
- MCP Adapter/protocol;
- required plugins/providers;
- object-cache mode;
- cron/worker mode;
- OAuth authority profile;
- host/runtime constraints.

## Testing strategy

P0:
exact supported profiles receive full critical-path compatibility tests.

Broader compatibility uses:
- pairwise/combinatorial selection;
- change-impact targeting;
- provider-specific matrices;
- security-sensitive focused combinations.

## Unsupported state

A combination not represented by a supported profile or certified compatibility evidence is UNKNOWN/UNSUPPORTED, not assumed compatible.

## Upgrade

Changing one profile dimension triggers the dependency-aware compatibility probes relevant to that dimension.

## Site eligibility

SiteRuntimeCompatibility references a SupportedRuntimeProfile or an explicitly certified derivative.

## Executor compatibility dimensions

ToolExecutorProfile compatibility MAY depend on:
- operating-system family/architecture;
- PHP/runtime version;
- WordPress/WP-CLI version;
- database client/server;
- filesystem/symlink semantics;
- process-launch support;
- provider CLI version;
- cron/service availability;
- network egress;
- account permissions;
- container/sandbox mode.

An operation mapping certified on one executor/runtime profile is not assumed equivalent on a materially different profile.
