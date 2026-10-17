# IMP06 — Guided Import Experience, Simulated Journeys and Fail-Closed UX

**Feature 007 / PR #366 · 10 October 2026 · Source implementation only**

## Design rule

The import interface must explain *what the operator can do now*, not display all 23 internal Modes and all backend identifiers before a source has even been selected. Business-critical approval, data provenance, policy, destination verification and rollback boundaries remain independent of the UI.

The WordPress Tools → MAD4B Import Review admin screen uses `MAD4B_SCP_Activity_Import_Experience::render()` as its default entry, while the existing, separately nonced intake/approval/export/archive handlers remain the only write-like actions. No change in authorization grants, no saved APIs/secrets, no importer jobs launched.

## Four progressive operator steps

1. **Choose destination and permitted method**: dropdown populated from the live Content Experience Profile status, not a typed slug. Disabled Profiles are filtered. Site-approved Mode allowlist determines the main list. Provider installation does not imply readiness: exact Profile-bound mode preflight checks encrypted source key, signer scope, staging environment and adapter readiness. Advanced full 23-Mode catalog is on demand rather than dominant.
2. **Add source**: current review is checked first. An active or unreadable legacy review prevents a second upload; it directs the operator to the review or to an administrator. A real CSV upload is limited to 1 MiB / 500 rows. External Apps Script/Make/n8n/etc. shows a connector checklist and never asks for secrets in the WordPress page. A previously denied user-selected Mode never silently switches to another one.
3. **Resolve issues**: explicit totals for blocking errors, warnings and all issue types; readable explanations for currency, status, malformed price, missing WPML translations and relationships; previous/next page of all source issues, redacted values; optional and separately triggered paginated WordPress destination reconciliation. Comparing Meta fields does not claim provider import, WPML or relationship success.
4. **Approve fixed source and hand off**: verify current snapshot and policy; if blocked, explain required correction. If approvable, explicitly display the **entire** nonblocking commercial warning count (not only first 200 rendered), require an affirmative checkbox and pass the exact count to the existing independent nonce-protected approval. The server recomputes and compares this count against the immutable source at approval time. Issue pagination never creates a permanent deadlock when only warnings remain. Once approved, show only an exact immutable CSV download. Separate archive within a secondary disclosure, with a warning that this invalidates approval but does not delete WordPress posts.

Read-only views do not alter source data. All HTML values must be escaped and forms must preserve existing WordPress admin nonces and action IDs. Responsive and screen-reader semantics use WordPress native admin classes and `role=alert/status` where appropriate.

## UX edge cases and source-level simulated outcomes

| Scenario | Previous confusing behavior | Intended IMP06 behavior |
|---|---|---|
| New operator, no Profile selected | Freeform slug and 23 Modes | One enabled-Profile dropdown; no unsupported import action |
| Disabled/unconfigured Profile | Generic technical error after click | Explain missing site-owned Import Contract and required fields |
| 23 alternative Modes | Dense five-column table | Show permitted and exact Profile-ready methods; full catalog on demand |
| Invalid explicitly chosen Mode | Silent fallback to preferred | Reject and ask for an explicit allowed choice |
| Encryption key or connector not installed | Mode looks present/ready | Exact scoped preflight lists missing setup; no false success |
| Existing review pending | CSV button appears available | Show review link; new upload blocked |
| Legacy, corrupt or inaccessible review | Missing data interpreted as empty | Refuse a new upload; present administrator escalation |
| CSV wrong extension, oversized, bad headers/formula | Dead-end `wp_die` | Return to source step with redacted, actionable reason; no data write |
| Successful CSV staging | Redirected to full table/start | Redirect directly to issue-review step |
| 390+ source issues | Only first 200 visually | Accessible totals, previous/next pages for all issues |
| Expensive destination comparison | Automatic 25-query table on every load | Explicit opt-in, 25 records/page, previous/next, no raw prices |
| Missing Identity Meta binding | Backend error | Explain to choose approved destination Meta identity key |
| Approval blockers remain | Unclear button/state | No approval button; show blocking count and correct-source action |
| Warnings but no blockers | Generic confirmation | Show full business warning count, require checkbox and reject a stale acknowledgement |
| 390 warning-only issues | Truncated 200-item list could permanently block approval | Paginate all 390, require exact count 390; no silent acknowledgement |
| Approved source, no importer execution | Apparently complete task | Explicitly "manual CSV handoff only"; separate native WP All Import run/readback |
| External WP All Import job reports a finished hook | Treated as completed import or scattered logs | Optional advanced job ID lookup; status explicitly unverified and links to independent destination check |
| Review archived | Old source reused approval | Archive only specific receipt; follow-up upload requires new review |
| Narrow/mobile or RTL WordPress | Wide nonresponsive table/stepper | Responsive horizontally scrollable table, wrapping steps, direction-neutral margins |
| Screen reader or keyboard | Visual-only status | Labeled form inputs, table captions, alerts/status roles, aria-current step |

Simulations are declared in the isolated PHP `tests/imp06-import-ux-runtime.php` fixture. **Writing a test is not evidence it has passed**; native PHP 7.4/8.3 and WordPress/browser acceptance on the exact PR head still need to be run.

## Usability and safety acceptance requirements

- No more than one primary user action visible for the current stage, with "Back", "Next", or "Choose different method" where appropriate.
- No destructive provider post action in the UI. `approve` has `manual_approved_snapshot_export_only`, not import/publish authority.
- Every provider failure shows a usable next step, and errors never display HMAC keys, source pricing values or raw JSON.
- Issue truncation is a UI pagination indicator, **not a business validation failure**. If `block_issue_count=0`, human approval is possible only with a nonce-protected checkbox and exact `acknowledged_warning_count` that matches all warnings on independent server-side recomputation; direct MCP approval must supply the same integer.
- Business-rate fields and WPML issues are labeled for humans, but automatic correction of `ERU`, `puplished`, or pricing tier assumptions remains forbidden.
- Workflow state comes from independently verified Profile and source receipts. A query-string `staged=1` or `approved=1` by itself MUST NOT be treated as proof.
- Accessibility: keyboard-only navigation, native WP admin form semantics, descriptive labels, table headers/captions, announced warnings, mobile responsiveness, RTL direction.
- Performance: no automatic WordPress Meta comparison on page load; only an explicit paginated user request.
- Review/version concurrency: switching Profile or Mode cannot silently rebind a prior source approval or import job.
- Existing exact SHA security, nonce protection, file limits, encryption, authorization and Staging restrictions are inherited unchanged.

## Operational test plan

1. Run native PHP 7.4 / 8.3 lint and isolated UX fixture; exercise all listed input/profile/snapshot transitions and mark evidence by exact HEAD.
2. Deploy an authorized Staging build and inspect real WP Admin in mobile/desktop, RTL/LTR and keyboard/screen-reader environments; test browser history, reload, file errors and slow connection.
3. Validate exact provider status on a real site Profile: unsigned/expired HMAC, inactive source key, missing encryptor, active review, duplicate Meta identity and WP All Import wizard not installed.
4. Verify no forms call automatic WP All Import execution and that the approved CSV is byte-equivalent to approved source **at the semantic row level** (UTF-8, quoting/line endings may differ from original JSON).
5. The installed plugin's WPML/JetEngine integration, database CAS/fences, >500 row queue, Production promotion and Brand Core human authority remain independently blocked.

## Remaining UX gap (explicit)

This change does not provide in-place spreadsheet editing, a universal Web UI credential vault, full-blown importer template design, a bulk conflict resolution editor, or an end-to-end background import monitor. These must be built and separately certified before a claim of one-click universal import. Existing WP All Import job configuration and execution remain in the official plugin UI. The guided wizard only helps the operator safely complete **review and handoff**.
