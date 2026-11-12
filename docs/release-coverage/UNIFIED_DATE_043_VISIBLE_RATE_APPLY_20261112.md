# Unified Date 0.4.3 — missing Rate Apply after Elementor layout change

Status: candidate-only on independent Git worktree. Original313 release authorization: not established.

## Live reproduction

On the published Tour page (post 60629), the date trigger is visible but both native JetSmartFilters Select siblings are absent from its immediate parent. The rateDateFields function requires at least two siblings to build the Rate toolbar. After clicking date Clear, stagedRateIntent is set but no visible Apply exists, resulting in Travel Dates · Pending, old query parameters, and unchanged Fluent Form #6 date. The alternate active Date Intent chip is in a hidden native widget. The legacy browser test targeting a trigger inside the missing toolbar is therefore stale.

## Candidate change

Exact live PHP (already containing native post__in=[0] fail-closed enforcement), active JS 0.4.3 and CSS 0.3.18 are source-pinned. Only two JS regions are semantically modified:

- Create a Rate Apply toolbar whenever the native Rate Date Range widget is mounted and no toolbar exists, not only if both Select widgets are siblings.
- Reuse the original four-control toolbar unchanged when both native Select widgets exist. Otherwise, build the existing styled two-control Date + Apply toolbar with a bounded two-column grid. No new AJAX provider, booking, order, payment authority, form sender or location redirect has been introduced.

When Clear is staged, the existing native rate Date Intent apply function remains the only commit path. Clicking Apply uses existing JetSmartFilters AJAX, synchronizes URL and Fluent Form #6, and rewrites WPML links.

## Verified in real browser with candidate injected into QA browser only

- Desktop 1366px: visible Apply, staged Clear does not change URL prematurely; native JetSmartFilters date_clear=1 AJAX is dispatched only after Apply; old URL dates, Fluent Form #6 date and French WPML query dates are removed. Browser JavaScript errors: 0.
- Mobile 390px: date trigger and Apply fit viewport (both at x=22, right=368); both remain visible after AJAX. The toolbar and Apply each exist exactly once. EGP query context remains, Form #6 and date query are cleared. Browser JavaScript errors: 0.
- Tested by intercepting the live 0.4.3 JS response only in the Playwright browser and providing the locally patched candidate. Tours.dev live files and Woo orders were unchanged.
- Python source tests independently SHA-verify the three file snapshots and reconstruct the byte-identical JS preimage by reversing exactly the two reviewed regions.

Evidence: candidate JSON and browser readbacks under /opt/mad4b-company-provisioning/reports/unified-local-coverage-20261111/date-v043-apply-candidate-browser-output.json and date-v043-mobile-candidate-browser-output.json.

## Release boundary

Publishing these files requires the approved governance path; no Original313 signed receipt is present and Original318 host release readiness is blocked. Do not direct-copy from an unrestricted root session or overwrite the full plugin/site. Require approved exact-file CAS and independent post-install SHA/readback, with rollback to pinned preimage. Production and Wayfatours were not modified.
