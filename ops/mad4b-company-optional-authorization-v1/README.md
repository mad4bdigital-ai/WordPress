# MAD4B Optional Company Authorization Requests v1

This is an OPTIONAL, non-blocking consent and audit workflow. A pending, declined, failed, expired, or absent email request MUST NOT prevent original VPS Client / Cross-SSH / WP-CLI execution or an already-authorized signed native Write Executor. Consent is NOT a native WordPress grant, OAuth token, plugin access privilege or 313 receipt.

## Central operations

The central Staging Company Manager exposes these individually admin-authenticated REST operations (all under /wp-json/mad4b-company-management/v1):

- GET /authorization-requests/plan?company=...&environment=staging: nonblocking plan
- GET /authorization-requests/recipients?company=...&environment=staging: exact source-verified Master Admin IDs, no plaintext emails
- POST /authorization-requests: unsent request with exact site, purpose and selected owner_user_id
- POST /authorization-requests/{uuid}/send with confirmation SEND COMPANY AUTHORIZATION EMAIL: explicit one-shot email queue
- GET /authorization-requests: status and decision receipts

Agent-discoverable abilities are mad4b/company-optional-authorization-plan, mad4b/company-optional-authorization-create and mad4b/company-optional-authorization-send. The host has separate original-WP-CLI-only methods host_create and host_queue, providing the VPS Client route without impersonating a company user. No existing router, original write authority or company OAuth logic was edited.

## Company owner approval

The host verifies original registered Cross-SSH target, exact live Site UUID and canonical WordPress home. Only an explicitly selected, existing master_admin with valid mailbox is eligible. The company-side plugin stores the SHA-256 link token hash, exact WordPress user ID, mailbox hash, purpose, site identity, expiry and one-use state. The email directs the owner to their own company WordPress site.

Approval requires their normal WordPress login, exact master_admin role, matching assigned user, authenticated admin session, WordPress nonce, live site binding and unexpired single-use token. Receipt is retrieved by the original pinned Cross-SSH route. No elevated Master Admin capability or new user is created.

## Delivery and operation isolation

Central send is opt-in ONLY: an explicit administrator queue action is required. Root-owned mode 0600 HMAC key at /etc/mad4b/optional-company-authorization-hmac.key ensures stable token for interrupted attempts without storing plaintext. The central email sender atomically records DELIVERY_IN_PROGRESS before attempting wp_mail; mail transport acceptance does not imply inbox delivery. Failed/incomplete delivery cannot silently re-send. No SMTP passwords, bearer tokens, link tokens, passwords or unrelated user data are logged or committed.

The standalone host timer checks explicitly queued requests and pending consent readbacks every 30 minutes. It does not create requests, discover owner accounts, send automatic solicitations, or affect the host routing policy. Owner candidate refresh is a separate explicit host worker recipients action. A ChatGPT Agent can ask VPS Client to run that action or process a queued request without requiring WordPress admin UI navigation.

## Pilot status

Central module deployed on Tours.dev Staging; company module deployed on All Royal Egypt Staging and Egypt Tour Gates Staging only. Both remote sites rejected an anonymous approval and an incorrect site identity prepare call with zero new pending records. Central GET endpoints reject anonymous calls with HTTP 401. Worker selftest PASS and timer enabled. No emails have been sent.

ARE has two Master Admin accounts with valid mailboxes, but its central Company Bootstrap Owner Email is not yet bound, so no owner ID was selected automatically. Select the exact intended recipient explicitly before creating and sending a request. Production was not modified. Do not claim end-to-end owner click, inbox delivery, or OAuth reconnect until verified by a real owner.

## Rollback

Disable mad4b-optional-company-authorization.timer; remove only the central 322 and company 323 MU files from their enrolled sites after backing up. Inert stored request records do not change any original permission. Do not remove other MAD4B MU plugins or native transport code.

## Real activation after company-owner approval

Approval starts an optional activation track. Real OAuth access/refresh tokens can only be issued through the WordPress OAuth Server authorization-code and S256-PKCE exchange initiated by the enrolled ChatGPT public client. The approval email cannot mint tokens. The agent then invokes the original Staging Write Authority reconciliation using its newly authorized OAuth bearer. No route is blocked if an optional request is ignored or declined.

- WAITING_CHATGPT_PKCE_OAUTH: owner approved, but no exact post-approval ChatGPT OAuth token issuance yet.
- WAITING_ORIGINAL_GRANT_CONVERGENCE: original OAuth token issued with exact Site UUID, enrolled OAuth administrator, ChatGPT client, resource and required scopes; original write-grant operation still needs authenticated execution.
- READY: original current grants, candidate binding, and Write Authority verified after OAuth evidence.

The company verifier uses original Local OAuth Store metadata for unrevoked, nonexpired, unused refresh credentials issued after the approval and within 24h, with the required authority-step-up scope for write cases. It does not expose OAuth token contents or hashes. The central optional-request status synchronizes this proof and names the next original grant-plan ability. It does not spoof a token or issue grants via root SSH.

No actual owner has yet approved a request, so this implementation does not claim a newly issued OAuth token or an executed write-grant reconciliation. Production remains unchanged.
