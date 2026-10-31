# MAD4B Native WordPress Deployment Policy

**Target policy:** Package certification is **not a mandatory prerequisite** for ordinary WordPress installation, replacement, or update. Trust is established at execution time by the native provider, exact artifact and target checks. Existing certification catalogs remain advisory/compatibility only. This is a staged migration; do not misrepresent incomplete routes as ready.

## 1. Source and execution policy

- Installed plugins with a server-resolved WordPress update offer: use `plugin-remote-update-plan` and exact `plugin-remote-update-apply`; do not require `certified-providers.json`. This is the **implemented** first slice in PR #667.
- WordPress.org new installations: prefer the native official Plugin API and `Plugin_Upgrader`; restrict archive origin to `downloads.wordpress.org`, validate plugin identity, slug and version, and require `install_plugins`. A generic bounded installer for this route **is not implemented yet**.
- Private, company-owned plugins: trust exact registered GitHub repository+commit and bounded source/manifest checks (not an extra package approval), use a governed installer and exact readback. This private-source route **is not implemented yet**.
- MU plugins: use a different site-owned staging release routine, with path allowlisting, exact source identity, backup, PHP lint, independent postcondition verification and rollback. A regular plugin ZIP installer **cannot deploy MU plugins**. This route **is not implemented yet**.
- A package hash is an integrity measurement, not an authorization certificate; always compute and compare it. Preserve WordPress permission checks, source allowlists, path traversal defenses, the installed plugin protection list, owner-revocable deployment authority, and auditing.
- Never treat an earlier security denial of a specific file as approved merely because the package certification policy changed. The previously denied Identity Reviews `284` module is excluded until separately admitted.

## 2. Staging-first Production promotion

- Test the exact same code and configuration *on Staging first*. Record the site, source commit, source/installed content hash, approved operation, PHP/runtime checks, current plugin activation, smoke test, rollback test, and independent post-install readback.
- Production becomes eligible **only after** the Staging result is PASS for the exact version and source, the same content identity is confirmed, and the authorized Production Batch is active. A CI status alone is insufficient.
- Production is **not automatically deployed** by a Staging PASS. A separate Production-targeted native execution is required. Never inherit Staging privileges or use a generic root shell as the authorization substitute.
- At present `MAD4B_SCP_Production_Automation::save_batch()` requires `test_attestation` with a trusted signed test certificate. Replace this mandatory certificate precondition with independently verified, bound Staging readback evidence **before** claiming the new policy is active in Production. The replacement must not accept client-asserted PASS or unverifiable JSON.
- Until a trustworthy Staging evidence verifier exists and is tested, Production promotion stays blocked. Do not delete the old guard first.

## 3. Migration acceptance

| Operation | New policy | Implementation in this PR |
| --- | --- | --- |
| Installed plugin native WordPress update | No package certificate, validated offer, exact plan, rollback | Implemented existing update route |
| Installed plugin `replace` with explicit `plugin_file` and no provider ID | Same native update route, not a package-plan gate | Implemented |
| New WordPress.org plugin | Official download only, standard WP permissions | Planned, not executable here |
| Private repository/plugin release | Registered source identity + immutable manifest, not package certificate | Planned, not executable here |
| MU plugin release | Separate Staging release with rollback | Planned, not executable here |
| Production promotion | Same release verified on Staging and independently authorized Production Batch | Planned: current signed test-certificate guard remains |

## 4. Tests and rollback

The hermetic test at `tests/plugin-transaction-native-cert-optional.php` verifies the new default replacement route, unsafe-offer denial, missing-plugin denial, provider-independent planning and no automatic Production permissions. Existing `mad4b/plugin-remote-update-apply` owns backup and rollback. To roll back the **routing** change, revert the transaction planner commit; this does not undo unrelated site changes.

**Status:** source-only Draft. This policy document does not amend Production authority and does not grant install privileges.
