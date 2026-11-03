#!/usr/bin/env python3
"""Read-only live WordPress runtime vs exact Git candidate source parity.

Safety: reports hashes and paths only; does NOT copy, patch, restart, deploy, or
expose source content. Exit 2 with --enforce when a release must be held.
"""
from __future__ import annotations
import argparse
import datetime as dt
import hashlib
import json
import pathlib
import subprocess
import sys


def git(repo: pathlib.Path, *args: str) -> str:
    r = subprocess.run(["git", "-C", str(repo), *args], capture_output=True, text=True)
    if r.returncode:
        raise RuntimeError("git " + " ".join(args[:2]) + ": " + r.stderr.strip()[:200])
    return r.stdout.strip()


def sha_file(p: pathlib.Path) -> str:
    return hashlib.sha256(p.read_bytes()).hexdigest()


def live_sha(container: str, docroot: str, relative: str) -> str | None:
    cmd = ["docker", "exec", "-u", "0", container, "sha256sum",
           docroot.rstrip("/") + "/" + relative]
    r = subprocess.run(cmd, capture_output=True, text=True, timeout=20)
    if r.returncode == 0 and len(r.stdout.split()) >= 1:
        checksum = r.stdout.split()[0].strip()
        if len(checksum) == 64 and all(c in "0123456789abcdef" for c in checksum):
            return checksum
    if "No such file" in r.stderr:
        return None
    raise RuntimeError("live hash check failed: " + relative + " (" + r.stderr[:130] + ")")


def bootstrap_dependencies(source: str) -> set[str]:
    import re
    return set(re.findall(
        r"""require_once\s+MAD4B_SCP_DIR\s*\.\s*['"]([^'"]+)['"]""",
        source,
    ))


def bootstrap_coverage(repo: pathlib.Path, container: str, docroot: str) -> dict:
    path = "wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php"
    candidate = (repo / path)
    if not candidate.is_file():
        return {"status": "UNKNOWN_CANDIDATE_BOOTSTRAP"}
    live = subprocess.run(
        ["docker", "exec", "-u", "0", container, "cat",
         docroot.rstrip("/") + "/" + path],
        capture_output=True, text=True, timeout=25,
    )
    if live.returncode:
        raise RuntimeError("live bootstrap could not be read safely")
    wanted = bootstrap_dependencies(candidate.read_text(encoding="utf-8"))
    loaded = bootstrap_dependencies(live.stdout)
    if len(wanted) < 50 or len(loaded) < 50:
        raise RuntimeError("bootstrap require parser yielded unexpectedly few modules")
    extra = sorted(loaded - wanted)
    missing = [p for p in extra
               if not (repo / "wp-content/plugins/mad4b-site-control-plane" / p).is_file()]
    return {
        "status": "BOOTSTRAP_DRIFT" if extra else "MATCH",
        "candidate_requires": len(wanted),
        "live_requires": len(loaded),
        "extra_live_require_count": len(extra),
        "extra_not_in_candidate": len(missing),
        "extra_live_require_paths": extra,
        "not_in_candidate_paths": missing,
    }


def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--repo", type=pathlib.Path, required=True)
    p.add_argument("--base", required=True)
    p.add_argument("--container", required=True)
    p.add_argument("--docroot", default="/var/www/html")
    p.add_argument("--out", type=pathlib.Path, required=True)
    p.add_argument("--enforce", action="store_true")
    a = p.parse_args()
    repo = a.repo.resolve()
    current = git(repo, "rev-parse", "HEAD")
    base = git(repo, "rev-parse", a.base)
    paths = git(repo, "diff", "--name-only", base, current).splitlines()
    rows = []
    for rel in paths:
        if not rel.startswith("wp-content/") or not rel.endswith(".php") or "/tests/" in rel:
            continue
        source = repo / rel
        if not source.is_file():
            continue
        original = sha_file(source)
        installed = live_sha(a.container, a.docroot, rel)
        status = ("MATCH_EXACT_SOURCE" if original == installed
                  else "NOT_INSTALLED" if installed is None else "LIVE_SOURCE_DRIFT")
        rows.append({"source": rel, "status": status,
                     "candidate_sha256": original, "installed_sha256": installed})
    counts = {label: sum(row["status"] == label for row in rows)
              for label in ("MATCH_EXACT_SOURCE", "LIVE_SOURCE_DRIFT", "NOT_INSTALLED")}
    bootstrap = bootstrap_coverage(repo, a.container, a.docroot)
    hold = (counts["LIVE_SOURCE_DRIFT"] > 0 or
            counts["NOT_INSTALLED"] > 0 or
            bootstrap["status"] != "MATCH")
    report = {
        "contract": "mad4b.live-wordpress-source-parity.v1",
        "checked_at_utc": dt.datetime.now(dt.timezone.utc).isoformat(),
        "git_head": current, "git_base": base, "docker_container": a.container,
        "document_root": a.docroot, "source_count": len(rows), "summary": counts,
        "promotion_gate": "HOLD_SOURCE_RECONCILIATION" if hold else "MATCH",
        "bootstrap_dependency_coverage": bootstrap,
        "deployment_executed": False,
        "source_replaced": False,
        "rows": rows,
        "notes": [
            "CI and browser tests do not establish deployed source equality.",
            "Uninstalled optional modules and recorded source drift require a migration plan, not blind file replacement.",
            "The runtime may have independent changes absent from the candidate Git branch.",
        ],
    }
    a.out.parent.mkdir(parents=True, exist_ok=True)
    a.out.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n")
    print(json.dumps({k: report[k] for k in
                      ("git_head", "source_count", "summary", "promotion_gate")}))
    return 2 if hold and a.enforce else 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (RuntimeError, subprocess.TimeoutExpired, OSError) as exc:
        print("SOURCE_PARITY_CHECK_ERROR: " + str(exc), file=sys.stderr)
        sys.exit(3)
