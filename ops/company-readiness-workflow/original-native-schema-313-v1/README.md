# Original MAD4B Schema 313 — source-backed receipt recovery

Source-only snapshot of the deployed read-only JetEngine Schema 313 flow from 2026-11-06. These files are **not** credentials, grants, or certificates. The host still owns all authenticated runtime authority.

## Scope

- A signed owner-created 305 Job is claimed through the original broker and eligible 312 authority.
- The executor reads `jet_engine_meta_boxes` once and stores an immutable root-only host receipt.
- If central WordPress WP-CLI is saturated, `STAGING_WPCLI_BUSY` is classified separately from an authorization refusal.
- The original worker may choose **receipt-only delivery** for an already reserved Schema Job, while its original 312 grant and Job expiry are still valid.
- Receipt-only delivery never reruns the target Schema command and must not be used for plugin installation or other mutating operations.
- Independent 313 still requires its separate host-signed readback proof and normal original WordPress verification.
- An expired Job or missing original proof remains unverified; a previous host receipt is **not** a retrospective 313 certificate.

## Host deployment map

| Source path | Deployed component |
| --- | --- |
| `claimed-job-worker.py` | `/opt/mad4b-company-transport/native-313-independent/claimed-job-worker.py` |
| Remaining runtime modules | `/opt/mad4b-company-transport/native-313-independent/schema-readback-v1/` |

Prerequisites: the already enrolled original host transport, central WordPress broker APIs, target runtime resolver, and separate original verifier are required. Do not deploy source files by copying over active runtime without an exact backup, parity verification and supported release workflow.

## Verification

`python3 -m unittest discover -s . -p 'test_schema_receipt_resume.py' -v`

Unit tests are not proof of a new signed 313; runtime proof must be produced by a fresh, valid original owner-authorized Job. Production prices, tour post meta, Orders and Grants are out of scope.
