# Performance & SLO Model

For a synthetic 50,000-term taxonomy: returned terms never exceed requested limit/hard cap; no full enumeration; DB query count, elapsed time and incremental peak memory are asserted; response exposes complete/truncated_sections/applied_limits.

Initial CI profile: term_limit <= 200; <=25 plugin discovery queries excluding bootstrap; incremental peak memory <=32 MiB; target elapsed <=1500 ms in fixture environment. These are CI budgets, not universal Production promises.

Soak: 100 governed cycles, no leaked locks, no unexpected open recovery cases, bounded journal growth, no unbounded cache growth. Concurrency: 10 independent operations plus same-target, same-operation_key and double-receipt contention. Correctness outranks throughput.
