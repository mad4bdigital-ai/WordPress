# MAD4B Browser QA Concurrency — independent site safety

The original Critical CSS public-route worker and homepage owner already use
the host lock /run/lock/mad4b-native-lazy-browser-global.lock, but earlier
ad hoc Chromium runs and several additional monitors had their own separate
locks. As a result two heavyweight browser runs could overlap, increase host
load and PHP latency, even though both individual workers were correct.

On 17 November 2026, the exact issue was reproduced while an original
Wayfatours public CSS qualification was running. The manual Tour CSS combine
test also started Chromium without acquiring the shared global browser lock.
The original public worker completed its normal measurement and recorded a
visual/LCP hold. It was not hung and was not killed.

## Permanent host slot

Use qa-coordinator.sh for all new MAD4B manual browser runs and the four
Systemd services under systemd/. This wrapper uses the SAME global lock
as the existing Critical CSS engine, not a parallel queue. It uses fd199
because the original tour-story/presentation scripts use fd9 and other
Critical CSS scripts use fd8/9. A collision does not launch another browser.

Modes: private-mobile-wayfa, frontend-wayfa, presentation, tour-story,
manual. Existing original service commands and own inner locks are retained.
Normal one-shot services return a traceable DEFER status on lock contention
and are attempted again by their existing timers. Manual QA returns exit 75
on busy lock or high host load and requires an explicit timeout.

The original WordPress response performance, pricing, checkout, signed Woo
sessions, content and HTML cache rules were never altered to fix the QA load.
Public/production browser services are not restarted as part of this slot.

## Scoped stale cleanup

Systemd's mad4b-stale-playwright-qa-cleanup.timer enrolls the EXISTING
audited stale-playwright-qa-janitor-v1.py every 30 minutes, 60-minute age
threshold and a maximum 8 one-shot containers per run. The janitor never
uses generic Docker prune or blanket chrome/process kill. Its existing
source tests pass 17/17. Its first live run found zero abandoned eligible
one-shot containers or managed API child tasks. The production Playwright
service was preserved healthy.

## hPanel worker orphaned Chrome processes

A separate hPanel read-only browser worker had 104 long-lived defunct
Chrome/Crashpad descendants, all parented to Python PID1, with no active
browser child and no established client connection. These were historical
zombie processes, not current CPU load. Only the hPanel worker container was
recreated while idle with Docker `init: true` (Tini PID1) in its original
Compose file, preserving the /browser-profile mount, 127.0.0.1:13871 bind,
restart policy and security settings. The worker returned HTTP 404 for an
unknown health-probe path as expected, and global zombie count became zero.
The source Compose snapshot is in hpanel/. Do not restart this worker just
because its Docker container exists.

## Recovery and existing business alerts

The live original unit service files were saved at
/opt/mad4b-company-provisioning/ops/browser-qa-concurrency-v1/.
rollback-overrides.sh removes ONLY the four coordination drop-ins, never
changes Nginx, WordPress, application data or the original unit files.

The disabled cart still uses payment_only and the temporary Tour CSS
combiner QA MU-plugin 377 was removed. The experimental HTML was discarded
after recording non-sensitive hashes and original verified counts.

The distinct company-browser-cache-context audit had an ALERT on Wayfatours
checkout. It is a cache-policy test result, not a hung Playwright process;
do not reset/suppress that failure as part of CPU or zombie cleanup.

Permanent code exists in the original company-neutral ops source; Company
specific service identifiers are adapters, not engine branding.
