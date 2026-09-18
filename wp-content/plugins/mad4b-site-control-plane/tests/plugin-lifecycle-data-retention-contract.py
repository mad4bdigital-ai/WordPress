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
if "register_deactivation_hook(" in main_text:
    raise SystemExit("plugin deactivation must remain non-destructive and hook-free unless a separately reviewed continuity contract is added")

print("mad4b.plugin-lifecycle-data-retention-contract.v1: PASS")
