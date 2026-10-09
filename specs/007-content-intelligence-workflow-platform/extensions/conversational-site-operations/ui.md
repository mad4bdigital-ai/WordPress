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
