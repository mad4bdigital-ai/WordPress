---
name: wordpress-extension-strategy
description: Decide whether a WordPress capability should reuse an existing plugin, extend it with a bounded add-on, fork it, or be built natively, while preserving MAD4B authority, portability, certification, rollback, and upgrade safety.
---

Use this skill whenever a new WordPress capability, provider integration, workflow, or customization is proposed.

## Default strategy

Prefer the smallest maintained surface that satisfies the requirement:

1. **Reuse** a mature existing plugin when its supported public API already covers the requirement.
2. **Extend with an add-on/integration plugin** when the base plugin is suitable but MAD4B needs extra behavior, governance, automation, evidence, UI, adapters, or provider-specific semantics.
3. **Fork** only when the required behavior cannot be implemented through stable extension points and the fork has an explicit ownership, merge/upstream, security-update, and exit plan.
4. **Build from scratch** only when no suitable maintained plugin exists, the required authority/security model cannot be safely layered on an existing plugin, or extension would create more lifecycle risk than a bounded native implementation.

Do not equate "possible to build" with "should build".

## Existing-plugin assessment

Before choosing an architecture, inspect the candidate plugin for:

- maintenance activity and release cadence;
- WordPress/PHP compatibility;
- license and redistribution constraints;
- stable hooks, filters, REST endpoints, PHP APIs, WP-CLI commands, webhooks, or documented extension points;
- data ownership and uninstall behavior;
- multisite and localization behavior when relevant;
- security history and current hardening posture;
- performance characteristics and background-task behavior;
- export/import and portability support;
- backup and rollback implications;
- whether updates can invalidate an integration contract;
- whether the plugin exposes only the authority needed by the MAD4B adapter.

Prefer evidence from the installed exact version and its runtime behavior over assumptions from a different version.

## Add-on-first customization pattern

When customization is required and the base plugin is otherwise acceptable, prefer a separate MAD4B add-on instead of modifying vendor files.

The add-on should:

- use documented hooks/APIs when available;
- isolate provider-specific code behind an adapter boundary;
- never patch vendor files at runtime;
- declare the exact compatible provider/version range;
- expose capability traits rather than assuming provider identity;
- bind execution plans to provider profile and certification fingerprints when the operation can mutate state;
- fail closed when the provider version or required extension point is unknown;
- preserve the provider's normal update path;
- keep MAD4B data separate unless shared storage is required by the provider contract;
- be independently activatable/deactivatable;
- provide deterministic uninstall/disable behavior without deleting third-party data;
- include a compatibility test and a runtime certification test;
- include a rollback/recovery path for any mutation it performs.

An add-on may extend UI, workflow logic, automation, diagnostics, MCP projection, evidence collection, or governed mutation semantics, but it must not silently broaden Production authority.

## Fork decision

A fork is a controlled exception. Require all of the following before selecting it:

- no safe supported extension point can satisfy the requirement;
- the forked surface is materially smaller or safer than a clean-room replacement;
- upstream security fixes can be tracked and merged;
- the fork has an owner and update SLA;
- compatibility and divergence are measured continuously;
- migration away from the fork is documented.

Never fork only to make a one-off local edit easier.

## Build-native decision

Build a MAD4B-native component when:

- the capability is part of MAD4B's authority, audit, release, recovery, evidence, or policy kernel;
- external plugins cannot provide the required fail-closed semantics;
- third-party lifecycle coupling would compromise portability or recovery;
- the function is small enough that a dependency would add more risk than it removes.

Keep native components provider-neutral whenever practical.

## Quality-tool reuse

Do not rebuild generic quality tooling inside the production plugin. Prefer maintained tools in CI or disposable environments for generic concerns such as PHP linting, WordPress coding standards, PHP compatibility, static analysis, Plugin Check, and package integrity. Add MAD4B-specific guards only for invariants external tools cannot understand, including:

- ability registration/mount/permission consistency;
- authority negative-space;
- immutable lineage and plan-digest binding;
- provider certification/profile binding;
- package/source/manifest parity;
- release/root-trust contracts;
- recovery and rollback semantics.

Runtime diagnostic plugins such as Query Monitor belong in Staging/development unless explicitly authorized elsewhere.

## Decision output

Return a decision record containing:

- requirement;
- candidate existing plugins/providers;
- exact installed/candidate versions when known;
- decision: REUSE, ADDON, FORK, or NATIVE;
- extension points used;
- MAD4B adapter/add-on boundary;
- data ownership;
- authority impact;
- upgrade/certification policy;
- rollback/disable path;
- portability/exit path;
- tests required;
- unresolved live evidence.

Do not mark a provider integration production-ready only because repository tests pass. Exact-version runtime certification and the relevant live environment gates remain separate.
