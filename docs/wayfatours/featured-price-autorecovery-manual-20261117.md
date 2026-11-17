# Wayfatours: Selective Featured Price self-recovery

This source change is offered as a **draft integration into PR #814**, not as a signed Original313 release.

**Live operator action (2026-11-17):** one preexisting first-party PHP presentation loader was replaced manually on Wayfatours after exact SHA256 preimage checking and a private rollback backup. The installed loader SHA256 is `0b226b9aee0a3696d1cf8bf2025559814d19c19cfe4adfc57a0daa5f15136183`. No rates, bookings, checkout, Stripe credentials, or WooCommerce order state were changed; the central Production write gate remains closed.

On the first real uncached anonymous archive request, the native Elementor Dynamic Tag event registered the price source and executed one content-addressed reconciliation. WordPress then stored the computed code fingerprint. Repeated public archive tests confirmed 10/10 Featured prices in English and 10/10 in Spanish on desktop and 390px mobile.

The new loader runs **after** the original price Tag registration (`elementor/dynamic_tags/register`, priority 90), not during early WordPress `init`. Recovery is bound to the SHA256 of the unchanged Original Tour price provider and the loader. It detects the actual published Elementor/JetEngine templates using source pricing tags, invalidates only those template render caches, and asks the existing Host public-cache owner to version safe archive and home paths for active WPML languages. Failures use a bounded retry backoff; it never flushes Redis globally or bypasses payment or Host governance.

The module is inert when full Commerce already owns the route, when no Presentation runtime is admitted, or when the original pricing evidence, Elementor tag, or scoped cache owner is unavailable.

GitHub code review and CI **do not** constitute Original313 certification. Review/merge the PR separately from live manual operational receipts.
