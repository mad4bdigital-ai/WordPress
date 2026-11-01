# Operational Fluent Forms → FluentCRM + ChatbotX (Woo Lab)

**Date:** 2026-10-29. **Environment:** ETG Woo Lab `local`, isolated DB `etg_woocommerce_lab`. **Stage:** Inquiry/Estimated Quote; no online checkout, booking or payment.

## Architecture and activation

The Commerce Composition module `FluentInquiryCrmIntegration.php` registers on Fluent Forms' **native saved submission event**, `fluentform/submission_inserted` (priority 35). It reads the **original persisted submission**, confirms actual form owner, published form schema with a declared email input, validates email, rejects spam/trashed/deleted entries, and calls the installed **FluentCRM Contacts API**. It uses CRM's own email uniqueness and returns an existing contact unchanged or creates a **transactional lead**. It **never adds contacts to marketing lists, subscribes them, force-subscribes suppressed/unsubscribed contacts, sends mail, creates Woo Orders, or calls suppliers**.

This route is intentionally **not** an ordinary `fluentcrm_feeds` configuration. The bundled FluentCRM native feed implementation automatically creates `subscribed` contacts when double opt-in is disabled and sends opt-in mail when enabled, neither appropriate for operational-only inquiry management without marketing consent. If a real marketing feed is later required, configure explicit consent and double opt-in separately.

After CRM creation, the existing governed `WorkflowJobs::enqueue('link_contact_record')` processes a stable idempotency key, and the existing Worker uses `ContactBridge::link` with admin authority to bind the contact to the **original Fluent Form submission**. No spoofed front-end ID is trusted. The source mapping has two disjoint ownership keys:

- Legacy `tour_form6_inquiry` remains exclusively form **6** (existing historical references preserved).
- New `general_fluent_inquiries` handles forms **1,2,3,5,7,8,9,11**.

Form **4** is published but has no declared email field, so the sync excludes it rather than manufacturing an identity. All 9 other published forms are active on Woo Lab via site-bound option `commerce_operational_crm_intake_v1` with admin actor ID and request-only semantics. No global tenant/Production setting was changed.

## ChatbotX integration

The existing active WordPress **Customer Messaging Bridge** plugin (v0.4.1) is the ChatbotX WordPress adapter. `CMB_Business_Context` local form-event observation is now enabled for the **same 9 form IDs**, preserving other existing plugin settings. It records only HMAC-anonymized event fingerprints, event kind, status and time locally. No submitted answers, email, phone or name are sent to ChatbotX.

ChatbotX remote `https://chatbotx.mad4b.com/api/health` responded HTTP 200 during verification. **No external CRM/contact sync** is enabled: provider token, inbox conversation identity, consent and remote webhook/source ownership proof still need separate certification. Do not mistake widget or health success for authenticated lead exchange.

## QA and rollback

VPS evidence: `/opt/commerce-components/evidence/etg-forms-crm-chatbotx-20261029/`.

- 21/21 checks in `test-operational-crm.php`: four representative Fluent Forms, proper source mapping, creation of transactional Lead, repeat idempotency, cross-form/spam/blank rejection, existing unsubscribed status preservation, local ChatbotX event privacy, no Woo/Boards mutations.
- 14/14 checks in `test-native-form-event.php` using the actual `fluentform/submission_inserted` action with a real published Form object and an isolated synthetic submission. CRM created and linked through `WorkflowJobs::process` successfully; repeated dispatch did not create another contact or job. Test used SQL `START TRANSACTION` + `ROLLBACK` and disabled external mail/HTTP calls.
- Independent MariaDB readback after tests: Fluent Forms submissions **28**, FluentCRM contacts **2**, contact links **18**, workflow jobs **12**, HPOS orders **1**, FluentBoards tasks **6**, unchanged by the QA cases.
- Runtime source/install parity: **75/75 files**. Source version pending Draft PR CI.

**Remaining**: FluentBoards provider is ready but a new end-to-end request-to-task workflow still needs formal acceptance; estimated price preview is available but immutable customer-visible price-at-submit snapshot is not; FluentSMTP has **0 sending connections**; no Google reCAPTCHA v3 on Woo Lab (native Honeypot and token protections remain enabled); authenticated ChatbotX conversation/contact sync is not configured.

Rollback on Woo Lab only: change `commerce_operational_crm_intake_v1.enabled` to false, then restore the previous `commerce_contact_bridge_sources_v1` and `cmb_business_integrations_v1` options from a prior snapshot if needed. Source rollback: remove `FluentInquiryCrmIntegration.php` and its two registration lines in `composition.php`, revert readiness additions to `InquiryPhaseOperations.php`. Existing CRM contacts created by actual customers are business records and must not be silently deleted.

**No Production mutation or payment/supplier integration.**

## FluentBoards direct task proof (isolated)

Additional Lab transaction test in `test-boards-task.php`: a synthetic unpriced request moved into the `under_review` fixture, created **one** native FluentBoards task via `FluentBoardsTaskBridge::ensure`, then called ensure again and verified the **same task was reused**. SQL ROLLBACK returned counts to baseline: Requests **9**, FluentBoards Tasks **6**, Request–Task links **3**. No customer mail was sent. This proves native task creation + idempotency, not the full browser-to-agent assignment journey.
