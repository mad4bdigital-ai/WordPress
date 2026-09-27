#!/usr/bin/env python3
"""Cross-platform Host Runner link/reparse path denial contract."""

from __future__ import annotations

import importlib.util
import os
import subprocess
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]


def load(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec and spec.loader
    spec.loader.exec_module(module)
    return module


runner = load("mad4b_host_runner_path_security", ROOT / "tools/mad4b_host_runner.py")

with tempfile.TemporaryDirectory() as td:
    tmp = Path(td)
    zone = tmp / "zone"
    target = tmp / "outside"
    zone.mkdir()
    target.mkdir()
    (target / "sentinel.txt").write_text("outside\n", encoding="utf-8")

    link = zone / ("junction" if os.name == "nt" else "symlink")
    if os.name == "nt":
        completed = subprocess.run(
            ["cmd", "/c", "mklink", "/J", str(link), str(target)],
            text=True,
            capture_output=True,
            check=False,
        )
        if completed.returncode != 0:
            raise SystemExit(
                "FAIL host-runner-path-security: unable to create Windows junction: "
                + (completed.stderr or completed.stdout).strip()
            )
    else:
        link.symlink_to(target, target_is_directory=True)

    try:
        if not runner._is_link_like(link):
            raise SystemExit("FAIL host-runner-path-security: link/reparse object was not detected")

        try:
            runner._reject_symlink_chain(link / "sentinel.txt", zone)
            raise SystemExit("FAIL host-runner-path-security: link/reparse chain was accepted")
        except ValueError as exc:
            if "link/reparse" not in str(exc):
                raise

        # Direct JSON loading through a linked/reparse file path must also fail.
        try:
            runner.load_json_bounded(link / "sentinel.txt")
            raise SystemExit("FAIL host-runner-path-security: linked JSON source was accepted")
        except ValueError:
            pass

        if (target / "sentinel.txt").read_text(encoding="utf-8") != "outside\n":
            raise SystemExit("FAIL host-runner-path-security: outside target was mutated")


        # Plugin deployment package members are fixed below the exact plugin root;
        # traversal, absolute paths and Windows-style separators are denied.
        for unsafe in (
            "../escape.php",
            "/mad4b-site-control-plane/absolute.php",
            "mad4b-site-control-plane/../escape.php",
            "mad4b-site-control-plane\\escape.php",
        ):
            try:
                runner._safe_package_relative(unsafe)
                raise SystemExit(
                    "FAIL host-runner-path-security: unsafe deployment archive path accepted: "
                    + unsafe
                )
            except ValueError:
                pass

        import zipfile
        info = zipfile.ZipInfo("mad4b-site-control-plane/link.php")
        info.external_attr = (0o120777 << 16)
        if not runner._zip_member_is_symlink(info):
            raise SystemExit("FAIL host-runner-path-security: ZIP symlink metadata was not detected")
    finally:
        if os.name == "nt" and link.exists():
            subprocess.run(["cmd", "/c", "rmdir", str(link)], check=False)
        elif link.exists() or link.is_symlink():
            link.unlink()

print(
    "mad4b.host-runner.path-security.v1: PASS "
    + ("windows-reparse" if os.name == "nt" else "posix-symlink")
)
