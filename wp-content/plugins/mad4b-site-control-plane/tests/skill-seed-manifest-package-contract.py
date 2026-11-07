#!/usr/bin/env python3
"""Ensure every canonical Skills seed declared in the manifest ships in the release."""
import argparse
import json
import re
import subprocess
from pathlib import Path
from zipfile import ZipFile

PLUGIN = Path(__file__).resolve().parents[1]
REPO = Path(__file__).resolve().parents[4]

def validate_manifest():
    manifest = json.loads((PLUGIN / "config/skill-seed-manifest.json").read_text(encoding="utf-8"))
    assert manifest.get("contract") == "mad4b.skill-seed-manifest.v1"
    items = manifest.get("skills")
    assert isinstance(items, list) and items
    seen, required = set(), []
    for row in items:
        name = row["name"]
        assert re.fullmatch(r"[a-z0-9]+(?:-[a-z0-9]+)*", name)
        key = (row["level"], row["target"], name)
        assert key not in seen, f"duplicate seed identity: {key}"
        seen.add(key)
        rel = Path("skill-seeds") / name / "SKILL.md"
        path = PLUGIN / rel
        assert path.is_file() and not path.is_symlink(), f"missing canonical seed file: {rel}"
        raw = path.read_text(encoding="utf-8")
        assert raw.startswith("---\n") and re.search(r"(?m)^name:\s*"+re.escape(name)+r"\s*$", raw)
        assert re.search(r"(?m)^description:\s*\S+", raw), f"missing description for {name}"
        required.append(rel.as_posix())
        # These two canonical provider/reference contracts are part of the
        # declared content-jobs Skill. Generated discovery caches are optional
        # and must never be required for installing the managed Skill.
        if name == "content-jobs-ui-reconcile":
            for filename in (
                "content-jobs-reconcile-contract-v1.json",
                "dynamic-skill-family-policy-v1.json",
            ):
                ref = Path("skill-seeds") / name / "references" / filename
                actual = PLUGIN / ref
                assert actual.is_file() and not actual.is_symlink(), f"missing Skill reference contract: {ref}"
                doc = json.loads(actual.read_text(encoding="utf-8"))
                assert isinstance(doc, dict) and isinstance(doc.get("contract"), str), f"invalid Skill reference contract: {ref}"
                required.append(ref.as_posix())
    return required

def check_git(paths):
    if not (REPO / ".git").exists():
        return
    tracked = subprocess.run(
        ["git", "-C", str(REPO), "ls-files", "--cached", "--", "wp-content/plugins/mad4b-site-control-plane/skill-seeds"],
        capture_output=True, text=True, check=True
    ).stdout.splitlines()
    tracked = set(tracked)
    for path in paths:
        rel = "wp-content/plugins/mad4b-site-control-plane/" + path
        assert rel in tracked, f"seed exists on disk but is not tracked in Git: {rel}"

def check_archive(paths, archive):
    with ZipFile(archive) as z:
        names = set(z.namelist())
        for path in paths:
            member = "mad4b-site-control-plane/" + path
            assert member in names, f"package omitted required seed: {member}"
            assert z.getinfo(member).file_size > 0, f"empty seed in package: {member}"

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--archive", type=Path, default=None)
    args = parser.parse_args()
    paths = validate_manifest()
    check_git(paths)
    if args.archive:
        check_archive(paths, args.archive)
    print(f"PASS canonical_skill_seed_manifest_and_git count={len(paths)} archive_checked={bool(args.archive)}")

if __name__ == "__main__":
    main()
