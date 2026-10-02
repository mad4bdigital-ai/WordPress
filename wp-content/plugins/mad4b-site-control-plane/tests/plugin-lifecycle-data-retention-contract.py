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
