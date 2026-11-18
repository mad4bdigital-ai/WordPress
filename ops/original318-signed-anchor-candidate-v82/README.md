# Original318 v82 — governed release candidate only

This directory preserves the exact reviewed Original Host Authority v82 PHP
candidate and the original NOT-AUTHORIZATION release proposal, independently
of the obsolete temporary development worktree.

The original installed WordPress file remains SHA256
ecfefbee1a60f52431c4688f7b5858ea1df3633812483c9794f36515c418af55.

The candidate SHA256 is
56c78c0036c1e690069a2abbbbe4e84f8745bf30fec4dcc9c875835e52e442be.

Candidate behavior (not operational issuance):
- Verify an independently signed ACTIVE Host Authority Anchor v2 against the
  existing Broker Ed25519 public key and original exact Site Profile.
- Reject unsigned, stale, revoked, wrong-company, wrong-environment or
  wrong-revision anchor and identity readbacks.
- Observe a separately signed REVOKED Anchor, retaining explicit
  independent_highwater_verified=false and original313_certified=false until
  an independently signed Original313 current-highwater receipt exists.
- Preserve the legacy v1 original exact-host staging canary.
- Never create its own Anchor, production grants, or a simulated site write.

Hermetic tests include original Ed25519 PHP contract tests with ephemeral
keys and WordPress stubs only, 16 signed-identity/Anchor tests and 10 signed
revocation observations. Tested with PHP 7.4, 8.3 and 8.5.

This is NOT a live deployment and NOT a signed approval. The original
Native Source Publisher is currently enrolled on tours-central:staging only,
without this Original318 target in its approved release allowlist. Before a
real deployment, the publisher must accept separately verified Master Admin
and Broker release approvals, exact target and source SHA, preimage, backup,
independent readback and rollback. Never use SSH file replacement to bypass
that release boundary.

The Host Authority issue/revoke operation providers also remain deliberately
fail-closed until a separately issued, approved Original312 claimed job and
an independent revision/highwater ledger exist. Source availability alone
must never imply Original305/328/312/313 write readiness.

Standalone tests:
python3 -B -m unittest discover -s ops/original318-signed-anchor-candidate-v82 -p 'test_*.py' -v
