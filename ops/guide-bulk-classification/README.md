# MAD4B Bulk Guide Days Classification v1

## Installed components
- wp-content/mu-plugins/mad4b-guide-service-composition-v1.php
- wp-content/mu-plugins/mad4b-guide-bulk-classification-v1.php
- wp-content/mu-plugins/assets/mad4b-guide-bulk-v1.js

Admin URL: /wp-admin/edit.php?post_type=tours-and-activities&page=mad4b-guide-bulk-days

## Workflow
1. Read-only paginated AJAX inventory, 12 sources at a time. Deduplicates WPML siblings and shared Nile cruise Programs into their canonical source.
2. Source-based day classification with proposed status, confidence, reason and short direct evidence excerpt. Recognizes explicit guide full-day, guide half-day, guide not required, and optional guide statements as HIGH evidence. Plain sightseeing and transfer text is not commercial proof.
3. Multiple labeled day rows represent real days. One-day/half-day/hourly Tour titles allow their multiple activity stages to be grouped into exactly ONE guide day. All other unlabeled multistage structures are blocked, not priced.
4. Operator selects sources and explicitly confirms HIGH-only batch approval. Server reclassifies and updates only unknown day statuses; never overwrites existing approved statuses. Missing half-day policy factor prevents bulk half-day approval.
5. Review individual sources to approve medium/low/unknown suggestions with an evidence note of at least 12 characters when deviating from HIGH evidence. A half-day requires separately approved multiplier.
6. Every write rechecks permissions, AJAX nonce, source ID, exact canonical itinerary identity, source hash, previous revision and an in-flight source lock. Approval stores review method, operator, timestamp, revision and original evidence, and appends an audit-meta receipt. Source text edits invalidate the saved review. Stale source locks expire after 120 seconds.
7. No changes to Tour Rate price, order, supplier contract, Checkout, new Rate generation or published tour status.

## Sites and acceptance
Deploy on Tours.dev staging first; roll out identical SHA to Wayfatours Production only after staging lint and acceptance.
- tests/verify-readonly.php: no duplicate WPML / program sources, no unsafe day count, stale hash and revision rejected.
- tests/verify-mockwrite.php: safe partial manual review, evidence receipt and audit, concurrency rejection, with post-meta writes intercepted (no live Tour data modified).
- tests/verify-classifier.php: positive, negative, ambiguous, optional and duration evidence rules.
- tests/verify-admin-ui.php: WordPress admin menu, source editor and AJAX actions.
- tests/coverage-readback.php: current source count, blockers and high/medium/no-evidence distribution.
- Existing guide-package-composition acceptance tests: cost basis, inclusion delta, Form #6 preference.

No HIGH classifier suggestions should be auto-approved by deployment itself; only a separate explicit operator AJAX approval may save a classification.

## Commercial boundary
The classification layer may store source-day statuses after a human operator approves. Incomplete/Unknown day coverage blocks Guide cost. Complete Guide cost remains internal EGP cost, not a direct adjustment to the customer Form #6 selling price, the native Tour Rate, or Checkout. Full cost composition still needs hotel, transport, extra supplier evidence, pricing policy and currency/occupancy validation.
