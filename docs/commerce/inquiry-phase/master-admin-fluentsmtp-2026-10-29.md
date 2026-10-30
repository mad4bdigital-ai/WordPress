# Master Admin inquiry replies — FluentSMTP transport

**Scope:** ETG Woo Lab `local` only. 2026-10-29. Stacked on Draft PR #604 (which is stacked on #602 and #600). No Staging/Production source deployment or real customer mail.

## Native FluentSMTP, not a parallel mailer

`RequestMailReply.php` **only calls WordPress `wp_mail()`**, allowing the active **FluentSMTP plugin on that exact WordPress installation** to choose the configured sender/default connection. Commerce Composition does not hold provider secrets, does not create OAuth connections, does not borrow a sender from another installation and does not send via a direct HTTP/SMTP client. It checks for an installed active FluentSMTP connection, sender identity and (for Gmail) configured OAuth material before permitting the send action. The mail connection stays exclusively configured and rotated in **FluentSMTP → Settings → Connections**.

A working Gmail OAuth test connection is present on **ETG Staging**, but **Woo Lab has zero FluentSMTP connections**. Do not copy or export its OAuth credentials to Woo Lab. Until a legitimate FluentSMTP connection is configured on Woo Lab itself, the send command **fails closed** with `fluent_smtp_connection_missing`. The reply interface shows this and links to the native FluentSMTP settings. No mail was sent.

## Master Admin + User Switching

The existing `user-switching` WordPress plugin is active. An administrator can already switch to a Master Admin account using its native signed action and nonce. The Master Admin role has existing narrow `mad4b_manage_company_operations` permission but **does not** gain `manage_options`, `switch_users`, `commerce_approve_request_processing` or arbitrary user impersonation.

`RequestMailReply::authorized()` requires a logged-in current user who has the `master_admin` role **and** the existing `mad4b_manage_company_operations` capability, along with the site-bound inquiry reply option and enabled Request Review subsystem. No global role capabilities have been modified. User Switching changes the active user at WordPress authentication level. The sender's actual actor ID is recorded in `wp_commerce_request_reply_events`; if the plugin's validated old-user cookie exists, `switched_from_actor_id` is also recorded. Cookie impersonation was not simulated in CLI tests.

A dedicated **Inquiry Replies** admin menu lets the Master Admin select an eligible request, confirm the exact recipient, write a plain-text transactional reply, and press a manual Send button. The screen is disabled when the **same site's** FluentSMTP transport is not configured.

## Verifying requester identity and avoiding spam

`RequestMailReply::recipient()` reads an **unpriced, active** request with an open review state and verifies every original Fluent Forms submission. It enforces exactly one matching source-key per Form, one linked FluentCRM contact per submission, the same CRM ID and email across all request items, and equality with the persisted email address in FluentCRM. It excludes spam/deleted sources and suppressed/bounced/complained contact statuses. Only **the verified original email** can receive a reply; callers cannot supply arbitrary `to`, `from`, `cc` or `bcc`.

No marketing opt-in, checkout, quote approval, payment, booking or supplier transaction is performed. The request email is scoped as a reply to a customer inquiry, not a campaign. The subject and body are validated as short plain text; HTML and header injection are rejected. The send command must carry a unique idempotency key. An audit claim is inserted before calling `wp_mail()`, and duplicate commands cannot send the same request twice. The audit stores **HMAC hashes** of recipient and message, not email body or personal details. `transport_accepted` means `wp_mail()` accepted the message, **not** proof of external mailbox delivery. Failed/ambiguous attempts remain logged and are not blindly retried.

## Routes and UI

- Native WP Admin menu: `admin.php?page=commerce-request-reply` on Woo Lab.
- Private preview: `GET /wp-json/commerce-composition/v1/request-replies/{request_id}` when the Master Admin is signed in.
- Explicit send: `POST /wp-json/commerce-composition/v1/request-replies/{request_id}` with a unique `Idempotency-Key` header and only `subject`, `message` in JSON.
- Admin form uses WordPress action nonce and a unique intent key.
- No API endpoint or UI bypasses FluentSMTP readiness.

Site policy `commerce_request_reply_policy_v1` is enabled on Woo Lab only; `transport=fluent_smtp`. Audit table `wp_commerce_request_reply_events` installed. The transport is still **NOT READY** because Woo Lab has no FluentSMTP connection.

## Verified QA

Evidence on VPS: `/opt/commerce-components/evidence/etg-master-admin-mail-20261029/qa-result.json`.

- **17/17 PASS** on isolated Woo Lab (with fake FluentSMTP settings used solely as an in-memory provider fixture): Master Admin allowed, normal admin not allowed, Master Admin cannot switch to an administrator, missing live connection blocks all sending, verified original CRM Form recipient, header injection rejected, explicit single `wp_mail()` intercept, no real external delivery, message idempotency, audit actor, no Woo Orders or FluentBoards tasks, complete SQL rollback.
- **79/79 files** byte-matched between Commerce Composition source and installed Woo Lab, and manifest updated.
- The test **does not** establish real SMTP/Gmail transport health or mailbox delivery. No customer email was sent. User Switching browser/cookie round trip is not yet certified.
- All real outbound mail remains blocked until the administrator configures FluentSMTP on Woo Lab and runs an explicitly approved test to an authorized test mailbox.

## Operational next action

Open Woo Lab's own WordPress admin (not the public `tours.dev.mad4b.com` hostname that currently points to Staging), go to **FluentSMTP → Settings → Connections**, and connect/authorize a legitimate test sender. After a successful test delivery and authorized Master Admin switched-session smoke test, certify the mail channel. Never paste OAuth secrets or test mailbox passwords into repository commits.

Disable on Woo Lab without deleting audit history by setting `commerce_request_reply_policy_v1.enabled=false`; revert module registration + admin screen for code rollback. Staging's OAuth configuration is left intact.
