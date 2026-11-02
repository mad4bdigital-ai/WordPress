# Original 305 Cross-SSH Host Proof — Staging

This adapter addresses a real deployment topology: site WordPress instances run
on individually pinned Hostinger SSH accounts, not local Docker containers.
A local `company-staging-php` container must **not** be invented as proof.

- The Original Central 305 persistent grant must already be enrolled.
- The separately authorized, root-owned host policy must bind the exact
  company, environment, Site UUID, canonical origin, registry target and
  WordPress root.
- A root-only, deployment-local
  `/opt/mad4b-company-transport/broker-v1/external-site-ssh-inventory.v1.json`
  supplies registered SSH endpoints. **Do not commit this inventory,
  private SSH keys, known-hosts material or site private P-256 keys.**
- Pinned known-hosts / root-owned SSH credentials run only fixed, read-only
  `wp eval` Site Profile and actor checks on the actual target.
- A signed Ed25519 proof is then submitted to the **original Central** host
  proof endpoint. The `target_container` legacy field carries an external
  runtime alias, not a Docker container or an executable transport.
- The only outcome is
  `HOST_PROOF_VERIFIED_NATIVE_EXECUTOR_PENDING`. The native executor must be
  certified separately, with a transport-aware implementation, per-operation
  admission, an independent receipt and rollback evidence.
- The PHP change only accepts mixed-case Hostinger DB identifiers in the
  signed original proof; it does not relax the company/site/host checks.
- The 15-minute timer revalidates **existing, still-admitted** Staging 305
  grants. It never enrolls or self-approves a grant, and cannot mutate
  Production.

Verified on All Royal Egypt Staging: actual Hostinger site and admin actor
match, Original 305 pair registered, signed host proof persisted in Central and
independently read back. Executor remains unapproved.

Egypt Tour Gates is correctly denied: its Staging host scope is missing from
the original root policy. The remote local
`mad4b_company_site_identity_v1` is also absent. Do not copy ARE's policy,
identity or keys to ETG. Reconcile through the original company issuer.

Tests: `python3 -m unittest -q test_cross_ssh`, PHP syntax check, one
original `plan`, and separate Central `mad4b company-native-host-proof status`
readback. No live execution grants from this package.
