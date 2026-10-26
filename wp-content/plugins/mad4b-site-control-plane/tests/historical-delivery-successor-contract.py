#!/usr/bin/env python3
"""Non-authorizing, negative-path contract for Feature 007 immutable G6/G8/G9 evidence."""
import hashlib
import json
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(Path(__file__).resolve().parent))
from historical_source_successor import is_exact_historical_delivery_successor

EXT = ROOT / "specs/007-content-intelligence-workflow-platform/extensions/competitive-experience"
BOOTSTRAP = "wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php"
VALIDATOR = "specs/007-content-intelligence-workflow-platform/extensions/competitive-experience/validate.py"
n = 0


def verify(message, condition):
    global n
    if not condition:
        raise AssertionError(message)
    n += 1


for group, needle in (("g6", BOOTSTRAP), ("g8", VALIDATOR), ("g9", BOOTSTRAP)):
    payload = json.loads((EXT / (group + "-delivery.json")).read_text(encoding="utf-8"))
    rows = payload["evidence_integrity"] if group == "g9" else [
        item for section in ("code_paths", "test_paths", "spec_paths", "workflow_paths")
        for item in payload[section]
    ]
    record = next(row for row in rows if row["path"] == needle)
    raw = (ROOT / needle).read_bytes()
    sha = record["sha256"]
    size = record["bytes"]
    verify(group + ":valid original+successor", is_exact_historical_delivery_successor(ROOT, needle, raw, sha, size))
    verify(group + ":altered historical sha", not is_exact_historical_delivery_successor(ROOT, needle, raw, "0" * 64, size))
    verify(group + ":altered historic size", not is_exact_historical_delivery_successor(ROOT, needle, raw, sha, size + 1))
    verify(group + ":tampered current bytes", not is_exact_historical_delivery_successor(ROOT, needle, raw + b"tamper", sha, size))
    verify(group + ":wrong source scope", not is_exact_historical_delivery_successor(ROOT, needle + ".foreign", raw, sha, size))
    verify(group + ":missing archive record", not is_exact_historical_delivery_successor(ROOT, needle, raw, "", size))
    verify(group + ":wrong typed archive bytes", not is_exact_historical_delivery_successor(ROOT, needle, raw, sha, True))
    verify(group + ":no fabricated environment grant", not is_exact_historical_delivery_successor(ROOT, "../" + needle, raw, sha, size))

# Earlier G1-G5/UI archives omit the byte-count field. The archived SHA must
# still match exact historical Git source; missing bytes is not a wildcard.
sys.path.insert(0, str(EXT))
import validate as extension_validator

legacy = {
    "g1": VALIDATOR,
    "g2": BOOTSTRAP,
    "g3": BOOTSTRAP,
    "g4": BOOTSTRAP,
    "g5": "wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-g5-seo-provider-families.php",
    "ui": "wp-content/plugins/mad4b-site-control-plane/tests/admin-pages-wordpress.php",
}
for group, path in legacy.items():
    payload = json.loads((EXT / (group + "-delivery.json")).read_text(encoding="utf-8"))
    record = next(row for section in ("code_paths", "test_paths", "spec_paths", "workflow_paths")
                  for row in payload.get(section, []) if row["path"] == path)
    verify(group + ":legacy historic without bytes", extension_validator.matches_immutable_delivery_source(
        ROOT, path, record["sha256"], record.get("bytes")))
    verify(group + ":historic spoof denied", not extension_validator.matches_immutable_delivery_source(
        ROOT, path, "0" * 64, record.get("bytes")))
    verify(group + ":bad length denied", not extension_validator.matches_immutable_delivery_source(
        ROOT, path, record["sha256"], 1))
verify("new archive path forbidden", not extension_validator.matches_immutable_delivery_source(
    ROOT, VALIDATOR + ".extra", "0" * 64))

verify("unreviewed unrelated path rejected", not is_exact_historical_delivery_successor(
    ROOT, "wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-servers.php",
    (ROOT / "wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-servers.php").read_bytes(),
    "0"*64, 123,
))
print("FEATURE007_HISTORICAL_DELIVERY_SUCCESSOR: PASS " + str(n) + " positive/negative assertions")
