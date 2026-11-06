# MAD4B Persistent Site Shell

Portable WordPress plugin for company websites. No site domain, site UUID,
language list, Elementor template ID or cloud endpoint is embedded in the runtime.

## Current behavior

- Installed and activated on the source Staging, but partial navigation is **OFF** by default.
- Site origin, tenant UUID, environment and WPML language are resolved from that site's WordPress settings and existing MAD4B Site Profile.
- When expressly enabled, eligible anonymous GET pages can request a **local fragment** with only their main HTML, SEO head metadata and hashes/URLs of required CSS/JS assets.
- Existing header and footer DOM remains mounted only if locale, template signature, required assets and supported static widgets match. Otherwise normal navigation runs automatically.
- Cart, checkout, account, forms, filtering, listings and other interactive regions are not promoted to partial navigation.
- Fragment responses are **not cached**, carry a dedicated content type, and ask LiteSpeed not to cache. They are deliberately unsuitable for production until a safe local caching/invalidating strategy and browser QA are certified.
- No QUIC.cloud/remote CSS generation is used. WordPress and the site cache still own full-page delivery.

## Operations

Settings: WordPress > Settings > MAD4B Site Shell.
CLI: `wp mad4b shell status`.
Standalone identity test: `php tests/contract.php`.
Rollout requires desktop/mobile, each active language, Elementor/WPML, LCP, history/back, navigation and forms/booking regression tests.

The source site's opt-in setting must not be propagated to a company during provisioning.
