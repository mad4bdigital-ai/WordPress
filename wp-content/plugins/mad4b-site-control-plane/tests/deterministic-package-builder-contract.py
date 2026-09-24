#!/usr/bin/env python3
"""Prove canonical Control Plane packaging is byte-for-byte reproducible."""

from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import time
import zipfile

HERE = Path(__file__).resolve().parent
BUILDER = HERE / "build-deterministic-control-plane-package.py"
SOURCE = "a" * 40
ADAPTER = "b" * 64
VERSION = "0.4.0-rc.test"


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def seed(root: Path, producer: str, mtime: int) -> None:
    (root / "includes").mkdir(parents=True)
    (root / "config").mkdir(parents=True)
    (root / "mad4b-site-control-plane.php").write_text(
        "<?php\n/* Plugin Name: MAD4B Test */\n", encoding="utf-8"
    )
    (root / "includes" / "class-sample.php").write_text("<?php echo 'sample';\n", encoding="utf-8")
    (root / "config" / "sample.json").write_text('{"ready":true}\n', encoding="utf-8")
    # Producer-specific stale provenance must be discarded by the canonical builder.
    (root / "MAD4B-BUILD-PROVENANCE.json").write_text(
        json.dumps({"build_workflow": producer, "build_run_id": producer}) + "\n",
        encoding="utf-8",
    )
    for p in root.rglob("*"):
        if p.is_file():
            os.utime(p, (mtime, mtime))


def build(root: Path, out: Path, receipt: Path) -> dict:
    prov = out.with_suffix(".provenance.json")
    fp = out.with_suffix(".fingerprint.txt")
    md = out.with_suffix(".manifest.txt")
    subprocess.run(
        [
            sys.executable,
            str(BUILDER),
            "--root", str(root),
            "--output-zip", str(out),
            "--version", VERSION,
            "--source-sha", SOURCE,
            "--adapter-version", "0.6.1",
            "--adapter-sha", ADAPTER,
            "--provenance-output", str(prov),
            "--build-fingerprint-output", str(fp),
            "--manifest-digest-output", str(md),
            "--receipt-output", str(receipt),
        ],
        check=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
    )
    return {
        "provenance": json.loads(prov.read_text(encoding="utf-8")),
        "fingerprint": fp.read_text(encoding="utf-8").strip(),
        "manifest": md.read_text(encoding="utf-8").strip(),
        "receipt": json.loads(receipt.read_text(encoding="utf-8")),
    }


def main() -> int:
    if not BUILDER.is_file():
        raise AssertionError("canonical package builder missing")

    with tempfile.TemporaryDirectory(prefix="mad4b-deterministic-package-") as td:
        base = Path(td)
        root_a = base / "a" / "mad4b-site-control-plane"
        root_b = base / "b" / "mad4b-site-control-plane"
        root_a.mkdir(parents=True)
        root_b.mkdir(parents=True)
        seed(root_a, "workflow-A", 1_600_000_000)
        seed(root_b, "workflow-B", 1_900_000_000)

        zip_a = base / "a.zip"
        zip_b = base / "b.zip"
        data_a = build(root_a, zip_a, base / "a-receipt.json")
        data_b = build(root_b, zip_b, base / "b-receipt.json")

        if sha(zip_a) != sha(zip_b):
            raise AssertionError("same logical package produced different ZIP bytes")
        if zip_a.read_bytes() != zip_b.read_bytes():
            raise AssertionError("canonical package bytes differ")
        if data_a["provenance"] != data_b["provenance"]:
            raise AssertionError("canonical provenance depends on producer metadata")
        if data_a["fingerprint"] != data_b["fingerprint"] or data_a["manifest"] != data_b["manifest"]:
            raise AssertionError("canonical package identities drifted")
        if data_a["receipt"]["archive_sha256"] != sha(zip_a):
            raise AssertionError("builder receipt does not bind exact archive bytes")
        if data_b["receipt"]["archive_sha256"] != sha(zip_b):
            raise AssertionError("second builder receipt does not bind exact archive bytes")

        prov = data_a["provenance"]
        if prov.get("build_workflow") != "MAD4B Canonical Package Builder":
            raise AssertionError("package-internal build_workflow must be producer-neutral")
        if prov.get("build_run_id") != "":
            raise AssertionError("producer run id must not enter canonical package bytes")
        if prov.get("package_producer_metadata_external") is not True:
            raise AssertionError("producer metadata externalization marker missing")
        if prov.get("archive_format_contract") != "mad4b.deterministic-zip.v1":
            raise AssertionError("deterministic archive format contract missing")

        with zipfile.ZipFile(zip_a) as z:
            names = z.namelist()
            if names != sorted(names):
                raise AssertionError("ZIP entries are not canonically sorted")
            if any(info.date_time != (1980, 1, 1, 0, 0, 0) for info in z.infolist()):
                raise AssertionError("ZIP timestamps are not canonical")
            if "mad4b-site-control-plane/MAD4B-BUILD-PROVENANCE.json" not in names:
                raise AssertionError("canonical provenance missing from ZIP")
            embedded = json.loads(z.read("mad4b-site-control-plane/MAD4B-BUILD-PROVENANCE.json"))
            if embedded != prov:
                raise AssertionError("sidecar and embedded canonical provenance diverged")

        # Unsafe symlink candidates must fail closed.
        unsafe_root = base / "unsafe"
        unsafe_root.mkdir()
        (unsafe_root / "file.php").write_text("<?php\n", encoding="utf-8")
        symlink_supported = True
        try:
            (unsafe_root / "link.php").symlink_to(unsafe_root / "file.php")
        except (OSError, NotImplementedError):
            symlink_supported = False
        if symlink_supported:
            result = subprocess.run(
                [
                    sys.executable, str(BUILDER),
                    "--root", str(unsafe_root),
                    "--output-zip", str(base / "unsafe.zip"),
                    "--version", VERSION,
                    "--source-sha", SOURCE,
                    "--adapter-sha", ADAPTER,
                ],
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
            )
            if result.returncode == 0:
                raise AssertionError("builder accepted symlinked package content")

    print("mad4b.deterministic-control-plane-package.contract.v1: PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
