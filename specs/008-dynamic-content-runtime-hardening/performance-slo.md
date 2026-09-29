# Performance & SLO Model

For a synthetic 50,000-term taxonomy: returned terms never exceed requested limit/hard cap; no full enumeration; DB query count, elapsed time and incremental peak memory are asserted; response exposes complete/truncated_sections/applied_limits.

Initial CI profile: term_limit <= 200; <=25 plugin discovery queries excluding bootstrap; incremental peak memory <=32 MiB; target elapsed <=1500 ms in fixture environment. These are CI budgets, not universal Production promises.

Soak: 100 governed cycles, no leaked locks, no unexpected open recovery cases, bounded journal growth, no unbounded cache growth. Concurrency: 10 independent operations plus same-target, same-operation_key and double-receipt contention. Correctness outranks throughput.


## WordPress Admin read-hotpath SLO

Every ordinary wp-admin request is request-serving work, not MAD4B lifecycle repair. This includes MAD4B screens and third-party screens such as WPML, Elementor, JetEngine, WooCommerce, Rank Math and plugin support pages.

Structural invariants:

- no `dbDelta`, schema install/upgrade, or physical schema reconciliation during page render;
- no managed Skill seed/provider reconciliation during page render;
- no `rest_get_server()` / Abilities priming outside the exact Connection > MCP Endpoints diagnostics tab;
- no managed MU bootstrap refresh or MCP runtime conflict repair during ordinary page render;
- dashboard/console data is loaded per active tab; Overview must not run the adapter `runtime_self_test()` or MCP peer inventory scan;
- lifecycle repair remains available through activation/update, Runtime Convergence, WP-Cron/CLI, or the exact diagnostics surface.
- third-party wp-admin screens must return from MAD4B admin hooks before dependency inventory, bundled-archive hashing, deep governance schema/audit status, build-provenance inspection, Query Monitor profiling, authority inventory, REST/Abilities priming, filesystem repair or telemetry persistence;
- a third-party plugin materializing WordPress Abilities does not authorize MAD4B automatic authority recovery;
- global admin notices must route by MAD4B page before invoking any deep status builder.
- Runtime Convergence fallback detection/checkpoint scheduling must not run on third-party wp-admin screens; blocked convergence checkpoints require explicit resume and are never auto-retried from request traffic.

Staging acceptance budget for the MAD4B admin page that previously showed multi-second server time:

- server elapsed: <= 1500 ms target, <= 2000 ms hard ceiling;
- database queries: <= 100;
- peak memory: <= 128 MiB;
- evidence window: 3 consecutive uncached/warm mixed samples after deploying the exact candidate;
- no MAD4B Query Monitor warning/error regression.
- WPML Support canary: `admin.php?page=sitepress-multilingual-cms/menu/support.php` must render without MAD4B-triggered REST priming, dependency hashing, governance deep-read, repair work or acceptance-telemetry persistence; no MAD4B-attributable 504 is acceptable.

The structural CI gate is mandatory on every Feature 008 runtime change. The numeric budget is a live Staging acceptance gate because host/plugin composition and object-cache state cannot be faithfully represented by source-only CI.
