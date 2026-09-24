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
