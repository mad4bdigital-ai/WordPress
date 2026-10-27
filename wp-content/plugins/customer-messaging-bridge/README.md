# ChatbotX Customer Messaging Bridge — ETG WooCommerce Lab

Release: **0.2.3**, Lab only. Source: `feature/etg-woocommerce-lab-webchat-bridge` / PR #3.

The adapter is deliberately **not** an independent WhatsApp, Messenger,
Instagram, SMTP or AI provider. ChatbotX owns channel OAuth credentials,
team Inbox, automations, AI and contact conversations. WordPress provides
the Webchat widget, scoped discovery, and safe WooCommerce event hooks.

## Modules / deployment state

| Module | Shipped in plugin | Operational state |
|---|---|---|
| Website Live Chat | official script singleton | enabled for ETG WooCommerce Lab |
| WhatsApp Business | channel discovery/configuration | needs ChatbotX Meta OAuth/inbox |
| Facebook Messenger | channel discovery/configuration | needs ChatbotX Meta OAuth/inbox |
| Instagram DM | channel discovery/configuration | needs ChatbotX Meta OAuth/inbox |
| Email / Shared Inbox | channel discovery/configuration | needs ChatbotX SMTP/inbox |
| CRM / Contact Profiles | scoped API discovery, no writes | requires workspace API token |
| WooCommerce Orders | anonymized local event outbox | order events enabled in Lab |
| WooCommerce Customers | anonymized local event outbox | can be enabled by admin in Lab |
| Abandoned Cart | consent-gated cart observation | disabled pending consent provider |
| AI & Automations | signed inbound event adapter + admin management | provider rules/AI configured in ChatbotX, no outbound actions yet |
| Human handoff | ChatbotX team inbox | provider teams/roles configured in ChatbotX |

This is a **lab foundation**, not completed real-world provider onboarding or
a paid-message campaign. It does not independently send emails, WhatsApp
templates, cart reminders or customer data to ChatbotX.

## WordPress installation / rollback

Copy directory to `wp-content/plugins/customer-messaging-bridge`,
activate in WordPress, open **Settings → Customer Messaging**. Set Workspace
ID and Webchat ID from ChatbotX, then enable the Webchat widget.

- Lab Workspace: `11755877814459770` (MAD4B Digital)
- Lab Webchat: `11755877814902375` (ETG WooCommerce Lab)
- Local Lab origin: `http://localhost:8091/`
- Allowed ChatbotX embed domains: `localhost`,
  `jdj8d534-8091.euw.devtunnels.ms`.
- ChatbotX application pinned to the stable source SHA
  `8fb1a28c9c20fe1ae133d19be8e7467a423f0c06`.

Existing 0.1.x WooCommerce local status audit is migrated into the
new privacy-minimal event observation configuration on upgrade. The old
audit option is preserved for reference.

## ChatbotX API & channel discovery

To enable **read-only** discovery of connected inboxes, create an appropriately
scoped workspace API token in ChatbotX and put it in server-only
`wp-config.php`, above the stop-editing comment:

```php
define('CMB_CHATBOTX_WORKSPACE_TOKEN', getenv('CMB_CHATBOTX_WORKSPACE_TOKEN'));
```

Provision the actual environment variable using the authorized secret store
and grant only the `inbox` scope (singular). No token goes into
`wp_options`, HTML, or the WordPress editor.

- Authenticated API: `GET https://chatbotx.mad4b.com/api/v1/inboxes`
- All traffic requires TLS verification; host is hardcoded in the plugin.
Inbox discovery uses paginated GET requests (up to 10 pages × 50 inboxes)
and fails closed when the response is incomplete or malformed.
- WhatsApp Business, Messenger, Instagram and email OAuth/inboxes are
  connected and managed **inside ChatbotX** by a platform administrator.
- Outbound customer/order sync is not performed by this release. In
  particular `POST /v1/orders` creates native COD orders, **not** a safe
  import of arbitrary WooCommerce orders, so the plugin does not misuse it.

## Local WooCommerce events, consent and privacy

Events captured when their module is enabled: `order.created`,
`order.status_changed`, `customer.created`, `cart.observed`.

The outbox is capped at 200 observations and contains only HMAC event
fingerprints, event types, status labels and UTC timestamps. No names,
emails, phone numbers, basket lines or order/customer IDs are stored.
Woo payment completion, failure, cancellation and individual refunds are also
observed without customer/payment details. Repeated identical events are deduplicated; distinct status transitions
are preserved. It does **not** provide a recoverable replication queue.

Abandoned cart signals require both explicit opt-in to the module AND a
verified consent integration that returns `true` for:

```php
add_filter('cmb_customer_marketing_consent', function ($default) {
    // Only return true for the CURRENT visitor with verified opt-in.
    return false;
});
```

The default is false. A changed cart is an observed *candidate*, not a
verified abandoned basket. No automated follow-ups are sent.

## Optional inbound signed webhooks

`POST /wp-json/cmb/v1/events` remains disabled unless a random secret of at
least 32 bytes is defined in the WordPress server configuration:

```php
define('CMB_INBOUND_HMAC_SECRET', getenv('CMB_INBOUND_HMAC_SECRET'));
```

The client sends:

- `X-CMB-Timestamp`: 10-digit Unix timestamp, ±5-minute acceptance
- `X-CMB-Signature`: `sha256=HMAC_SHA256(secret, timestamp + "." + raw_body)`
- JSON body with `id`, `workspaceId`, `kind`

Only known event kinds and the configured Workspace ID are accepted.
The event ID is reserved atomically in WordPress options, with 24-hour
expiry and bounded opportunistic cleanup (no dependence on WP-Cron).
Payloads are never retained
and the webhook **does not** mutate WooCommerce orders or customers.
Read-only status is `GET /wp-json/cmb/v1/status`, restricted to
WordPress administrators.

To send actual provider callbacks into this receiver requires a separate
authorized ChatbotX webhook sender with the same signature protocol;
this release does not assert such a sender has been deployed.

## Testing

```bash
php -l integrations/wordpress/customer-messaging-bridge/customer-messaging-bridge.php
php integrations/wordpress/customer-messaging-bridge/tests/standalone.php
```

Standalone tests cover API token default-off, channel registry, resource
validation, widget singleton, local WooCommerce event policies, privacy,
idempotency, bounded retention, HMAC time window, signature tampering,
tenant isolation and replay protection.

Browser-based smoke QA is versioned at
`tests/browser-e2e.py` and requires an installed Playwright Python package
and Chromium browser. It uses a fresh, uncredentialed browser context and
checks real Desktop (1365 × 900) and Mobile (390 × 844) widget loading:

```bash
CMB_E2E_LAB_URL=http://localhost:8091/ python3   integrations/wordpress/customer-messaging-bridge/tests/browser-e2e.py
```

A real synthetic guest-message test was completed from Chromium on
2026-10-27: the message appeared in the chat interface, the input cleared,
and the ChatbotX database recorded **one** message in Workspace
11755877814459770 / Inbox 11755877814902375. To repeat a real send in
the Lab only, set `CMB_E2E_SEND_TEST_MESSAGE=1` explicitly.

The script does not authenticate an agent or send an agent reply. Live
browser handoff and agent-response testing remain independent gates.

The live Lab homepage contains the official widget script once, and the
remote JS/CSS/iframe respond with HTTP 200. A full real-browser chat
message round-trip, provider inbox OAuth, CRM writes, automation execution
and consent flows are **separate release gates**. A local-only lab URL
cannot substitute for browser e2e proof.

## MAD4B Control Plane provider integration (Lab v0.2.3)

The companion MAD4B SCP bundled adapter registers three **read-only**
abilities: `mad4b/chatbotx-provider-snapshot`,
`mad4b/chatbotx-channels-read`, and `mad4b/chatbotx-remote-health`.
The Control Plane source-review policy must approve the exact bundled class;
third-party adapters cannot self-enroll. WordPress settings are registered
for passive discovery but **not** exposed through REST and no arbitrary write
operations are granted through MCP. Live Webchat and WooCommerce writes stay
under WordPress' original authorization and consent constraints.
