#!/usr/bin/env python3
import argparse
import os
import re
from pathlib import Path


def workflow_block(lines, name):
    marker = f"  {name}:"
    try:
        start = lines.index(marker) + 1
    except ValueError as exc:
        raise SystemExit(f"workflow section missing: {name}") from exc
    out = []
    for line in lines[start:]:
        if line.startswith("  ") and not line.startswith("    ") and line.strip():
            break
        out.append(line)
    return out


def paths_from(section):
    values = []
    in_paths = False
    for raw in section:
        stripped = raw.strip()
        if stripped == "paths:":
            in_paths = True
            continue
        if in_paths and stripped.startswith("- "):
            values.append(stripped[2:].strip("'\""))
        elif in_paths and stripped and not raw.startswith("      "):
            break
    return values


def github_glob(pattern):
    i = 0
    out = ["^"]
    while i < len(pattern):
        ch = pattern[i]
        if ch == "*":
            if i + 1 < len(pattern) and pattern[i + 1] == "*":
                out.append(".*")
                i += 2
            else:
                out.append("[^/]*")
                i += 1
        elif ch == "?":
            out.append("[^/]")
            i += 1
        else:
            out.append(re.escape(ch))
            i += 1
    out.append("$")
    return re.compile("".join(out))


def load_trigger_paths(workflow_path, trigger):
    lines = Path(workflow_path).read_text(encoding="utf-8").splitlines()
    values = paths_from(workflow_block(lines, trigger))
    if not values:
        raise SystemExit(f"{trigger}: Control Plane Package path inventory is empty")
    if len(values) != len(set(values)):
        raise SystemExit(f"{trigger}: duplicate Control Plane Package paths detected")
    return values


def classify(changed_paths, patterns):
    compiled = [(pattern, github_glob(pattern)) for pattern in patterns]
    matches = [
        (path, pattern)
        for path in changed_paths
        for pattern, regex in compiled
        if regex.fullmatch(path)
    ]
    return bool(matches), matches


def run_self_test(workflow_path):
    push_paths = load_trigger_paths(workflow_path, "push")
    pr_paths = load_trigger_paths(workflow_path, "pull_request")
    if push_paths != pr_paths:
        raise SystemExit("Control Plane Package pull_request/push path inventories drifted")

    cases = {
        "wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php": True,
        "wp-content/plugins/example.zip": True,
        "tools/capture-functional-gap-contract-evidence.py": True,
        ".github/workflows/mad4b-release-verdict.yml": True,
        ".github/workflows/mad4b-owner-attestation-rerun.yml": False,
        ".github/mad4b-repository-governance-policy.json": False,
        "tools/classify_control_plane_update_scope.py": False,
        "README.md": False,
    }
    for path, expected in cases.items():
        actual, _ = classify([path], push_paths)
        if actual is not expected:
            raise SystemExit(
                f"classifier contract mismatch path={path} expected={expected} actual={actual}"
            )
    print(f"update-channel scope classifier contract: PASS paths={len(push_paths)} cases={len(cases)}")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--workflow", required=True)
    parser.add_argument("--changed-paths-file")
    parser.add_argument("--github-output")
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()

    if args.self_test:
        run_self_test(args.workflow)
        return

    if not args.changed_paths_file:
        raise SystemExit("--changed-paths-file is required outside --self-test")

    patterns = load_trigger_paths(args.workflow, "push")
    changed = [
        line.strip()
        for line in Path(args.changed_paths_file).read_text(encoding="utf-8").splitlines()
        if line.strip()
    ]
    publish, matches = classify(changed, patterns)
    reason = "package_input_changed" if publish else "governance_only_no_package_input_change"

    if args.github_output:
        with Path(args.github_output).open("a", encoding="utf-8") as handle:
            handle.write(f"publish={'true' if publish else 'false'}\n")
            handle.write(f"reason={reason}\n")

    print(
        "update-channel scope:",
        "PUBLISH" if publish else "NOOP",
        f"changed={len(changed)}",
        f"matches={len(matches)}",
    )
    for path, pattern in matches:
        print(f"  match path={path} pattern={pattern}")
    if not publish:
        for path in changed:
            print(f"  governance-only path={path}")


if __name__ == "__main__":
    main()
