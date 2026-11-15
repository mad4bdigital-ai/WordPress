# Performance v2 — original MAD4B HTTP integrity monitor

This is the local Git source for the existing VPS runtime module at:
 /opt/mad4b-company-provisioning/bin/performance-v2/performance_http_readback_v2.py

This is NOT a second deployed worker. The existing
 mad4b-performance-integrity-v2.timer imports the same script from the
original runtime. This source snapshot and its tests do not create a
scheduled job, edit WordPress, approve CSS, purge caches, or enable
cross-market HTML reuse.

The monitor accepts:
1. Original per-epoch/per-bundle verified Critical CSS, or
2. Original complete LiteSpeed CSS ONLY when WordPress native SHA-verified
   proof header equals 1 and HTML has one blocking full stylesheet.

A private-market-veto remains BYPASS/private. Public cache expectations
only pass with verified HIT or first-request MISS. Incomplete HTML reads
retry a bounded number of times; partial responses are never accepted.

Test using:
 python3 -m unittest discover -s ops/performance-v2/tests -p 'test_*.py' -q

Deploy only on a matching preimage of the installed runtime and never
treat this local source commit as an Original313 signed company release.

## Chromium and HTTP CSS content negotiation

The CSS capture script resides at ops/mobile-critical-css-v1/capture.js in
the same local Git tree. The installed source is in MAD4B provisioning ops.
Both tools use the chromium-mobile-webp-v1 HTML Accept profile.
LiteSpeed rewrites certain image URLs in combined CSS to .jpg.webp for
WebP-capable clients, changing the SHA without Elementor document changes.
Matching the browser negotiation profile enables exact capture-to-monitor
comparison but never treats JPEG-only CSS as WebP certified.
A site with private_market_veto stays private/BYPASS; browser Accept is
not a trusted cache or market identity.

## Bounded gzip transport

Source Tour pages can exceed 900KB uncompressed. The original read-only
HTTP integrity monitor requests gzip and inflates at most 8MiB, validates
the gzip trailer/CRC, rejects incomplete HTML and non-comment content after
the document close, and retries incomplete transport no more than 3 times.
LiteSpeed's short post-HTML diagnostic comments are allowed. This change
reduces network load in monitoring; it does not compress the visitor response
at WordPress level, change origin cache policy, or certify Critical CSS.
