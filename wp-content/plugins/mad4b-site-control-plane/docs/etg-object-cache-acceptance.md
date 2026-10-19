# ETG / MAD4B Object Cache verification (Memcached / LSMCD / Redis)

Hostinger LiteSpeed Memcached (LSMCD) can supply a persistent WordPress Object
Cache; LiteSpeed **page** caching alone is not proof. A PHP extension or the
`object-cache.php` drop-in is not proof of successful connection.

MAD4B's governed read ability `etg-dfsb/object-cache-status` exposes **passive**
signals, without creating keys, changing options, contacting the backend, or
making claims of cross-process persistence.

An explicitly approved Staging WP-CLI administrator can use the separate
acceptance script in four independent processes:

```sh
wp eval-file wp-content/plugins/mad4b-site-control-plane/tests/live-object-cache-acceptance.php seed --user=admin
wp eval-file wp-content/plugins/mad4b-site-control-plane/tests/live-object-cache-acceptance.php read <token> --user=admin
wp eval-file wp-content/plugins/mad4b-site-control-plane/tests/live-object-cache-acceptance.php increment <token> --user=admin
# 12+ seconds after seed:
wp eval-file wp-content/plugins/mad4b-site-control-plane/tests/live-object-cache-acceptance.php expiry <token> --user=admin
```

The seed adds a random namespaced key with a 12-second TTL and increments it.
Read in a separate process proves that the key was shared between these two
processes (not necessarily between servers). The increment proves functionality,
not atomicity under concurrency. To prove atomicity, run parallel increment
workers, compare their reported results and read the final count. To prove
multiple-server sharing, repeat read from each node. An expiry read after the
TTL shows the key is absent but should be repeated to rule out eviction.

The script refuses Production, non-WP-CLI and non-admin invocation. It never
writes options/transients or flushes cache. Do not enable ETG SEO publication
or the bridge based on passive signals.
