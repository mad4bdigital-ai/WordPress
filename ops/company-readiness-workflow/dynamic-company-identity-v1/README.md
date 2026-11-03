# MAD4B Dynamic Company Identity Readiness

Scope: **all originally registered companies, sites, and environments**. The Central
controller is not treated as a managed remote company site.

Components:
- `plan.py`: fail-closed read-only Central WordPress Site/Environment discovery,
  including live Staging signed-V4 preflight.
- `test_plan.py`: source-bound/no-grant test coverage.
- `systemd/*.service|*.timer`: separate, read-only 20-minute reconciliation.
- `owner-review.php`: original enrolled WordPress owner admin page on Central
  **Staging**. One user click invokes original REST transitions for the
  environment manager; original REST validates the browser nonce and CAS
  revision/binding at every step. It does not approve Production or issue 305.
- `site-plugin/`: universal site-local P-256 material provider and V4 signed
  read-only endpoint. This is a **candidate** until target-specific acceptance.
  Requires an originally enrolled Site Profile V2 and a matching original
  company binding. Never guesses company or UUID from hostname. Does not
  implicitly rotate keys or authorize remote writes.

Deployment lanes:
- Central Staging: read-only planner + original owner interface were installed
  and verified in the live VPS runtime. Systemd timer enabled.
- ETG Staging: existing exact-site V4 provider remains active and original
  Signed V4 proof was independently verified. The universal provider has **not**
  replaced it.
- Production: no automatic write, no enrollment without original Site Profile
  UUID and binding, no remote grants. Three sites have missing Central
  production identity enrollment; Wayfa requires an exact binding reconciliation.
- Original `305`: requires separate original host-scope policy, enrolled WordPress
  manager and independently signed original Broker receipt. Read-only V4 proof
  alone is never sufficient.

Validation: Python unit tests, PHP syntax and isolated provider context tests,
live Central `status` readback. Never commit private site P-256 or root SSH keys.

Operational caution: `owner-review.php` must only be exposed to the enrolled
owner's authenticated WP Admin session. The page is intentionally not usable
as unattended WP-CLI owner consent.
