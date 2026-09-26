#!/usr/bin/env python3
from pathlib import Path
import json

ROOT = Path(__file__).resolve().parents[1]
provider_src = (ROOT / "includes/class-mad4b-scp-provider-contracts.php").read_text("utf-8")
compat_src = (ROOT / "includes/class-mad4b-scp-provider-compatibility-certification.php").read_text("utf-8")
adapter_src = (ROOT / "includes/adapters/class-mad4b-scp-wp-import-export-adapter.php").read_text("utf-8")
inspection_src = (ROOT / "tests/wp-import-export-provider-package-inspection.py").read_text("utf-8")
certified = json.loads((ROOT / "config/certified-providers.json").read_text("utf-8"))
capabilities = json.loads((ROOT / "config/provider-capability-contracts.json").read_text("utf-8"))

provider = certified["providers"]["wp-import-export"]
assert provider["contract_mode"] == "composite_exact_packaged_components"
assert provider["certification_authority"] == "repository_composite_exact_archives"
components = provider["components"]
assert set(components) == {"import", "export"}

expected = {
    "import": {
        "version": "5.0.8",
        "archive": "wp-all-import-pro.zip",
        "sha": "eca6af2f5ecaa4119d051a0108045f61543d966e4765cfd219e49a60a7a50de3",
        "plugin_file": "wp-all-import-pro/wp-all-import-pro.php",
    },
    "export": {
        "version": "1.9.15",
        "archive": "wp-all-export-pro.zip",
        "sha": "28ceaee61f25523b86b559162e84c9535a482b00da21dcd4a6b21e7f709347b2",
        "plugin_file": "wp-all-export-pro/wp-all-export-pro.php",
    },
}
for key, exp in expected.items():
    item = components[key]
    assert item["version"] == exp["version"]
    assert item["archive"] == exp["archive"]
    assert item["archive_sha256"] == exp["sha"]
    assert item["plugin_file"] == exp["plugin_file"]
    assert len(item["critical_files"]) >= 3
    for digest in item["critical_files"].values():
        assert len(digest) == 64 and set(digest) <= set("0123456789abcdef")

for marker in (
    "composite_runtime_status",
    "composite_version_string",
    "installed_version_for_contract",
    "'component_drift'",
    "'component_count'",
    "'runtime_contract_ok'",
):
    assert marker in provider_src, marker

for marker in (
    "'component_artifacts'",
    "'components' => $component_artifacts",
    "runtime_artifact_fingerprint",
):
    assert marker in compat_src, marker

for marker in (
    "expected_critical = expected.get(\"critical_files\", {})",
    "missing_critical",
    "mismatched_critical",
    "certified_critical_files_verified",
    "additional_structural_files",
):
    assert marker in inspection_src, marker
assert 'expected.get("critical_files", {}) != normalized' not in inspection_src

catalog = capabilities["providers"]["wp-import-export"]["capabilities"]
assert catalog["jobs.read"]["risk"] == "read"
assert catalog["import.plan"]["risk"] == "read"
assert catalog["export.plan"]["risk"] == "read"
assert catalog["import.execute"]["risk"] == "high_risk_write"
assert catalog["export.execute"]["risk"] == "high_risk_write"
assert catalog["import.execute"]["reversible"] is True
assert catalog["import.execute"]["rollback_contract"] == "mad4b.rollback.wp-all-import-run.v1"
assert catalog["export.execute"]["reversible"] is False

# Cataloging future high-risk capabilities must not mount them.
assert "'content' => array(), 'admin' => array()" in adapter_src
assert "'mounted_execution_abilities'=>array()" in adapter_src
assert "wp-import-export/run-import" in adapter_src
assert "wp-import-export/run-export" in adapter_src

print("mad4b.wp-import-export-composite-provider-contract.v1: PASS")
