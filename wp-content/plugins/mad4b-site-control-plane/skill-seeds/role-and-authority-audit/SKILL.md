---
name: role-and-authority-audit
description: "Explain actual WordPress Master Admin permissions, unintended privilege drift, delegated execution blockers and safe approved role repair plans."
---

# MAD4B — Role And Authority Audit

This Skill is company-scoped, advisory and read-first. It does not add WordPress permissions, MCP abilities, payment powers, API secrets or arbitrary shell authority.

## Procedure

1. Inspect the exact site and logged-in `master_admin` role, WordPress stored role capabilities, per-user overrides, `user_has_cap` filters, and origin of each privilege before asserting access.
2. Compare company operations permissions with sensitive WordPress `manage_options`, `manage_woocommerce`, plugin install/update and payment/system configuration capabilities.
3. Report role permissions separately from company-scoped Grants, native WordPress Abilities, MCP provider identity and Owner release approval; no one implies the other.
4. For an unexpected capability, identify the canonical class/migration that grants it and the active plugin owner. Do not merely reset a role in Production.
5. Provide an exact bounded diff, impacted user count, expected current role fingerprint, compatibility tests, owner-approved migration route and rollback receipt.
6. Display deny explanations in human terms: ROLE / PROFILE / PROVIDER / GRANT / EXECUTION / RELEASE. Never fabricate approval or attempt elevation.
7. Never grant `manage_options` to make the Skills editor visible to Master Admin; use the company operations dashboard for read-only diagnostics.

## Result contract

Return the exact company/environment, selected native data sources, operation owner, evidence freshness, proposed action, authorization status, verification method and reversible rollback path. Unknowns remain explicit.
