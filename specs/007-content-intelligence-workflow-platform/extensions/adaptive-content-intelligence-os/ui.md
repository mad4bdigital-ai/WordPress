# UX and Operator Workspaces — Arabic/RTL and Multilingual

Contract: `mad4b.aci-os.operator-experience.v1`.

## Information architecture

1. **Overview** — site/brand/market selector; publication state, blocked tasks, spending and evidence freshness. Never replace authorization posture with a single decorative health score.
2. **Opportunity Desk** — keyword/search/intent candidates, locales, existing asset overlap, source strength, provider price and why ranked.
3. **Evidence Lab** — source traces, snippets with usage rights, contradiction matrix, crawl receipts, refresh/dedupe, approve/reject source.
4. **Brand & Authors** — Strategy/Tone/Editorial Guideline checklist, GDrive asset classification, locale WriterProfile preview and human review.
5. **Content Planner** — topic clusters, Information Gain, blueprint dependencies, recommended link/product relations, comparative evidence.
6. **Content Studio** — side-by-side human original and AI candidate, citation per claim, section revisions, media rights/alt text, accessible preview.
7. **Native Relations** — typed source→target graph; WPML language tabs; source/meta term and IDs, mapper ownership, differences, missing/ambiguous translations, no auto-fix without explicit policy.
8. **Quality & Publish** — distinct Fact/Editorial/SEO/Relations/Rights approval rows and a separate exact PublishManifest preview.
9. **Growth Lab** — comparable GSC/GA4 conversion windows, uncertainty, baseline, commercial impact and next experiment.
10. **Action Center** — grant/site drift, provider/budget issues, unknown external effects, stale receipts, host prerequisites, approvals and supported safe remediation.
11. **Audit / Recover** — read-only job timeline, artifact chain, retries, rollback eligibility and external reconciliation.

## UX rules

- Default language uses clear Arabic white language; proper RTL labels and keyboard navigation with screen-reader announcements.
- Avoid showing machine IDs in the main surface; always available under Inspect Evidence.
- Each blocker has: explanation, affected scope, non-action outcome, permitted next action, decision owner and evidence links.
- Display explicit states `OPEN`, `IN_PROGRESS`, `BLOCKED`, `REVIEW`, `PUBLISHED`, `UNVERIFIED`; a progress bar never substitutes for acceptance.
- Interactive controls reflect live effective authority; `write_enabled` in settings is not equal to executable write eligibility.
- Cancellation/retry may not discard uncertainty, chargebacks or previous attempts.
- A human can compare Original/Proposed/Provider-Native before approval; all proposed writes show exact changed fields and rollback limits.
- Accessible empty, expired-consent, outage, missing-locale, budget-exhausted, schema-drift and RTL long-text states are mandatory fixtures.

## Canonical journey

Open a site → choose one locale and a defined growth objective → verify required Brand Context → explore an evidence-backed opportunity → approve Blueprint → draft and revise → inspect native relation graph → complete independent QA → approve exact PublishManifest separately → publish through governed WordPress adapter → read back WordPress+rendered page → compare growth outcomes after defined window → create reviewable optimization proposal.

Every screen needs its own permission/acceptance test; screenshot/static mockup does not establish browser acceptance.
