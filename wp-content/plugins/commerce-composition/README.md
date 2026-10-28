# Commerce Composition — Current v0.8.0

Commerce Composition is a generalized policy-driven product and package layer, without company names, fixed CPT/taxonomy keys, or hosting-environment limitations.

## Source-of-truth responsibility matrix

- Inquiry-only: the original Fluent Forms submission is the permanent record of each inquiry. FluentCRM manages the person and relationship follow-up using its native Fluent Forms integration and consent rules. Inquiries do not create requests, Woo Cart items or WooCommerce Orders.
- Request-only: the independent Request Registry stores each multi-service booking request and its parent-and-child Package composition; state=pending_review, pricing_state=not_quoted, no amount column, no WooCommerce Order.
- FluentBoards: optional tasks and agent follow-up, never the booking/payment source of truth.
- Supplier bookings: remain in existing specialized operational tooling, independent of CRM contacts and invoicing.
- Certified Sale: exclusively the original WooCommerce Cart and HPOS shop_order after verified monetary amount, currency and commercial certification. This transition remains unimplemented and fail-closed.

Version 0.7.0 experimented with a separate nonfinancial HPOS booking_request type, but its zero numeric total was ambiguous. That design has been withdrawn in 0.8.0; the special type/status and unused PHP files were removed from the active runtime. The original request-batch table was migrated in place while EMPTY and now holds standalone unpriced requests, with a nullable sales_order_id reference for an eventual certified transition.

## Existing useful extension capabilities

The data-driven product/rate/taxonomy classifier, JetEngine source relation resolver, managed inclusion/upgrade/extras composition policy, responsive Fluent Forms field binding, saved submission proof, unique global source claim, session request basket and nonpayment review submission are preserved. Multiple independent source parents may be submitted together, while nested accommodation/transport/extras remain attached to the Package parent. Subsequent services do not duplicate previously reviewed requests.

An optional owner-gated frontend form is available via [commerce_request_submit]; the former QA-only [commerce_request_checkout] is a backward-compatible alias. They submit only an unpriced review request, and are OFF by default. Inquiry-only continues through Fluent Forms without using this form.

## FluentCRM / Fluent Forms verification

FluentCRM 3.2.6, Fluent Forms and FluentBoards are installed and active in the isolated Checkout QA clone. The native FluentCRM/Fluent Forms feed implementation is present. The existing published inquiry and booking forms have email/name fields but ZERO native FluentCRM feed mappings, CRM lists or tags have been configured. Existing CRM contacts include pending, bounced and unsubscribed states. No marketing subscriptions or contact status updates should occur automatically and no new contact storage should be built. Use native forms-to-CRM mappings after confirming the privacy policy, consent and customer support workflow.

## Safety and rollout

The Checkout QA clone remains in inquiry_only; payment gateways are disabled; the existing sale certification gate remains active; no public request submission or new marketing feed was enabled. The separate Request Registry has NO numeric amount/total field. RequestCheckout.promoteToSale returns a denied result until a genuinely certified sale transition exists. No modifications were made to original CPT product posts, supplier booking records, WooCommerce Orders, production, staging or GitHub.

Acceptance evidence is under evidence/CRM-INQUIRY-AND-REQUEST-REGISTRY-CORRECTION-20261026.md. Historical 0.7.0 details remain recoverable in Git history, but they are NOT the current architecture.


## v0.9.0 FluentCRM Contact Bridge: legacy fields and plugin-owned actions

An independent SQL contact-source reference table connects ORIGINAL records:
FluentCRM contacts, older private booking and order CPTs, original Fluent Forms
submissions, independent unpriced Request Registry rows and certified Woo orders.
Each link has a unique identity and preserves the owning plugin's data.

The site-owned WordPress option commerce_contact_bridge_sources_v1 defines source
keys, post types, allowed Form IDs and named metadata field projections. Exact
legacy CPT names and keys are QA CONFIGURATION, not hardcoded business logic.

The native FluentCRM subscriber_info_widgets filter adds a protected customer
record panel, listing verified linked source IDs, state and the presence of
six operational supplier fields: guide, driver, accommodation, service supplier,
sales representative and extras. Their raw values, supplier IDs, contact PII,
monetary values and contracts are NEVER copied to CRM.

Admin UI: WordPress Tools -> Contact relationships. A signed form links an
existing FluentCRM contact to an eligible record under strict owner capability.
Original private booking CPT metadata is reference-only, with permission
explicitly configured per source. CRM users do NOT get source post editing
rights by virtue of a relationship.

Admin-only REST:
GET /wp-json/commerce-composition/v1/contact-relations/{contact_id}
POST /wp-json/commerce-composition/v1/contact-relations
GET /wp-json/commerce-composition/v1/workflow-actions
POST /wp-json/commerce-composition/v1/workflow-actions

Workflow actions are owner typed:
- link_contact_record: actual verified CRM relationship.
- submit_request_review: existing claim-verified unpriced Request Registry,
  only from the authenticated caller's original Woo customer session.
- create_supplier_booking: BLOCKED without provider approval, supplier
  assignment and supplier booking integration.
- convert_request_to_certified_sale and convert_legacy_order_to_sale: BLOCKED
  until certified quote, per-line receipt, tax/FX and approved commercial
  transaction handlers exist.

Future provider plugins may declare additional actions through the filter
commerce_composition_workflow_action_catalog. These catalog entries can be
displayed but are deliberately NOT executable without separately certified
providers. This avoids hardcoding product/service/business action lists or
inventing financial authority.

FluentCRM native Form feeds and marketing consents remain unchanged. This is
not a new contact database, not a clone of supplier bookings, and not a payable
cart feature. Accepted tests exercise original private QA CPT records and
saved Form entries while rolling back relationship writes. No production or
GitHub deployment.


## 0.10.0: Automatic and remote workflow operations

Manual WordPress Actions are not the primary execution boundary. A durable
SQL workflow queue accepts the same verified link command via three paths:
(1) authorized REST job submissions, (2) native saved Fluent Forms and
configurable provider source events, (3) automatic propagation from existing
verified original Form requester links to newly stored independent Request
Registry records.

All events require a trusted contact identity already linked to an original
source. No contact matching by name, email, IP or unpublished supplier meta is
ever done. Multi-person requests cannot be attributed to one contact unless
every original Form submission independently identifies the same requester.

REST is authenticated with WordPress native user capabilities and supports
WordPress Application Passwords on properly secured HTTPS installations.
No new shared secret or custom bearer token was generated. Routes:
- POST /wp-json/commerce-composition/v1/workflow-jobs
- GET /wp-json/commerce-composition/v1/workflow-jobs/{job_id}
- GET /wp-json/commerce-composition/v1/workflow-jobs?page=1&page_size=20&state=queued
- GET /wp-json/commerce-composition/v1/workflow-actions

POST requires Idempotency-Key header, 16-128 ASCII characters, using only
letters, digits, period, underscore, colon, dash. Body schema:
{"action_key":"link_contact_record","payload":{"contact_id":123,
"source_key":"inquiry","source_id":456,"role":"requester"}}
IDs must reference existing proven records. HTTP 202 accepts new job; HTTP
200 replays identical original job; 409 denotes payload conflict; 422 denotes
invalid or uncertified command; 401/403 denies unauthorized requests.

The only currently certified async effect is link_contact_record. Supplier
booking creation, request-to-sale and legacy order-to-sale remain blocked
until their owning providers implement approval, supplier confirmation and
certified financial authority. Future provider catalog declarations do not
automatically make themselves executable.

The job table records opaque source IDs, actor, state, SHA hashes, attempts,
timestamps and sanitized failures without contact answers, passwords, vendor
secrets or price data. Every worker run re-verifies the original principal
still holds WordPress authority; SQL claim enforces a single winner. Stale
running jobs can be reclaimed after 15 minutes if fewer than three attempts.

The generic CLI worker lives in bin/process-workflow-queue.php. The isolated
Checkout QA uses a dedicated systemd timer and service called
commerce-workflow-qa, running every two minutes. This timer invokes ONLY this
plugin queue and does not trigger arbitrary other plugin schedules. The
already existing timer for a different QA clone remains unchanged.
Action Scheduler provides opportunistic early dispatch when available.

QA real service tested one committed job from queued to succeeded; removed its
test link, task and related scheduler record afterward. Internal authenticated
REST and anonymous HTTP access rejection were verified. An actual remote
Application Password HTTP flow still requires an authorized integration
account and a separate network acceptance test.

No GitHub, Staging or Production changes. Default inquiry_only, certified-sale
gate and payment-off behavior unchanged.


## 0.11.0 — Server-owned saved Form to nonfinancial request

A stored original Fluent Forms submission can now create a multi-component
booking review request via authorized REST or opt-in server-side Form events,
without using a guest WooCommerce session. The action key is
create_request_from_saved_submission and its payload contains only integer
form_id and submission_id. Existing protected workflow-jobs POST/GET APIs
and Idempotency-Key semantics still apply.

SubmissionContext keeps an immutable, HMAC-sealed WordPress SQL receipt
mapping Form+Submission to the original trusted source parent and Rate.
It can be captured from a genuine saved-Form PHP event by a trusted
source context provider or explicitly saved by an authorized owner.
The saved receipt is available to separate worker processes; client hidden
fields, email or guessed product IDs cannot supply source authority.

The worker reads only the saved original Form answers needed to validate the
approved selected components, and rechecks the CURRENT Booking Experience
policy, source ownership and linked Package options. A unique source claim
and transaction create one independent Request Registry record with
pricing_state not_quoted, sales_order_id NULL, and no monetary total. Repeated
runs return the original request ID. A prior browser claim for the same saved
Form prevents a duplicate server request. Inquiry-only always denies booking
intake and automatic event scheduling remains OFF by default.

Trusted filters to enable auto intake for approved forms are
commerce_composition_enable_auto_form_request_intake (strict true) and
commerce_composition_auto_form_request_actor_id (authorized WP admin ID).
These are not REST body flags and never grant sale or supplier confirmation.

Real QA proof used two independent PHP processes, an actual saved Form row,
persisted signed source context, original Package child and dedicated
systemd worker. Completed one attempt, correct unpriced Request Registry
record, no Woo sale. All temporary records and scoped CLI policy fixture
were deleted.

Future acceptance: full native Fluent Forms/FluentCRM feed lifecycle and
consent, real remote HTTPS credentials, supplier confirmation provider and
certified monetary Checkout. No GitHub/Staging/Production deployment.


## 0.12.0 — Native FluentCRM original Feed -> verified source relationship

The existing FluentCRM plugin is the person/contact system of record, not a
separate CRM built by Commerce Composition. The new FluentCrmFeedBridge
listens only to original native FluentCRM Form Feed completion events:
fluent_crm/contact_added_by_fluentform and
fluent_crm/contact_updated_by_fluentform.

It verifies ALL of these against their original owners before enqueueing an
idempotent relationship job:
- Existing original Fluent Forms submission ID, stored under the exact Form ID.
- Persisted, ENABLED native FluentCRM integration feed row for the same Form.
  The actual Fluent Forms form_meta.value has a FLAT enabled field.
- Existing contact ID supplied by the original FluentCRM Feed event.
- Active configured source adapter for that specific Form.
- A site-approved actor ID supplied by the server-only hook
  commerce_composition_native_crm_feed_actor_id.
- No already-linked DIFFERENT requester for that same original Form entry.

The bridge does not take customer-supplied contact IDs, match by email or
invent a new contact, unsubscribe/re-subscribe, send a notification or
create booking/financial records. Only an existing original contact-to-Form
reference is added through the old durable queue, unique source links and
audit-friendly original evidence.

The existing optional consent/engagement extension may already listen to
the saved Fluent Forms event on the local QA site. This new bridge DOES NOT
replace its consent receipts or contact-status rules and does not modify it.
Original FluentCRM native feeds on actual customer forms remain disabled:
the installed native plugin's feed settings can mark new contacts subscribed
or send opt-in mail, and customer forms currently lack a confirmed separate
marketing subscription consent. Configuring marketing feeds without the
approved notice/conditional consent is not part of this rollout.

Actual QA acceptance:
- 26/26 test assertions for the native Feed callback and schema: original
  verified Form source; persisted enabled/disabled feeds; distinct requester
  protection; authorized worker; replay deduplication; no CRM contact mutation.
  Tests used transactionally isolated synthetic feed metadata and existing
  QA contact references, not a real active customer marketing Feed.
- 15/15 assertions from the installed original Fluent Forms
  SubmissionHandlerService.handleSubmission for inquiry Form 3. Native
  storage hook and notification-complete signal both fired, but outbound
  wp_mail was intercepted and the transaction was rolled back. No external
  email or marketing contact update occurred.

Still open: the business-approved and consent-tested real native CRM Feed,
external HTTPS authenticated REST client acceptance with approved credentials,
real customer browser delivery/notifications, supplier approval adapters and
certified quote-to-payable WooCommerce conversion. The default inquiry_only
policy and all monetary/supplier guardrails are unchanged.


## 0.13.0 — Explicit optional marketing consent checkbox

Two existing native Fluent Forms on the Checkout QA site, the original
inquiry Form 3 and booking Form 6, now include a visible standalone
input_checkbox field with name marketing_optin. This is a checkbox with one
possible explicit "yes" selection and NO preset value or required validation.
Its purpose is optional promotional email offers, distinct from communication
needed to answer an inquiry or service a booking.

Customer text in the existing English-language forms:
  Marketing updates (optional)
  [ ] I agree to receive occasional travel offers and updates by email.
      I can unsubscribe at any time.
  Optional. Your request can be submitted without accepting promotional messages.

The site-owned consent rules define purpose marketing_updates, version
marketing-email-v1, accepted_value=yes, exact display statement, form SHA256
and effective time for each updated original Form. These are site
CONFIGURATION values, not hard-coded form IDs or brand-specific restrictions
in the shared plugin. An already present independent consent policy for
another test Form is preserved.

The reusable MarketingConsentField helper can prepare an optional original
input_checkbox field for any configured native Fluent Form ID; it requires
WordPress owner permission and refuses duplicate field-name insertion.
The field must be applied explicitly to existing site forms; activating
the portable plugin NEVER silently edits customer forms or subscribes anyone.

Only explicit checked consent is positive proof; unchecked/omitted has no
marketing opt-in effect. The site's existing consent receipt component can
record verified, authenticated owner-backed opt-in events, subject to its
identity and form-hash checks. Guest-form email alone remains insufficient
for CRM identity and promotional subscription. Native FluentCRM marketing
Feeds/campaigns remain NOT ENABLED.

QA proofs: rendered native Form shortcodes for both original Forms include
the checkbox and text. Actual Fluent Forms SubmissionHandlerService calls
with checked and unchecked values both succeeded and stored the correct
answer. Checked/unchecked tests were rolled back, outgoing test emails
intercepted, and all original contacts/submissions/orders unchanged.
Acceptance: 32/32 PASS in evidence/marketing-consent-forms-qa.log.

A restricted backup of original Form JSON and previous site consent options
exists only at:
  /opt/commerce-components/qa-backups/optional-marketing-consent-before-20261026.json
It must remain private: do not embed it in plugin ZIP or public Git sources.
Translations/RTL rendered context, fully verified guest identity and any
actual native CRM promotional Feed must be certified separately before live
campaign activation. No GitHub/Staging/Production changes.


## 0.14.0 — Locale-aware consent rendering and guest double opt-in proof

The optional marketing consent checkbox remains stored as ONE original
Fluent Forms field with the canonical technical value yes, required=false
and no default selection. The native
fluentform/rendering_field_data_input_checkbox filter changes ONLY its
visible text at render time according to current WPML language. A QA-owned
commerce_marketing_consent_i18n configuration provides Arabic, German,
Spanish and Italian labels, statement and help text; English remains the
canonical registered WPML string. The site owner has NOT activated disabled
languages or altered the original Form JSON or consent version/hash.

The generic ConsentPolicySource reads the owner-selected existing consent
rules option through commerce_marketing_consent_source. Shared PHP includes
no company-specific option key, CPT, host, site or environment name.

### Guest email verification is NOT CRM subscription

GuestConsentProof uses only the ORIGINAL saved Fluent Forms submission
and its explicit checked marketing_optin yes field, the correct live Form
SHA/purpose/version, the source Form's owner-configured email mapping and
valid source timestamp. Anonymous unverified mail addresses never create
subscribed FluentCRM contacts or marketing campaign permissions.

When and ONLY when an approved delivery transport is connected (currently
OFF in QA), a cryptographically random 256-bit token can be emailed to
the submitted original guest address via the trusted PHP delivery filter.
A small WordPress-prefix receipt table stores HMAC hashes of the email and
token, original Form and submission IDs, the specific purpose/version,
rendered statement language and exact text, expiration and verification
state. No raw guest email, token or Form answers are stored in the receipt.

A POST to /wp-json/commerce-composition/v1/guest-consent/confirm with the
emailed token confirms email ownership once within 24 hours. A GET alone
does not verify and cannot be auto-triggered by link-preview bots. It rejects
replay, expiration, deleted/altered original submission, unchecked consent,
changed Form schema or superseded consent rule. Successful verification
returns verified_email=true and marketing_crm_subscription=false; a
separate governed CRM action would be needed to change a contact status,
and it must preserve all vendor suppression/opt-out rules.

Site integration hooks are:
commerce_composition_guest_consent_transport_ready (boolean, default false)
commerce_composition_guest_consent_deliver (boolean, default false)
A trusted transport receives the original email address and ephemeral code
only transiently. Its contract must deliver a secure confirmation UI that
POSTs the token; a real sender, customer-facing confirmation page, resend
recovery and approved CRM subscription transition are NOT enabled or
certified in this QA delivery.

Evidence: evidence/marketing-i18n-guest-proof-qa.log, 36/36 PASS using
real native Fluent Forms shortcode rendering and an isolated mock sender,
without external email. Source option and policy are unmodified, all test
data rolled back. Other critical nonpayment and CRM regression suites pass.
No GitHub/Staging/Production deployment.


## 0.15.0 — WordPress mail adapter, optional SMTP and email confirmation

The guest consent verification mail transport now uses WordPress wp_mail()
through WordPressMailTransport. It automatically benefits from whichever
SMTP or Email API integration is separately installed for that WordPress
site. No FluentSMTP dependency or mail secrets exist in this plugin.

The site-owned commerce_guest_consent_mail option remains disabled:
  transport: wordpress
  enabled: false
  delivery_test_approved: false
An authorized owner must separately configure a reliable WordPress mailer,
validate its sender/DNS reputation and TEST external mailbox delivery before
enabling this option. A true return from wp_mail() alone is not evidence of
external receipt. Checkout QA continues to intercept all outbound mail.

On a real HTTPS site, an opted-in original saved guest Form gets a secure
email link to the WordPress site. The responsive no-JS confirmation page
at /?commerce_consent_confirm=<single-use-token> shows a review on GET.
Only submitting the confirmation POST consumes the one-time token and
records verified_email. Success expressly does not subscribe the guest to
FluentCRM or grant any sales/fulfillment authority.

The page prevents caching and indexing through WordPress and LiteSpeed
no-cache controls, Cache-Control private/no-store, Referrer-Policy no-referrer,
X-Robots-Tag noindex and a restrictive CSP. We verified these headers via
real HTTP. The existing protected POST-only REST confirmation endpoint
continues to work.

If a configured provider rejects email delivery, the original consent proof
remains in delivery_failed. The plugin-owned QA worker may retry after 15
minutes and subsequently after one hour, up to three total attempts.
Every retry rechecks the original saved Form, email, consent, source state,
Form SHA and policy version. It rotates the secret token on a new attempt
and invalidates records if the original user removes the opt-in.
The retry runner never drains other plugins' scheduled tasks.

Tests performed in isolated Checkout QA:
- WordPress wp_mail() intercepted 20/20 PASS (no actual external email).
- Retry scheduling/deduplication/opt-out 15/15 PASS.
- Real HTTP GET 200 pending -> POST 200 verified_email -> replay POST 400.
- All original guest consent, Fluent Forms, FluentCRM and unpriced Request
  Registry tests passed. Test records were removed.

This does not install FluentSMTP or configure another SMTP provider.
Actual sender approval, external inbox, bounce handling, a verified CRM
identity and approved marketing subscription are separate pending gates.
No GitHub, Staging or Production changes.


## 0.16.0-qa.1 — Operator Request Reviews (local QA candidate)

Adds an independent, append-only, NONFINANCIAL operator review ledger
for original verified Request Registry entries. The original Fluent Forms,
WooCommerce Cart, HPOS shop_order, tour booking CPT, CRM contact and
FluentBoards task owners do not change.

Configured OFF by default, and enabled only on the Checkout QA instance.
Requires WP manage_options, not general edit_posts, from both WooCommerce
> Request Reviews and the authenticated REST endpoints:
- GET /wp-json/commerce-composition/v1/request-reviews
- GET /wp-json/commerce-composition/v1/request-reviews/{request_id}
- POST /wp-json/commerce-composition/v1/request-reviews/{request_id}/decide
  JSON: {"to_state":"under_review","expected_revision":0,
         "reason_code":"review_started"}
  Header Idempotency-Key: 16–128 alphanumeric, period, colon, dash,
  underscore. Exact known keys only; no customer information or amounts.

Review states: pending_review -> under_review, needs_information or
rejected; under_review -> accepted_for_processing, needs_information or
rejected; needs_information -> under_review or rejected.
accepted_for_processing and rejected are terminal REVIEW states only,
not supplier-confirmed/payment-paid states. The original Request Registry
remains pending_review/not_quoted with sales_order_id NULL throughout.

Each transition uses InnoDB transaction and row lock, revision CAS and
unique command fingerprint, then writes one immutable audit event with
actor, reason code, timestamp, prior/new state. It does not send emails,
create suppliers, schedule real tasks, subscribe to FluentCRM or touch Woo.
An optional after-commit event offers a future governed integration hook.
The new acceptance transition rechecks every original saved Form belongs
to the correct Form and is not trashed/spam/deleted.

After QA resource recovery, the tightened original-source ledger completed
23/23 PASS with process exit 0, REST checks 17/17 PASS with data cleanup;
the REST PHP CLI harness still exits 255 after printing PASS. The cause
of the nonzero harness termination remains uninvestigated.
This is NOT a release-certified milestone. Customer browser acceptance,
source->native CRM and Boards provider handoffs, supplier assignment and
actual service confirmation are still OPEN.

Code scope: isolated Local QA and local git only, no hosted rollouts.


## 0.16.0-qa.2 — FluentBoards native operator follow-up (QA candidate)

A real FluentBoards native API bridge is now available for the independent
nonfinancial operator review ledger. Requests enter the original Request
Registry and may progress to under_review and accepted_for_processing
without being turned into shop_order, supplier confirmation or paid booking.

The approved operator may use the WooCommerce > Request Reviews page,
or the protected REST GET/POST
/wp-json/commerce-composition/v1/request-reviews/{request_id}/task.
The request is required to be under_review or accepted_for_processing.
Task title contains a Request ID only, no customer identity or private
booking answers. Calls use FluentBoardsApi('tasks')->create with the native
permission mechanism, NOT direct fbs_tasks insertion.

There is exactly one InnoDB link per review Request ID. Replays resolve
the original native task and never create duplicates; in-progress or
uncertain native outcomes require operator reconciliation instead of
risking duplicate FluentBoards tasks. Native task/board/stage must still
exist. FluentBoards contact_email is NEVER passed because its official
API may create or update a subscribed FluentCRM contact from that field.

This integration has a separate site-owned option
commerce_request_review_board:
  enabled: false
  auto_create: false
  board_id: (none)
  stage_id: (none)
An admin must explicitly select an active operational board and belonging
stage and choose whether manual or automatic task creation is permitted.
There is no selected operational board currently; this Checkout QA has
only synthetic test boards. Therefore new customer Requests do not
automatically create task records.

The review-only feature itself remains OFF by default on new installations;
on this isolated QA WordPress it was explicitly enabled. Its persisted
boolean is interpreted safely even when WordPress returns string "1".

QA: temporary board 1/stage 6, one original synthetic Request, stored
review under_review, native task creation/replay and admin REST validation
22/22 PASS, transactionally rolled back to original FluentBoards counts.
Original Form, CRM, Request Intake, Woo HPOS and queue regressions PASS.

This is not yet an operational release: external standalone request-review
REST test reports 17/17 assertions PASS but PHP CLI exit 255; real production
board and supplier confirmation owner not configured; browser/role/contact
acceptance pending. No GitHub, Staging or Production rollout.


## 0.16.0-qa.3 — Request CRM identity proof and REST shutdown fix

RequestContactEvidence::preview(request_id) resolves the existing identity
without guessing from email. Every original saved Fluent Forms submission
in the nonfinancial Request must have one pre-existing ContactBridge
requester relation. All entries must resolve to the same existing CRM
contact; missing, altered or conflicting identity evidence is refused.

The actual projection reuses ContactBridge::link, rather than writing
directly to the CRM or creating a subscriber. A separate owner option
commerce_request_contact_projection_enabled starts OFF and remains OFF
in isolated QA. Private REST GET /request-reviews/{id}/contact previews
the reference; POST accepts no caller-supplied identity or email. The
WooCommerce Request Reviews Inbox shows a nonce-scoped action only
when the feature and original identity are both approved.

The original native FluentCRM Feed is still not enabled; existing
synthetic source evidence demonstrates a guarded capability only.
No CRM status change, email send, supplier confirmation or Woo paid Order.

PHP standalone REST test had returned exit 255 despite green assertions
because its top-level test closure variable post overwrote WordPress
global post. Renaming to sendReviewDecision and guarding the global
fixed the test itself: 18/18 PASS and real process exit 0. No REST
authorization or server behavior was bypassed.

All source work remains Local Checkout QA, without external rollout.


## 0.16.0-qa.4 — Nonfinancial supplier preflight (read-only)

The existing native supplier booking owner requires a legitimate
WooCommerce Order ID AND Order Item ID when creating operational supplier
records. Commerce Composition's Request Registry does not create them
under inquiry_only mode. Supplier confirmation must therefore not be
inferred from operator acceptance or from a FluentBoards task.

RequestSupplierReadiness::preview(request_id) is a pure read-only gate:
- Requires both WordPress admin and original supplier-booking operator cap
  plus a previously accepted_for_processing operator review.
- Revalidates original saved Fluent Forms identities and active status,
  original service CPT posts and optional Rate posts, source line count,
  and the independent unpriced/no-sales-Order Request Registry state.
- Returns safe internal metadata-only status
  blocked_missing_nonfinancial_supplier_contract, not a draft supplier
  booking, fake commercial Woo order, or approval of a supplier.
- Checks native owner presence/readiness, but does not call supplier create,
  transition, upload, or any event/finance API.

Private authenticated GET route:
/wp-json/commerce-composition/v1/request-reviews/{request_id}/supplier-readiness.
The original WooCommerce Request Reviews admin has a supplier status column.
There is no POST or supplier-creation action.

Local QA 35/35 PASS with transaction rollback, actual native CPT sources
and rates, negative supplier roles, multiple distinct services,
guest denial and UI status evidence. No real supplier record touched.

For Stream02 to accept a Request without Woo, a new source-owned,
authority-verified native contract and migration are needed. DO NOT
manufacture a nonpaid or zero-priced Woo Order to satisfy the existing
commercial-item-only provider. All native booking and finance operations
remain owned by Stream02, not this bridge.


### Native supplier provider discovery configuration
Generic plugin source does not hardcode the original vendor name or native
operator capability. The isolated QA site has a site-owned configuration
option named commerce_request_supplier_owner_registry with fields enabled,
capability, readiness_class. Its currently configured provider exposes
ONLY the readiness method; no arbitrary method is executed and no supplier
write is authorized. If this registry is missing or invalid, preflight
fails closed. Provider configuration is not a contract to create supplier
bookings, nor does it enable financial conversion.


## 0.16.0-qa.5 — Source-native supplier bridge discovery (still gated)

Stream02 now owns a new optional original Request Registry supplier source
adapter. Commerce Composition does not create supplier bookings: it uses
a read-only owner capability filter to distinguish the absent contract,
installed but disabled source contract, and an operator-enabled source
contract that still requires explicit supplier selection.

The source-owned plugin uses the existing private tour_bookings CPT,
native audit/proofs, native v2 outbox and a separate Request/source/slot
identity table, without a Woo Order or Item. Its native test passed 61/61
checks with SQL rollback; Commerce preflight 38/38.

Two separate owner site options are OFF by default: source write and
source-typed event-consumer compatibility. No native supplier operation is
created by reading the Commerce admin status. The source owner and its
private proof upload still require actual operational configuration and
event consumer certification. Real CRM Feed, business Boards, staff E2E
and financial checkout remain separately blocked.

## 0.16.0-qa.6 — Order-before-Booking

Canonical workflow: original source channel -> verified intake -> WooCommerce
Order -> mapped Product Order Items -> native supplier booking(s).
An Order may still be inquiry_only / awaiting_quote, without paid checkout
or supplier confirmation. Zero must not be presented as a verified free
price. The experimental direct Request Registry-to-supplier plugin was
deactivated in isolated QA; historical 61/61 tests no longer authorize
that bypass. The original native Order/Item supplier provider remains on.

Read-only RequestSupplierReadiness now reports awaiting_woocommerce_order
and rejects supplier booking authority. Dedicated checks 10/10 (native
booking negative) and 38/38 (private inquiry supplier preview) PASS.
ADR-004 and Spec P1-10 track the still-missing universal omnichannel
unpriced Woo Order conversion before real supplier fulfillment.


## 0.16.0-qa.7 — Dual intake (partial component)

IntakeModePolicy resolves crm_first versus order_first by approved site,
channel and service rules; conflicting mixed services fail closed.
Options commerce_intake_routing_policy_v1 and
commerce_intake_case_registry_enabled both default OFF.

IntakeCaseRegistry atomically claims exactly one original Request case using
InnoDB unique Request ID. It verifies original saved Forms, active service
sources, native Request Review, existing verified FluentCRM contact and
immutable source and policy hashes. Its CRM First case stage is
awaiting_approved_business_board with NULL Woo Order ID. No actual
FluentBoards task or FluentCRM contact is created in this slice.

Private GET/POST /wp-json/commerce-composition/v1/intake-cases/{request_id}
is admin-restricted and ignores all caller-selected identity and route
parameters. Order First is recognized but cannot write an Order until a
certified unpriced WooCommerce Order/Item factory exists (P1-10).

The current original Woo-session Request Registry has verified web origin.
Future channels need their own authenticated source adapter; configurable
policy support is not equivalent to operational channel onboarding.


## 0.16.0-qa.8 — Order First, genuine nonfinancial HPOS draft (partial)

IntakeOrderDraft ensures a verified Order First Request Registry can create or
reuse exactly ONE WooCommerce HPOS Order with status checkout-draft. It stores
original Request ID, policy digest, source snapshot digest, channel, proven
CRM contact reference and inquiry_only/not_quoted meta. Its Order has NO
Product Order Item yet: missing source-to-Woo Product mapping or an unapproved
rate may never be replaced by a fake zero-priced service sale. Consequently
native supplier booking remains strictly blocked until certified Order Items.

New flag commerce_intake_nonfinancial_order_enabled is OFF by default, as
are the separate IntakeCaseRegistry and IntakeModePolicy feature switches.
Admin/Commerce-manager GET/POST for verified Order First:
 /wp-json/commerce-composition/v1/intake-cases/{request_id}/order-draft
No arbitrary identity, rates, customer fields or monetary data are accepted.

A case row is claimed before Woo creation. On ambiguous Woo failure or
interruption it retains a reconciliation-required claim rather than
retrying into a duplicate. Replays validate the original Woo ID and
immutable inquiry-only status. Neither quote certification nor supplier
booking nor payment is implemented here.

A long SQL QA transaction across Woo CRUD proved unsafe due to a
WordPress options transient invalidation error. The final QA harness creates
short-lived exact-match fixtures, exercises the real Woo API and then
removes only those fixtures via scoped CRUD. Final acceptance: 26/26 PASS,
no additional real Order after cleanup and no source/contact mutations.

The existing original Woo product resolver raised a WordPress critical error
when directly inspected in QA. Do not claim mapped purchasable Order Items:
mapping, price/quote certification and safe Woo Item creation remain open.


## 0.16.0-qa.9 — Native CPT Product Integration

Source products come from the installed Custom Post Type WooCommerce
Integration data store (currently 2.2.11). A configured original service
CPT post is itself readable as a WC Product with the SAME post ID; no
post_type=product duplicate is necessary or authorized.

ServiceProductIdentity::inspect() reads the configured CPT types, checks
WC Product readback and exposes source identity, NOT approved price, item
or payment authority. In local QA, original tour 60645 is readable as
Product 60645. Rate 61158 is a separate pricing CPT, not a second sold
service. Property 36474 is NOT configured as a native CPT Product and
fails closed. CPT data store raw price fields are never certified quotes.

IntakeOrderDraft::preview() includes actual CPT Product identities
per original Request service line. The genuine unpriced HPOS draft is
allowed, but Item materialization, supplier booking and financial checkout
remain gated until an authoritative Rate/quote is approved.

A discarded experimental standalone Product generator was never included
in a release. Its one leftover private/unpriced QA Product was verified
as unlinked and deleted via Woo CRUD. Current Commerce code only reads
CPT Product identity; it does not provision duplicate Products.


## 0.16.0-qa.10 — Tour Rate date eligibility (READ ONLY)

TourRateEligibility uses original source/Rate postmeta relation evidence
related_tour_id and _etg_last_synced_tour_id, Rate Unix start/end date
and the enabled season taxonomy month/day configuration (cross-year
winter supported). No active native JetEngine Tour -> Rate relation
exists in this Local QA runtime. Read-only source evidence is NOT a
commercial price or supplier confirmation authority.

Date eligibility is separate from approved quote. Rate 61158 links to
Tour 60645 and covers a winter example; the partial August 2026 Rate
66840 belongs to another Tour 60437 and has stop_sale plus QA-only
markers. Even an intersecting selected date does not permit selling it.

Staff GET /wp-json/commerce-composition/v1/intake-cases/{request_id}/order-draft
may include ?travel_date=YYYY-MM-DD to preview eligibility of the
ORIGINAL Request rate. Neither selected Rate ID nor amount is accepted
from caller input. No date gives travel_date_required blocker; invalid
calendar dates produce an error. No Order Item or payment is authorized.
Rate precedence, travel policy, market/currency, occupancy, taxes,
allocation and signed quote remain independent release prerequisites.


## 0.16.0-qa.11 — Overlap, original Rate, quote and Item safety

TourRateResolution loads and evaluates published Rate CPT candidates by
original tour, date, season, stop-sale and source identity. In Tour 60645,
winter Gold Rates 61148 and 61158 both match a winter date, so a missing
selection requires an explicit customer choice. The original Request's
saved Rate 61158 is verified rather than replaced. This is eligibility
only, never a pricing approval.

The locally updated Stream01 quote gate recognizes CPT Product Integration
original IDs 60645 and 61158. A valid Booking Configuration v2 still fails
commercial authorization because owner pricing controls are disabled.
The Rate currency EUR and WooCommerce USD require a separately certified
FX decision. The pricing owner produces read-only previews, not automatically
payable totals.

A CPTWoo original service item requires the integration's CPTOrderItemProduct
subclass, not plain WC_Order_Item_Product. The native Stream02 supplier
validator now blocks any commerce_intake_nonfinancial Order family even
if a genuine CPT Order Item exists. This is an explicit temporary stop
until a separate signed and persisted commercial handoff exists.
P1-10 remains PARTIAL_QA and all operational switches remain OFF.
