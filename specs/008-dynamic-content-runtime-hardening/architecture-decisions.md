# Architecture Decisions

ADR-001 identity separation: operation_key != operation_id != operation_binding_sha256.
ADR-002 journal is an append-only event log; lifecycle state is a projection.
ADR-003 high-volume journal storage uses dedicated tables, not wp_options.
ADR-004 provider manifests extend existing trusted provider authority; no parallel registry.
ADR-005 cache is optimization only; apply always live-revalidates.
ADR-006 impact level and impact flags are orthogonal.
ADR-007 deterministic TTL tiers first; predictive adaptation deferred.
ADR-008 fault injection excluded from Production package.
ADR-009 visualizer is non-blocking for core certification and non-authoritative.
ADR-010 compatibility is semantic old-field projection, not whole-response byte equality.
