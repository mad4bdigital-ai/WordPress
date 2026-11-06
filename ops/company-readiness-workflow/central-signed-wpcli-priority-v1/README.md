# MAD4B Central — signed WP-CLI priority admission

Source-backed checkpoint for the existing Tours.dev Staging WP-CLI scheduler. No new broker, executor, WordPress Grant or Job is issued.

## Runtime behavior

1. **Original authority stays original:** 305/306/312/313 run through the enrolled signed broker and independent readback. This module does not authenticate or execute a Job.
2. A limited read-only query fetches up to 64 recent Central WordPress Broker Jobs directly from the enrolled MariaDB container. The database container is discovered by the enrolled Central PHP compose project and validated against Docker service, environment and running state. No WordPress Bootstrap or WP-CLI slot is consumed by this priority probe.
3. Only relevant, nonexpired original Jobs with exact host scope, WordPress operation, valid SHA and adequate remaining lifetime trigger priority. Unknown or expired Jobs do not hold the optional queue.
4. The existing **11 fixed, scheduled background service commands** check this signal before waiting for the serial lock and again after acquiring it. When priority is active they defer to their original systemd timers, rather than execute or hold the lock. A failed signal does not become execution authority.
5. The **original 313 worker** uses the existing timer and executor with `Nice=0` and `CPUWeight=200`. No timer was added; no worker or job was re-created.
6. The two-slot WP-CLI guard in the site's `wp-config.php` stays **unchanged**. This is admission fairness, not increased concurrency, a bypass, or a reduced security check.

## Deployment

The three Python source files map to `/opt/mad4b-company-provisioning/bin/`. The unit drop-in maps to the original service shown in `source-manifest.json`. Preserve existing before-images and validate live SHA-256 before rolling out again; never overwrite a newer source without reconciliation.

## Tests

```sh
python3 -m unittest discover -s /opt/mad4b-company-provisioning/bin -p test_wpcli_signed_priority.py -v
python3 /opt/mad4b-company-provisioning/bin/wpcli-site-scheduler.py --selftest
python3 /opt/mad4b-company-provisioning/bin/wpcli-signed-priority.py status
systemctl show mad4b-company-original-313-worker.service -p Nice -p CPUWeight
```

12/12 isolated unit regressions, scheduler selftest and read-only live signal passed on VPS. There was no still-eligible owner Job when the priority probe was deployed. A new owner-authenticated read-only Schema Job is needed to certify end-to-end throughput; historical/expired 313 receipts cannot be reused.

## Limitations

This prioritizes the 11 services already registered with the serial scheduler. Independent processes not using that scheduler may still consume slots. Do not claim a guaranteed empty slot or a signed 313 certificate without host and Central readback proof.

## Independent telemetry admission (after first deployment)

The original dispatch core triggers `mad4b-dispatch-telemetry.service`, which can run independent noncritical WP-CLI diagnostic commands outside the 11-service serial scheduler. The deployed systemd `ExecCondition` calls the same read-only bounded priority probe through its `optional-admit` subcommand. When an original owner-created time-eligible Job is present, it skips **starting** that optional telemetry service (exit code 1); it does not stop running work or affect the original Broker service. When no job is present, exit code 0 allows normal telemetry; if the priority DB probe fails, telemetry is also allowed (without granting any execution authority).

The two added isolated tests cover deferral, resumption and DB-probe failure. Current tests: **14/14 PASS**. This mitigates telemetry contention but does not preempt all independently invoked WP-CLI commands.

## Original Broker 306 capacity correction — 7 November 2026

The live original `vps-broker-poller.py` previously only deferred on `returncode == 75` plus `STAGING_WPCLI_BUSY`, while WordPress's Docker/CLI wrapper sometimes returns code `1` with the same exact **stderr** guard message. The corrected poller defers only when an unsuccessful original WordPress command has a stderr line beginning with `STAGING_WPCLI_BUSY:`. Other errors, such as `CENTRAL_HOST_REQUIRED` or `OWNER_PERMISSION_DENIED`, continue to fail closed. The signed 306 heartbeat, claim verification, job scopes and two-slot guard are unchanged.

`test_vps_broker_busy.py` provides 7/7 regressions covering both busy exit codes, real denials, fake stdout busy text, receipt typing, and missing JSON. A deferral **is not** a signed heartbeat or an Independent 313 certificate.

The exact host private signing key is never included in this PR.