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
    # Hermetic git fixture uses a disposable source registry with an exact
    # reviewed candidate pin, never the production source record.
    fixture_manifest = tmp / "sources.json"
    data = json.loads(MANIFEST.read_text(encoding="utf-8"))
    entry = next(x for x in data["sources"] if x["id"] == "etg-dynamic-filter-seo-bridge")
    entry["candidate_source_commit_sha"] = sha
    entry["candidate_artifact_version"] = "0.4.0-alpha.13"
    fixture_manifest.write_text(json.dumps(data), encoding="utf-8")
    command = [sys.executable, str(SCRIPT), "--trusted-manifest", str(fixture_manifest),
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
    # An existing but different signed Git commit is also rejected by the
    # registered immutable source pin, not merely by checkout HEAD mismatch.
    other_repo = tmp / "other-checkout"
    run(["git", "clone", "-q", str(repo), str(other_repo)])
    (other_repo / "etg-candidate-change.txt").write_text("different commit", encoding="utf-8")
    run(["git", "-C", str(other_repo), "config", "user.email", "ci@example.invalid"])
    run(["git", "-C", str(other_repo), "config", "user.name", "Fixture"])
    run(["git", "-C", str(other_repo), "add", "."])
    run(["git", "-C", str(other_repo), "commit", "-qm", "different commit"])
    other_sha = run(["git", "-C", str(other_repo), "rev-parse", "HEAD"]).stdout.strip()
    unreviewed = subprocess.run(
        [sys.executable, str(SCRIPT), "--trusted-manifest", str(fixture_manifest),
         "--checkout", str(other_repo), "--source-id", "etg-dynamic-filter-seo-bridge",
         "--sha", other_sha, "--out", str(tmp / "unreviewed")],
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
    )
    assert unreviewed.returncode != 0 and "exact reviewed registry SHA" in unreviewed.stderr
    original = (target / "index.php").read_bytes()
    (target / "index.php").write_bytes(original + b"// tracked drift\n")
    dirty = subprocess.run(command + ["--out", str(tmp / "dirty")], capture_output=True, text=True)
    assert dirty.returncode != 0 and "working tree differs" in dirty.stderr
    (target / "index.php").write_bytes(original)
    extra = target / "untracked.txt"
    extra.write_text("not committed")
    assert subprocess.run(command + ["--out", str(tmp / "untracked")], capture_output=True).returncode != 0
    extra.unlink()
    (repo / ".git/info/exclude").write_text("ignored.txt\n")
    extra = target / "ignored.txt"
    extra.write_text("ignored additions cannot silently enter a signed source package")
    assert subprocess.run(command + ["--out", str(tmp / "ignored")], capture_output=True).returncode != 0
    extra.unlink()
    # A source changing after status/listing must still fail blob verification.
    import importlib.util
    from unittest.mock import patch
    spec = importlib.util.spec_from_file_location("candidate_builder", SCRIPT)
    builder = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(builder)
    read_bytes = pathlib.Path.read_bytes
    def changed_read(path):
        data = read_bytes(path)
        return data + b"// raced after status\n" if path == target / "index.php" else data
    with patch.object(pathlib.Path, "read_bytes", changed_read):
        try:
            builder.build(fixture_manifest, repo, "etg-dynamic-filter-seo-bridge", sha, tmp / "raced")
        except ValueError as exc:
            assert "source blob changed" in str(exc)
        else:
            raise AssertionError("race payload received exact-source provenance")
    (target / "escape-link.php").symlink_to(target / "index.php")
    symlinked = subprocess.run(command + ["--out", str(tmp / "symlinked")],
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    assert symlinked.returncode != 0
    print("CROSS_PLUGIN_DETERMINISTIC_BUILDER: PASS")
