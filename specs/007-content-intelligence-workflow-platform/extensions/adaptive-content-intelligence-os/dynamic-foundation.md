# ACI01 Dynamic-by-Default Foundation

Contract: `mad4b.aci.os.dynamic-foundation.v2`. Status: `SPEC_BACKLOG_ONLY`. No runtime authority, Production promotion, new credentials or automatic writes.

**Dynamic is a universal invariant**, not a feature flag: no site, market, provider, locale, content recipe, workflow stage or approval gate may use a fixed universal sequence or vendor-specific assumption as its source of truth. Every decision takes a current, explicit, authenticated runtime scope and returns a bounded, explainable, versioned *non-authorizing plan*.

## Generic resolution contract

`resolve(scope, observed, policy, requested_intent) -> CandidatePlan | REVIEW | DENY`.

- **Scope:** tenant/site UUID, canonical origin, brand, environment, market, locale, account binding, artifact/restore generation, source/runtime SHA, actor/subject.
- **Observed:** certified provider capabilities and versions, actual native WordPress entity registry, multilingual field ownership, source evidence, budget reservations, host limits, freshness and capability confidence.
- **Policy:** explicit granted capability, per-action approval class, field/content owner, source rights, monetary limits, privacy/data residency, acceptable freshness, dependency predicates and eligible autonomy lane.
- **Output:** one immutable plan with selected typed adapters, exact input fingerprints, negative cases, independent certification gates, available alternatives, cost bounds, rollback requirements and blocked reasons; `authorizing=false` is mandatory.

Plan compilation is pure. Its output **never** calls WordPress, a paid provider, a host, an AI writer, or alters rights. Existing MAD4B approval/commit-time checks retain write authority.

## Per-domain dynamic rules

| Domain | Dynamic discriminator | Denial / isolated degradation |
|---|---|---|
| Tenant/site/brand | exact current site UUID, origin, runtime generation, profile revision | cloned origin or unknown site ⇒ no writes |
| Content intent | detected existing assets, target type, rights/ownership and content recipe | ambiguous owner ⇒ read/inventory only |
| Knowledge & authors | approved source assets and versioned locale/style profiles | missing Tone of Voice ⇒ pause only affected writing stages |
| Search / research | certified provider account, consent, market/device/window, quota and cost | unavailable or unpriced provider ⇒ omit paid stage, not fabricated data |
| Evidence | source trust, provenance, content license, currency/window and freshness per claim | missing/contradictory facts ⇒ REVIEW with scoped collection |
| Native relations / WPML | typed post/term namespace, field owner and translation policy | unknown mapper/semantic link ⇒ relation mutation fenced |
| Workflow | runtime capability, request schema, idempotency, lease/restore epoch, effect class | failure ⇒ affected descendants suspended; unrelated safe reads continue |
| AI drafting & QA | content type, brand and locale, evidence coverage, independent evaluator profile | self-certified/fabricated QA ⇒ blocked publication |
| Media/SEO | native provider/format/usage rights/accessible output | unsupported serialization or missing rights ⇒ blocked affected asset |
| Publishing | exact native revision, approval, permissions and rendered-readback recipe | stale plan ⇒ replan, never blind retry |
| Learning & optimization | comparable attribution, market, conversion, control and error budget | unstable or seasonally confounded signal ⇒ proposal only |
| Operator UX | effective permissions, language/RTL, blocker and supported remediations | show a truthful next action, never generic “Done” |
| Release & recovery | exact package, granted authority, site-specific native/browser evidence | stale CI/Staging/host/candidate ⇒ release blocked |

## Execution DAG versus Certification DAG

An execution dependency is a prerequisite artifact or capability for a particular operation. A certification dependency is a required independent proof before declaring one capability certified. They **must not be conflated**.

- Context, read-only native relation discovery, provider schema inventory, and read-only keyword fixture research can start in parallel after shared site identity.
- Paid research cannot start until provider-account reservation is current even if other research is ready.
- Writing requires the relevant Brand Context + Blueprint + rights/claim evidence, but need not wait on an unrelated WPML certification for a text-only draft.
- Publishing needs exact authority plus *all gates applicable to the specific manifest*, native readback and rendered verification; unrelated optional provider certificates do not become dependencies.
- Performance and restore rehearsals can run on disposable fixtures in parallel, with final release closure requiring an aggregate of applicable certificates.

The scheduler emits `READY_FOR_DISPOSABLE_TEST`, `WAITING_DEPENDENCY`, `NEEDS_EVIDENCE`, `NEEDS_APPROVAL`, `QUARANTINED` or `DENIED`; it cannot emit `AUTHORIZED_TO_MUTATE`.

## Dynamic registration and extension

New provider/locale/post type/term field is discovered into an **untrusted candidate registry**, compared against the last reviewed contract, and classified under existing typed, versioned strategies only. A novel provider protocol or write effect requires reviewed code, behavior and authority. Do not generate PHP/SQL, arbitrary symbol calls, or unbounded tools from discovered manifests. Unknown or downgraded certification, restore epoch or owner changes invalidate affected descendants.

## Adaptive stop and cost controls

Use a shared account-level budget reservation, bounded retries, deadline, concurrency and per-tenant quotas. A timeout after a possible paid or WordPress side effect enters `EXTERNAL_EFFECT_UNKNOWN` until reconciled. Cooldowns and error budgets prevent repair and growth feedback flapping. An operator can disable automation without disabling safe reads.

## Planned validation deliverables

- Versioned, typed task/requirement/gate and capability registries, with exact bidirectional linkage and independent evidence receipt identifiers.
- Acyclic execution and conditional certification graphs with fail-closed source/path authority domination.
- DomainFactAuthorityProfile and ContentRecipe registries resolved per site/locale/provider, not global tourism defaults.
- A scenario engine for positive and adversarial journeys: no-price provider, missing translation, human edit, site clone, Restore Epoch, provider cost race, unavailable browser, seasonal data, paid call uncertainty, mixed markets and Production denial.
- Structural tests + real code/disposable native/live Staging/browser/provider/performance receipts independently. A Spec-only “PASS” never counts as feature acceptance.
