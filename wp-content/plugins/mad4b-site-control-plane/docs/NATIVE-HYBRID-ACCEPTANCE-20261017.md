# MAD4B Native Hybrid — source delivery

Date: 2026-10-17. Scope: Draft PR #403; Staging code only; no live deployment or Production grants.

## Execution
New installations present Native Hybrid as the default execution preference, but neither WP-Cron nor the browser starts without the administrator explicitly saving consent. An owner-saved Native Hybrid preference schedules one bounded WordPress HTTP smoke in Staging when WP-Cron is available. If WP-Cron is disabled, the browser part remains possible, and the server smoke remains unavailable.

On opening Browser Acceptance in wp-admin with that saved opt-in, a passive local browser probe runs once per tab. It loads the site homepage plus at most one same-origin known archive/category listing route; inspects actual HTTP navigation status, canonical presence, hreflang markers, listing element counts and post-ID hashes, and late JavaScript exceptions. A separately unchecked filter option can exercise one JetSmartFilters checkbox, blocking non-GET network calls and form submissions, recording only whether a fetch/XHR request and changed listing identity were observed. No raw URL, HTML, cookies, SQL, IDs or secrets are stored by the result endpoint. The action is bounded and admin-only in Staging.

## Trust and quality
WP-Cron is not JavaScript. Native browser observations are supplied by the browser session, not independent third-party attestation. Both modes ALWAYS have release_ready=false, independent browser attestation=false and filter dataset parity not independently verified. The nonce only protects the admin submission, not the truth of client-provided evidence. Missing canonical, navigation responseStatus, CSP/iframe restrictions, missing filter, or anti-bot page may produce INCOMPLETE; no protections are disabled to force PASS.

The native mode does not require external runner API credentials. The existing external provider mode remains optional for independently signed browser evidence. The existing shadow-provider behavioral recertification and SQL performance budgets are unchanged.

## Tests
New native-hybrid-acceptance-runtime.php verifies unauthorized and Production rejection, malformed fields, fake secret injections, SHA-only IDs, local positive observations never certifying release, and incomplete JS cases. Existing WP-Cron opt-in tests now verify pristine default cannot schedule. PHP 7.4 and 8.3 fixtures pass locally. Chrome/Puppeteer local fixture simulates HTTPS WordPress admin + homepage + archive + GET filter and verifies passive and manual runs. Exact-head GitHub CI pending.

## Unresolved acceptance
- GitHub exact-head PHP, MySQL/MariaDB and architecture checks.
- New Staging package upload on ETG and All Royal Egypt after explicit owner approval, then genuine CSP/iframe/WPML and AJAX smoke.
- Independent browser identity signing for full Browser Acceptance, plus verified isolated Elementor/JetSmartFilters rollback.
- Current site SQL budget violations are not fixed by this module.

## Task register
NH-001 native default, NH-002 WP-Cron, NH-003 admin DOM, NH-004 GET-only filter, NH-005 bounded evidence: SOURCE IMPLEMENTED.
NH-006 local PHP/browser tests: PASS locally / CI PENDING.
NH-007 Staging install and acceptance: NOT STARTED.
NH-008 independent signed browser certification: NOT VERIFIED.
NH-009 site-specific SQL remediation: NOT VERIFIED.

## Dynamic task-scoped functional acceptance
Native Hybrid now derives staging_acceptance_ready from the specific requested function, current package, site origin, saved owner revision and freshness of observations. An unrelated Google or independent browser gate does not cause frontend functional failure. It never grants Write or Production permission; independent browser evidence is still required only for the separate independent-browser-release scope. Details: OPERATIONAL-SCOPE-READINESS-20261017.md.
