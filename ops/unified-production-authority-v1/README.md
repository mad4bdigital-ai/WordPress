# Production authority: reuse approved identity, retain signed execution

The read-only Host observer now reuses an existing exact Native Site Profile
that Central previously enrolled using pinned SSH. It no longer demands a
second local WordPress identity option simply to recognize the already
verified read-only site.

The new state is CENTRAL_SSH_NATIVE_IDENTITY_AND_OWNER_VERIFIED_WRITE_GRANT_MISSING.
The next action is ORIGINAL_SIGNED_JOB_SCOPED_305_312_313_REQUIRED.

This requires Central's native identity record matching the exact Site Profile
UUID and origin; an active, bound Production owner approval with operations
rights; independently pinned remote WordPress readback with matching Production
environment, home/site URL, audit and persisted Site Profile; and no governed
write permission. All failing checks remain fail-closed.

This is NOT a new 305 Grant, Host policy rotation, Original313 certificate,
signed authorization or Production content write. Original execution gates
remain unchanged. Production changes still need the original operation-scoped
signature, authorization, independent readback and rollback.

Installed manually first on live VPS under
/opt/mad4b-company-provisioning/bin/company-native-external-enrollment-readback.py,
with exact preimage and SHA256 receipt under
/opt/mad4b-company-provisioning/reports/unified-production-authority-20261117/.

ETG and Tourism Almallah now report original signed WRITE Grant missing instead
of requesting a duplicate read-only Site Profile. All Royal Egypt continues
to report its genuine missing Grant. No Host policy, grant, permission, WordPress
user or Production content changed.
