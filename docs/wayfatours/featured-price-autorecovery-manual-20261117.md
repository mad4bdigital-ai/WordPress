# Wayfatours: Selective Featured Price self-recovery

This source change is offered as a **draft integration into PR #814**, not as a signed Original313 release.

**Live operator action (2026-11-17):** one preexisting first-party PHP presentation loader was replaced manually on Wayfatours after exact SHA256 preimage checking and a private rollback backup. The installed loader SHA256 is `0b226b9aee0a3696d1cf8bf2025559814d19c19cfe4adfc57a0daa5f15136183`. No rates, bookings, checkout, Stripe credentials, or WooCommerce order state were changed; the central Production write gate remains closed.

On the first real uncached anonymous archive request, the native Elementor Dynamic Tag event registered the price source and executed one content-addressed reconciliation. WordPress then stored the computed code fingerprint. Repeated public archive tests confirmed 10/10 Featured prices in English and 10/10 in Spanish on desktop and 390px mobile.

The new loader runs **after** the original price Tag registration (`elementor/dynamic_tags/register`, priority 90), not during early WordPress `init`. Recovery is bound to the SHA256 of the unchanged Original Tour price provider and the loader. It detects the actual published Elementor/JetEngine templates using source pricing tags, invalidates only those template render caches, and asks the existing Host public-cache owner to version safe archive and home paths for active WPML languages. Failures use a bounded retry backoff; it never flushes Redis globally or bypasses payment or Host governance.

The first-party Rate fallback is inert when full Commerce already owns the route; the extracted **shared cache-recovery module** is active in BOTH Commerce and Presentation modes, and inert only when neither runtime is enabled or a required price/tag/cache dependency is unavailable.

GitHub code review and CI **do not** constitute Original313 certification. Review/merge the PR separately from live manual operational receipts.


## Source and tenant runtime convergence (2026-11-17)

- Source `tours.dev.mad4b.com` activates `MAD4B_COMMERCE_RUNTIME_ENABLED`; Wayfatours production uses `MAD4B_PRESENTATION_RUNTIME_ENABLED`. The split is an execution-permission policy, NOT separate pricing engines.
- A single `shared-tour-card-price-recovery-v1.php` owns the elementor/dynamic_tags/register priority 90 hook in both runtime modes; `native-rate-runtime-owner-v1.php` now owns only the Presentation-only Rate/locale/tag fallback.
- The MAD4B Commerce plugin entrypoint includes both modules. Source continues to load its authoritative price provider through the ordered MU map. No duplicate price calculator/checkout route is installed.
- The fingerprint includes the original provider SHA256 and this shared module's SHA256, so each site recomputes once on a changed code dependency; zero repeat purges after success.
- Deployment remains a manually documented, isolated file-level operation; it is NOT Original313 certification and does not authorize other Production writes.
