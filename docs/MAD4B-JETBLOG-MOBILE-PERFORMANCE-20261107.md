# MAD4B JetBlog mobile-first performance mitigation

Tours.dev Staging, 2026-11-07. Original JetBlog plugin files unchanged.

## Evidence
The operator's Pixel 10 screenshots show initial HTML request times: Home 53.89s, Tours archive 15.63s, Tour detail 34.77s. Mobile JetBlog AJAX requests are 8-13s. PHP-FPM has six children and logs 12+ second requests. Slowlog samples include Elementor Dynamic CSS/controls, JetEngine Listing and visibility queries, WPML permalink/translation and JetBlog. Multiple WP-CLI and Chromium QA requests compete with PHP workers. Cached FastCGI homepage is 30s and archive 15s, but private, cookie and query contexts bypass it.

JetBlog vendor generates an AJAX URL with nocache=time() and jet_blog_ajax=1. On non-desktop viewports the JetBlog initSmartListing handler automatically makes an initial signed POST for every Smart Listing, even if below fold. Three Smart Listing wrappers were found on Home.

## Staging-only patch v0.2.0
- Adapter: wp-content/mu-plugins/mad4b-jetblog-mobile-lazy-canary.php
- Generated script: wp-content/mu-plugins/assets/mad4b-jetblog-lazy-mobile.js
- Only the initial responsive Smart Listing AJAX is deferred until 450px from viewport using IntersectionObserver.
- Fallback for missing IntersectionObserver calls the native AJAX immediately.
- User click on filter or page controls cancels the pending initial refresh.
- No change to vendor payload signatures, endpoint, POST body, content markup, query args or other 3 AJAX call sites.
- Original plugin source SHA256 must equal 1c03c3f87299c68376daea342e33131d237b54b76470e155accfd03a4a4c5ee4 or adapter leaves native script in place.
- Patched JS must equal fd7a92e7bc67310257f33dfe04a0058a32f9946e0011b169cf704403243bc92b or native remains.
- Adapter runs only on anonymous mobile Staging frontend. It rebinds the script after Elementor fires frontend/after_enqueue_scripts and excludes this one script from LiteSpeed optimization to avoid stale compiled bundle reuse.
- Default Staging switch: mad4b_jetblog_mobile_lazy_v1 = on when not explicitly set. Set the option to off to revert. For browser A/B, request ?mad4b_jetblog_lazy=0 for original or =1 for patched; these query URLs bypass Nginx FastCGI.

## Verified
- Original and generated file fingerprints checked, PHP lint and Node syntax pass.
- Isolated Node VM contract passes: no AJAX while offscreen, exactly one initial AJAX when entering viewport, repeated observer callbacks do not duplicate it, clicking cancels pending refresh.
- Native mobile control and patched mobile requests returned HTTP 200, and their HTML selected the expected distinct script sources.
- Normal mobile noncached Staging HTML serves the patched source from MU assets; PHP, Nginx and Redis health remain good.
- Two single unpaired samples recorded 8.61s new and 20.70s old TTFB, but loads differed. They DO NOT demonstrate statistically valid speed gain.
- No new production, Rate, Quote, Checkout or FastCGI purge change.

## Outstanding
This change reduces concurrent initial offscreen mobile AJAX, but does not solve expensive initial PHP/Elementor/WPML HTML render. Needed: controlled mobile browser navigation and AJAX counts under quiet server load, interaction and translation parity, cold/warm TTFB samples, JetEngine Dynamic CSS and WPML repeated work instrumentation, PHP CLI scheduler load control, and mobile LCP payload review. Do not claim Core Web Vitals or browser interaction acceptance until those tests run.
