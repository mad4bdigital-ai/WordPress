# QA31 — RC118 Staging-only source intake (non-executable, NOT a release)

This PR preserves **three SHA-pinned source candidates** read from the user-authorized ETG Commerce Woo Lab Staging Control Plane (`tours.dev.mad4b.com`) on 2026-10-29. They are stored under `ops/qa31/rc118-source-intake/source/`, **not** under the executable WordPress plugin `includes/` directory. No runtime hooks or REST endpoints are activated.

## Why this intake exists

- GitHub's current Control Plane main source reports `0.4.0-rc.104`; the deployed site runs `0.4.0-rc.118`. A direct master deployment would be a **downgrade**.
- The live RC118 manifest records **877** files; **876** still match the old signed manifest. The main PHP bootstrap has three additional `require_once` lines totaling **266 bytes**. Removing them **in memory** exactly reproduces the manifest main SHA256. The underlying three module files are **not present in that old manifest**, and were not present in GitHub master.
- This PR snapshots their exact source bytes for independent source review and avoids relying on a temporary VPS folder as the only source of truth.

## Unreviewed source candidates

| Candidate | SHA-256 |
| --- | --- |
| `class-mad4b-scp-company-tenant-migration.php` | `06447093ff420e76af963d07285922972ff29be17ca1cb5d2072738d9c41bbf9` |
| `class-mad4b-scp-migration-decision-catalog.php` | `2321244f43113cc998887c03ad923ab8dfc50510c33a73d435a4127afc787b8d` |
| `class-mad4b-scp-company-migration-decisions.php` | `408febe1cea99ce389f84e9035f91dce1930843ec2f535e9cbc9d987198e2488` |

These files include company roles, staging approvals, migration database operations and hook registrations. **Do not copy them from this candidate directory into a running plugin without a separately reviewed permission, source-integrity, operational and rollback analysis.** CI PHP lint cannot certify behavior or authorization.

## Review and release sequence

1. Independently review and classify the three modules' read/write paths and tenant-scoped identity/REST/admin permissions; check their versioned dependencies and no cross-company leakage.
2. Reconcile the existing reviewed GitHub `rc.104` source with the **exact source history** that produced runtime `rc.118`; resolve unrelated source differences and maintain QA30/QA31 W1, adapter and governance contracts. **Do not** treat the live runtime as source authority.
3. Review the three guarded loader lines, preserve them in the authoritative source **only after** review, and build a new immutable versioned Control Plane package including all three files with a **new manifest**. Never modify the historical manifest to force parity.
4. Run approved regression tests across WP-CLI, REST, WordPress roles, WooCommerce, WPML and company migration lifecycle in a disposable Staging candidate, backed by a restore plan.
5. Obtain separate immutable release, digest-bound provenance, exact-head owner attestation, Staging-bound one-time approval, backup and independent readback.
6. Independently certify DFSB RC15 under [Issue #565](https://github.com/mad4bdigital-ai/WordPress/issues/565), update registry only after approval, and keep Safe Boot held until that succeeds. Production remains NO-GO.

## Contracts

`tests/qa31_rc118_unexecuted_source_intake_contract.py` checks expected hashes, manifest semantics, absence of these files from executable plugin source, and that the candidate is **non-authorizing**. The dedicated workflow lints the candidates on PHP 8.3 and 8.5 without booting the WordPress runtime. This is **source intake only**, not RC118 authority restoration, Selected-HEAD update approval, immutable release publication or DFSB certification.
