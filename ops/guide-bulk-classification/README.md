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

## 2026-11-20: Five-source day-unit recovery and scoped Guide-day approvals

Both Tours.dev Staging and Wayfatours Production were evaluated separately, preserving each site's live source fingerprints.

- 55113 Sound and Light Show: 2.5-hour Duration term proves 3 activity stops form one day. Guide in Included, but no approved half-day cost factor or assigned Guide Language. Day status remains unknown.
- 55260 Private Nile Dinner Cruise: Half Day Duration term proves 5 activity stops form one day. No explicit Guide inclusion proven. Day status remains unknown.
- 60443 Best of Egypt: NBSP and plural Days 7 recognized. Exactly 8 actual days; guide presence, duration and assignment per day are not certified, so eight unknown statuses.
- 60451 Best of Cairo: one-day Duration, explicit professional Egyptologist in Included, and guided sightseeing.
- 60455 Saqqara & Memphis: one day / nine hours, explicit Egyptologist and guided visit.
- Additional equally evidenced single-day tours 60457 (7 hours) and 60461 (8.5 hours) have separately listed Guides and guided visits.

All five previously blocked sources in each site now pass day-unit preflight (zero blocked). No original itinerary, Duration taxonomy terms or Included content was overwritten.

Four one-day tours 60451, 60455, 60457, 60461 were reviewed and approved on each site with one full_day status per source. These were explicitly delegated chat-operator approvals using authorized WP-CLI, NOT human browser clicks: approval_channel=delegated_wp_cli_operator, evidence notes, source SHA, reviewer and append-only audit metadata. Internal English base-cost readback is EGP 2,500 each. Available languages must be checked independently for other languages.

Before-write backup files:
  /opt/mad4b-company-provisioning/backups/guide-five-source-20261120/{tours-dev,wayfatours}-preapproval-snapshot.json

Source divergence preserved: Tour 60451 has different itinerary description/HTML encoding in Wayfatours and Tours.dev, although Guide inclusion evidence is equivalent. Each site's approval was based on its own live SHA. Never blindly copy source-day postmeta across sites.

Source hash now covers Included services, ordered itinerary row content, source title and duration taxonomy proof. Subsequent edits invalidate review. No Rate states, retail prices, Checkout or bookings changed. Wayfatours remains at 106 Draft and 2 Auto-draft Tour Rates.

Regression:
- tests/verify-five-source-recovery.php: recovered sources, HIGH evidence, weak evidence excluded, inclusion edit invalidates source hash.
- tests/verify-high-mockwrite.php: delegated HIGH mock review with audit, without real Tour writes, regardless of pre-approved status.
- tests/verify-approved-readback.php: postrelease four reviews and three unresolved sources.
- apply-evidenced-one-day-guides.php: scoped ID whitelist, dry-run by default, fresh hash checks, backups and no Rate changes.

Commercially unresolved: guided evening show needs half-day cost factor and Guide Language assignments; yacht guide service is not proven; Best of Egypt's eight-day guide assignment/durations still require service-level review. Unknown is NEVER interpreted as no guide.

## Native JetEngine Inner Repeater draft control

The site-neutral MU plugin mad4b-guide-jetengine-inner-v1.php injects a native nested JetEngine select named mad4b_guide_service_draft into two original registered repeaters: Tours CPT itinerary_item and Programs taxonomy program_itinerary.

JetEngine registration happens through the runtime raw-fields filter, without overwriting original CPT/taxonomy registry options or migrating stored itinerary arrays. These are DRAFT review proposals only; the approved cost source stays in the separately audited, hash-bound Guide coverage record. A draft status in the repeater does not activate costs or checkout by itself.

If an activity has multiple hourly stages but one actual day, drafts aggregate to one day. Contradictory stage drafts or drafts contradicting a previously certified Guide coverage record fail closed until reviewer reconciliation via Bulk Review. Manual non-HIGH recommendations still require evidence notes.

60443: see audit-60443-guide-days.md (eight unreviewed days, including Abu-Simbel contradiction).
55260: explicit owner no-Guide approval on both sites; EGP zero Guide cost and unchanged Tour Rate counts, with recorded pre-approval backups.
