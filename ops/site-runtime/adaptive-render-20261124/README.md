# Adaptive render orchestration — phase 1 (2026-11-24)

The existing Render Query Governor includes a read-only staging-only profiler. It measures exclusive nested Elementor render times, phase timings, resolved public query scopes and native cache outcomes. Observation does not authorize cache admission or deduplication. Production profiling remains disabled. The Wayfatours file is a distinct tenant runtime source; deploy the shared include alongside it only through the original release flow.

Page Dependency Cache 0.5.4 writes up to 64 edges per prepared INSERT, with the original surrounding transaction, row-count validation and rollback. Compiled Tour Family 0.2.3 and Mixed Family Islands 0.2.4 register source-backed dependency edges when constructing an eligible fingerprint, including on replay. Page graph coverage stays partial and no TTL or commercial policy changes.

Acceptance: isolated staging MariaDB temporary table, 140/140 exact rows, 140 to 3 insert statements, sample 47.95ms to 3.54ms. Native structural regression tests and live HTTP 200 with Form 6 and price markup on both sites. These are not full booking or browser qualification proofs. Origin <1s, event-driven long-lived compilation, autonomous scheduling, and transactional deferral remain open. Do not interpret this source reconciliation as release approval.
