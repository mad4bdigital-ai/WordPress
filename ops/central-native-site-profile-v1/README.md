# Central-first, host-verified Production Site Profile identity

The preferred mode is MAD4B Central VPS using existing root-owned Hostinger
SSH keys and exact ed25519 host pins, without logging into WordPress on
individual companies. The existing site Administrator UI is fallback only.

The new Site Profile method provision_from_central_host() is available only in
native WP-CLI with zero WordPress actor and exact host-intent declaration.
It is locked to the two verified Production origins and uses the ORIGINAL
WordPress CAS, audit and finalization mechanism, with no OAuth users and no
write authority.

Primary VPS tool:
 /usr/local/libexec/mad4b-central-native-site-profile-enroll.py

The source is ops/central-native-site-profile-v1/host_enroll.py.
The default mode enroll verifies the pinned SSH route, company, environment,
original source file hash and audit readiness. It then performs a native
identity-only save and checks again through a separate SSH connection.
Status and preflight modes are read-only; already enrolled exact profiles
return ALREADY_ENROLLED_NO_DUPLICATE_WRITE.

Central registration uses ops/central-native-site-profile-v1/central_register.py.
The Host checks a fresh exact readback against the original root-owned receipt,
then a distinct Central WP-CLI process saves the read-only binding under
mad4b_tourism_company_site_registry_v1 with its own append-only audit. Existing
identical registrations are idempotent. Conflicts and failing audit deny.

The Central binding includes preferred_identity_provisioner =
central_native_pinned_ssh, wordpress_admin_fallback_available = true,
native_write_authorized = false and identity_only = true.

Actual Production records:
 Egypt Tour Gates  0c7786ba-a025-4650-9ceb-32a51d74aaec
 Tourism Almallah b8dffda1-db99-4789-aa72-86bf63f557cc

Both now appear as IDENTITY_PROBE_CANDIDATE in Central and remain
write_authorized=false. No Original305/312/313, OAuth or owner certificate was
manufactured. New content changes still require their original signed scope.

On ETG the external notoptions cache briefly claimed there was no Site Profile
even though the canonical SQL row and append-only audit were present. The
bounded Host readback now checks the exact same-origin read-only DB row and
invalidates only the stale Options cache entries. It never re-enrolls a valid
profile or rotates the UUID during retry.

The remote PHP source has a preserved host-specific WPML delta. Only the exact
new method and audit code was patched on its existing preimage, not a whole
plugin overwrite. Original preimages and private readback receipts are under
/opt/mad4b-company-provisioning/reports/central-ssh-site-profile-20261117/.

Testing: the existing Site Profile lifecycle matrix passes 1825 assertions;
12 native admission and 22 Central registry assertions on PHP 7.4, 8.3, 8.5.
Python hermetic source checks prove strict host pin and no WP user impersonation.
Rollback of code is permitted by exact source hash; NEVER delete the saved
database Site Profiles or their immutable audit events.
