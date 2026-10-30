#!/usr/bin/env python3
"""Contract tests: operator responsibility must not silently waive safety gates."""
import json
from pathlib import Path
root=Path(__file__).resolve().parents[1]
catalog=json.loads((root/"tour-lifecycle-stage-catalog-v1.json").read_text())
media=next(s for s in catalog["stages"] if s["id"]=="media_scraping_importing")
p=media["policy"]
assert media["gate"]=="operator_responsibility_and_technical_safety"
assert p["license_document_required"] is False
assert p["rights_verification_required_for_staging_import"] is False
assert p["unverified_rights_permitted_for_staging"] is True
for mandatory in ("operator_assertion_required","source_provenance_required","known_takedown_blocks","known_legal_prohibition_blocks","ssrf_and_malware_checks_required","publication_separate"):
    assert p[mandatory] is True, mandatory
spec=(root/"tour-lifecycle-orchestration-v1.md").read_text().lower()
assert "unknown license status alone is not a hard block" in spec
assert "this does not create or imply a copyright license" in spec
assert "separate" in spec and "publication" in spec
print("PASS operator-responsibility media contract: 9 invariants")
