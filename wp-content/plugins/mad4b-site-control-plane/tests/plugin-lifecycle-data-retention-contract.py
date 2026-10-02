#!/usr/bin/env python3
from pathlib import Path

plugin = Path(__file__).resolve().parents[1]
main = plugin / "mad4b-site-control-plane.php"

if (plugin / "uninstall.php").exists():
    raise SystemExit("silent uninstall entrypoint is forbidden; governance deletion requires a separately reviewed explicit data-destruction decision")

php_files = list(plugin.rglob("*.php"))
for path in php_files:
    text = path.read_text(encoding="utf-8", errors="replace")
    if "register_uninstall_hook(" in text:
        raise SystemExit(f"uninstall hook is forbidden without an explicit reviewed data-destruction contract: {path.relative_to(plugin)}")

main_text = main.read_text(encoding="utf-8")
assert main_text.count("register_deactivation_hook(") == 1
assert "array( 'MAD4B_SCP_Catalog_Lifecycle', 'deactivate' )" in main_text
lifecycle = (plugin / "includes/class-mad4b-scp-catalog-lifecycle.php").read_text()
assert "wp_clear_scheduled_hook( 'mad4b_catalog_gc' )" in lifecycle
for forbidden in ("delete_option(", "delete_site_option(", "DROP TABLE", "DELETE FROM", "update_option("):
    assert forbidden not in lifecycle, f"deactivation must preserve data: {forbidden}"
assert "finally { restore_current_blog(); }" in lifecycle

print("mad4b.plugin-lifecycle-data-retention-contract.v1: PASS")

# Explicit retirement remains outside plugin boot and remote Ability surfaces.
purge = (plugin / 'tools/catalog-decommission.php').read_text()
assert 'tools/catalog-decommission.php' not in main_text
for required in ('CLI only', 'manage_options', 'is_super_admin', 'ms_is_switched', 'READER_GRACE_SECONDS', 'hash_equals', 'SHA2(option_value,256)', 'readback_verified', 'authority_data_deleted', 'LIMIT 10001'):
    assert required in purge, required
for forbidden in ('wp_register_ability', 'register_rest_route', 'DROP TABLE', 'delete_site_option(', 'delete_metadata('):
    assert forbidden not in purge, forbidden
print('mad4b.explicit-catalog-retirement-boundary.v1: PASS')
