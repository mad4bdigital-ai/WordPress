#!/usr/bin/env python3
import argparse
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
PLUGIN = ROOT / "wp-content/plugins/mad4b-site-control-plane"
MANIFEST = PLUGIN / "config/repository-runtime-components.json"
ADAPTERS = PLUGIN / "includes/adapters/class-mad4b-scp-runtime-component-adapters.php"
LOADER = PLUGIN / "mad4b-site-control-plane.php"
PLUGIN_BOOT = PLUGIN / "includes/class-mad4b-scp-plugin.php"
UI = PLUGIN / "includes/class-mad4b-scp-runtime-components-admin-ui.php"


def fail(message):
    raise SystemExit(message)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", required=True)
    args = parser.parse_args()

    data = json.loads(MANIFEST.read_text(encoding="utf-8"))
    if data.get("contract") != "mad4b.repository-runtime-components.v1":
        fail("runtime component manifest contract mismatch")

    core = data.get("wordpress_core", {})
    for marker in core.get("required_markers", []):
        if not (ROOT / marker).is_file():
            fail(f"missing WordPress Core marker: {marker}")

    themes_dir = ROOT / "wp-content/themes"
    actual_themes = sorted(
        p.name for p in themes_dir.iterdir()
        if p.is_dir() and not p.name.startswith(".")
    )
    expected_themes = sorted(data.get("themes", {}).get("repository_themes", []))
    if actual_themes != expected_themes:
        fail(f"repository theme manifest drift: actual={actual_themes} expected={expected_themes}")

    mu_artifacts = data.get("mu_plugins", {}).get("repository_artifacts", [])
    if not mu_artifacts:
        fail("MU plugin repository artifacts are not declared")
    for artifact in mu_artifacts:
        if not (ROOT / artifact).is_file():
            fail(f"missing MU plugin repository artifact: {artifact}")

    recognized_dropins = set(data.get("drop_ins", {}).get("recognized_files", []))
    declared_dropins = sorted(data.get("drop_ins", {}).get("repository_artifacts", []))
    actual_dropins = sorted(
        f"wp-content/{p.name}" for p in (ROOT / "wp-content").iterdir()
        if p.is_file() and p.name in recognized_dropins
    )
    if actual_dropins != declared_dropins:
        fail(f"repository drop-in manifest drift: actual={actual_dropins} expected={declared_dropins}")

    specialized = data.get("specialized_themes", {})
    if specialized.get("astra", {}).get("adapter_id") != "astra-theme":
        fail("Astra specialized adapter mapping missing")
    if specialized.get("astra_child", {}).get("adapter_id") != "astra-child-theme":
        fail("Astra Child specialized adapter mapping missing")
    if specialized.get("astra_child", {}).get("parent_template") != "astra":
        fail("Astra Child parent contract is not exact")

    source = ADAPTERS.read_text(encoding="utf-8")
    required_classes = [
        "MAD4B_SCP_WordPress_Core_Adapter",
        "MAD4B_SCP_MU_Plugins_Adapter",
        "MAD4B_SCP_Drop_Ins_Adapter",
        "MAD4B_SCP_Themes_Adapter",
        "MAD4B_SCP_Astra_Theme_Adapter",
        "MAD4B_SCP_Astra_Child_Theme_Adapter",
        "MAD4B_SCP_Runtime_Components_Adapter",
    ]
    for cls in required_classes:
        if f"class {cls}" not in source:
            fail(f"missing runtime component adapter class: {cls}")

    abilities = [
        "wordpress-core/status",
        "mu-plugins/inventory",
        "drop-ins/inventory",
        "themes/inventory",
        "themes/get-theme",
        "astra-theme/status",
        "astra-child-theme/inventory",
        "runtime-components/inventory",
    ]
    for ability in abilities:
        if ability not in source:
            fail(f"missing runtime component ability: {ability}")

    for prohibited in ("MAD4B_SCP_Policy', 'can_admin", "MAD4B_SCP_Policy', 'can_content", "MAD4B_SCP_Policy', 'can_breakglass"):
        if prohibited in source:
            fail(f"runtime component adapters opened non-read authority: {prohibited}")
    if "'mutation_exposed' => false" not in source:
        fail("runtime component adapters do not explicitly deny mutation exposure")
    if "read_only_non_authorizing" not in source:
        fail("runtime component authority mode is not explicit")

    loader = LOADER.read_text(encoding="utf-8")
    if "class-mad4b-scp-runtime-component-adapters.php" not in loader:
        fail("runtime component adapters are not loaded by plugin bootstrap")
    if "class-mad4b-scp-runtime-components-admin-ui.php" not in loader:
        fail("runtime components admin UI is not loaded by plugin bootstrap")

    plugin_boot = PLUGIN_BOOT.read_text(encoding="utf-8")
    if "MAD4B_SCP_Runtime_Components_Admin_UI::boot();" not in plugin_boot:
        fail("runtime components admin UI is not booted")

    ui = UI.read_text(encoding="utf-8")
    for tab in ("WordPress Core", "Plugins", "MU Plugins", "Drop-ins", "Themes", "Astra / Child"):
        if tab not in ui:
            fail(f"runtime components UX tab missing: {tab}")
    if "read-only" not in ui.lower():
        fail("runtime components admin UI does not state read-only boundary")

    evidence = {
        "contract": "mad4b.repository-runtime-component-coverage-evidence.v1",
        "status": "passed",
        "wordpress_core_markers": core.get("required_markers", []),
        "repository_theme_count": len(actual_themes),
        "repository_themes": actual_themes,
        "mu_plugin_repository_artifacts": mu_artifacts,
        "repository_drop_ins": actual_dropins,
        "specialized_runtime_theme_adapters": ["astra-theme", "astra-child-theme"],
        "runtime_component_adapters": [
            "wordpress-core", "mu-plugins", "drop-ins", "themes",
            "astra-theme", "astra-child-theme", "runtime-components"
        ],
        "mutation_exposed": False,
        "normal_write_default": "deny",
    }
    Path(args.output).write_text(json.dumps(evidence, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps(evidence, sort_keys=True))


if __name__ == "__main__":
    main()
