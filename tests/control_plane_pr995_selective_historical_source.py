"""Cross-check frozen PR808 source and independently SHA-reviewed PR995 successor.

Historical review bytes remain unchanged. The current source is never treated
as the original reviewed source or as an Owner/Host release approval.
"""
from pathlib import Path
import hashlib
import json
import subprocess

ROOT=Path(__file__).resolve().parents[1]
PARENT="784ae71e5a088ee299fe4db20912f0d8fe21932e"
MANIFEST=ROOT/"governance/release-parity/qa31-pr990-wayfa-selective-operator-selfupdate-source-20261121.json"
PREFIX="wp-content/plugins/mad4b-site-control-plane/"
OPERATOR="includes/class-mad4b-scp-operator-grant-convergence.php"
ORIGINAL_SHA="b400b1ea0afbf7fb6d3e115fd8b8e568bcb89ef308fc5a0bf3b58efb0b0e3405"
REVIEWED_SHA="68a50b1c84d2cc2f8e81917dcd160a1744836780a066d005071201938a41f211"
REVIEWED_BLOB="ce9b3af3442ecbb0efa098e8bbe78c41ae9518cf"


def digest(value):
    return hashlib.sha256(value).hexdigest()


def git_blob(value):
    return hashlib.sha1(b"blob "+str(len(value)).encode()+b"\0"+value).hexdigest()


def assert_operator_historical_and_exact_successor(historical_digest, current_bytes):
    """Fail closed for any changed source without mutating frozen sidecars."""
    assert historical_digest==ORIGINAL_SHA
    original=subprocess.check_output(
        ["git","show",PARENT+":"+PREFIX+OPERATOR],cwd=ROOT,timeout=15)
    assert digest(original)==ORIGINAL_SHA

    manifest=json.loads(MANIFEST.read_text(encoding="utf-8"))
    assert manifest["contract"]=="mad4b.qa31-pr990-wayfa-selective-operator-selfupdate-source.v1"
    assert manifest["base_head_sha"]==PARENT
    assert manifest["source_only"] is True
    assert manifest["reviewed_for_ci_only"] is True
    assert manifest["release_gate"]=="BLOCKED_SOURCE_RECONCILIATION"
    for claim in ("production_authorized","release_approved","host_grant_issued",
                  "operator_grants_written","update_executed","changes_live_wordpress"):
        assert manifest[claim] is False,claim

    row=manifest["source_paths"][OPERATOR]
    assert row["original_sha256"]==ORIGINAL_SHA
    assert row["reviewed_sha256"]==REVIEWED_SHA
    assert row["reviewed_blob_sha"]==REVIEWED_BLOB
    assert digest(current_bytes)==REVIEWED_SHA
    assert git_blob(current_bytes)==REVIEWED_BLOB

    source=ROOT/PREFIX/OPERATOR
    assert source.is_file() and not source.is_symlink()
    assert source.read_bytes()==current_bytes

    committed=subprocess.check_output(
        ["git","rev-parse","HEAD:"+PREFIX+OPERATOR],cwd=ROOT,
        text=True,timeout=10).strip()
    assert committed==REVIEWED_BLOB

    reviews=json.loads((ROOT/"governance/production-readiness/capability-coverage.json").read_text())
    matches=[r for r in reviews["reviewed_changes"] if
             r.get("path")==PREFIX+OPERATOR and
             r.get("source_blob_sha")==REVIEWED_BLOB]
    assert len(matches)==1
    review=matches[0]
    assert review["status"]=="reviewed_for_ci_only"
    assert review["production_authorized"] is False
    assert review["live_evidence_required"] is True
    assert row["regression"] in review["regression_tests"]
    assert (ROOT/row["regression"]).is_file()
    return True
