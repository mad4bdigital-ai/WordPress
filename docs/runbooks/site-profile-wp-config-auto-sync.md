# Save Site Profile → WordPress environment automatically (Staging only)

## User-facing behavior
When an administrator saves an exact Site Profile with `environment=staging` and `environment_sync_mode=host_managed`, WordPress will now attempt a **local, guarded** `wp-config.php` rewrite as part of the save request. No additional MCP call or Host Runner approval is required for this *local admin-authorized* configuration step. It is intentionally not a general remote-file-write ability.

The existing Site Profile save requires a WordPress administrator, the WordPress nonce, a non-Production origin confirmation when WordPress still reports implicit Production, and persistence with audit finalization. Only **after** those checks and exact saved-profile readback, the config updater runs.

The updater:
- Resolves WordPress's exact `wp-config.php` in the root or supported parent; rejects symlinks and non-standard locations.
- Refuses an explicit environment from either `WP_ENVIRONMENT_TYPE` or the Host, including explicit Production, and never changes a Production-configured site.
- Refuses unexpected config syntax, existing environment declarations, missing local admin authority, missing Staging attestation, and unwritable files.
- Inserts only `define( 'WP_ENVIRONMENT_TYPE', 'staging' );` before the one standard `require_once ABSPATH . 'wp-settings.php';`.
- Uses an exclusive file lock, file SHA/inode precondition, same-directory atomic rename of a PHP-suffixed temporary file, mode preservation, post-write hash verification, optional append-only audit, and compensating rollback on verification failure. It does not place secret-bearing backups in `wp-content` or a public download.
- Returns `environment_sync.state` in AJAX and an explicit admin-post result, distinguishing successful file readback from the **new request** required before WordPress's environment API can confirm Staging.

If the Staging profile requests `profile_only`, automatic editing is disabled. An existing saved mode is not silently rewritten. The host's deployment binding is still mandatory for separately governed MCP Selected-HEAD plugin updates, but is not required merely to edit `wp-config.php` in an already authenticated and attested local administrator Save.

## Boundaries
This is not auto-promotion, auto-updating plugins, a generic wp-config editor, an unattended remote exploit surface, or an automatic Production downgrade. If PHP lacks ownership or write permission, an unsupported config is encountered, or a Host declares Production explicitly, the updater returns a blocking status and leaves `wp-config.php` unchanged. Host Runner remains available as the separately approved recovery path.
