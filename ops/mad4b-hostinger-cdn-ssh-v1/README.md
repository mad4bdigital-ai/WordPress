# MAD4B Hostinger CDN SSH operation

Installed on Tours.dev central staging and the enrolled Cloud Startup Hostinger account on 8 November 2026.

Operation ID: company.cache.hostinger.refresh
Knowledge ID: hostinger.cdn.ssh.invalidation
Company Manager page: /wp-admin/admin.php?page=mad4b-company-hostinger-cdn-operations
Read API: /wp-json/mad4b/v1/company-hostinger-cdn-operation (manage_options required)
Read abilities: mad4b/hostinger-cdn-operation-status and mad4b/hostinger-cdn-knowledge

The operation checks exact enrolled SSH identities, known WordPress roots and their canonical WP home. LiteSpeed URL purge uses a bounded WP-CLI URL command and does not claim to invalidate Hostinger CDN. Independent edge requests test whether HTML stored by the Hostinger CDN is incorrectly used for personal currency, market, passport, login or WooCommerce session contexts. A sensitive CDN HIT yields WAITING_PROVIDER_CDN_PURGE. Provider invalidation must be confirmed by its own receipt and edge recheck before declaring success.

The central Company & Brand Readiness admin submenu is read-only. The central sync projects the status/knowledge into WordPress options, with no user secrets. Periodic systemd operation reconciles every 30 minutes. This is NOT a signed original 313 operation.

Provider limitation: hPanel API returns 403 and no CDN-purge CLI is installed on the remote SSH account. Tourism's Hostinger WordPress plugin has no callable CDN purge hook. Changing origin Cache-Control or purging LiteSpeed cannot revoke already stored Hostinger CDN HTML.

Production safety: never flush Redis or all caches; never treat an origin purge as Hostinger CDN invalidation, and never enable shared CDN HTML until context isolation is confirmed.

Live paths:
- /opt/mad4b-company-provisioning/bin/company-hostinger-cdn-operation-v1.py
- /opt/mad4b-company-provisioning/reports/company-hostinger-cdn-operation-current.json
- /opt/mad4b-company-provisioning/reports/company-hostinger-cloud-cdn-edge-current.json
- /opt/mad4b-company-provisioning/knowledge/hostinger-cdn/hostinger-cdn-ssh-invalidation-v1.json
- /etc/systemd/system/mad4b-company-hostinger-cdn-operation.service and .timer

## Dual-route site identity (November 8, 2026)

Internal company path: MAD4B_Company_Environment_Registry_V2::discover() is the source of company/environment/canonical origin. The Company & Brand Readiness Hostinger CDN page exposes a proposal form for company site, registered SSH target, Hostinger account alias, and Hostinger website-domain candidate. They are references, not API credentials or SSH keys. Proposals remain PROPOSED_PENDING_EXTERNAL_SSH_AND_API_WEBSITE_VERIFICATION.

External host path: company-hostinger-two-route-binding-v1.py matches enrolled source sites against registered SSH accounts and checks each WordPress home through a pinned host key over SSH. This readback verified 5/5 ARE, ETG, and Tourism environments.

Routing choices:
- site.identity.read and wordpress.wpcli.read: registered SSH when verified.
- wordpress.litespeed.url_purge: eligible SSH transport but does not issue a mutation from routing alone.
- hostinger.cdn.purge: WAITING_HOSTINGER_API_WEBSITE_BINDING until the exact website is independently verified in the approved Hostinger account.
- company.cache.hostinger.refresh: composite SSH origin invalidation, Hostinger API edge invalidation and independent CDN isolation check. No substitute of an origin-purge receipt for a missing edge purge.

The router rechecks enrolled SSH identities on every 30-minute operation cycle. Central ExecStartPost projects exact origin, routing and observations to Company Manager. A domain candidate is not authorization; no API or SSH secrets are stored in WordPress.

First acceptance: 5 SSH-verified company environments, 0 API-verified websites. The connected primary Hostinger account returned empty hosting_websites_list; Tourism's domain was not found. No Hostinger CDN purge was issued.
