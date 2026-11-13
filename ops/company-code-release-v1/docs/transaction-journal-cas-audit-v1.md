# Company Code Release — transaction safety and recovery audit

**Scope:** the existing `company.code.release` original executor and Original 313
host/readback interfaces. No new executor, app, database or bypass of the
first-party Broker authorization was introduced. This is a source PR only.

## Architecture and admission flow

`Plan (immutable source Git + tenant preimage)` → `Broker signed exact grant` →
`Original 306/312/313 runtime admission` → `root-private backup` →
`PHP source lint using the target PHP container` → `durable apply intent` →
`file-by-file just-in-time CAS + atomic replace` → `full postimage CAS` →
`WordPress boot/acceptance` → `Host receipt` → `independent Original 313 readback`.

The default **preflight** runs inside the exact bound target container using
`docker exec -i <container> php -l`, with the full candidate byte stream from
its signed Git commit. It rejects invalid PHP **before the first file write**.
It does not treat PHP syntax as proof of business behavior or include order.

For each file, the Executor revalidates the expected original SHA **and
signed original UID/GID/mode** immediately before `atomic_write`, even when a
previous file in the same release has already been applied. Existing tenant
files retain their original owner, group and mode through Apply and Rollback;
new files continue using the original code release policy mode. This prevents
a root executor from silently turning a `www-data`-owned Plugin file into a
root-owned file, or discarding Wayfatours' original group permission. Atomic write fsyncs the new file **and its parent
directory** to make the private journal durable before live code is changed.
A Rollback that removes a newly introduced file also fsyncs its parent
directory, so an acknowledged deletion is durable.

## Cross-plan conflict fencing

`overlapping_unresolved_transactions()` examines all root-private Apply and
Rollback intent records under the existing receipt lock, not just entries
belonging to the current plan SHA. A newer signed plan for the **same
company/environment/site UUID** cannot write any file intersecting an open
older transaction (including `ROLLBACK_UNVERIFIED` states). This prevents a
fresh plan from overwriting a half-written version after a worker crash.
A disjoint file set may continue without blocking the whole company. Exact
signed recovery receipts close historical intents, and foreign code remains
protected by JIT SHA/UID/GID/mode comparisons. This is a ledger fence, not a
new independent authorization system.

## Filesystem durability and ambiguous exceptions

The Executor records each target as a **write attempt before** calling
`atomic_write`. A rename can succeed and update live PHP before the caller
receives an `OSError` while fsyncing its parent directory. An exception from
`atomic_write` therefore does **not** imply zero mutations. The handler must
check the backup and attempt its exact signed-preimage rollback, and mark
`ROLLBACK_UNVERIFIED` when durable restoration cannot be proved. It can mark
`ABORTED_NO_MUTATION` only when no file write was even attempted. The
transaction journal is fsynced before the first attempt.

## Exact journal contracts

| Event | Durable evidence | Status / action |
|---|---|---|
| Before Apply | `<grant_nonce>.intent.json` and `<plan_sha>.backup.json` | `APPLY_STARTED` is not a success |
| Applied + validated | `<grant_nonce>.json` | `APPLIED_VERIFIED`, pending separate 313 certification |
| Initial target changed | terminal receipt | `ABORTED_NO_MUTATION` (never claims rollback) |
| Automatic rollback and readback succeed | terminal receipt | `ROLLED_BACK` |
| Concurrent writer prevents automatic rollback | terminal receipt | `ROLLBACK_UNVERIFIED`; new Applies held |
| Apply killed without terminal receipt | open Apply intent | New Apply held; new **signed rollback** may recover exact before/after bytes |
| Signed Rollback starts | `<rollback_nonce>.rollback-intent.json` | `ROLLBACK_STARTED` is not a success |
| Signed Rollback is killed mid-restore | open rollback intent | Require a **new signed rollback**; mixed-state checks only |
| Interrupted Apply recovered and confirmed | signed rollback receipt | `RECOVERY_ROLLBACK_VERIFIED`, linked to original apply nonce |
| Interrupted Rollback recovered and confirmed | signed rollback receipt | `ROLLBACK_VERIFIED`, lists recovered rollback nonces |
| Unexpected modified bytes | no success receipt | Denied, preserved external edit; manual reconciliation required |

The recovery lane requires a **separately signed Broker rollback grant** bound
to the exact original immutable plan/company/environment/site UUID. An old
nonce is never reusable. The original 313 finalizer does not infer success
from an intent, recovery receipt, or Host-only receipt. The independent
readback now **rejects non-APPLIED_VERIFIED Host results before checking
postimages**. The Host Handler differentiates `ABORTED_NO_MUTATION`,
`ROLLED_BACK`, and `ROLLBACK_UNVERIFIED` instead of labelling all errors as
successful rollback.

## Five-axis review

| Axis | Verified mitigation | Not yet certified |
|---|---|---|
| Correctness | Mixed-state signed recovery, exact data SHA, state-specific Host/313 receipts | full functional parity and cache-visible render |
| Readability | One canonical release path, typed journal states, no tenant-specific hardcoded filenames | centralized ledger archival/index when scale requires it |
| Architecture | Adds CAS/journal to the existing executor; original native modules remain owners | atomic swap for an entire 24-file PHP module version |
| Security | No extra release privileges; nonce one-time, root private receipts, rechecked original runtime identity, prewrite lint | atomic kernel compare-and-replace unavailable for ordinary filesystem |
| Performance | no WordPress calls in private-file hash scans; current receipt directory only 22 entries | linear journal scans need indexed retention at large history volumes |

## Company-aware compatibility

Tenant portfolio differences remain intentional. Wayfatours uses legacy MU
Option Bridge hooks (3) while Tours.dev uses the native plugin hook (1).
The code release does **not** auto-retire an MU file or replace Elementor
`_elementor_data`, JetEngine options, WPML translations, site CSS/cache policy,
child pricing, forms, booking and payment state. These are managed by their
respective native providers and require separate semantic readback. The
Commerce source `shared-product-core-loader.php` cannot be copied to Wayfa
alone: its source dependency on `runtime-phases/00-foundation.php` is absent
on the tenant and fails admission before any write.

## Test and rollout status

- Focused release tests: **102/102** locally, including a simulated worker kill
after the first file, a second kill during rollback, foreign mid-release
and mid-rollback edits, identity mismatch, preflight parser error, backup
integrity, and one-time nonce behavior.
- Original 313 targeted VPS tests: **62/62** (includes root-only admission,
Host state mapping, independent readback, 305/312 signed lifecycle fixtures).
- Unprivileged hermetic CI suites: Release 102, Original 313 contract 17,
independent readback 10, full cycle 5. The Root Admission tests remain
explicitly VPS-root-only, not skipped with a fake success.
- **Tenant file metadata:** live Wayfatours' native Experience module is
`www-data:www-data` mode `0664`. On an isolated temporary fixture (not live
WordPress) the root-owned executor's atomic update preserved exactly
UID `33`, GID `33` and mode `0664` after changing PHP bytes. Signed-plan
metadata CAS and signed rollback parity are independently unit-tested.
- Native PHP hook observer: **4/4** fixture states, only read-only observations
of the live WordPress runtime.
- Earlier broad `test_exact_code_plan_enrollment.py`: original four preimage
failures associated with a live `mad4b-tenant-sync` resource remain a separate
release gate; not fixed by changing its baseline or suppressing its failures.
- Original live Publisher returns `SOURCE_PUBLISHER_NOT_ENROLLED` and no new
site permissions, plugin writes, Elementor mutations, grants or 313 receipts
were generated by this review.

### Known non-goals / next controlled releases

1. **Semantic authority:** an independently certified `MU → Plugin` owner
transition with validated hooks, runtime options and WPML associations. The
current probe is observational only.
2. **True whole-release atomicity:** versioned plugin directory cutover and
OPcache/FPM/browser readback without changing site-owned code. Current
file-by-file writes can have a short intermediate visibility window to web
requests even with crash recovery and per-file CAS.
3. **Dependency coverage:** literal PHP `__DIR__` paths are checked; dynamic
includes, enqueued CSS/JS, AJAX-loaded Elementor widgets and external assets
need separate native runtime/browser acceptance.
4. **Large-scale operation:** root-private journal retention and indexing
before receipt history exceeds practical linear scan costs.
5. **Original enrollment:** review/merge PRs in order; reconcile against the
latest enrolled source; sign and roll out through the authorized original
Broker and independent 313, then certify Wayfatours separately.
