#!/usr/bin/env python3
"""Exact isolated readback bootstrap delta audit; never an owner approval.

The existing historical 69-file semantic provenance remains immutable. This
independent review surface proves one specific insertion, without weakening
any existing signed/unsigned provenance or release eligibility checks.
"""
from __future__ import annotations
import hashlib
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MAIN = "wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php"
MODULE = "wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-original-job-readback.php"
MODULE_SHA256 = "cbde0e415f23c69c65e8940e5f9912188fb9532a7fa6312bc79253d404f89e2d"
BASE = "31badd1e350c033dcd6f274d031c80d295bbbdf9"
PREVIOUS_SHA256 = "6d5c8cd24fdae962ee7ad243917538811ffb294ba8c311d6b5d13d3c432f9677"
CANDIDATE_SHA256 = "67d336b11b3ef0decf4e8c9867cbf28d603523c039cfe06013c2ab37fe754efb"
ANCHOR = b"MAD4B_SCP_FCP_Queue_Readback::boot();\n"
INSERT = (
    b"// Original305 Job/Claim observation only. Register a bounded admin Ability;\n"
    b"// this module cannot grant, claim, execute or certify an Original312 result.\n"
    b"// The earlier foreign REST/ordinary Core zero-touch exits remain unchanged.\n"
    b"require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-original-job-readback.php';\n"
    b"MAD4B_SCP_Original_Job_Readback::boot();\n"
)


class ProvenanceDenied(ValueError):
    pass


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def compare_bytes(previous: bytes, candidate: bytes) -> dict:
    if sha256(previous) != PREVIOUS_SHA256 or previous.count(ANCHOR) != 1:
        raise ProvenanceDenied("UNTRUSTED_ORIGINAL_CONTROL_PLANE_SOURCE")
    if candidate != previous.replace(ANCHOR, ANCHOR + INSERT, 1):
        raise ProvenanceDenied("CONTROL_PLANE_READBACK_BOOT_DELTA_UNREVIEWED")
    if sha256(candidate) != CANDIDATE_SHA256:
        raise ProvenanceDenied("CONTROL_PLANE_READBACK_CANDIDATE_SHA_DRIFT")
    return {
        "contract": "mad4b.control-plane-redacted-job-source-delta-review.v1",
        "state": "EXACT_SOURCE_DELTA_MATCH__INDEPENDENT_RELEASE_REVIEW_REQUIRED",
        "base_commit": BASE,
        "base_sha256": PREVIOUS_SHA256,
        "candidate_sha256": CANDIDATE_SHA256,
        "files_changed_in_review": 1,
        "additional_bootstrap_lines": 5,
        "previous_provenance_mutated": False,
        "independent_owner_approval_verified": False,
        "installed_runtime_matches_candidate": False,
        "live_wordpress_readback_performed": False,
        "release_authorized": False,
    }


def checked_readback_module(content: bytes) -> str:
    """Pin the loaded implementation independently of its bootstrap line."""
    if sha256(content) != MODULE_SHA256:
        raise ProvenanceDenied("CONTROL_PLANE_READBACK_MODULE_SHA_DRIFT")
    return MODULE_SHA256


def audit(root: Path = ROOT) -> dict:
    root = Path(root)
    checked = subprocess.run(["git", "-C", str(root), "show", BASE + ":" + MAIN],
                             stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                             check=False, timeout=20)
    if checked.returncode != 0:
        raise ProvenanceDenied("ORIGINAL_CONTROL_PLANE_GIT_OBJECT_NOT_FOUND")
    path = root / MAIN
    if not path.is_file() or path.is_symlink():
        raise ProvenanceDenied("CONTROL_PLANE_SOURCE_NOT_REGULAR")
    candidate = compare_bytes(checked.stdout, path.read_bytes())
    module = root / MODULE
    if not module.is_file() or module.is_symlink():
        raise ProvenanceDenied("CONTROL_PLANE_READBACK_MODULE_NOT_REGULAR")
    candidate["readback_module_sha256"] = checked_readback_module(module.read_bytes())
    candidate["readback_module_source_verified"] = True
    candidate["readback_module_installed_verified"] = False
    return candidate


if __name__ == "__main__":
    import json
    try:
        print(json.dumps(audit(), sort_keys=True, indent=2))
    except (ProvenanceDenied, OSError, subprocess.TimeoutExpired) as err:
        print(json.dumps({"state": "HOLD__PROVENANCE_NOT_VERIFIED",
                          "reason": type(err).__name__, "release_authorized": False}))
        raise SystemExit(2)
