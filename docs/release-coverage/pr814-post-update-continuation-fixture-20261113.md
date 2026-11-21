# PR #814 — Post Update continuation source-test reconciliation

Status: **CI-only fixture repair / Production release HOLD**.

The installed Control Plane self-update implementation requires a configured,
origin-enrolled and exact Site Profile `status()` (`authority_ready` and
non-empty `site_uuid`) before classifying any bootstrap candidate-drift
recovery. It also asks the existing dynamic Site Profile policy for
`environment_allowed`, and requires independent Post Update Continuation
readback and the prior write authority reconciliation plan. These controls
were already present in the current governed runtime source.

`self-update-continuation-policy-runtime.php` was an outdated isolated test
fixture: its `MAD4B_SCP_Site_Profile` double omitted `status()` entirely,
causing a fatal `Call to undefined method` before the existing safety
assertions on PHP 7.4, PHP 8.1 and PHP 8.3. The repair supplies the exact
method contracts in the test double, not by bypassing self-update policy.
The Site Profile environment policy test double continues to take the
caller-supplied environment list; it does not create a new static operation
allowlist. An added negative case confirms `authority_ready=false` blocks
bootstrap eligibility **before** any privileged self-update operation.

Local isolated acceptance (PHP 8.5, no network or WordPress instance):
- Post Update Continuation guards: PASS
- Self Update Continuation policy (including missing Site Profile denial): PASS
- Candidate-drift bootstrap authority quarantine: PASS
- Runtime Maintenance Lease: PASS
- Superseded restart checkpoint: PASS
- Runtime restart grace read-only: PASS
- Guided convergence, owner and bounded retry: PASS

The deployed Self Update, Company Release, Original305/306/312/313,
signatures, WordPress DB and production sites were **not modified**.
GitHub's independent PHP 7.4/8.1/8.3 checks must pass on the updated PR
commit. Original313 historical signed postimage mismatch remains a separate
unresolved release blocker; test coverage cannot issue Grants, signatures or
approval on behalf of the original authority.
