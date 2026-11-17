---
name: incident-and-release-recovery
description: "Coordinate WP-Cron, CPU load, cache and provider incidents through governed evidence-first recovery and dependency-aware releases."
---

# MAD4B — Incident And Release Recovery

This Skill is company-scoped, advisory and read-first. It does not add WordPress permissions, MCP abilities, payment powers, API secrets or arbitrary shell authority.

## Procedure

1. Establish site identity, environment, incident time, affected users, CPU/PHP/DB baseline and recent changes before taking action.
2. Inspect WP-Cron vs Host Cron ownership, Site Health, bounded worker queues, retry ledger, Redis/object cache, FastCGI cache epoch, CSS/JS delivery and error logs.
3. Classify issue as infrastructure, provider, content, release regression, permission or stale cache; prefer a small reversible isolation rather than blanket purge or restart.
4. Correlate current Git/tree digest, original native release receipt and active site bytes. Source tests never count as Production acceptance.
5. Build an ordered recovery DAG: capture evidence → contain user impact → approved owner fix → source/host readback → language/browser/commerce acceptance.
6. Keep expensive tests event-driven and incremental; suspend optional work under CPU pressure. Never bypass Production release/grant controls.
7. Report before/after metrics, irreversible steps, rollback proof, remaining risks and a specific closure criterion.

## Result contract

Return the exact company/environment, selected native data sources, operation owner, evidence freshness, proposed action, authorization status, verification method and reversible rollback path. Unknowns remain explicit.
