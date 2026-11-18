# Exact native read-only identity admission, no implicit Production writes

The original admission observer now treats a first-party Central pinned-SSH
Site Profile as sufficient proof of **site identity only** when the independent
root-owned external doctor labels it
CENTRAL_SSH_NATIVE_IDENTITY_AND_OWNER_VERIFIED_WRITE_GRANT_MISSING.

Central's exact, active Production owner assignment is the authoritative
administrative grant. The old company-level delegation can point at Staging
and must not cause a second Production approval when a separately approved,
bound environment-specific Production record exists.

It validates the readback's exact Site UUID, pinned-SSH status, Production
assignment state, revision, UUID, operations authorization and Profile audit;
no Original305/312/313 grant is inferred. The output remains
ORIGINAL_REMOTE_305_GRANT_NOT_ISSUED and
native_writes_authorized=false until original signed authority exists.

This is a read-only readiness correction. It makes no Host policy change,
mints no credentials, signs no jobs, and does not modify tenant content.

Live first-party manual install on the Central VPS uses
/usr/local/libexec/mad4b-company-reconcile-production-admission.py, with
exact prior SHA256 preimage under
/opt/mad4b-company-provisioning/reports/original-production-admission-native-readback-20261117/.

Observed live after deployment: ETG, Tourism Almallah and All Royal Egypt all
report exact identity and Production owner verified; all still require
an Original remote 305 signed write grant. This does not establish universal
write enablement. Original signed Host-v2 authority and 312/313 certification
remain separate release blockers.
