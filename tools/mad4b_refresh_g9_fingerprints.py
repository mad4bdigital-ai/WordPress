#!/usr/bin/env python3
"""Check/explicitly refresh immutable G9 source fingerprints after code changes.

Default mode is read-only. --write only repairs SHA-256 and byte counts of the
existing exact inventory; it cannot authorize a release or change task status.
This helper is NOT invoked automatically by build/CI to conceal drift.
"""
from __future__ import annotations

import argparse
from hashlib import sha256
import json
import os
from pathlib import Path
import stat
import tempfile

ROOT = Path(__file__).resolve().parents[1]
MANIFEST = ROOT / "specs/007-content-intelligence-workflow-platform/extensions/competitive-experience/g9-delivery.json"
WORKFLOW = ".github/workflows/feature-007-spec-ci.yml"
BOOTSTRAP = "wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php"
VALIDATOR = "wp-content/plugins/mad4b-site-control-plane/tests/g9-delivery-contract.py"


def safe_read(path: str) -> bytes:
    relative = Path(path)
    if (not path or relative.is_absolute() or ".." in relative.parts
            or chr(92) in path or chr(0) in path or relative.as_posix() != path):
        raise RuntimeError("unsafe manifest source path: " + repr(path))
    file = ROOT / relative
    if (not file.is_file() or file.is_symlink() or not file.resolve().is_relative_to(ROOT)
            or any(p.is_symlink() for p in file.parents if p.is_relative_to(ROOT))):
        raise RuntimeError("missing, symlinked or escaped manifest source: " + path)
    contents = file.read_bytes()
    if len(contents) <= 20:
        raise RuntimeError("empty manifest source: " + path)
    return contents


def run(write: bool) -> int:
    if MANIFEST.is_symlink() or not MANIFEST.is_file():
        raise RuntimeError("G9 delivery manifest missing or symlinked")
    original = MANIFEST.read_bytes()
    manifest = json.loads(original)
    if (manifest.get("contract") != "mad4b.feature007-g9-delivery.v1"
            or manifest.get("status") != "REPOSITORY_G9_GUARDED_FOUNDATION_EXTERNAL_ACCEPTANCE_PENDING"
            or manifest.get("task_status") != "PARTIAL"
            or manifest.get("authorizing") is not False
            or manifest.get("production_authorized") is not False):
        raise RuntimeError("G9 delivery identity/status changed; manual review required")
    sources = set()
    for key in ("code_paths", "test_paths", "spec_paths"):
        rows = manifest.get(key)
        if not isinstance(rows, list) or not rows or len(set(rows)) != len(rows):
            raise RuntimeError("invalid G9 source group " + key)
        if sources.intersection(rows):
            raise RuntimeError("duplicate G9 source group record")
        sources.update(rows)
    inventory = sources | {BOOTSTRAP, WORKFLOW, VALIDATOR}
    records = manifest.get("evidence_integrity")
    if not isinstance(records, list) or len(records) != len(inventory):
        raise RuntimeError("G9 fingerprint inventory count drift; refusing auto repair")
    observed = set()
    changed = []
    for record in records:
        if not isinstance(record, dict) or set(record) != {"path", "sha256", "bytes"}:
            raise RuntimeError("G9 fingerprint schema drift")
        path = record["path"]
        if not isinstance(path, str) or path in observed or path not in inventory:
            raise RuntimeError("G9 fingerprint scope drift")
        observed.add(path)
        contents = safe_read(path)
        expected = sha256(contents).hexdigest()
        if record["sha256"] != expected or record["bytes"] != len(contents):
            changed.append(path)
            record["sha256"] = expected
            record["bytes"] = len(contents)
    if observed != inventory:
        raise RuntimeError("G9 fingerprint missing inventory records")
    if not changed:
        print("G9 FINGERPRINTS: PASS (%d exact records)" % len(records))
        return 0
    for path in changed:
        print("G9 FINGERPRINT DRIFT: " + path)
    if not write:
        print("G9 FINGERPRINTS: FAIL; explicit --write and source review required")
        return 1
    # Do not overwrite a changed manifest or follow a symlink during write.
    if MANIFEST.is_symlink() or sha256(MANIFEST.read_bytes()).digest() != sha256(original).digest():
        raise RuntimeError("manifest changed during review; abort")
    updated = (json.dumps(manifest, indent=2, ensure_ascii=False) + "\n").encode("utf-8")
    mode = stat.S_IMODE(MANIFEST.stat().st_mode)
    temp = None
    try:
        with tempfile.NamedTemporaryFile(dir=MANIFEST.parent, prefix=".g9-evidence-", delete=False) as fp:
            temp = Path(fp.name)
            fp.write(updated)
            fp.flush()
            os.fsync(fp.fileno())
        os.chmod(temp, mode)
        if sha256(MANIFEST.read_bytes()).digest() != sha256(original).digest():
            raise RuntimeError("manifest changed during commit; abort")
        os.replace(temp, MANIFEST)
        temp = None
    finally:
        if temp is not None:
            temp.unlink(missing_ok=True)
    print("G9 FINGERPRINTS: updated %d/%d records; run exact G9 tests, review diff and commit" %
          (len(changed), len(records)))
    return 0


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--write", action="store_true", help="explicitly refresh exact recorded hashes; never a CI bypass")
    args = parser.parse_args()
    raise SystemExit(run(args.write))
