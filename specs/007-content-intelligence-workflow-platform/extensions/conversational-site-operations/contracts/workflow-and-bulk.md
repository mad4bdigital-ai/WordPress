# Bulk, Workflow DAG and Trigger Protocol

Natural language compiles into an immutable typed DAG using only certified Ability IDs and exact schemas. Nodes declare inputs/outputs, dependency edges, issuer/site scope, budget, retries, readback, provider side effects and compensations. Reject cycles, unbound outputs, unknown nodes and plugin/webpage prompt injection. A workflow wait, queue or cron is never a capability grant.

Bulk plans contain an exact target list and selection query hash, per-target revisions, planned diff, dry-run, canary size and owner approval. Durable per-item checkpoints, rate limits, fairness and cancellation isolate a failed node. Timeout after potential commit => UNCERTAIN and independent reconciliation, no blind replay. Partial success stays PARTIAL. Different plugin APIs and webhook effects are NOT globally ACID.

External events require signer identity, site audience, nonce/time window, signature validation, ordering and deduplication before any approved effect. No self-provisioning event that grants WordPress authority. Expired consent or drift invalidates pending triggered operation.
