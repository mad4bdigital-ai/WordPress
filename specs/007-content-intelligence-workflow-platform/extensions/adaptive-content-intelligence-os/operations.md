# Operational Runbook — ACI01 target system

Contract: `mad4b.aci-os.operations.v1`; operators must use current runtime commands from the installed MAD4B version, not invent API endpoints from this specification.

## Preflight and safe mode

Record exact Hub/build/site profile/WordPress environment, runtime generation, restore epoch, provider/version/subject, OAuth scope and current write grant snapshot. Staging hostname is *not* sufficient environment authority. Validate target site is not a clone, then inspect native relations and context with read abilities. No write while `candidate_binding_match=false`, Skills not effective, missing host sandbox or provenance unverified. Keep other safe reads usable.

## Triage decision table

| Signal | First action | Forbidden action |
|---|---|---|
| `INSUFFICIENT_EVIDENCE` | scoped reread, show missing fields | auto-approve claim |
| `WPML_POLICY_UNKNOWN` | inspect field mode and Meta ID Mapper | auto-map ID |
| `MISSING_TRANSLATION` | offer original/skip/manual policy | assert locale match |
| `UNEXPECTED_CONTENT_OWNER` | preserve live human edit and review three-way diff | overwrite |
| `BUDGET_EXHAUSTED` | pause dependent paid stages, ticket | alternate account silently |
| `EXTERNAL_EFFECT_UNKNOWN` | inspect provider receipt and journal | blind retry |
| `RESTORE_EPOCH_DRIFT` | invalidate old approvals and rebind current identity | replay old ticket |
| `AUTHORITY_NOT_CURRENT` | governed current-identity handshake and owner step-up | blanket grant |
| `SKILLS_RUNTIME_NOT_READY` | certified managed skills reconciliation | invent missing capability |
| `BROWSER_ACCEPTANCE_MISSING` | real browser and rendered readback | claim page published/SEO good |
| `HOST_SANDBOX_UNAVAILABLE` | external host operator remediation | unsandboxed Developer fallback |

## Recovery contract

Suspend affected DAG descendants; preserve immutable prior receipts; determine whether any external call or WordPress change occurred before assigning retry. If pre-state and post-state disagree, require owner-visible reconciliation. Restore of both DB/files mandates epoch switch and rediscovery of provider capabilities, grants, costs and native mapping.

## Release communications

Report `IMPLEMENTED`, `REPOSITORY_TESTED`, `DISPOSABLE_VERIFIED`, `STAGING_CERTIFIED`, `BROWSER_CERTIFIED`, `PRODUCTION_AUTHORIZED` as separate facts. Never collapse them to a single Done. Every blocked control offers a next permitted action, ownership and exact evidence.
