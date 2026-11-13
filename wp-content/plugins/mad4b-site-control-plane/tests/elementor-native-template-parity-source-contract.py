#!/usr/bin/env python3
"""Static seed + adapter contract checks; no WordPress bootstrap or writes."""
import json
import re
from pathlib import Path

base = Path(__file__).resolve().parents[1]
contract = "mad4b.elementor-native-template-parity.v1"
adapter = (base / "includes/adapters/class-mad4b-scp-elementor-adapter.php").read_text()
policy = (base / "includes/class-mad4b-scp-elementor-native-template-parity.php").read_text()
seeder = (base / "includes/class-mad4b-scp-skill-seeder.php").read_text()
manifest = json.loads((base / "config/skill-seed-manifest.json").read_text())
assert contract in policy
assert "MAD4B_SCP_Elementor_Native_Template_Parity::verify" in adapter
assert adapter.count("update_post_meta(") == 1, "Do not add a parallel document write path"
writes = re.findall(r"\$this->persist_elements\s*\((.*?)\)", adapter)
assert len(writes) == 8, f"Expected all 8 forward/rollback routes, got {len(writes)}"
assert all("sha256" in x for x in writes), "Every write must bind current source SHA"
for name in ("elementor-dynamic-content", "wordpress-change-safety", "wordpress-browser-acceptance"):
    skill = (base / "skill-seeds" / name / "SKILL.md").read_text()
    assert contract in skill, f"Skill {name} missing canonical contract"
assert f"const SEED_VERSION = {manifest['seed_version']};" in seeder
assert manifest["seed_version"] >= 30
print("PASS: common write path + three Skills + seed version")
