#!/usr/bin/env python3
"""Source-only regression for canonical, non-authorizing Commerce lifecycle Skills."""
import json
import re
from pathlib import Path

repo = Path(__file__).resolve().parents[4]
plugin = repo / "wp-content/plugins/mad4b-site-control-plane"
portable = repo / "plugins/mad4b-wordpress/skills"
manifest = json.loads((plugin / "config/skill-seed-manifest.json").read_text("utf-8"))
catalog = json.loads((plugin / "config/skill-provider-catalog.json").read_text("utf-8"))
seeder = (plugin / "includes/class-mad4b-scp-skill-seeder.php").read_text("utf-8")
names = {
    "wordpress-commerce-inquiry-qualification": "inquiry-qualification",
    "wordpress-commerce-quote-booking-gates": "quote-booking-gates",
}
assert manifest["contract"] == "mad4b.skill-seed-manifest.v1"
assert manifest["seed_version"] == 15
assert "const SEED_VERSION = 15;" in seeder
for name in names:
    rows = [r for r in manifest["skills"] if r["name"] == name]
    assert len(rows) == 1, name
    assert rows[0]["level"] == "workflow" and rows[0]["target"] == "commerce-lifecycle"
    assert rows[0]["enabled"] is True
    source = (plugin / "skill-seeds" / name / "SKILL.md").read_bytes()
    mirror = (portable / name / "SKILL.md").read_bytes()
    assert source == mirror and len(source) > 1400, name
    text = source.decode("utf-8")
    assert text.startswith("---\nname: " + name + "\ndescription:")
    assert "Production" in text and "Rate" in text
    assert "approval" in text.lower() and "readback" in text
    assert "not authority" in text.lower() or "never grants" in text.lower() or "does NOT" in text
    assert not re.search(r"https?://(?:staging\.|tours\.dev|localhost)|(?:form_id|tour_id)\s*[:=]\s*\d+", text, re.I)
for family, skill in [
    ("fluentforms", "fluentforms-inquiry-qualification-evidence"),
    ("woocommerce", "woocommerce-commercial-release-readiness"),
    ("bitflows", "bitflows-commerce-lifecycle-routing"),
]:
    definitions = [d for d in catalog["packs"][family] if d["name"] == skill]
    assert len(definitions) == 1
    assert definitions[0]["description"] and definitions[0]["body"]
    assert "read" in definitions[0]["body"].lower() or "inspect" in definitions[0]["body"].lower()
    assert "not" in definitions[0]["body"].lower() or "never" in definitions[0]["body"].lower()
assert "commerce_approve_request_processing" not in seeder
print("commerce-lifecycle-skill-contract: PASS")
