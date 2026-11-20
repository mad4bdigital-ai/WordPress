# Dynamic Wayfa home CSS dependency observation

A passing original home Critical CSS certificate is tied to the precise
LiteSpeed combined CSS bytes used in the real Chromium QA profile. Changing
the CSS emitted on the homepage (for example after a dynamic JetEngine
listing, Elementor template or destination/menu update) may invalidate the
old certificate even if WordPress CSS epoch and option generation remain
unchanged. Merely hashing the OLD manifest sources misses such drift.

For the Wayfatours production EN/ES homepage ONLY, the first-party home
worker now observes the current anonymous mobile HTTP combined CSS link
and computes SHA256 of its exact locally retained LiteSpeed file. The
private state is content-addressed and sampled no more than hourly.
When and only when either homepage source identity changes, this produces
a NEW event-gate dependency key. It opens a fresh original Chromium
evaluation, without approving the observed HTTP/cache/client-hints
variant as a certificate or bypassing any original PHP, WCML, Elementor,
FCP, LCP, CLS, country/currency or signed admission proof.

Source Tours.dev does not inherit Wayfa state and continues its separate
six-language engine. Missing/unsafe HTTP observation retains the previous
event key and logs a HOLD. Unknown CSS origin, non-HTTPS URL, unknown
language, symlinks, transient/oversized HTTP, and tampered private
observation fail closed. No WooCommerce/cart/checkout or WordPress
options are modified. Existing full original CSS remains available.

This avoids repeated expensive tests on an unchanged source: the
15-minute timer uses one cheap local state check, whereas the bounded
live HTTP inspection updates the dependency signal only once per hour.
The registered actual Chromium source is still independently verified
before any live CSS certificate is published.
