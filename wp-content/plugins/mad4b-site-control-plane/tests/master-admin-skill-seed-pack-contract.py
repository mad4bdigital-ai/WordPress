#!/usr/bin/env python3
"""Exact bounded Skills v31 payload: no grants, no runtime mutation."""
import json
from pathlib import Path

plugin = Path(__file__).resolve().parents[1]
repo = plugin.parents[2]
manifest = json.loads((plugin / "config/skill-seed-manifest.json").read_text())
assert manifest["contract"] == "mad4b.skill-seed-manifest.v1"
assert manifest["seed_version"] == 31
seeder = (plugin / "includes/class-mad4b-scp-skill-seeder.php").read_text()
assert "const SEED_VERSION = 31;" in seeder
assert "self::exact_enrolled_site()" in seeder
assert "MAD4B_SCP_Skill_Registry::catalog_enabled()" in seeder
rows = manifest["skills"]
assert len(rows) == 29 and len({x["name"] for x in rows}) == len(rows)
new = {
    "master-admin-operations", "skills-coverage-and-recovery",
    "role-and-authority-audit", "booking-to-cash-reconciliation",
    "pricing-catalog-readiness", "localization-ui-parity",
    "incident-and-release-recovery", "team-workload-and-sla",
}
assert new <= {x["name"] for x in rows}
for row in rows:
    assert isinstance(row["enabled"], bool)
    if row["name"] in new:
        assert row["enabled"] is True
    name = row["name"]
    source = plugin / "skill-seeds" / name / "SKILL.md"
    portable = repo / "plugins/mad4b-wordpress/skills" / name / "SKILL.md"
    assert source.is_file() and portable.is_file(), name
    raw = source.read_bytes()
    assert raw == portable.read_bytes(), name
    text = raw.decode("utf-8")
    assert text.startswith("---\nname: " + name + "\n"), name
    assert "description:" in text and len(text) > 400, name
    if name in new:
        assert "does not add WordPress permissions" in text, name
        assert "## Procedure" in text and "## Result contract" in text
print("PASS: 29 canonical/portable Skills, 8 new bounded runbooks and guarded v31 source")
