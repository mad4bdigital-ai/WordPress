# WordPress-native selected-HEAD update channel (PR #258)

## Scope
The new opt-in `wordpress_native_candidate_upload` channel uses only WordPress administration, the enrolled MAD4B Site Profile, a trusted source-bound GitHub ZIP/manifest, WordPress's existing plugin-upgrade machinery, and the existing governed authorization pipeline. It does **not** require an external Host Runner, SSH, `MAD4B_SCP_DEPLOYMENT_BINDING`, direct wp-config editing, Developer, or Breakglass.

It is **not** the default update channel. The existing fixed master Release channel and the earlier Host-governed Staging candidate channel are unchanged.

## Enable once in WordPress
Log into WordPress Staging as the **enrolled administrator** with `manage_options` and `update_plugins`. Open MAD4B → Site Profile, review exact Staging identity and choose **Enable WordPress-native candidate mode**. Enter `ENABLE WORDPRESS NATIVE STAGING CANDIDATES` and submit the nonce-protected WP admin form. The option is bound to the existing Site UUID, origin and profile digest. It is revoked on material Site Profile changes and can be disabled from the same page.

An explicit non-Staging WordPress environment always blocks this mode. An implicitly reported Production default may only be used when the enrolled Staging Site Profile carries the recorded non-production attestation. Production Site Profiles, drifted origins, pending audit, clones detected by Site Profile, and unsupported multisite cases remain denied.

**Threat boundary:** a WordPress-only option is not independent cryptographic proof of a physical host. If a complete database, application and origin are cloned together, WordPress alone cannot distinguish them. For stronger clone assurance use the existing Host-bound channel. Neither the new option nor a status read grants write rights.

## MCP use
- Read `mad4b/wordpress-native-update-status` (no arguments) to check the Staging opt-in, followed by `mad4b/control-plane-selected-head-plan` selecting a pull request, branch or exact commit.
- The source selector is bounded to the official repository and resolves the exact commit.
- The selected-HEAD plan chooses `update_channel=wordpress_native_candidate_upload` only when this WordPress option is enabled. Without it the existing Host path remains selected.
- An eligible plan still requires an **independently certified immutable GitHub candidate** and all existing **central governed Write approval**, **enrolled administrator OAuth authority step-up**, `update_plugins` capability, exact plan SHA and explicit `INSTALL SELECTED HEAD ON STAGING` confirmation.
- The apply uses the same existing `mad4b/control-plane-selected-head-apply`, which calls the approved Control Plane package verifier and WordPress plugin upgrader with maintenance lease, protected backup, activation preservation, post-write provenance readback, audit, post-update convergence and rollback. Do not use an arbitrary ZIP URL or bypass a failed Write Authority check.

## Important constraints
- Stale Write grants or a missing central approval **still block** remote update. They must be reconciled independently; WordPress-native means **Host-independent**, not permissionless.
- GitHub Actions publication must create an exact immutable candidate ZIP and manifest with a successful source-bound verdict and trusted publisher evidence. A v7 local ZIP alone is not a certified release.
- Existing control-plane code cannot use this new option until the updated plugin is installed once through an already authorized path. After that, subsequent candidates can be selected remotely.
- The site might require WordPress filesystem credentials or writable plugin directories, in which case the core upgrader may stop. That is a WordPress filesystem limitation, not a reason to use arbitrary shell execution.
- A source commit and a successful ZIP build are not Staging runtime certification; verify real installation, fresh MCP abilities, backup/readback and rollback.

## Acceptance
`php wp-content/plugins/mad4b-site-control-plane/tests/wordpress-native-candidate-policy-runtime.php` plus exact-source PHP lint, existing selected-head and staging updater fixtures, canonical packaging, and live Staging acceptance. No Production promotion or remote update has been performed by adding these files.
