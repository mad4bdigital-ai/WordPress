#!/usr/bin/env python3
"""Inspect the exact repository Bit Flows package without authorizing execution."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import zipfile
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[4]
PLUGIN_ROOT = ROOT / "wp-content/plugins/mad4b-site-control-plane"
ARCHIVE = ROOT / "wp-content/plugins/bit-pi.zip"
CATALOG = PLUGIN_ROOT / "config/certified-providers.json"
CONTRACT = "mad4b.bitflows-exact-package-diagnostic.v1"

TARGETS = (
    "backend/app/src/Flow/FlowExecutor.php",
    "backend/app/Model/FlowHistory.php",
    "backend/app/Model/Flow.php",
    "backend/app/Model/FlowNode.php",
    "backend/app/HTTP/Controllers/FlowController.php",
    "backend/app/HTTP/Controllers/WebhookDispatchController.php",
)


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def unique_suffix(names: list[str], suffix: str) -> str:
    matches = [name for name in names if name == suffix or name.endswith("/" + suffix)]
    if len(matches) != 1:
        raise RuntimeError(f"archive path resolution failed for {suffix}: matches={len(matches)}")
    return matches[0]


def decode_php(raw: bytes, path: str) -> str:
    try:
        return raw.decode("utf-8")
    except UnicodeDecodeError as exc:
        raise RuntimeError(f"non-UTF8 PHP source: {path}") from exc


def methods(source: str) -> list[str]:
    return sorted(set(re.findall(r"\bfunction\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(", source)))


def function_body(source: str, method: str) -> str:
    match = re.search(r"\bfunction\s+" + re.escape(method) + r"\s*\([^)]*\)\s*(?::\s*[^\{]+)?\{", source, re.S)
    if not match:
        return ""
    start = match.end() - 1
    depth = 0
    quote = ""
    escaped = False
    line_comment = False
    block_comment = False
    i = start
    while i < len(source):
        ch = source[i]
        nxt = source[i + 1] if i + 1 < len(source) else ""
        if line_comment:
            if ch == "\n":
                line_comment = False
            i += 1
            continue
        if block_comment:
            if ch == "*" and nxt == "/":
                block_comment = False
                i += 2
                continue
            i += 1
            continue
        if quote:
            if escaped:
                escaped = False
            elif ch == "\\":
                escaped = True
            elif ch == quote:
                quote = ""
            i += 1
            continue
        if ch in ("'", '"'):
            quote = ch
            i += 1
            continue
        if ch == "/" and nxt == "/":
            line_comment = True
            i += 2
            continue
        if ch == "/" and nxt == "*":
            block_comment = True
            i += 2
            continue
        if ch == "#":
            line_comment = True
            i += 1
            continue
        if ch == "{":
            depth += 1
        elif ch == "}":
            depth -= 1
            if depth == 0:
                return source[start + 1 : i]
        i += 1
    raise RuntimeError(f"unbalanced method body: {method}")


def return_shapes(body: str) -> list[str]:
    rows: list[str] = []
    for expr in re.findall(r"\breturn\s+(.{0,240}?);", body, re.S):
        compact = re.sub(r"\s+", " ", expr).strip()
        if re.fullmatch(r"(?:true|false|null)", compact, re.I):
            shape = compact.lower()
        elif compact.startswith("array(") or compact.startswith("["):
            shape = "array"
        elif compact.startswith("new "):
            shape = "object"
        elif re.fullmatch(r"\$[A-Za-z_][A-Za-z0-9_]*(?:\[[^\]]+\])?", compact):
            shape = "variable"
        elif re.fullmatch(r"[0-9]+", compact):
            shape = "integer"
        else:
            shape = "expression"
        rows.append(shape)
    return sorted(set(rows))


def marker_positions(body: str, markers: tuple[str, ...]) -> dict[str, list[int]]:
    positions: dict[str, list[int]] = {}
    for marker in markers:
        found = [m.start() for m in re.finditer(re.escape(marker), body)]
        if found:
            positions[marker] = found
    return positions


def semantic_summary(path: str, source: str) -> dict[str, Any]:
    summary: dict[str, Any] = {
        "path": path,
        "sha256": sha256_bytes(source.encode("utf-8")),
        "methods": methods(source),
        "flow_history_refs": source.count("FlowHistory"),
        "flow_executor_refs": source.count("FlowExecutor"),
        "history_id_refs": len(re.findall(r"history[_A-Za-z]*id|historyId|history_id", source, re.I)),
    }
    if path.endswith("FlowExecutor.php"):
        body = function_body(source, "execute")
        if not body:
            raise RuntimeError("FlowExecutor::execute body not found")
        summary["execute"] = {
            "body_sha256": sha256_bytes(body.encode("utf-8")),
            "return_shapes": return_shapes(body),
            "flow_history_refs": body.count("FlowHistory"),
            "history_id_refs": len(re.findall(r"history[_A-Za-z]*id|historyId|history_id", body, re.I)),
            "provider_execution_ref_markers": len(re.findall(r"execution[_A-Za-z]*id|executionId|execution_id|run[_A-Za-z]*id|runId|run_id", body, re.I)),
            "marker_positions": marker_positions(
                body,
                (
                    "FlowHistory",
                    "FlowHistory::",
                    "new FlowHistory",
                    "->insert(",
                    "::insert(",
                    "->create(",
                    "::create(",
                    "->save(",
                    "->update(",
                    "::execute(",
                    "return ",
                ),
            ),
            "returns_execution_identity_candidate": bool(
                re.search(r"return\s+[^;]*(?:history|execution|run)[_A-Za-z]*id", body, re.I)
            ),
        }
    return summary


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()

    catalog = json.loads(CATALOG.read_text(encoding="utf-8"))
    providers = catalog.get("providers", catalog)
    provider = providers.get("bit_pi")
    if not isinstance(provider, dict):
        raise SystemExit("bit_pi certification entry missing")

    expected_archive = str(provider.get("archive_sha256", "")).lower()
    actual_archive = sha256_file(ARCHIVE)
    if actual_archive != expected_archive:
        raise SystemExit(f"Bit Flows archive SHA mismatch: {actual_archive}")

    critical = provider.get("critical_files")
    if not isinstance(critical, dict) or not critical:
        raise SystemExit("Bit Flows critical_files certification is missing")

    evidence: dict[str, Any] = {
        "contract": CONTRACT,
        "authorizing": False,
        "provider_id": "bit_pi",
        "provider_version": str(provider.get("version", "")),
        "archive": str(provider.get("archive", "")),
        "archive_sha256": actual_archive,
        "archive_sha256_matches_catalog": True,
        "critical_files_verified": 0,
        "critical_file_mismatches": [],
        "semantic_sources": {},
        "executor_call_sites": [],
        "history_write_sites": [],
    }

    with zipfile.ZipFile(ARCHIVE, "r") as archive:
        names = archive.namelist()
        for logical, expected in critical.items():
            resolved = unique_suffix(names, logical)
            actual = sha256_bytes(archive.read(resolved))
            if actual != str(expected).lower():
                evidence["critical_file_mismatches"].append(
                    {"path": logical, "expected_sha256": str(expected).lower(), "actual_sha256": actual}
                )
            else:
                evidence["critical_files_verified"] += 1

        if evidence["critical_file_mismatches"]:
            raise SystemExit("Bit Flows critical file certification mismatch")

        for logical in TARGETS:
            resolved = unique_suffix(names, logical)
            source = decode_php(archive.read(resolved), logical)
            evidence["semantic_sources"][logical] = semantic_summary(logical, source)

        for name in names:
            if not name.endswith(".php"):
                continue
            try:
                source = decode_php(archive.read(name), name)
            except RuntimeError:
                continue
            if "FlowExecutor" in source and "::execute(" in source:
                evidence["executor_call_sites"].append(
                    {
                        "path": name,
                        "flow_executor_refs": source.count("FlowExecutor"),
                        "execute_call_refs": source.count("::execute("),
                    }
                )
            if "FlowHistory" in source and re.search(r"(?:FlowHistory|flowHistory|flow_history).{0,160}(?:insert|create|save|update)", source, re.I | re.S):
                evidence["history_write_sites"].append(
                    {"path": name, "flow_history_refs": source.count("FlowHistory")}
                )

    evidence["executor_call_sites"] = sorted(evidence["executor_call_sites"], key=lambda row: row["path"])
    evidence["history_write_sites"] = sorted(evidence["history_write_sites"], key=lambda row: row["path"])
    executor = evidence["semantic_sources"]["backend/app/src/Flow/FlowExecutor.php"]["execute"]
    evidence["execution_correlation"] = {
        "execute_returns_identity_candidate": bool(executor["returns_execution_identity_candidate"]),
        "execute_return_shapes": executor["return_shapes"],
        "execute_history_id_refs": executor["history_id_refs"],
        "execute_flow_history_refs": executor["flow_history_refs"],
        "provider_execution_ref_markers": executor["provider_execution_ref_markers"],
        "safe_retry_proven": False,
        "readback_correlation_proven": False,
        "certification_state": "DIAGNOSTIC_ONLY",
    }

    encoded = json.dumps(evidence, indent=2, sort_keys=True) + "\n"
    if args.output:
        args.output.write_text(encoded, encoding="utf-8")
    print(encoded, end="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
