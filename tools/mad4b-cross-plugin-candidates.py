#!/usr/bin/env python3
"""Resolve any reviewed plugin sources into a bounded, immutable, non-authorizing CI matrix."""
import argparse
import json
from pathlib import Path
import re
import sys

SHA = re.compile(r"^[0-9a-f]{40}$")
ID = re.compile(r"^[a-z][a-z0-9_-]{0,63}$")
REPO = re.compile(r"^[A-Za-z0-9_.-]{1,70}/[A-Za-z0-9_.-]{1,100}$")
PATH = re.compile(r"^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+){0,12}$")
PLUGIN = re.compile(r"^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\.php$")
VERSION = re.compile(r"^[A-Za-z0-9][A-Za-z0-9.+_-]{0,79}$")
REVIEWED = {"source_reviewed_artifact_pending", "source_reviewed_artifact_certified"}


def candidates(manifest, requested=""):
    raw = json.loads(Path(manifest).read_text(encoding="utf8"))
    if raw.get("contract") != "mad4b.cross-plugin-sources.v1" or not isinstance(raw.get("sources"), list):
        raise ValueError("unreviewed source registry")
    if requested and not ID.fullmatch(requested):
        raise ValueError("invalid source selection")
    seen, matrix = set(), []
    for row in raw["sources"]:
        if not isinstance(row, dict):
            raise ValueError("invalid source record")
        ident = row.get("id", "")
        if not isinstance(ident, str) or not ID.fullmatch(ident) or ident in seen:
            raise ValueError("invalid/duplicate registry ID")
        seen.add(ident)
        if row.get("review_state") not in REVIEWED:
            continue
        if requested and requested != ident:
            continue
        repo = row.get("repository", "")
        sha = row.get("candidate_source_commit_sha") or row.get("source_commit_sha") or ""
        version = row.get("candidate_artifact_version") or row.get("artifact_version") or ""
        directory = row.get("plugin_subdirectory", "")
        entry = row.get("plugin_file", "")
        provider = row.get("provider_id", "")
        if (not isinstance(repo, str) or not REPO.fullmatch(repo) or ".." in repo
            or not isinstance(sha, str) or not SHA.fullmatch(sha)
            or not isinstance(version, str) or not VERSION.fullmatch(version)
            or not isinstance(directory, str) or not PATH.fullmatch(directory) or ".." in directory
            or not isinstance(entry, str) or not PLUGIN.fullmatch(entry)
            or not isinstance(provider, str) or not ID.fullmatch(provider)
            or not entry.startswith(directory.rsplit("/", 1)[-1] + "/")):
            raise ValueError("candidate lacks exact reviewed identity: " + ident)
        matrix.append({"source_id": ident, "repository": repo, "source_commit_sha": sha})
    if requested and not matrix:
        raise ValueError("source absent or not approved for candidate build")
    if len(matrix) > 32:
        raise ValueError("candidate matrix budget exceeded")
    return {"include": sorted(matrix, key=lambda x: x["source_id"])}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--manifest", required=True)
    parser.add_argument("--source-id", default="")
    parser.add_argument("--github-output", default="")
    args = parser.parse_args()
    try:
        matrix = candidates(args.manifest, args.source_id)
    except (ValueError, OSError, json.JSONDecodeError) as exc:
        print("CROSS_PLUGIN_CANDIDATES_REJECTED: " + str(exc), file=sys.stderr)
        return 2
    out = json.dumps(matrix, sort_keys=True, separators=(",", ":"))
    if args.github_output:
        with open(args.github_output, "a", encoding="utf8") as f:
            f.write("matrix=" + out + "\n")
            f.write("has_candidates=" + ("true" if matrix["include"] else "false") + "\n")
    print(out)
    return 0


if __name__ == "__main__":
    sys.exit(main())
