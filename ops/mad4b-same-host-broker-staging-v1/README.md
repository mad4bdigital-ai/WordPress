# MAD4B Same-Host signed WP-CLI broker candidate

Source for **Staging-only** Same-Host registration at `tours.dev.mad4b.com`; not a general VPS execution permission or Production release. Preserve the site's other, independently deployed MU functionality when reconciling this source.

## Scope

- `306` classifies company requests submitted through the **existing owner-authenticated 305 intake** as `signed_vps_wpcli` when operation is `company.remote.wpcli.full`, without executing them.
- `312` admits a Company on the *same physical* broker VPS only if it is a **registered separate company** (not `tours-central`), Staging environment, original `wpcli.argv.json.v1` request, exact owner-enrolled `wpcli.full` transport, exact grant, original per-correlation 305 claim, signed 306 claimed Job, original Company Manager site and delegation.
- The local host's `maintenance.shell` and `tours-central` execution identity remain separate. Unknown `job_type`, production-company requests, missing claim/transport/owner scope, or host-vs-WordPress target mismatch fail closed.
- `313`'s signed WP-CLI receipt **remains PENDING_INDEPENDENT_NATIVE_READBACK**, not completed work. Do not mark it Verified or execute from the 312 preflight alone.
- Host SSH authorization, native executor certification, target-container binding, source command digest, rollback and independently verified readback are still required.

## Tests

```sh
php -l ops/mad4b-same-host-broker-staging-v1/306-mad4b-central-vps-broker.php
php -l ops/mad4b-same-host-broker-staging-v1/312-mad4b-broker-exact-ssh-authority.php
php ops/mad4b-same-host-broker-staging-v1/tests/same_host_306_hermetic.php
php ops/mad4b-same-host-broker-staging-v1/tests/same_host_312_hermetic.php
```

## Company VPS client boundary

`/opt/mad4b-company-provisioning/governed-execution/company-vps-client.py` currently serves the **Wayfa production provisioning documentation** policy (`DOCUMENTATION_ONLY_NOT_DEPLOYED`). Its `apply` and `rollback` intentionally return `DENIED_MUTATION_NOT_IMPLEMENTED`. This source patch does **not** alter that client's authorization, change the Wayfa production policy, grant NHI, or turn documentation into executable rights. Proper Company Apply requires a separate, reviewable native handler accepting a signed authorized Job and producing an independent receipt before enabling the client.

## Local Staging evidence

Applied via the original root-owned VPS Native Operator for `tours-central:staging`: `broker306312-staging-samehost-exact-20261101` with `state=VERIFIED`, `readback_matches_expected=true`, two-file backup + rollback. Original Queue: 325 tasks, 129 Batches, task identity SHA256 `ef96a0321583894b1d1741399222d79ecb3dae5612e7e5fff1e4e2e5059e65e4`; no queue, grant or Production mutation. Real live Company Same-Host Apply remains **not evidenced** because the currently registered company target on this VPS is Wayfa **Production**, not an enrolled Staging company.
