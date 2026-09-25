# Contract — Long-Term Generalization Rules

Contract: mad4b.generalization-rules.v1

## Rule 1 — Semantic IDs over vendor IDs
Use workflow.execute, serp.snapshot, cron.run, content.publish, host.logs.read. Map vendor methods in adapters.

## Rule 2 — Stable envelopes
Jobs, artifacts, provider observations, plans, and gate decisions use stable versioned envelopes.

## Rule 3 — Site profiles hold specialization
ETG-specific destinations, taxonomies, writers, languages, SEO requirements, and source mappings belong in site/brand profiles, not generic schemas.

## Rule 4 — Capabilities compose
Skills and workflows compose capabilities; they do not become authority shortcuts.

## Rule 5 — Facts and decisions differ
Installed version is a fact.
Certified capability is a governance decision.
Upstream latest is informational.
Approval is an authority decision.
QA is a quality decision.
These fields must never collapse into one "ready" boolean without reason codes.

## Rule 6 — Evidence is addressable
Every important decision references exact artifacts, source versions, package hashes, plan hashes, and correlation IDs.

## Rule 7 — Fail closed on unknown
Unknown provider, unknown capability, unknown source authority, unknown target state, unknown host scope, or stale artifact blocks the affected write.

## Rule 8 — Replace providers without domain rewrites
A future n8n/SERP/scraper/hosting provider should require an adapter + certification, not changes to ContentJob or core artifacts.

## Rule 9 — Multi-business readiness
All reusable domain objects are tenant/site/brand scoped where necessary. No assumption that one WordPress install equals one business context.

## Rule 10 — Progressive privilege
Read → shadow → canary → reversible write → wider certified write. Do not start with broad mutation.

## Rule 11 — Semantic operations over command syntax
A Skill or workflow requests `schema.diagnostics.read`, `host.logs.read`, `plugin.package.apply` or another semantic operation. It never depends on shell text, WP-CLI syntax or provider CLI flags.

## Rule 12 — One service, many frontends
MCP, Admin UI, WP-CLI, standalone CLI and Host Runner are adapters over the same application service and policy. Frontend-specific business logic is a defect unless explicitly contracted.

## Rule 13 — Executor replaceability
An operation MAY move between WordPress-native execution, WP-CLI, Host Runner, provider API or provider CLI without changing its semantic contract.

## Rule 14 — Transport is not authority
SSH access, OAuth scope, an API token, a CLI binary or a WordPress administrator session proves transport capability only. Governance authority remains separately evaluated.

## Rule 15 — No implicit privilege fallback
Fallback can change executor only among certified candidates satisfying the same authority and semantic requirements. It cannot widen filesystem, network, database, shell or Production authority.

## Rule 16 — Recovery must survive application failure
At least the minimum recovery surface needed to inspect health and restore a known-good package cannot depend exclusively on healthy WordPress plugin boot.

## Rule 17 — Bounded machine evidence
Tool output is normalized, bounded, redacted and addressable. Raw terminal output is not the platform contract.

## Rule 18 — Path and target identity are explicit
Operations bind canonical target/resource/root identity. Ambient working directory, implicit hosting account or guessed site path is never sufficient.

## Rule 19 — Secrets are handles, not payloads
Jobs/plans/evidence refer to secret bindings or opaque handles. Reusable secret material is not persisted in ordinary envelopes, logs or artifacts.

## Rule 20 — Provider brands decompose into channels
A hosting/provider brand may expose API, CLI, MCP/plugin, local runner and recovery channels. Each is discovered/certified separately; the brand name never implies capability equivalence.


## Rule 21 — Remote operation parity
Any automation-eligible local operation must have a bounded governed remote counterpart. A wp-admin button, browser step, WP-CLI command, provider console action or maintenance screen may be a frontend, but must not be the only transport. Human-only transport gaps are engineering defects, not governance.

## Rule 22 — Discoverability before dependency
New features, operations, providers, executors and maintenance capabilities must register semantic metadata in a discoverable catalog before other workflows depend on their exact ability names. Discovery must work by intent, feature, provider, executor and authority surface without prior name knowledge.
