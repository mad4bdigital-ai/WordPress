#!/usr/bin/env python3
"""Fail-closed source/ZIP integrity check for the reviewed Control Plane guards.

This is only an artifact non-regression check. It does not confer deployment,
release, owner approval, or Production permissions. Reviewed successor content
must receive its own immutable, non-authorizing manifest before it can pass.
"""
import argparse
import hashlib
import io
import json
import stat
import tempfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PREFIX = "mad4b-site-control-plane/"
ADDON = PREFIX + "includes/class-mad4b-scp-addon-registry.php"
SELF = PREFIX + "includes/class-mad4b-scp-self-update.php"
GUARDED = (ADDON, SELF)
ADDON_PIN = "8153b244678120e53937e8ca13bf36158e7273b5f8714a9128be9ff728f036c5"
REVIEW = ROOT / "governance/release-parity/qa31-pr1014-native-forward-only-source-review-20261121.json"
LIMIT = 524288
MAX_ENTRIES = 10000


class Hold(ValueError):
    pass


def digest(contents):
    return hashlib.sha256(contents).hexdigest()


def trusted_pins():
    manifest = json.loads(REVIEW.read_text(encoding="utf-8"))
    required = {
        "contract": "mad4b.qa31-pr1014-native-forward-only-source-review.v1",
        "parent_head_sha": "29fe79e3ae87bd10507186ae24704d2b331c5c3c",
        "source_path": "includes/class-mad4b-scp-self-update.php",
        "reviewed_source_sha256": "7a2691b1af6f2459bfed796fc56b325eff1276fa61276b010e2106b78ecb2546",
        "reviewed_for_ci_only": True,
        "source_only": True,
        "live_evidence_required": True,
        "authorizing": False,
        "production_authorized": False,
        "source_promotion_approved": False,
        "archive_installed_by_ci": False,
    }
    if any(manifest.get(key) != val for key, val in required.items()):
        raise Hold("REVIEWED_SOURCE_IDENTITY_MISMATCH")
    pins = {ADDON: ADDON_PIN, SELF: manifest["reviewed_source_sha256"]}
    for path, expected in pins.items():
        original = ROOT / "wp-content/plugins" / path
        if original.is_symlink() or not original.is_file() or digest(original.read_bytes()) != expected:
            raise Hold("EXACT_REVIEWED_SOURCE_MISMATCH:" + path)
    return pins


def check(archive, pins):
    seen = {}
    with zipfile.ZipFile(archive, "r") as z:
        info = z.infolist()
        if not 1 <= len(info) <= MAX_ENTRIES:
            raise Hold("ZIP_ENTRY_COUNT_INVALID")
        unique = set()
        for entry in info:
            path = entry.filename
            parts = path.rstrip("/").split("/")
            if (not path or path.startswith("/") or "\\" in path
                or any(part in ("", ".", "..") for part in parts)
                or path in unique):
                raise Hold("ZIP_DUPLICATE_OR_UNSAFE_PATH")
            unique.add(path)
            file_type = (entry.external_attr >> 16) & 0o170000
            if file_type == stat.S_IFLNK:
                raise Hold("ZIP_SYMLINK_REJECTED")
            if path in pins:
                if entry.is_dir() or entry.file_size > LIMIT or entry.file_size < 1:
                    raise Hold("ZIP_GUARDED_FILE_INVALID_SIZE")
                body = z.read(entry)
                if len(body) != entry.file_size or digest(body) != pins[path]:
                    raise Hold("ZIP_REVIEWED_FILE_HASH_MISMATCH:" + path)
                seen[path] = digest(body)
        if set(seen) != set(pins):
            raise Hold("ZIP_PROTECTED_FILES_MISSING")
    return {"contract": "mad4b.cp-zip-forward-integrity.v1",
            "result": "PASS", "checked": len(seen), "files": seen,
            "source_only": True, "release_approved": False,
            "production_authorized": False, "mutation_performed": False}


def zip_bytes(entries):
    result = io.BytesIO()
    with zipfile.ZipFile(result, "w", zipfile.ZIP_STORED) as z:
        for name, value in entries:
            z.writestr(name, value)
    return result.getvalue()


def self_test(pins):
    files = [(name, (ROOT / "wp-content/plugins" / name).read_bytes())
             for name in GUARDED]
    with tempfile.TemporaryDirectory(prefix="mad4b-zip-review-test-") as tmp:
        target = Path(tmp) / "candidate.zip"
        target.write_bytes(zip_bytes(files))
        assert check(target, pins)["result"] == "PASS"
        negative = [
            ("missing", files[:1]),
            ("duplicate", files + [files[0]]),
            ("marker-preserving-tamper", [(n, b + b"\n// unreviewed source\n") if n == SELF else (n, b)
                                         for n, b in files]),
            ("addon-tamper", [(n, b + b"\n// unreviewed source\n") if n == ADDON else (n, b)
                             for n, b in files]),
            ("traversal", files + [("../escape.php", b"payload")]),
            ("backslash", files + [("rogue\\entry.php", b"payload")]),
            ("oversized", [(n, b"x" * (LIMIT + 1)) if n == SELF else (n, b)
                           for n, b in files]),
        ]
        for name, entries in negative:
            target.write_bytes(zip_bytes(entries))
            try:
                check(target, pins)
            except (Hold, zipfile.BadZipFile):
                continue
            raise AssertionError("Unsafe ZIP accepted: " + name)
    return {"contract": "mad4b.cp-zip-forward-integrity.tests.v1",
            "result": "PASS", "positive": 1, "negative": len(negative),
            "mutation_performed": False}


def main():
    args = argparse.ArgumentParser()
    args.add_argument("--archive", type=Path)
    args.add_argument("--self-test", action="store_true")
    opts = args.parse_args()
    if not opts.self_test and opts.archive is None:
        args.error("--archive or --self-test is required")
    try:
        pins = trusted_pins()
        if opts.self_test:
            print(json.dumps(self_test(pins), sort_keys=True))
        if opts.archive is not None:
            print(json.dumps(check(opts.archive, pins), sort_keys=True))
    except (Hold, OSError, ValueError, zipfile.BadZipFile, RuntimeError) as exc:
        print(json.dumps({"contract": "mad4b.cp-zip-forward-integrity.v1",
                          "result": "HOLD", "reason": str(exc),
                          "release_approved": False, "mutation_performed": False}))
        raise SystemExit(2)


if __name__ == "__main__":
    main()
