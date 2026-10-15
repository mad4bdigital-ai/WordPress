# Staging WordPress Plugin Upload — nginx 504 Triage and Safe Recovery

**Observed:** 2026-10-12 operator browser screenshot of `504 Gateway Time-out` from nginx after attempting to upload/install `mad4b-site-control-plane-0.4.0-rc.96` from PR #258, with browser landing on `https://staging.egypttourgates.com/wp-admin/plugin-install.php`. The operator's offline ZIP integrity and PHP 8.3 lint were PASS on source `badcbb5039f221377a82b0014589204eba7fd976`, SHA-256 `180c18c8870b695e453fff542def2efb71decb8d92a497f79fa9b5e89594c32e`, but that receipt explicitly says **Staging was not deployed/certified**.

**Not proven:** A 504 alone does not reveal whether the originating timeout occurred on a POST plugin upload/install or on the redirected GET plugin installer screen, nor which plugin, database or PHP worker caused it. ETG Staging MCP diagnostics were unreachable during this review. Do not claim the site update succeeded, rollback succeeded, or that the exact bottleneck is established without host request logs.

## Source-level bottlenecks found

1. The Control Plane entrypoint included **378** individual module files and approximately **98** direct boot calls during an ordinary full bootstrap. Its prior early zero-touch guard applied to unrelated REST/AJAX, not native wp-admin plugin-management GET/HEAD pages. Consequently simply opening `plugin-install.php` could spend CPU/memory loading unrelated Control Plane code. This is a *credible design hotspot*, not yet a measured causal result.
2. `MAD4B_SCP_Upgrade_Continuity::pre_boot()` could inspect and restore persisted identity for non-passive wp-admin requests during package transitions.
3. `MAD4B_SCP_Schema_Lifecycle::boot()` wires `maybe_reconcile_admin_lifecycle()` to `admin_init`; that path could invoke `MAD4B_SCP_Schema::install_or_upgrade()`, whose migration implementation contains `dbDelta()`, on an eligible page rather than only a dedicated worker. A 504 caused by lock contention, slow database I/O or worker exhaustion remains possible.
4. **Actual upload/update/activation** remains expensive: `MAD4B_SCP_Plugin::activate()` explicitly performs schema install/upgrade, audit-head setup, execution receipt crypto and Skill seed initialization. Do not weaken or skip these security prerequisites without a separate native activation migration strategy and rollback evidence.

## Exact source mitigations in this PR

- Entry point: before the 378-module load, **read-only** core wp-admin pages `plugin-install.php`, `plugins.php` and `update-core.php` short-circuit after minimal catalog deactivation registration. Only true `GET/HEAD` requests with **no explicit action** qualify. POST, direct activation/deactivation/update actions, AJAX, WP-CLI and other routes continue through existing full code.
- Schema lifecycle: `admin_init` GET/HEAD returns before repair. Scheduled worker / explicit write/CLI paths retain their original guards and maintenance lease.
- G9 protected root bootstrap file SHA-256/size repinned to current exact content.
- `tests/native-plugin-management-zero-touch-runtime.php` added to the native PHP 7.4/8.3 preflight to assert zero-touch GET page, existing native deactivation hook, preserved activation declaration and passive schema non-reconciliation. No database or production changes.

**Boundaries:** These source patches can shorten the redirected plugin-install GET, but do **not** guarantee that a POST upload/activation completes before nginx's configured upstream timeout. Do not raise timeouts blindly or remove all plugin hooks to hide a slow blocking operation.

## Immediate no-change forensic checks, exact Staging only

1. Match the browser's **Request URL + HTTP method + timestamp** in Network tools. Distinguish `POST /wp-admin/update.php?action=upload-plugin` (or an AJAX upgrade path) from `GET /wp-admin/plugin-install.php`. Save response time, request ID and status; do not share cookies/nonces.
2. Check nginx error/access logs for `upstream timed out` matching that timestamp and upstream status/time. In PHP-FPM logs, inspect worker max-children, termination, slowlog stack, request duration and memory exhaustion. Inspect host resource saturation (RAM, CPU, IO, database threads/locks), MySQL/MariaDB slow log and `wp-content/debug.log` if configured.
3. From a **verified Staging shell**, perform read-only inventory without loading third-party plugins:
   `wp --skip-plugins --skip-themes plugin status mad4b-site-control-plane`
   and `wp --skip-plugins --skip-themes plugin list --fields=name,status,version,update`.
   A 504 can occur **after files are copied**. Compare installed `MAD4B-BUILD-PROVENANCE.json`, PHP plugin header and archive manifest before retrying.
4. If host access is currently unavailable, use the host console's PHP logs and file manager to determine whether the plugin folder was replaced, but **do not delete or re-upload repeatedly**. Verify the exact Staging origin/tenant before any recovery.
5. If a bad active plugin blocks WP admin, make a backup then use an authorized host-level **Staging-only** rollback procedure with a previously verified plugin artifact; avoid raw SQL or blind directory deletion.

## Acceptance after the new exact-head build

- Native isolated PHP 7.4/8.3 fixture, full-tree PHP lint, G9 delivery contract, exact source/file fingerprint and package hash
- A staging-host request comparison on clean plugin-install GET (server processing time, DB queries, PHP peak RAM) and a controlled POST install/update with no 504, plus activation readback
- Plugin state/installed provenance match, Site Profile and candidate binding, WordPress MCP discovery, rollback and audit, no unintended provider/production mutations
- Staging must be backed up and authorized separately; **offline package PASS is not site deployment certification**
