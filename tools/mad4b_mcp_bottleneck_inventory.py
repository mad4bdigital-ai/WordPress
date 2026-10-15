#!/usr/bin/env python3
"""Read-only, portable dependency/boot inventory for MAD4B WordPress.

A static source inventory is NOT a benchmark, a performance pass, a release
verdict or permission to remove eager imports. Use live per-phase metrics
before changing boot/module loading semantics.
"""
import argparse
import collections
import hashlib
import json
import re
from pathlib import Path

IMPORT_RE = re.compile(r"require_once\s+MAD4B_SCP_DIR\s*\.\s*['\"]([^'\"]+)['\"]\s*;")
BOOT_RE = re.compile(r"\b(MAD4B_SCP_[A-Za-z0-9_]+)::(boot|bootstrap|boot_early|pre_boot)\s*\(")
HASH_RE = re.compile(r"\bhash_file\s*\(")


def inventory(root: Path) -> dict:
    root = root.resolve()
    plugin = root / "wp-content/plugins/mad4b-site-control-plane"
    entry = plugin / "mad4b-site-control-plane.php"
    text = entry.read_text(encoding="utf-8")
    matches = list(IMPORT_RE.finditer(text))
    modules = []
    errors = []
    for match in matches:
        relative = match.group(1)
        target = (plugin / relative).resolve()
        if plugin.resolve() not in target.parents:
            errors.append({"path": relative, "error": "unsafe_path"})
            continue
        if not target.is_file():
            errors.append({"path": relative, "error": "missing_file"})
            continue
        modules.append({"path": relative, "bytes": target.stat().st_size,
                        "line": text.count("\n", 0, match.start()) + 1})
    occurrences = collections.Counter(m["path"] for m in modules)
    large = sorted(modules, key=lambda x: (-x["bytes"], x["path"]))[:20]
    group_sizes = collections.defaultdict(lambda: {"imports": 0, "source_bytes": 0})
    for item in modules:
        group = "adapter" if item["path"].startswith("includes/adapters/") else (
            "admin_like" if "admin" in Path(item["path"]).name else "feature_or_core"
        )
        group_sizes[group]["imports"] += 1
        group_sizes[group]["source_bytes"] += item["bytes"]
    boot_calls = collections.Counter(
        cls + "::" + method for cls, method in BOOT_RE.findall(text)
    )
    data = {
        "contract": "mad4b.mcp-static-bootstrap-inventory.v1",
        "source_kind": "static_local_filesystem",
        "source_sha256": hashlib.sha256(text.encode("utf-8")).hexdigest(),
        "entrypoint": entry.relative_to(root).as_posix(),
        "direct_require_once_count": len(matches),
        "unique_literal_imports": len(occurrences),
        "unique_module_source_bytes": sum(
            next(m["bytes"] for m in modules if m["path"] == key) for key in occurrences
        ),
        "duplicate_literal_imports": [
            {"path": p, "occurrences": c} for p, c in sorted(occurrences.items()) if c > 1
        ],
        "eager_import_categories": dict(sorted(group_sizes.items())),
        "largest_eager_modules": large,
        "literal_hash_file_calls_in_entrypoint": len(HASH_RE.findall(text)),
        "bootstrap_registration_calls": sum(boot_calls.values()),
        "bootstrap_calls_by_name": [
            {"callback": name, "occurrences": count}
            for name, count in sorted(boot_calls.items())
        ],
        "input_errors": errors,
        "limitations": [
            "This static scan counts literal entrypoint imports, not all transitive WP/plugin includes.",
            "Module byte size is not PHP execution time or peak memory.",
            "No current write authority, runtime grant, server timing, DNS or gateway latency inferred.",
            "Do not change bootstrap semantics before native acceptance and rollback testing."
        ],
        "read_only": True,
        "mutation_performed": False,
    }
    return data


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--repository-root", type=Path, default=Path(__file__).resolve().parents[1])
    ap.add_argument("--output", type=Path, default=None,
                    help="Optional output JSON; leave absent to print without writing anything")
    ap.add_argument("--baseline", type=Path, default=None,
                    help="Optional prior exact-source JSON, for informational deltas")
    args = ap.parse_args()
    current = inventory(args.repository_root)
    if args.baseline:
        prior = json.loads(args.baseline.read_text(encoding="utf-8"))
        current["baseline_deltas"] = {
            key: current[key] - int(prior[key])
            for key in ("direct_require_once_count", "unique_literal_imports",
                        "unique_module_source_bytes", "bootstrap_registration_calls")
            if isinstance(prior.get(key), int)
        }
    payload = json.dumps(current, ensure_ascii=False, sort_keys=True, indent=2) + "\n"
    if args.output:
        args.output.write_text(payload, encoding="utf-8")
    else:
        print(payload, end="")
    if current["input_errors"]:
        raise SystemExit("Required literal imports are missing or unsafe")


if __name__ == "__main__":
    main()
