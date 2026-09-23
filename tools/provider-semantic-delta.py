#!/usr/bin/env python3
"""Produce non-authorizing semantic delta evidence for a premium provider.

The baseline archive is located from Git history by its governed SHA-256.
No live WordPress runtime is used as certification authority. The report
contains structural metadata only; it never copies proprietary source code.
"""

from __future__ import annotations

import argparse
import hashlib
import io
import json
import pathlib
import re
import subprocess
import sys
import zipfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
CONTROL = ROOT / "wp-content/plugins/mad4b-site-control-plane"
CONTRACT = json.loads((CONTROL / "config/certified-providers.json").read_text(encoding="utf-8"))

PLUGIN_ENTRIES = {
    "jetengine": "jet-engine.php",
    "jetsmartfilters": "jet-smart-filters.php",
}

RISK_MARKERS = {
    "eval": r"\beval\s*\(",
    "shell_exec": r"\bshell_exec\s*\(",
    "exec": r"(?<![A-Za-z0-9_])exec\s*\(",
    "system": r"(?<![A-Za-z0-9_])system\s*\(",
    "passthru": r"\bpassthru\s*\(",
    "proc_open": r"\bproc_open\s*\(",
    "file_put_contents": r"\bfile_put_contents\s*\(",
    "wp_remote": r"\bwp_remote_(?:get|post|request)\s*\(",
    "wpdb": r"\$wpdb\b",
    "register_rest_route": r"\bregister_rest_route\s*\(",
    "permission_callback": r"permission_callback",
    "manage_options": r"manage_options",
    "mcp": r"(?i)\bmcp\b",
}


def sha256(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def run(*args: str) -> bytes:
    return subprocess.check_output(args, cwd=ROOT, stderr=subprocess.DEVNULL)


def git_history(path: str) -> list[str]:
    raw = run("git", "log", "--format=%H", "--follow", "--", path)
    return [line.strip() for line in raw.decode().splitlines() if line.strip()]


def blob_at(commit: str, path: str) -> bytes | None:
    try:
        return run("git", "show", f"{commit}:{path}")
    except subprocess.CalledProcessError:
        return None


def find_baseline(path: str, expected_sha: str) -> tuple[str, bytes]:
    seen = set()
    for commit in git_history(path):
        raw = blob_at(commit, path)
        if raw is None:
            continue
        digest = sha256(raw)
        if digest in seen:
            continue
        seen.add(digest)
        if digest == expected_sha:
            return commit, raw
    raise SystemExit(f"baseline archive SHA not found in Git history: {path} {expected_sha}")


def locate_main(names: set[str], basename: str) -> str:
    matches = sorted(
        n for n in names
        if not n.endswith("/")
        and "__MACOSX/" not in n
        and (n == basename or n.endswith("/" + basename))
    )
    if len(matches) != 1:
        raise SystemExit(f"expected one main plugin entry {basename}, got {matches}")
    return matches[0]


def locate_relative(names: set[str], prefix: str, relative: str) -> str | None:
    exact = prefix + relative
    if exact in names:
        return exact
    matches = sorted(
        n for n in names
        if not n.endswith("/")
        and "__MACOSX/" not in n
        and (n == relative or n.endswith("/" + relative))
    )
    return matches[0] if len(matches) == 1 else None


def inspect_archive(raw: bytes, provider: str, governed_paths: list[str]) -> dict:
    with zipfile.ZipFile(io.BytesIO(raw)) as zf:
        names = set(zf.namelist())
        main = locate_main(names, PLUGIN_ENTRIES[provider])
        prefix = main.rsplit("/", 1)[0] + "/" if "/" in main else ""
        header = zf.read(main).decode("utf-8", errors="replace")[:65536]
        version_match = re.search(r"^\s*\*?\s*Version:\s*([^\r\n]+)", header, re.I | re.M)
        if not version_match:
            raise SystemExit(f"{provider}: version header missing")
        files = {}
        missing = []
        for relative in governed_paths:
            member = locate_relative(names, prefix, relative)
            if member is None:
                missing.append(relative)
                continue
            raw_file = zf.read(member)
            files[relative] = {
                "sha256": sha256(raw_file),
                "bytes": len(raw_file),
                "raw": raw_file,
            }
        return {
            "version": version_match.group(1).strip(),
            "main_entry": main,
            "files": files,
            "missing": missing,
        }


def symbols(raw: bytes) -> dict:
    text = raw.decode("utf-8", errors="replace")
    functions = sorted(set(re.findall(r"\bfunction\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(", text)))
    classes = sorted(set(re.findall(r"\b(?:class|interface|trait)\s+([A-Za-z_][A-Za-z0-9_]*)\b", text)))
    hooks = sorted(set(
        name
        for _, name in re.findall(
            r"\b(add_action|add_filter)\s*\(\s*['\"]([^'\"]+)['\"]",
            text,
        )
    ))
    routes = sorted(set(
        f"{ns}:{route}"
        for ns, route in re.findall(
            r"\bregister_rest_route\s*\(\s*['\"]([^'\"]+)['\"]\s*,\s*['\"]([^'\"]+)['\"]",
            text,
        )
    ))
    risks = {name: len(re.findall(pattern, text)) for name, pattern in RISK_MARKERS.items()}
    return {
        "lines": text.count("\n") + (1 if text else 0),
        "functions": functions,
        "classes": classes,
        "hooks": hooks,
        "rest_routes": routes,
        "risk_marker_counts": risks,
    }


def set_delta(old: list[str], new: list[str]) -> dict:
    a, b = set(old), set(new)
    return {"added": sorted(b - a), "removed": sorted(a - b)}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--provider", required=True, choices=sorted(PLUGIN_ENTRIES))
    parser.add_argument("--archive-path", required=True)
    parser.add_argument("--baseline-sha256", required=True)
    parser.add_argument("--expected-current-version", required=True)
    parser.add_argument("--output", required=True, type=pathlib.Path)
    args = parser.parse_args()

    provider_contract = CONTRACT["providers"][args.provider]
    if provider_contract.get("archive_sha256") != args.baseline_sha256:
        raise SystemExit("requested baseline SHA does not match governed provider contract")
    governed_paths = sorted(provider_contract.get("critical_files", {}))
    if not governed_paths:
        raise SystemExit("governed critical-file set is empty")

    current_path = ROOT / args.archive_path
    current_raw = current_path.read_bytes()
    baseline_commit, baseline_raw = find_baseline(args.archive_path, args.baseline_sha256)

    old = inspect_archive(baseline_raw, args.provider, governed_paths)
    new = inspect_archive(current_raw, args.provider, governed_paths)
    if old["version"] != provider_contract.get("version"):
        raise SystemExit(f"baseline version mismatch: {old['version']}")
    if new["version"] != args.expected_current_version:
        raise SystemExit(f"candidate version mismatch: {new['version']}")
    if old["missing"] or new["missing"]:
        raise SystemExit(f"critical manifest incomplete old={old['missing']} new={new['missing']}")

    changed = []
    unchanged = []
    for relative in governed_paths:
        old_file = old["files"][relative]
        new_file = new["files"][relative]
        if old_file["sha256"] == new_file["sha256"]:
            unchanged.append(relative)
            continue
        old_symbols = symbols(old_file["raw"])
        new_symbols = symbols(new_file["raw"])
        changed.append({
            "path": relative,
            "old_sha256": old_file["sha256"],
            "new_sha256": new_file["sha256"],
            "old_bytes": old_file["bytes"],
            "new_bytes": new_file["bytes"],
            "line_delta": new_symbols["lines"] - old_symbols["lines"],
            "functions": set_delta(old_symbols["functions"], new_symbols["functions"]),
            "classes": set_delta(old_symbols["classes"], new_symbols["classes"]),
            "hooks": set_delta(old_symbols["hooks"], new_symbols["hooks"]),
            "rest_routes": set_delta(old_symbols["rest_routes"], new_symbols["rest_routes"]),
            "risk_marker_delta": {
                key: new_symbols["risk_marker_counts"][key] - old_symbols["risk_marker_counts"][key]
                for key in sorted(RISK_MARKERS)
                if new_symbols["risk_marker_counts"][key] != old_symbols["risk_marker_counts"][key]
            },
        })

    out = {
        "contract": "mad4b.premium-provider-semantic-delta.v1",
        "non_authorizing": True,
        "provider": args.provider,
        "baseline": {
            "version": old["version"],
            "archive_sha256": sha256(baseline_raw),
            "source_commit": baseline_commit,
        },
        "candidate": {
            "version": new["version"],
            "archive_sha256": sha256(current_raw),
            "source_commit": run("git", "rev-parse", "HEAD").decode().strip(),
        },
        "critical_file_count": len(governed_paths),
        "changed_critical_file_count": len(changed),
        "unchanged_critical_file_count": len(unchanged),
        "changed_critical_files": changed,
        "unchanged_critical_files": unchanged,
        "semantic_review_required": True,
        "promotion_authorized": False,
        "source_code_included": False,
    }

    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(out, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(
        f"{args.provider}: baseline={old['version']} candidate={new['version']} "
        f"changed={len(changed)} unchanged={len(unchanged)} baseline_commit={baseline_commit}"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
