#!/usr/bin/env python3
"""Hermetic deterministic cross-plugin package and source integrity regression."""
import hashlib
import json
import pathlib
import subprocess
import sys
import tempfile
import zipfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
REPO = ROOT.parents[2]
SCRIPT = REPO / "tools" / "mad4b-cross-plugin-package.py"
MANIFEST = ROOT / "config" / "cross-plugin-sources.json"


def run(cmd):
    return subprocess.run(cmd, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)


with tempfile.TemporaryDirectory(prefix="cross-plugin-ci-") as scratch:
    tmp = pathlib.Path(scratch)
    repo = tmp / "checkout"
    target = repo / "wp-content" / "plugins" / "etg-dynamic-filter-seo-bridge"
    target.mkdir(parents=True)
    (target / "etg-dynamic-filter-seo-bridge.php").write_text(
        "<?php\n/**\n * Plugin Name: ETG Dynamic Filter SEO Bridge\n * Version: 0.4.0-alpha.13\n */\n", encoding="utf8")
    (target / "index.php").write_text("<?php // inert fixture\n", encoding="utf8")
    run(["git", "init", "-q", str(repo)])
    run(["git", "-C", str(repo), "config", "user.email", "ci@example.invalid"])
    run(["git", "-C", str(repo), "config", "user.name", "Fixture"])
    run(["git", "-C", str(repo), "add", "."])
    run(["git", "-C", str(repo), "commit", "-qm", "fixture"])
    sha = run(["git", "-C", str(repo), "rev-parse", "HEAD"]).stdout.strip()
    command = [sys.executable, str(SCRIPT), "--trusted-manifest", str(MANIFEST),
        "--checkout", str(repo), "--source-id", "etg-dynamic-filter-seo-bridge", "--sha", sha]
    a = tmp / "a"
    b = tmp / "b"
    run(command + ["--out", str(a)])
    run(command + ["--out", str(b)])
    archive_a = next(a.glob("*.zip"))
    archive_b = next(b.glob("*.zip"))
    assert hashlib.sha256(archive_a.read_bytes()).hexdigest() == hashlib.sha256(archive_b.read_bytes()).hexdigest()
    receipt = json.loads(next(a.glob("*.json")).read_text())
    assert receipt["source_commit_sha"] == sha and receipt["outcome_authorizes_deployment"] is False
    with zipfile.ZipFile(archive_a) as f:
        assert "etg-dynamic-filter-seo-bridge/etg-dynamic-filter-seo-bridge.php" in f.namelist()
        assert all(not v.startswith("/") and ".." not in pathlib.PurePosixPath(v).parts for v in f.namelist())
    bad_sha = subprocess.run(command[:-2] + ["--sha", "0" * 40, "--out", str(tmp / "bad")],
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    assert bad_sha.returncode != 0
    (target / "escape-link.php").symlink_to(target / "index.php")
    symlinked = subprocess.run(command + ["--out", str(tmp / "symlinked")],
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    assert symlinked.returncode != 0
    print("CROSS_PLUGIN_DETERMINISTIC_BUILDER: PASS")
