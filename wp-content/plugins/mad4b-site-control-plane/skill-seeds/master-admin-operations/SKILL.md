---
name: master-admin-operations
description: "Coordinate company-scoped operational reviews, tasks and delegated actions for MAD4B Master Admin without WordPress global administrator privileges."
---

# MAD4B — Master Admin Operations

This Skill is company-scoped, advisory and read-first. It does not add WordPress permissions, MCP abilities, payment powers, API secrets or arbitrary shell authority.

## Procedure

1. Resolve the exact company, WordPress environment and logged-in role; confirm `mad4b_manage_company_operations` for company-owned tasks.
2. Read the Operations & Skills dashboard, catalog dimensions, company task board, active content jobs and order/inquiry summaries through existing read-only abilities.
3. Group findings by ownership: company team, content/SEO, commerce, provider/integration, infrastructure or governed release. Never silently assume authority across companies.
4. Use `mad4b/skill-match-task` and `mad4b/skill-composition-plan` only to select advisory workflows. Plans do not execute actions.
5. Delegate eligible company tasks through the original Fluent Boards/company operations permissions; never use `manage_options` as a shortcut.
6. For each request provide owner, next action, status, evidence date, current provider readiness, blockers, and linked authorized operator route.
7. Require exact native ability, tenant binding, expected current state, review/approval and readback before any change. Disallow acting from the Skill itself.

## Result contract

Return the exact company/environment, selected native data sources, operation owner, evidence freshness, proposed action, authorization status, verification method and reversible rollback path. Unknowns remain explicit.
