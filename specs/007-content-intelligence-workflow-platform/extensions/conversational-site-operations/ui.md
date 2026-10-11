# CSO01 — UX journeys, mobile and accessibility

1. Explore: “Show fields I can edit in Rank Math” returns certified permission-filtered fields with type, owner, locale, supported operation, provenance and current redacted state; unknown fields say UNSUPPORTED, not “editable”.
2. Typed form: controlled fields (string, URL, integer, number/currency, date, boolean, taxonomy, CPT relation, media reference, nested/repeater, multi-select and rich text). Server resolves conditional dependencies and excludes unauthorized hidden selections. Autocomplete is actor/site scoped and paginated.
3. Review plan: before/after diff, affected posts/relations/SEO/cache, expected revision, cost/time, risk, irreversibility, proposed rollback and exact approval. Preview must never save or publish.
4. Governed execution: one user-confirmed exact plan, separately authorized operation, durable progress with per-item state and independent readback. Partial batch shows partial result, not success.
5. History/Doctor: what changed, by whose role, independent receipt, what can be safely reverted, what has irreversible effects, and one next safe step on denial.
6. API secrets: a user-initiated link to trusted first-party secure input; never render editable API secret in a normal chat text field. Chat receives configured/verified status, not secret value.

## UX acceptance

Arabic White Language and English; RTL labels with bidi-isolated IDs, code, hashes and URLs. Keyboard-only access, correct focus/tab order, descriptive errors, ARIA association, screen-reader tables, touch targets and responsive 320px layouts. Slow/offline connection, stale session, expired approval, plugin schema drift and interrupted operations show honest states and explicit recovery choices. Operator and advanced views may expose technical identities, but ordinary users see task, impact, status, owner and result.

Measure verified task completion, time-to-success, number of WP-admin escapes, retries, approval delays, user confusion, accessibility violations and secret-leak incidents. All metrics are redacted and tenant retention bound.

## Foundation UX critique / improvements under PR #368

The candidate adds **search → inspect → validate → stop**. Search shows only registered names in the governed read catalog, human labels and supported/unsupported state, and binds page continuations to a digest of observed entries. A missing/stale digest, actor change, unenrolled site or permission loss requires a fresh search. Form descriptors provide label/control/direction hints, never editable fields. Validation returns known field IDs and machine-readable corrections without echoing submitted values. English/Arabic/RTL renderers may use `ui.locale`, `ui.direction` and `ui.steps`, but visual, keyboard, 320px, screen-reader and browser acceptance remain OPEN until exercised in the actual ChatGPT client.

**Deliberate limitations:** no arbitrary vendor option reads, unknown storage writes, chat secrets, nested/repeater forms, rich text/media editing, approval button or automatic save. Unsupported schema should display `UNSUPPORTED` and a safe next step, not a clickable save control.

## Provider-aware UX decision table (PR #368 read-only candidate)

| Person asks for | Internal flow | What the UI must show |
|---|---|---|
| “Find my tour fields” | `cso/integration-search` → exact `cso/integration-inspect` | Bounded suggested abilities; provisional results, selected provider/status, typed Schema or Unsupported |
| “View JetEngine CPT fields” with version drift | Provider certification preflight → blocked before schema preparation | “JetEngine version differs from certified baseline. Re-certify before using this integration”; no action button |
| “Edit WooCommerce product” on site without WooCommerce | Search may find an Ability stub → provider unavailable on inspect | “WooCommerce is not active/available here”; never offer Save or execute |
| “Read WPML status” through read-only family | Exact read-schema preview, provider certification unavailable | Preview-only status and user-friendly explanation; no execution approved by form |
| “Show Fluent Forms settings” | Read metadata and definitions only | Form submission/lead values are never part of the suggested read-form payload |
| “Connect API key” | Secret-like field classifier rejects normal typed form | First-party secure handoff **not yet implemented**, with explicit not-available state |
| “Work with nested repeater/Elementor widgets” | JSON Schema compiler rejects unsupported object/array/pattern | Name the unsupported field family, recommend certified adapter; never silently flatten data |
| “Use this exact form for another role/site” | Actor HMAC, scope and descriptor hash changed | Refresh the site connection and form; do not reuse values, approvals or capability receipts |

The two discovery commands serve different jobs: `cso/form-catalog` searches strictly the existing **MAD4B core read** catalog with bounded paging; `cso/integration-search` uses the **existing unified gateway** to find other provider Abilities across the larger site universe, explicitly reporting non-exhaustive provisional results. `cso/integration-inspect` performs exact metadata preparation and provider-runtime certification preflight; only read form Schema with safe scalar constraints is renderable. A verified provider read form and an uncertified preview get different `state` values. **Neither state means permission to run the vendor Ability or to edit the site.**

The canonical user experience should prefer the unified integration search when the user describes an outcome in natural language, while still allowing advanced operators to open the core read catalog. The real ChatGPT UI form must not be called complete until actual client rendering, accessibility, error/retry and save-prevention flows are demonstrated independently.
