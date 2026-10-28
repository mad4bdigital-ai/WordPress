# 0.16.0-qa.11 — 2026-10-26 (Rate overlap and quote bridge QA)

- Added TourRateResolution for overlapping candidate Rates and explicit source-saved selection. Gold Tour 60645 has winter Rate 61148 and 61158 both eligible for the same date: never auto-pick the first or cheapest.
- Original saved Rate 61158 can be recognized in Order First read-only preview, and candidate alternatives are shown without granting commercial price, Order Item or booking.
- Narrow Stream01 Quote Gate 0.4.1 now resolves original native CPT Woo Product IDs, not duplicate Product posts. Unsigned sale requests remain blocked, and a valid booking-configuration preview is not financially authorized.
- Existing certified sale issuer remains blocked by pricing owner controls, and original Rate EUR currency mismatches WooCommerce USD. No paid Order Item can be issued honestly yet.
- Stream02 native supplier validator now refuses any Order created as commerce_intake_nonfinancial even if a genuine CPT Product Item exists, awaiting an explicit persisted commercial transition.
- Rates 13/13, Order First+REST 34/34, quote-source guard 15/15, supplier inquiry guard 6/6 PASS. Existing suite verified, no permanent fixture data.
- All feature flags OFF. No GitHub/Staging/Production deployment.

# 0.16.0-qa.10 — 2026-10-26 (Tour Rate source/season/date preflight)

- Added TourRateEligibility: requires published Tour and Rate, related_tour_id and _etg_last_synced_tour_id exact match, valid Rate Unix date range and enabled season term meta; handles cross-year season and partial Rate windows.
- Source Rate 61158 matches Tour 60645 and winter season; partial summer QA-only Rate 66840 belongs to Tour 60437 and is blocked by stop_sale and QA fixture guard.
- The active JetEngine runtime has NO Tour-to-Rate relation object; both source Rate meta fields are validated as evidence, not financial authorization.
- Integrated selected-date read-only diagnostic into Order First original Request and protected GET REST using travel_date. Rate ID comes from saved Request, not visitor input.
- Fixed wrong HTTP 200 for calendar-impossible dates by forwarding the validation error, now guarded by regression tests.
- Tour Rate checks 18/18 PASS, Order First with date REST 33/33 PASS, original CRM/CPT/Order Item and engine regressions passed. No Order Item, quote/payment or supplier booking writes.
- Still open: certified quote, rate precedence, chosen accommodation/options, price validity, transactional Order Item and supplier booking. Flags OFF, no GitHub/Staging/Production changes.

# 0.16.0-qa.9 — 2026-10-26 (CPT Product Integration correction; Local QA ONLY)

- Corrected product identity: active Custom Post Type WooCommerce Integration v2.2.11 exposes the ORIGINAL service CPT ID as Woo Product identity. Do not create duplicate post_type=product records from WooCommerce Travel Bridge.
- Confirmed live settings selected tours-and-activities (original source 60645) and tour-rates (Rate 61158). The source WC Product ID equals 60645, with existing CPT price metadata that IS NOT a certified travel quote. Other kinds such as properties are not configured and remain blocked.
- Replaced an uncommitted experimental Product provisioner with a read-only CPT Product identity inspector. The Order First draft preview now reports the original CPT Product ID and rate reference, without authorizing Order Item creation, supplier fulfillment or financial checkout.
- Removed the unlinked, private technical QA Product 66904 created by discarded experimentation through verified WC CRUD. Separate Product post count restored to 6, Orders to 30; no other Product changed.
- CPT identity QA 13/13 PASS; Order First source mapping and REST 28/28 PASS; CRM First 20/20, native Order-before-Booking 10/10, pure core suites pass.
- No Order Items or supplier bookings created. Certified Rate relation, quote authority, other service types and cross-channel adapters remain gated. No GitHub/Staging/Production changes.

# 0.16.0-qa.8 — 2026-10-26 (Order First canonical nonfinancial Woo draft, local QA only)

- Added IntakeOrderDraft, a default-OFF, staff-only WC HPOS checkout-draft source order factory for original verified Order First Request Registry cases and existing proven FluentCRM contact. It creates a real Woo Order from an original source, stores source/policy/channel hashes in private Order meta, and links the canonical Order ID to exactly one intake case.
- Order has inquiry_only business state, not_quoted monetary state, no approved amount, no payment/checkout authority and NO fabricated 0-priced WC Product Items. Native supplier booking owner refuses it until authoritative product/item and rate certification.
- Replays verify order status and every immutable private meta field; persistent case uniqueness and creating_order_draft reconciliation state avoid duplicate Orders on interrupted writes. Woo exceptions fail closed for manual reconciliation.
- Protected owner-only GET/POST /commerce-composition/v1/intake-cases/{request_id}/order-draft; POST refuses user-supplied customer, currency, quote or identity fields.
- Native test 26/26 PASS after using explicit exact-match fixture cleanup rather than a long SQL transaction across Woo hooks. One exploratory rollback test exposed WordPress transient invalidation/transaction interference, and the leftover incomplete test Order was verified and cleaned by scoped WC CRUD; final QA returns exactly 30 original orders.
- Remaining P1-10 gaps: map/validate actual source Product Order Items for all services, quoted totals and displayed on-request pricing, CRM First acceptance->Order conversion, non-web adapters, native business Board, concurrency/fault recovery, and browser/user-role acceptance. No GitHub/Staging/Production release.

# 0.16.0-qa.7 — 2026-10-26 (Local QA ONLY)

- Added site-owned routing policy with CRM_FIRST, ORDER_FIRST and manual review; rejects conflicting service-specific policies.
- Added independent native InnoDB case registry keyed by original Request ID, immutable source/policy digest, and verified pre-existing FluentCRM contact. Source evidence is native saved Forms. It does not create FluentCRM subscribers, native Boards tasks, Woo Orders, invoices or supplier bookings.
- Owner-only GET/POST /commerce-composition/v1/intake-cases/{request_id}; payload cannot choose contact, mode or Order. Both feature and routing policy OFF by default. CRM First persists a case-only receipt; Order First blocks until certified source-neutral Woo Order factory.
- New routing policy 9/9, native case REST/source proof 20/20, original REST 18/18 and Order-first native gate 10/10 PASS. Fixture writes rolled back.
- Existing web Request is the only verified operational intake adapter; external channels require separate proven sources. No live CRM Feed, business Board, customer communications, payment or remote deployment enabled.

# 0.16.0-qa.6 — 2026-10-26 (Order-first Local QA safeguard)

- Owner decision: every supplier booking requires a canonical WooCommerce HPOS Order and its validated product Order Item, independent of original intake channel.
- Retired the experimental Stream02 direct Request Registry-to-booking plugin from isolated QA activation, preserving code and historical evidence. The original Woo Order/Item-backed supplier owner remains enabled.
- Changed the private, read-only supplier preflight and operator Inbox to display awaiting_woocommerce_order with explicit Order and Item prerequisites; no supplier mutation endpoint added.
- Existing nonfinancial Request Registry remains source intake evidence, NOT a booking Order. P1-10 opens the missing omnichannel unpriced Woo Order conversion; the priced local native draft factory is not general enough.
- Negative original owner Order/Item guard 10/10 PASS, supplier readiness 38/38 PASS, operator REST 18/18 PASS, core 91/91 PASS, process exits zero.
- No direct source bookings, money, customer mail or historical supplier data changed. No GitHub/Staging/Production deployment.

# 0.16.0-qa.5 — 2026-10-26 (Local QA; NOT RELEASED)
- Connected the Commerce Request Supplier Readiness metadata to a genuine source-owned Stream02 native Request Registry booking extension using a read-only WordPress capability filter, without coupling Commerce to Stream02 class names.
- Displays contract installed but OFF, or explicit owner/event approval pending, rather than implying the source contract is missing.
- Preserves separate supplier write ownership: no Commerce supplier POST, no synthetic Woo Order, no automatic confirmation or customer messaging.
- Native Stream02 supplier tests 61/61 PASS with exact rollback and private proof cleanup; Commerce preflight 38/38 PASS; both native feature and event-consumer compatibility approvals remain OFF.
- Original Woo item booking flow and its historical dirty source files untouched, no GitHub/Staging/Production changes.

# 0.16.0-qa.4 — 2026-10-26 (isolated QA, NOT RELEASED)

- Added site-owned generic native supplier readiness registry (capability and readiness class are WordPress configuration, never hardcoded business-specific names). The isolated QA option is read-only; missing provider configuration fails closed.
- Added strictly read-only RequestSupplierReadiness, exposing original saved Form and source/Rate IDs for a reviewed inquiry, and checking both administrator and native supplier-operator capability. Readiness requires accepted_for_processing and original unpriced Request Registry with NULL Woo sales_order_id; it refuses missing/inactive Form and missing/deleted product/Rate sources.
- It checks the original supplier owner availability through its read-only readiness method and returns BLOCKED explicitly because the existing native supplier create path requires a verified WooCommerce Order Item, absent from independent inquiry-only Request Registry. No artificial Woo Order is fabricated and NO native supplier API write is invoked.
- Added private GET-only REST /request-reviews/{id}/supplier-readiness; no POST/confirm/write route. Native WooCommerce Request Reviews Inbox shows a separate supplier handoff status and blocker, without supplier creation buttons.
- Native real WP QA read-only tests 35/35 PASS with process exit 0: guest and missing operator role denied, source and rate proof, accepted/rejected/review states, multi-service inquiries, fake IDs, changed monetary state, admin REST GET vs POST, admin Inbox and full SQL rollback. Original supplier CPT, booking link/proof/audit, Woo HPOS, Fluent Forms, CRM, Boards and jobs preserved.
- Existing review REST 18/18, saved Fluent Forms intake 32/32, pure engine 91/91 PASS. The original supplier booking provider and its audit/confirmation contracts were inspected but not mutated.
- Missing native contract for nonfinancial Request Registry -> genuine supplier handoff remains an OWNER-BLOCKED cross-stream dependency. No customer mail, payment, supplier confirmation, external booking, GitHub, Staging or Production work.

# 0.16.0-qa.3 — 2026-10-26 (Local QA candidate, NOT RELEASED)
- Fixed the operator REST test PHP exit 255: a top-level PHP test variable named post shadowed the WordPress global post. Renamed the test closure to sendReviewDecision and added a regression assertion. REST now 18/18 PASS and process exits 0 with fixtures cleaned; the actual REST implementation was not weakened.
- Added RequestContactEvidence, an admin-only existing-contact proof and separately gated source-to-Request projection. Every original saved Fluent Forms submission must have exactly one ContactBridge requester relation, all components must refer to the same existing FluentCRM contact, and no ambiguous or conflicting request owner may exist. No email lookup, subscriber creation or status changes.
- Added admin REST GET/POST /request-reviews/{id}/contact and an optional nonce-protected action in WooCommerce Request Reviews. POST refuses caller-supplied email/contact identifiers. Source-owned contact links are written only through existing ContactBridge. Disabled by default and inactive in the current QA site.
- Native synthetic provenance and CRM projection 27/27 PASS with no CRM contact/status/order changes and SQL rollback. Existing ContactBridge 30/30, Contact Actions 23/23, native CRM feed 26/26, Ledger 23/23, FluentBoards 22/22, Forms 32/32, Request Intake 32/32, Workflow 23/23, and pure core 91/91 PASS.
- Woo Store API HTTP 200. No native marketing Feed activated; supplier and payment authority, business Board, QA resource stability and browser acceptance remain gated. No GitHub/Staging/Production changes.

# 0.16.0-qa.2 — 2026-10-26 (Local QA integration candidate; UNRELEASED)

- Fixed persistence interpretation for commerce_composition_request_review_enabled: WordPress can reload an explicitly enabled boolean as scalar string "1". Only true, integer 1 or string "1" enables; invalid/absent values remain OFF.
- Implemented a genuine, native FluentBoards follow-up integration using FluentBoardsApi('tasks')->create() and its original board permission checks, not direct insertion into its tables. No contact_email or invented CRM subscriber is passed, avoiding FluentBoards' native auto-subscribe path.
- Added a dedicated InnoDB request->FluentBoards task reference with unique request ID and task ID, conflict/ambiguous outcome fail-closed, native stage and board validation, replay idempotency, and refusal before original operator review begins.
- Added admin-only REST GET/POST /request-reviews/{id}/task and conditional WooCommerce Request Reviews action "Create follow-up task" with WordPress nonce. Board/stage are obtained ONLY from the site owner's configuration, not from clients.
- The existing QA has only two synthetic test boards. No production/business board was silently selected; automatic task creation remains OFF and no persistent business board target configured. Test used a temporary server-only target for synthetic board 1/stage 6.
- Real QA native FluentBoards API created and read back a temporary task, rejected customer email injection, reused same task on repeat, and rolled back all test database effects: 22/22 PASS. Tasks restored to 1,016, activities to 2,037, CRM contacts to 14.
- Current operator review ledger 23/23 PASS, native Form 32/32, native CRM 26/26, Request Intake 32/32, Workflows 23/23 and pure core 91/91 PASS, with process exit zero for these tests.
- Independent RequestReview REST regression still returns 17/17 assertion PASS but the standalone PHP CLI test ends with exit code 255 after printing results. Remains NOT certified at process-level; never claim P1 full operational acceptance.
- Supplier draft/provider authority, real native CRM Feed contact identity, operational board choice, browser and customer acceptance, and financial WooCommerce payment/quote are separately OPEN.
- No paid sales, supplier booking, marketing campaign, customer message, GitHub, Staging or Production mutations.

# 0.16.0-qa.1 — 2026-10-26 (UNRELEASED Local QA candidate)
- Added an operator-only, independent nonfinancial request review ledger,
  protecting the original Request Registry as immutable intake evidence.
  Supports start review -> needs information -> resume -> accept for
  processing/reject, with state machine, optimistic revision concurrency,
  idempotency key hash and append-only audit events. Acceptance is never
  supplier confirmation, CRM marketing consent or financial sale authority.
- Added WooCommerce > Request Reviews administrative Inbox with nonce-
  protected decision buttons and source-ID-only, PII-free summaries.
- Added authenticated GET/POST REST endpoints for review listing/status/
  decisions; strict schema, admin capability, idempotency and no-store.
- Admin-only feature flag OFF by default; enabled on Checkout QA site only.
- Current source-verified WP database acceptance: 23/23 PASS for review state
  machine, permission, idempotency, audit and no finance/supplier writes.
  REST 17/17 assertions PASS (the WP CLI harness still exits 255 on shutdown) for anonymous denial, denied extras and CAS/race replay.
- Hardened accept-for-processing to revalidate original saved Fluent Forms
  IDs/status. This last source-hardening increment has passed PHP syntax
  but could not complete a fresh real-WordPress regression: the pre-existing
  QA PHP container reached its 768 MiB cgroup limit and requests timed out.
  Do NOT claim browser acceptance or full Stream01/02/03/04 closure.
- Cleaned a synthetic QA review fixture; no enduring test review or
  request rows, no HPOS or supplier booking changes.
- Supplier draft provider, FluentBoards native task creation and native CRM
  Feed/identity remain separately gated. No GitHub/Staging/Production work.

# 0.15.0 — 2026-10-26
- Added vendor-neutral WordPressMailTransport based solely on wp_mail(). FluentSMTP and other SMTP/Email API mailer plugins are OPTIONAL provider adapters, not code dependencies.
- Site-owned commerce_guest_consent_mail option defaults enabled=false, delivery_test_approved=false, transport=wordpress, with no SMTP/API secrets.
- Original checked guest consent remains necessary. Sending requires approved transport and HTTPS; one-time 256-bit token links to a confirmation page.
- Added mobile-responsive, no-JavaScript GET review -> POST confirmation page. GET is side-effect-free; replayed POST rejected. This does not subscribe anyone.
- Bypassed LiteSpeed cache for confirmation pages by early DONOTCACHEPAGE and X-LiteSpeed-Cache-Control: no-cache; private/no-store, no-referrer, noindex and restrictive CSP.
- Added bounded retry for failed delivery: first retry after 15 minutes, later retries after one hour, maximum three attempts. Every retry issues a new token and revalidates Form opt-in and original email/policy. Changed sources are invalidated.
- Plugin-only Checkout QA worker now drains queued workflows and due guest consent retries, leaving unrelated plugin queues untouched.
- WordPress mail intercepted QA 20/20 PASS, retries/opt-out 15/15, actual successful GET/POST/replay HTTP PASS, preexisting guest 36/36, Form checkbox 32/32, CRM 26/26, Request Intake 32/32, workflow jobs 23/23 and generic core 91/91 PASS.
- No real external email, CRM subscriber mutation, commercial Order, supplier booking, GitHub or production change.

# 0.14.0 — 2026-10-26
- Site-owned multilingual consent copy for native Fluent Forms input_checkbox, rendered by a targeted field-data hook: Arabic, German, Spanish and Italian preview languages, with English canonical source; WPML languages were NOT automatically activated. The stored Form JSON, SHA policy, checkbox default, required flag and "yes" value stay unchanged.
- Generic ConsentPolicySource maps the consent registry option through site configuration instead of embedding any company/vendor-specific option name in portable PHP.
- Introduced guest marketing email verification proof: original opted-in, guest-only saved Fluent Forms entry, current versioned policy/form SHA, original email field mapping, explicit owner-enabled delivery adapter, one-time 256-bit token and a 24-hour deadline. Token and email stored only as keyed hashes; locale and displayed statement are snapshotted for audit.
- POST-only /wp-json/commerce-composition/v1/guest-consent/confirm consumes the token atomically, checks original Form entry and policy are still valid and unchanged, blocks replay, expiration, forged/unchecked intent and automatically changing FluentCRM status.
- Default email sender remains OFF and no production guest email verification is active. QA used an isolated mock provider to test the state machine with no outbound email.
- WPML-flavored Arabic/German original shortcode rendering and one-time guest verification, changing or missing consent, expired/replayed tokens and no CRM mutations: 36/36 PASS. Previously shipped native consent checkbox 32/32, CRM feed bridge 26/26, server request intake 32/32, workflow queue 23/23, pure core 91/91 all PASS.
- Existing 14 contacts, 16 Fluent Forms entries, Woo HPOS orders, source data, active marketing feeds and WPML-enabled languages unchanged; no GitHub/Staging/Production rollout.

# 0.13.0 — 2026-10-26
- Added an explicitly optional, UNCHECKED-by-default native Fluent Forms marketing-consent checkbox to original QA inquiry Form #3 and booking Form #6. Field name: marketing_optin; only explicit selected value "yes" is accepted.
- Clear customer-facing statement: "I agree to receive occasional travel offers and updates by email. I can unsubscribe at any time." Separate optional service note emphasizes inquiry and booking still work without subscription.
- Existing site-managed, source-hash/versioned consent receipts register a dedicated marketing_updates purpose for both Form schemas, preserving the previously configured consent Form #14. No FluentCRM native marketing feed, campaign, notification or unsolicited external delivery was enabled.
- Reusable MarketingConsentField helper validates the native Fluent Forms field structure, requires admin permissions, performs compare-and-swap update, prevents duplicate field names and validates original source readback.
- Original Form schema backup and prior consent policy saved privately on QA VPS at /opt/commerce-components/qa-backups/optional-marketing-consent-before-20261026.json (not included in release ZIP).
- Native actual Fluent Forms send checked/unchecked, render, exact consent-policy hashes, nonrequired validation, original site consent preservation and transactional cleanup: 32/32 PASS. Test email notifications intercepted and not delivered.
- Existing customer data, CRM subscription states, original Fluent Form submissions, Woo Orders and source/queue registries unchanged. Default inquiry_only and quote/sale gate preserved.
- Guest submissions are not used as proof of an authenticated CRM subscriber. Marketing subscription sending/CRM native Feed remains off until separate consent and identity lifecycle certification.

# 0.12.0 — 2026-10-26
- Added source-backed listener for FluentCRM's original contact_added_by_fluentform and contact_updated_by_fluentform events. An enabled, persisted native feed record, saved original Form/Submission, matching contact ID and authorized job operator are required.
- The bridge creates/reuses ONLY the original saved Form -> existing FluentCRM contact reference using the v0.10 durable REST/Action Scheduler worker. It NEVER creates or subscribes a contact, edits consent, sends mail, infers identity from names/email or authorizes a sale.
- Corrected native Fluent Forms feed data parsing: settings are saved FLAT in form_meta.value, not as nested settings. Reject unregistered/disabled/wrong-form feeds and conflicting original requester references.
- Verified original Fluent Forms SubmissionHandlerService.handleSubmission on actual inquiry Form 3 with all default hooks and notification-complete signal, while intercepting test mail and rolling back original Form/CRM rows.
- QA native CRM feed events 26/26 PASS; full Fluent Forms original lifecycle 15/15 PASS, no outbound mail; v0.11 original verified Request Intake 32/32 PASS, context security 17/17 PASS, async jobs 23/23 PASS, core Composer/FormSchema/Classification 91/91 PASS.
- Existing optional site-owned customer engagement/consent plugin detected and left untouched. Native FluentCRM Feed on customer forms remains unconfigured pending explicit business contact policy and separate marketing consent. External authenticated HTTP App Password acceptance remains blocked/unverified; no temporary credentials remain.
- No Woo sale, supplier confirmation, FluentCRM subscriber or source CPT mutation, GitHub, Staging or Production changes.

# 0.11.0 — 2026-10-26
- Added original saved Fluent Forms -> standalone nonfinancial Request Registry intake without a Woo visitor session.
- Reused common validated FormIntent source parent/Rate/Package choice computation for both browser and server actions.
- Introduced durable immutable HMAC source-context receipts, binding original saved Form+Submission to verified parent and Rate; policy always revalidated at execution.
- Added typed create_request_from_saved_submission action to REST jobs and owner-opt-in native Form events.
- Original Form source claim ensures exactly one nonfinancial request even across different job keys and rejects collision with guest basket claims.
- Default inquiry_only and automatic Form-to-Request intake OFF. No price field, Woo HPOS Order, customer subscription or supplier action.
- Tests: server intake 32/32; source-context forgery and inquiry mode 17/17; separate-process real systemd worker succeeded in one attempt. Existing pure 91/91, Fluent saved-row 20/20, Request Registry 29/29 and REST jobs 23/23 PASS.
- CRM Feed/consent, full native Fluent listener chain, external authenticated HTTP and supplier/paid-sale adapters remain open.

# 0.10.0 — 2026-10-26
- Added durable unique workflow jobs with original user authority, strict payload contracts and idempotency.
- Added private REST command submission (POST workflow-jobs), GET status and paginated GET list. Repeat same request reuses prior job; different body under same key is a conflict.
- Added Action Scheduler opportunistic dispatch plus a dedicated two-minute QA-only systemd worker. Other QA Cron and other plugins are untouched.
- Added original Fluent Forms stored submission event and generic verified source plugin event; contact linkage only after trusted provider supplies an original contact identity. No email matching.
- Added automatic propagation of explicitly verified original Form requester identity to independent request records, but only if ALL submission sources agree on the same requester.
- Added crash-recovery for stale running jobs, max three worker claims and fail-closed financial/supplier dispatch.
- Tests: REST/queue and event 23/23 PASS; ambiguous identity 7/7 PASS; trusted source and crash recovery 11/11 PASS; actual dedicated systemd worker on committed QA job PASS.
- Native WP REST authenticated in process, anonymous HTTP 401. Live external HTTP Application Password authentication not certified during this QA cycle.
- No marketing CRM mutation, supplier booking creation, priced Woo order, GitHub or Staging/Production deployment.

# 0.9.0 — 2026-10-26
- Generalized FluentCRM contact bridge and owner-controlled source references, original CPT field projection, manual Tools UI and guarded Action catalog.

# 0.8.0 — 2026-10-26
- Distinct inquiry/CRM, independent unpriced Request Registry, and certified Woo-only sales.
