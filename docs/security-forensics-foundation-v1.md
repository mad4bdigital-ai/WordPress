# MAD4B Security & Forensics — Foundation v1

Status: **source candidate / not installed / NOT CERTIFIED / no Production mutations**.

## Existing infrastructure reused

- `MAD4B_SCP_Audit::record()` / Audit v2: stores native WordPress lifecycle events in the existing append-only database chain. No second audit DB or shadow permissions.
- `MAD4B_SCP_Security_Diagnostics::can_read()`: exact enrolled Site Profile + existing read policy, reused by the eight new read/plan Abilities.
- `MAD4B_SCP_Site_Profile::site_uuid()`: source of tenant identity; an absent UUID is `unbound`, not a guessed blog ID.
- Existing Governed Write and Operation Journal remain the only future way to authorize response. Foundation v1 does **not** register `mad4b/security-quarantine-apply` or a new developer/breakglass capability.

## Implemented: bounded, explicit operations

- WordPress events: `activated_plugin`, `deactivated_plugin`, `deleted_plugin`, `upgrader_process_complete`, `switch_theme`, `set_user_role`, `user_register`, `wp_login`, allowlisted `updated_option`. Never store password, username, token, cookie, option value or untrusted forwarded IP.
- Attribution means WordPress authenticated request context **only**. Do not infer SSH actor or mark prior authorization from `get_current_user_id()`.
- File inventory: allowlisted `plugins`, `mu_plugins`, `uploads`, `upgrade`, `themes`, `root`; limited to 48 files, 192 entries, 8MiB, 0.25 seconds and directory depth 3; symlinks never followed. The sample does **not** compare to an owner-approved baseline or certify malware absence.
- Plugin Guardian: compare active WordPress plugin references with bounded on-disk directory entries. `unknown` is never `confirmed malicious`; custom/commercial plugins must have reviewed provenance.
- Security admin screen: no heavy scanner on initial page load, no mutations. Eight guarded read/plan Abilities: `security-status`, `security-integrity-scan`, `security-plugin-guardian`, `security-events-query`, `security-incident-report`, `security-baseline-plan`, `security-policy-plan`, `security-quarantine-plan`.
- `security-incident-report` currently returns an explicit **evidence-collection-required** state. It is not a live event correlator.
- All plan abilities are **non-authorizing**. Quarantine/apply, independent witnessing, full malware detection and rollback are **not** implemented by these abilities.

## Independent observer — separate package

The off-host Python Security Control Center v0.4 fixes cache HMAC, signed observation timestamps, HMAC-bound outbox state and per-occurrence event IDs, and adds optional Ed25519 receipt verification. It remains a separate artifact: **not bundled in this WordPress PR or deployed**, and still requires an independently managed machine and witness checkpoints. Native/External/Hybrid remains optional.

## Mandatory release sequence

1. Merge only after exact-head GitHub checks, owner attestation, and repository policy. No branch update authorizes live installation.
2. Staging read-only diagnostics: test site identity, exact build, resource budget, existing Audit v2 schema, plugin bootstrap compatibility and the eight guarded Abilities.
3. Explicit owner-reviewed baseline from known-clean sources, protected witness on a separate principal/device, and independent heartbeat/alert delivery verified.
4. Controlled harmless file changes and WordPress lifecycle actions on **All Royal Egypt Staging**; ensure actor_unknown for external filesystem changes. Do not assume Hostinger SSH/SFTP logs exist.
5. Attest evidence custody and retention separately. Signed transport ACK is **not** WORM.
6. Only then separately plan a governed, reversible Staging quarantine capability. Production write/quarantine/delete/restore forbidden until explicit per-action owner approval.

## Run local tests

```sh
php -l wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-security-forensics.php
php wp-content/plugins/mad4b-site-control-plane/tests/security-forensics-foundation-runtime.php
```

Run on PHP 7.4 and 8.3. These fixtures do not establish live WordPress/Hostinger access.
