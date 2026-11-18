#!/usr/bin/env python3
"""Exact-CAS migration of already installed Original306 v86 Worker to v87.

NO control flow, claim or execution behavior changes. This upgrades only the
content-pinned original306 typed Host Anchor classifier SHA. Version v87 also
requires the independent v85 Root Registry manifest pin upgrade and current
Original306 persisted Claim-shaped verifier source before running real Jobs.
"""
from __future__ import annotations
import hashlib

PREIMAGE_SHA256 = (
    "c5c1888bc1319f81a158b70d9a5617df5258f6aaa5006636d1f53393b8185acf"
)
OLD_CLASSIFIER_SHA256 = (
    "40e01518c83b57d3020d797adb9990f23ddded0c7b40b7deac36b4b0c1eecc45"
)
NEW_CLASSIFIER_SHA256 = (
    "84d209b40144fe04fe19d7db3ea869e7335c06694c76d286bf0b2f3e26d68b5f"
)
EXPECTED_SHA256 = (
    "819d2509fc5f072189f9c23ddde7116739ba2b6442950bdadf8436a08f7c7120"
)


def render(current: bytes) -> bytes:
    old = hashlib.sha256(current).hexdigest()
    if old != PREIMAGE_SHA256:
        raise ValueError("ORIGINAL306_WORKER_PREIMAGE_DRIFT:" + old)
    source = current.decode("utf-8")
    if source.count(OLD_CLASSIFIER_SHA256) != 1:
        raise ValueError("ORIGINAL306_CLASSIFIER_ROOT_PIN_NOT_UNIQUE")
    modified = source.replace(
        OLD_CLASSIFIER_SHA256, NEW_CLASSIFIER_SHA256, 1).encode("utf-8")
    if hashlib.sha256(modified).hexdigest() != EXPECTED_SHA256:
        raise ValueError("ORIGINAL306_V87_CANDIDATE_MISMATCH")
    compile(modified, "original306_v87_worker.py", "exec")
    return modified
