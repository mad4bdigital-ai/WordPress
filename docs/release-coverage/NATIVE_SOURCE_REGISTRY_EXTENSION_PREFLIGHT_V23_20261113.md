# V23 — Original Native Registry Extension Preflight (read-only)

Source-only, 2026-11-13. No first-party enrollment, private key access,
Master Admin ticket, Broker grant, WordPress mutation, or signed Original313
receipt.

The existing `dynamic-operation-registry-v1/first_party_install.py` is an
**initial installer** for the first `company.code.release` Provider. It
hard-codes a Wayfatours Production plan and refuses an already enrolled index.
Calling `--install` against the active registry is not a valid source-release
upgrade. The Central `mad4b/native-source-release` Ability is also not
registered, so Approval Plan cannot create a valid approval ticket for it.

The new `first_party_registry_extension_preflight.py` is a pure preflight. It
loads the original installed first-party Registry and its SHA-pinned existing
manifests, builds source candidates from **all declarative operation files**,
rechecks component SHA-256, detects current-file CAS collisions, and projects
one additive index digest preserving every existing provider and trust field.
No static operation list or second executor is introduced. Only digests and
blockers are returned, never a private key or installable modified index.

## Additional gaps found

The current source manifest for `source.code.release` does not declare the
Original306 `preclaim` hook or the Original313 `finalizer` hook. The Worker
would reject Preclaim even if an unsigned Registry index entry were added.
The installed Original306 worker also does not match the signed-association
support already tracked in Git, and the root-owned independent association
issuer anchor has not been enrolled. These require first-party signed
provider/worker/finalizer integration, not direct file copying.

The preflight intentionally reports HOLD until a certified publisher can
review exact source component bytes, index CAS, WordPress target, approval
issuer and worker/finalizer compatibility. Its report is not itself a release
approval, and the 7/8 live Original328 guard matrix remains unchanged.
