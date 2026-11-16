"""Original Native Source manifest parity: immutable historical fixture vs current source.

Source-only. This contract must never repin an installed provider or issue a
release grant merely because its source hash has changed.
"""
from __future__ import annotations

import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OPS = ROOT / "ops"
HISTORICAL = OPS / "native-source-publisher-v1/catalog/generated/source.code.release.manifest.json"
V8 = OPS / "dynamic-operation-registry-v1/providers/host.source.release/manifest.v8.CANDIDATE.json"
V9 = OPS / "dynamic-operation-registry-v1/providers/host.source.release/manifest.v9.CANDIDATE.json"
HISTORICAL_SHA256 = "1b117e63e2cb762718378048e8e47707a97f906415e60f9d9a1bb319c54f85f3"
HISTORICAL_DEPENDENCIES = {
    "native-source-publisher-v1/native_source_publisher.py":
        "f2fefd9c8ff7298b1c943140168e3f8abf63e4e45434233408d31697d4a13195",
    "company-code-release-v1/company_code_release.py":
        "d922204de29a9a4380249094086d40cc0e7fbc5df947601103da7366112e7600",
    "executor-time-cas-v1/preclaim.py":
        "0f1b4f43da32b2d84b190b39953cd2c88019743d908e50019d98942365490c95",
}


def sha256(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def check_current_source_and_historical_fixture(check, candidate: dict) -> None:
    """Require reviewed V8→V9 source transition and preserve V27 snapshot.

    The historical generated descriptor is a fixture; it must not be silently
    repinned to create an apparent current signed provider.
    """
    saved_bytes = HISTORICAL.read_bytes()
    saved = json.loads(saved_bytes)
    check.assertEqual(sha256(saved_bytes), HISTORICAL_SHA256)
    check.assertEqual(
        {key: value for key, value in candidate.items() if key != "dependencies"},
        {key: value for key, value in saved.items() if key != "dependencies"},
        "Native operation metadata changed without a separate source review",
    )
    check.assertEqual(
        {row["path"]: row["sha256"] for row in saved["dependencies"]},
        HISTORICAL_DEPENDENCIES,
        "Historical Native V27 source fixture was rewritten",
    )
    cur = {row["path"]: row["sha256"] for row in candidate["dependencies"]}
    check.assertEqual(list(cur), list(HISTORICAL_DEPENDENCIES))
    check.assertEqual(
        {key for key in cur if cur[key] != HISTORICAL_DEPENDENCIES[key]},
        {"native-source-publisher-v1/native_source_publisher.py",
         "company-code-release-v1/company_code_release.py"},
        "Unreviewed dynamic source transition",
    )
    for relative, digest in cur.items():
        paths = [OPS / relative, OPS / "dynamic-operation-registry-v1" / relative]
        resolved = [item for item in paths if item.is_file() and not item.is_symlink()]
        check.assertEqual(len(resolved), 1, "Missing/ambiguous dynamic source " + relative)
        check.assertEqual(sha256(resolved[0].read_bytes()), digest, relative)

    v8, v9 = (json.loads(x.read_text()) for x in (V8, V9))
    check.assertEqual((v8["version"], v9["version"]), (8, 9))
    v8_deps = {x["path"]: x["sha256"] for x in v8["dependencies"]}
    v9_deps = {x["path"]: x["sha256"] for x in v9["dependencies"]}
    check.assertEqual(set(v8_deps), set(v9_deps))
    check.assertEqual(
        {name for name in v8_deps if v8_deps[name] != v9_deps[name]},
        {"company-code-release-v1/company_code_release.py"},
    )
    check.assertEqual(cur["native-source-publisher-v1/native_source_publisher.py"],
                      v8_deps["native-source-publisher-v1/native_source_publisher.py"])
    check.assertEqual(cur["company-code-release-v1/company_code_release.py"],
                      v9_deps["company-code-release-v1/company_code_release.py"])
    check.assertFalse((V9.parent / "manifest.json").exists(),
                      "Never convert a successor candidate to an enrolled provider")


__all__ = ["check_current_source_and_historical_fixture"]
