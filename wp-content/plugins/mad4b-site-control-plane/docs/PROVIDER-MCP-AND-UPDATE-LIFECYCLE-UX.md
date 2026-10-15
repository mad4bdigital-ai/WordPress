# MAD4B Plugin-wide MCP routing, Action Center and post-update lifecycle

Status: implementation + bounded review; **not live-site acceptance**.
Scope: all provider adapters and all environments. No new mutation authority.
Related PR: #395 (source review), base PR #394, protected workflow PR #389.

## Native MCP evidence is a separate dimension from MAD4B execution eligibility

Path A: ChatGPT -> governed MAD4B MCP -> WordPress plugin adapter -> provider.
Path B: ChatGPT -> a separately enrolled provider-native MCP client -> provider.
Path C: MAD4B -> reviewed provider-native WordPress Ability / native MCP -> provider.

These paths are not interchangeable. A registered MAD4B Elementor or JetSmartFilters write operation
that is blocked by MAD4B policy is **not** proof the underlying plugin lacks editing functionality.
Conversely, discovering a provider-native write tool is **not** authorization to bypass site-bound
owner OAuth, exact plan/ticket, WP privilege, data/rollback contract, append-only audit and new-request readback.
Native provider-owned side channels cannot be automatically enabled to "fix" an authorization error.

The on-demand Provider Coverage **MCP Routes** tab performs **local WordPress Abilities discovery only**.
It reports the path origin as unverified unless exact published identity evidence is known;
it never claims that an external MCP `tools/list` exchange has happened.
For full discovery an **explicit external provider-native MCP connection** and its consent
and tool schema must be reviewed separately, then bound to the governed tool path.

Current representative bounded inventory:

| Plugin | Known read path example | Known write path example | What this release can establish |
|---|---|---|---|
| Elementor | `elementor/get-document`, `elementor/validate-document` | `elementor/update-widget-settings` | Only observed local MAD4B adapter / ability presence; external native MCP not scanned |
| JetSmartFilters | `jetsmartfilters/get-filter`, `jetsmartfilters/get-query-binding` | `jetsmartfilters/update-filter-meta` | Same distinction; policy block is not proof of provider incapability |
| JetEngine | Native inventory / configuration operations | Governed create/query operations | Separate reviewed JetEngine client bridge exists; live exact native transport acceptance still needs explicit test |
| WPML | Native settings / relation checks if registered | No direct write route attested here | Do **not** infer WPML native write is impossible from absent registered ability |

### Per-provider decision order (applies to all plugins, including future providers)

1. Inspect actual installed plugin version, WP Ability registry and exact MAD4B adapter mapping.
2. Ask each **authorized** provider-native MCP client (when separately enrolled and supported) for its
   current `tools/list` / schema; explicitly mark `NOT_PROBED` if absent. Never claim exhaustiveness from MAD4B-only discovery.
3. Match the requested operation to a unique provider-native tool with exact identity, schema,
   write annotations, tested version and a confirmed admissible governed execution lane.
4. Prefer that native operation **under MAD4B governance** if it passes source-specific security
   and rollback constraints. Otherwise evaluate the MAD4B Adapter for equivalent safe behavior.
5. If neither route passes, report the precise layer: transport unavailable, native not probed,
   semantic ambiguity, adapter missing, execution policy denied, provider runtime denied,
   approval required, postcondition unverified.
6. Never use transport switching, provider REST fallback, generic execution, direct PHP callback
   or standalone MCP side channel to circumvent MAD4B Write denial. Production remains separately scoped.

## Workspace information architecture and page responsibility

| Page | Role | What it does not do |
|---|---|---|
| **Action Center** | High-level triage, priorities, owner, environment and correct next screen | No direct mutation, consent, ticket creation or release acceptance |
| **Operational Remediation** | Detailed recovery decision, unresolved issues and exact next step / readback | No silent mutation or replacement of the governed executor |
| **Approval Decisions** | Display pending/approved exact owner tickets and decision history | A read-only link does not create or approve a ticket |
| **Provider Coverage -> MCP Routes** | Compare registered adapter and native WordPress Ability evidence; show external discovery gap | No external native tool probe on regular admin GET, no provider mutation |
| **Provider Connections** | OAuth / provider connection troubleshooting | No scope expansion without consent |
| **Runtime Components** | Installed package versions, update evidence and maintenance | No assumption of release trust from version badge |
| **Governance & History** | Effective grants, audit, consent and mutation reconciliation | Does not authorize arbitrary new operations |
| **Brand & Context** | Provider-scoped source choice, ownership and policy review | No automatic Google reauthorization or quarantined ownership transfer |

The operator should select the *goal* in Action Center; the details stay on the
owning page. All buttons must land on a registered action/page, never generic Overview.

## Post-update policy: Staging vs Production

**Before any update:** read current source SHA, release channel, version, manifest,
Site Profile environment and origin, backup/rollback readiness. A valid persistent
host binding must survive normal update with no key rotation or second binding approval.

**Staging, after update:** check installed source digest, MCP/OAuth and database health.
Reuse valid native binding; if genuinely missing, separately authorize the one-time native
binding ticket and verify on a **new request**. Recompute an exact Write plan after Site
Profile revision change, then use enrolled owner Step-Up to attest/claim only that plan.
Resolve Google and legacy Context independently; do not infer approval from install success.

**Production, after update:** perform a **read-only** provenance and runtime reconciliation
first (source SHA, trusted release manifest/signature, DB topology, Site Profile, OAuth/MCP,
provider status, audit/rollback). Never auto-grant Write, auto-create host binding, auto-enable
Developer/Breakglass, or assume previously valid Staging grants imply Production permissions.
Production updates themselves remain possible **only** under the existing dedicated
`MAD4B_SCP_PRODUCTION_SELF_UPDATE_ENABLED` opt-in, exact Site Profile Production Write policy
and governed release-channel gates. WordPress core automatic-update selection is a separate
observed policy; MAD4B does not automatically opt the plugin into that channel.

If a Production self-update completes but a readiness gate fails, record `REVIEW_REQUIRED`
with the exact failing layer. Continue safe read-only diagnostics and keep writers fail-closed;
an explicitly approved, independent Production change is required to repair the state.
No implicit "Staging Recovery" executor runs just because Production sees a new package.

## Acceptance criteria and known outstanding work

- Admin GET (Action Center / Provider Coverage / Operational Remediation) has no writes.
- Native-WP-Ability registration != external native MCP tools discovery; unknown != unsupported.
- No automatic provider mutation, extra grants, cross-site ticket or secret leakage.
- PHP 7.4 / 8.3; source route, production policy, and browser navigation tests pass.
- Provider-native MCP `tools/list` across actual installed vendors and exact governance
  integration must be **live verified**; this patch alone cannot certify all external clients.
- Full one-click host-binding ticket creation/approval/claim was **not** delivered; its separate
  identity-and-owner ticket must be integrated without bypassing approval.
- Production real self-update / post-update test requires separately authorized Production
  staging-equivalent acceptance before any Production mutation; this code change does none.
- Exact GitHub CI head, approved release package and ETG/ARE on-site readback are outstanding.

## Action Center consolidation follow-up

The expensive WordPress recovery plan is owned by **Operational Remediation** with explicit `?recovery=show` opt-in. Action Center remains the light triage dashboard and links to that registered remediation route. The static lifecycle test now enforces exactly this single rendering owner; deep plan computation does not happen on ordinary Action Center GET.

The MCP Routes tab also avoids invoking the expensive full Plugin Coverage snapshot. It lists locally observed WordPress Ability namespaces from all providers, but never interprets namespace presence as signed provider-native tool identity or authorization. Full external MCP tools/list remains a separate authorized probe.
