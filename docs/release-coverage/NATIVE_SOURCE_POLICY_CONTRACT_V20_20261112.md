# Native Source Publisher — enrollment document alignment V20

**2026-11-12. Source-only. No Production or Staging writes.**

The disabled policy template was still on `mad4b.native-source-publisher-policy.v1`
while the installed candidate's validator only admits `v2`. The draft
publisher enrollment proposal also referenced the retired Master Approval
attestation `v1`, rather than `v2` used in the signer verifier.

The existing `build_enrollment_candidate.py` now checks its disabled policy
and source-only enrollment proposal against the actual runtime's current
contract constants and the existing dynamic operation descriptor **before**
producing candidate manifests. It rejects changed approval ability/provider,
wrong site UUID/origin/allowlist, undeclared operation, falsely claimed
activation, and embedded public keys in the disabled source template. No
executable operation allowlist is introduced; the live Original306 first-party
Registry is still the only source of executable provider authority.

This is **not an authorization** to copy `policy-template.DISABLED.json` into
`/etc/mad4b`, to install any source provider, or to issue Broker/Master
attestations. The source builder remains an unsigned candidate generator.
The source authority has no enrolled root policy, no registered WordPress
`mad4b/native-source-release` write Ability, no Original305/312 fresh claim,
no Original313 independent signed receipt, and V19 Original313 guard re-pin
is awaiting separately governed publication.
