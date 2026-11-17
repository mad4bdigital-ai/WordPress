# Company Reconcile: no bypass of CPU / 313 authority and fresh host observation

## Incident

The original complete Company Reconcile admin asset inventory was refreshed on 2026-11-16 23:53 UTC. It remained **stale** past 11:40 UTC on Nov 17 even though many read-only child projections were current. The full service was safely skipped by the optional-work and original host CPU/313 guards: load1 ~6.23 on eight CPUs exceeded the pre-existing soft guard `max(2, cores*0.60)`, and WP-CLI / original native work sometimes required priority. Its 15-minute service only sampled the admission once, so a short busy instant discarded the whole interval.

## Permanent bounded admission (no relaxed thresholds)

`ops/company-reconcile-inventory-bounded-gate-v1/company-reconcile-inventory-bounded-gate.py` gives only **the existing read-only inventory service** up to 45 seconds to find a quiet window. On *each* try, it runs BOTH existing original, root-owned, non-world-writable `/usr/local/libexec/mad4b-optional-work-admission.py` and `/usr/local/libexec/mad4b-cpu-admission.py` (sample + admit), with all their **original thresholds unchanged**. It never creates credentials, grants, native 305/312/313 jobs or WordPress writes itself. A guard denial or missing trusted script returns skip and uses the existing timer. The serial WP-CLI site scheduler and all original native execution boundaries remain in place. No competing timer is created.

Install with the supplied `95-bounded-admission.conf` as a drop-in on the existing `mad4b-company-reconcile-inventory-refresh.service`: it replaces only the pair of original `ExecCondition`s with the single wrapper, so both original guard decisions are evaluated together in one bounded pass. No resource protection is relaxed. After deployment, a real 45-second systemd test returned **SKIPPED (8 attempts)** because the original optional guard did not admit a safe quiet window; this must be reported as **still stale**, never as success.

## Separate cheap host observation (read-only advisory, not a full inventory)

`company-reconcile-host-asset-observer.py` hashes the same 11 source-owned Admin Experience asset paths declared in the existing original company-appearance broker using AST literal inspection, not importing or executing WordPress. It only reads exact source and Wayfatours document roots and UUIDs declared by the existing root-owned enrollment policy. It rejects invalid policy, symlinked asset paths, duplicate/unknown target and overlarge files. Results are published atomically to private, root-owned `/opt/mad4b-company-provisioning/reports/company-reconcile-host-assets-current.json`, with all authority flags explicitly false. The existing 5-minute `mad4b-company-reconcile-projections.service` gets a cheap `ExecStartPost` that does **not** change its main result, grant, WP-CLI, or original 313 lane. It does not create another timer.

Direct readback 2026-11-17 confirmed **9/11 current source-target file mismatches**. This observation is fresh, but **it is not the full authenticated WP-CLI scan**, not an Original305 request manifest and not an Original313 proof; it never updates full inventory `refreshed_utc` or marks a job eligible. No automatic Production file transfer occurs.

## Remaining independent gates

Wayfatours' old Original305 job was `EXPIRED_UNCLAIMED`; no new job can reuse it. The new owner review is bound to the current exact original inventory digest. ETG Production and Tourism Almallah Production still lack exact enrolled UUIDs. The Wayfatours `master_admin` role has six forbidden capabilities relative to the source's current role policy; a *separate owner-governed, reducing-only role migration* must handle this. Full WordPress semantic reconciliation and browser/locale acceptance remain independent; the host SHA observer does not certify Elementor, WPML, JetEngine, pricing or booking.

## Regression evidence

Both new modules have 10 hermetic unit tests each (no external IO in the tests). Original CPU and optional-work gate selftests remain unchanged and must still pass. Source and Wayfatours homepage HTTP are both 200 after the host drop-ins. `/opt/mad4b-company-provisioning/reports/company-reconcile-bounded-admission-20261117` retains exact installed-file SHA256 receipts. No Original313 signed deployment or broad Company reconciliation release was claimed.
