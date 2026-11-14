#!/usr/bin/env python3
"""Read-only closure of the original Git-backed Company Code Release catalog.

No runtime write authority. Every catalogued MU source must exist as a regular
Git blob at exactly the reviewed commit, regardless of whether it is installed.
"""
from __future__ import annotations
import hashlib
import json
from pathlib import Path
from typing import Any
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import company_code_release as release


def audit(repo: Path, commit: str) -> dict[str, Any]:
    catalog_path = "ops/company-code-release-v1/catalog/mad4b-release-components.json"
    try:
        catalog = json.loads(release.git_bytes(repo, commit, catalog_path))
    except (TypeError, ValueError) as exc:
        raise release.Denied("RELEASE_CATALOG_JSON_INVALID") from exc
    release.fail_if(not isinstance(catalog, dict) or sorted(catalog) !=
                    ["components", "contract"] or
                    catalog.get("contract") != "mad4b.company-release-components.v1",
                    "RELEASE_CATALOG_INVALID")
    elements = catalog["components"]
    release.fail_if(not isinstance(elements, list) or len(elements) > 500 or
                    not elements, "RELEASE_CATALOG_COMPONENTS_INVALID")
    seen = set()
    count = 0
    digests = {}
    for element in elements:
        release.fail_if(not isinstance(element, dict) or
                        sorted(element) != ["files", "id"] or
                        not isinstance(element["files"], list) or
                        not element["files"], "RELEASE_CATALOG_ENTRY_INVALID")
        for path in element["files"]:
            file = release.exact_relative(path)
            release.fail_if(file in seen or not file.startswith(
                "wp-content/mu-plugins/mad4b-") or not file.endswith(".php"),
                "RELEASE_CATALOG_FILE_SCOPE_INVALID")
            seen.add(file)
            data = release.git_bytes(repo, commit, file)
            digests[file] = hashlib.sha256(data).hexdigest()
            count += 1
    return {
        "contract": "mad4b.company-release-catalog-source-closure.v1",
        "status": "PASS", "commit": commit,
        "components": len(elements), "sources": count,
        "sha256": digests, "deployment_authorized": False,
    }


def main() -> None:
    import argparse
    import subprocess
    parser = argparse.ArgumentParser()
    parser.add_argument("--repo", type=Path, default=Path(__file__).resolve().parents[3])
    parser.add_argument("--commit")
    args = parser.parse_args()
    commit = args.commit or subprocess.check_output(
        ["git", "-C", str(args.repo), "rev-parse", "HEAD"], text=True).strip()
    try:
        print(json.dumps(audit(args.repo, commit), sort_keys=True))
    except (release.Denied, ValueError, OSError, RuntimeError) as exc:
        print(json.dumps({"status": "DENIED", "error": type(exc).__name__ + ":" + str(exc)[:160]}))
        raise SystemExit(2) from exc


if __name__ == "__main__":
    main()
