# Constitution

1. Fail closed on ambiguity, stale state, missing ownership, incomplete provider coverage or unverifiable recovery.
2. operation_key, operation_id and operation_binding_sha256 MUST NOT substitute for one another.
3. Approvals/recovery/acceptance/publication bind exact state and policy digests.
4. Human edits win over compensation.
5. Discovery, caches and provider manifests never create authority.
6. Persisted configuration contains no executable callbacks.
7. External/irreversible effects are explicit; no false atomicity.
8. Traces/simulation/diffs/visualizers are read-only and non-authorizing.
9. Loops, discovery, telemetry, TTL, recovery, retention and cleanup are bounded.
10. Recovery uses journal + current readback + ownership/CAS evidence.
11. Telemetry privacy/access/retention/tamper evidence are release gates.
12. Fault injection is absent from Production packages.
13. AI approval is default deny.
14. Staging certification never implies Production authorization.
