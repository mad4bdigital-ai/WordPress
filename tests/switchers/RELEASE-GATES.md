# Country & Traveller Nationality switchers — QA release boundary
- This branch contains standalone switchers and the MAD4B shared source / market context required for Country.
- The QA plugin ZIPs are **not** a deployment-ready Control Plane upgrade. The Control Plane must use its governed packaging, compatibility and rollback process before Country is activated elsewhere.
- CI checks PHP syntax, source ownership, no price/currency interception, dependency presence and reproducible archives. These static checks do **not** replace WooCommerce checkout integration tests.
- Verified separately on ETG WooCommerce Local Lab 2026-10-25: Country/Nationality PHP 39/39; Blocks PHP 20/20; Store API simulated cart HTTP 200.
- Not yet certified: real booking-engine traveller count, real full checkout + sandbox payment, MaxMind MMDB, CDN cache separation, registered-customer session, Staging and Production.
- Do not transfer any `qa-*` market approval records or secrets to other environments. Do not enable promotional/seasonal offers.
- The existing `etg-` plugin folder, action names and metadata keys remain temporarily for backward compatibility. Reusable naming / migration is a separate guarded change.
