# Feature007 — AI approval mode, WordPress admin-dashboard lifecycle audit

**Scope:** `mad4bdigital-ai/WordPress` PR #258 source, All Royal Egypt Staging read-only provenance, 2026-10-12. Source inspection is **not** WordPress admin/browser E2E certification.

## Separate semantics (must not be conflated)

- **Brand Context review policy**: persistent `human_only` or `human_and_ai`; governs whether an eligible, normalized original first-party Context asset can be reviewed by a separately delegated AI agent. It does not grant original source ownership, change source classification, or authorize a human review.
- **Standing AI operation-ticket policy**: existing `MAD4B_SCP_AI_Approval` configuration with exact agent, one-time tickets, original classification, NHI grant, Site Profile/build and audit.
- **New optional owner OAuth authorization**: `mad4b:approval:auto:all` grants the bound ChatGPT AI agent advance decision authority on eligible Staging operation tickets after **fresh explicit consent**. It is *not* an OAuth default and is not implied by `mad4b:read`, `mad4b:authority:step-up` or `offline_access`. A wp-admin cookie session cannot know if ChatGPT actually holds this scope.
- **Executing a write** remains a separate outcome after exact approval, runtime grant, applicable policy, budget, provenance checks and audit. These indicators must never be shown as interchangeable.
- The broad owner scope does not replace independent first-party owner attestation, allow Production or raw-SQL Breakglass, or convert AI approval to `approved_by>0`. Approval history must be read with current ticket audit provenance.

## Admin dashboard surface audit

| Workspace / panel | Source owner | AI approval effect / required readback |
| --- | --- | --- |
| Global MAD4B Workspace navigation and all registered admin pages | `Admin_Workspace` + `AI_Approval` | Shared compact status: standing policy, optional OAuth scope support, and explicitly unknown *remote token grant* during local wp-admin GET. Fail-closed; no auto-approval action. |
| Site Profile | `Site_Profile_Admin` | Site identity, OAuth users, exact host/environment remain authoritative. Scope never means write/Production grant. Shared workspace status displayed. |
| ChatGPT Connection | `ChatGPT_Connection_Admin_UI` | Distinguishes default connection scopes from optional `mad4b:approval:auto:all`, requiring new ChatGPT authorization/consent; does not claim existing grants are known. |
| Connection > OAuth, endpoints, isolation, certification | `Connection_Admin_UI` | OAuth metadata may advertise the scope, but only remote signed bearer proves its use. Separate endpoint and host acceptance still needed. |
| Local OAuth consent | `Local_OAuth_Server` | Separate text explaining broad AI decisions, exact client and Staging; consent signed and scope is not silently added to old tokens. |
| Governance & History > Agents, Approvals, Consent, Mutations, Audit | `Admin_UI` + `Approval_Tickets` | Existing snapshots/recovery, one-time ticket and ledger remain authoritative. Global notice states alternative approval policy. |
| Approval Decisions > Needs action / History | `Approval_Decision_Admin` | This is the human action inbox. A parallel AI-decision lane can decide eligible tickets. Shows `approved_by>0` as WP human and `approved_by=0` as **nonhuman/unattributed — verify audit**; never silently label unknown historical records human. |
| Brand & Context > Review, Sources, Assets, Quality | `Context_Admin_UI` | Scope of `Human only / Human + AI` label is Brand *asset* review, not global AI ticket approval. Original owner proof/quarantine is unaffected. |
| Action Center / Guided Operator and Operator Workspace | `Operator_Control_Center`, `Guided_Operator_Experience`, `Operator_Workspace` | Readiness and human-ticket prompts remain conditional; global notice prevents assuming the remote scope was consented to. |
| Content Pipeline / Search Intelligence / Dynamic Content | Pipeline admin and Search admin | Approvals of content changes still require exact content/Context/SEO/source-rights gates; an AI-approved ticket does not imply a publish outcome. Shared notice. |
| Provider Coverage / Runtime Components / Managed Skills | Individual admin panels | Tools may be available but noncertified; scope does not install providers or bypass execution gates. Shared notice. |
| Browser Acceptance / Performance / MCP Diagnostics | Individual admin panels | Separate browser, PHP, host-isolation, performance evidence needed; AI scope is not runtime acceptance. Shared notice. |

## Source remediation on #258

1. Added `MAD4B_SCP_AI_Approval::dashboard_projection()` with an explicitly non-authorizing, request-local interpretation of available scope vs *verified current* remote OAuth token, no write effects.
2. Added a single read-only expandable `render_admin_status()` panel to the central `Admin_Workspace` chrome, covering **all registered MAD4B admin screens** rather than fragmenting visibility across pages.
3. Corrected `ChatGPT_Connection_Admin_UI::status()` and rendered connection instructions to distinguish optional broad AI scope requiring reconsent from defaults and to represent remote token consent as unobserved.
4. Corrected `Context_Admin_UI` asset review labels and explanation; clarified `Approval_Decision_Admin` is a human inbox and exposed ticket approver evidence in history without guessing.
5. Corrected `Staging_Write_Authority::approval_policy_projection()` to show the owner OAuth current-request exception separately from legacy standing policy rather than saying all remote writes have no exception when a verified delegated bearer is active.
6. Added `tests/admin-ai-approval-dashboard-runtime.php` and native PHP 7.4/8.3 lint entries for all touched dashboard PHP files to `tests/feature007-manual-preflight.py`.

## Negative and acceptance cases required

- Existing token with `mad4b:read mad4b:authority:step-up offline_access` **cannot** approve tickets under the new owner OAuth delegation without fresh consent.
- Page views with a WordPress cookie and without a bearer show scope as **supported**, not activated; site/profile/environment mismatches and Production show it unavailable.
- Old `human_only` Brand policy remains human-only for original source reviews, while the separate owner OAuth policy may permit **eligible operation ticket** AI decisions; approval cannot transform ownership.
- Pending ticket → AI decision → exact payload/candidate/agent/scope and permission checks → one-time execution → readback → audit `approver_type=ai_agent, approved_by=0` → display history.
- Missing NHI grant, expired token, revoked delegation, stale payload, changed candidate, unsupported origin, noncanonical ChatGPT client, host constraints, Production and Breakglass must remain fail-closed.
- Browser accessibility, CSS/JS behavior on all 14+ registered dashboard routes, PHP 7.4/8.3 native runtime fixture and exact-head signed Staging package/readback require **independent executed receipts**, not static assertions.

**Live installed source at review:** `f139203d03e1b39270ad4210725d0b3e374ebe11`. The newer PR head was **not installed**. Dashboard source improvements do not prove live UI acceptance or consent.
