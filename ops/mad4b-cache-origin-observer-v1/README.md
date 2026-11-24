# MAD4B Cache / Origin Observer v1

Versioned live-source observability, as deployed 22 November 2026. Tours.dev Nginx currently emits sampled, categorical-only decisions for cache HIT/MISS/BYPASS, route family, origin timing and safety flags. Wayfatours uses ONLY anonymous synthetic probes: its live Nginx remains on the earlier, validated config and the real-visitor classifier is NOT enabled there.

Both sites run separate ~30-minute systemd timers, which check real container/host bind SHA consistency and sample anonymous public tour pages if host load is acceptable. No request IP, full URL, cookie value, user agent, or customer data is stored by new telemetry. Do NOT whitelist unknown cookies or remove temporary Tours.dev IP market isolation before accepted public-shell/market correctness tests.

Read-only inspection: python3 ops/mad4b-cache-origin-observer-v1/bin/mad4b-cache-origin-observer-v1.py --site source --skip-probes (requires enrolled VPS runtime).

The Nginx patch in patches/ applies ONLY to Tours.dev. It is an additive observability patch, not a replacement Nginx config, and does not change cache keys or safety bypass. Do NOT apply to Wayfatours: different topology and variables.

Caution on read-only single-file Nginx bind mounts: atomic replace on the host does not alter the running container's mount; report DRIFT_HOLD and perform a human-reviewed planned restart ONLY after correct Nginx config validation, 0644 host permissions, backup with safe restore mode, health-window verification, and possible cold cache priming. One Wayfatours controlled rollback briefly caused HTTP 503 because a 0600 backup was restored directly; correcting file mode to 0644 restored its original Nginx configuration and external HTTP 200. It is now healthy. This release does not auto-restart nginx.

Origin bottleneck remains real. Samples: source empty-cookie FastCGI ~12ms, unknown-cookie bypass ~5.6-6.2s; Wayfa empty-cookie ~13-17ms, unknown-cookie ~3.9-4.2s. WP-CLI SAVEQUERIES examples: source 875 queries, ~0.318s query total, 4.38s WP-CLI duration; Wayfa 502 queries, ~0.126s query total, 3.27s WP-CLI. PHP-CLI OPcache is OFF while PHP-FPM OPcache is ON, so CLI numbers are not a full production browser/FPM profile. Redis object cache is enabled both sites.

Remaining: robust independent HTML/signed market semantics; any source cache key consolidation; true FPM stage-level performance profiling and fixes; Wayfa visitor log collector rollout. No Production WordPress owner/grant policy touched.
