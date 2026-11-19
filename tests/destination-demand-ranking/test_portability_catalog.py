"""Source-only company release catalog and MU map integration contract.

No grants, host mutation, or WordPress runtime operations are performed.
"""
from pathlib import Path
import importlib.util
import hashlib
import json
import sys

ROOT = Path(__file__).resolve().parents[2]
RELEASE = ROOT / "ops/company-code-release-v1"
sys.path.insert(0, str(RELEASE))
import company_code_release as release

CATALOG = RELEASE / "catalog/mad4b-release-components.json"
MAP_PHP = ROOT / "wp-content/plugins/mad4b-platform/config/ordered-mu-map.php"
MAP_CONTRACT = ROOT / "wp-content/plugins/mad4b-platform/config/ordered-mu-map-contract.json"
MU = ROOT / "wp-content/mu-plugins/mad4b-destination-demand-rank-v1.php"
BRIDGE = ROOT / "wp-content/plugins/mad4b-platform/modules/presentation/mad4b-destination-demand-rank-v1.php"


def main():
    catalog = json.loads(CATALOG.read_text())
    items = [x for x in catalog["components"] if x.get("id") == "mad4b-destination-demand-order"]
    assert len(items) == 1
    paths = release.registered_component_files(items[0])
    assert paths == ["wp-content/mu-plugins/mad4b-destination-demand-rank-v1.php"]
    assert MU.exists() and BRIDGE.exists()
    assert "final class MAD4B_Destination_Demand_Rank_V1" in MU.read_text()
    assert "require_once $source" in BRIDGE.read_text()
    assert "final class MAD4B_Destination_Demand_Rank_V1" not in BRIDGE.read_text()
    data = json.loads(MAP_CONTRACT.read_text())
    entries = data["ordered_entries"]
    assert data["expected_count"] == len(entries)
    assert len(set(x[0] for x in entries)) == len(entries)
    assert ["mad4b-destination-demand-rank-v1.php", "mad4b-platform/modules/presentation/mad4b-destination-demand-rank-v1.php"] in entries
    assert data["ordered_entries_sha256"] == hashlib.sha256(json.dumps(entries, separators=(",", ":"), ensure_ascii=False).encode()).hexdigest()
    print("PORTABILITY_PASS", "components", len(items), "map_entries", len(entries), "non_modifying", True)


if __name__ == "__main__":
    main()
