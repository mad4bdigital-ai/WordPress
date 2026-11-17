# MAD4B Company Reconcile Production Admission (live-first, 2026-11-17)

This root-only **read-only** cross-host status reconciles real external Hostinger
pinned-SSH WordPress identity readback with the original source-owned Admin
Experience file inventory and installed first-party original Native operation
registry. It **cannot** enroll WordPress site profiles, impersonate an owner,
create a new Original305 Job/306 Claim, mint an independent 312 proof, or issue
an Original313 signed release.

## Deployment

- Entry: `company-reconcile-production-admission.py`, executed as root after
  original evidence snapshots have been published.
- Live location: `/usr/local/libexec/mad4b-company-reconcile-production-admission.py`
- Advisory output: `/opt/mad4b-company-provisioning/reports/company-reconcile-production-admission-current.json`
- Existing `mad4b-company-reconcile-projections.service` receives a
  nonfatal `ExecStartPost` drop-in. The original independent enrollment doctor
  retains its schedule; no additional site WP-CLI scan or timer is created.
- All input contracts, root ownership, digest-pinned first-party provider
  manifests, freshness windows and exact company/environment/origin/UUID are
  checked fail closed. Successful host SSH identity is not a signed Host 328
  or Original312 proof.

## 2026-11-17 live evidence and next transitions

The pinned, verified remote readbacks from both Production WordPress instances
report `site_profile_plugin_active=true`, `site_profile_audit_ready=true`,
but `site_profile_uuid=null`, `company_site_uuid=null`. The original Central
Registry also has no exact Production UUID or authorized original Grant. Thus:

| Target | State | Legitimate next actor action |
| --- | --- | --- |
| `egypt-tour-gates:production` | `OWNER_SITE_PROFILE_SAVE_REQUIRED` | Login as the actual Production WordPress administrator at `https://egypttourgates.com/wp-admin/admin.php?page=mad4b-control-plane-site-profile`, save the Production Site Profile via its real nonce and audit, then register the new UUID through the original Central owner delegation process. |
| `tourism-almallahgroup:production` | `OWNER_SITE_PROFILE_SAVE_REQUIRED` | The identical owner-governed process on `https://tourism.almallahgroup-mg.com/wp-admin/admin.php?page=mad4b-control-plane-site-profile` |

**Never copy either Staging UUID** and do not create a fake enrollment using a
root-run `wp option update` or a synthetic WordPress Administrator session. The
original `MAD4B_SCP_Site_Profile::save_current_site` validates current owner
capability, WordPress environment, origin, reserved UUID native attestation,
Production write confirmation, append-only audit and transactional finalization.
After legitimate owner save, exact source binding, Original305 remote host pair,
producer and independently signed Original312/313 still need to pass.

Wayfatours currently has 11/11 exact file source/target SHA matches. The scope
was repaired manually beforehand, with separately retained rollback, not via
Original313. In this state, any new Native job is unnecessary. The signed
`company.code.release` provider exists in the original Native registry, but it
cannot stand in for `company.appearance.reconcile`: the current file set is
not in the Code Release catalog, and `mad4b-commerce` is not a valid scoped
Portable Plugin profile. Do not declare the entire financial/plugin surface
portable merely to get files into the deployment catalog.

## Tests

17 hermetic cases cover exact identity, lack of a forged Original312/313
certificate, stale readback, all registered company independence, missing
signed operation provider, signed-grant separation, duplicate paths/identities,
fail-closed content hashes, and no writes. Run
`python3 -m unittest discover -s ops/company-reconcile-production-admission-v1 -p 'test_*.py'`.

## Direct legacy Broker Apply removed at live execution source

The older host-local `company-system-appearance-broker-reconcile.py` contained
an `--mode apply` that only checked a *generic current native resolver plan*
and the operator-supplied `--allow-production` flag. That is **not** proof of
a specific signed Original305 Job/306 Claim/312 authority/313 result. On the
live VPS this unsafe direct write path was closed **before reading any site**,
then its old file-copy block (including rollback-only-in-process) was deleted.
Existing `--mode plan` and `--mode verify` are retained for source observation.
Any direct `--mode apply` is a deterministic **HOLD** with no mutation. The
future signed native operation must be implemented as a separately registered,
content-pinned provider through Original306/312/313, not by relaxing this
legacy script or borrowing an unrelated code-release grant.

The exact host source is committed here as
`company-system-appearance-broker-reconcile.py`; SHA256 equals the installed
`/opt/mad4b-company-provisioning/bin/company-system-appearance-broker-reconcile.py`.
`test_legacy_broker_signed_only.py` runs actual isolated CLI invocations
with a stub of an otherwise host-only import and checks that Production and
Staging apply are denied before any site access, source assets remain bounded
and plan/verify remain present. The manually reconciled 11 Wayfatours assets
stay unchanged and still yield a source/target SHA match.

### Exact production owner-principal readback and native registry pin

A matched Site UUID does not mean the intended Production administrator and
manager are the same approved principal. The observer now requires all four
separate first-party evidence bits in Production: active Central owner
delegation, **exact target UUID** (not the Staging assignment UUID), exact
administrator matching the authenticated remote target, and environment owner
operations authorization. This correctly classifies All Royal Egypt Production
as `ORIGINAL_OWNER_DELEGATION_NOT_VERIFIED`, even though its Production Site
Profile and Production UUID are present and equal on both sides. No previous
Staging user delegation is silently reused.

The root-installed original dynamic operation registry is now loaded through
its own `registry.Registry` admission validator: **every** manifest, provider
handler, verifier, evidence schema, and declared dependency is content-pinned
and root protected before any operation can be described as registered. A mere
manifest entry is not a valid enrolled signed executor. If validation fails the
report fails closed; neither the original Host Worker nor site contents are
changed. Combined tests: **26/26** hermetic admission plus legacy Broker tests.
