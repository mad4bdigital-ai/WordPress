#!/usr/bin/env python3
"""Generate or verify the exact MCP Adapter runtime symbol surface.

The profile is policy-driven rather than a hand-maintained class allowlist.
For one exact certified release archive it:
- selects PHP files from bounded runtime prefixes,
- computes Git-compatible blob identities and one deterministic tree digest,
- discovers declared WP\\MCP classes/interfaces/traits,
- writes or verifies runtime_symbols.

This lets new upstream runtime dependencies become visible automatically during
certification instead of failing later inside McpAdapter::create_server().
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import zipfile
from pathlib import Path

CONTRACT = "mad4b.mcp-runtime-surface.v1"


def git_blob_sha1(raw: bytes) -> str:
    return hashlib.sha1(b"blob " + str(len(raw)).encode("ascii") + b"\0" + raw).hexdigest()


def archive_root(zf: zipfile.ZipFile) -> str:
    names = [name for name in zf.namelist() if not name.endswith("/")]
    mains = [name for name in names if name == "mcp-adapter.php" or name.endswith("/mcp-adapter.php")]
    if len(mains) != 1:
        raise SystemExit(f"unexpected MCP Adapter archive roots: {mains}")
    return mains[0][:-len("mcp-adapter.php")]


def selected_files(zf: zipfile.ZipFile, prefix: str, include_prefixes: list[str]) -> dict[str, bytes]:
    out: dict[str, bytes] = {}
    for name in zf.namelist():
        if name.endswith("/") or not name.startswith(prefix):
            continue
        rel = name[len(prefix):]
        if not rel.endswith(".php"):
            continue
        if not any(rel.startswith(item) for item in include_prefixes):
            continue
        out[rel] = zf.read(name)
    return dict(sorted(out.items()))


def declared_symbols(raw: bytes, rel: str, namespace_prefixes: list[str]) -> list[dict[str, str]]:
    text = raw.decode("utf-8", errors="replace")
    ns_match = re.search(r"^\s*namespace\s+([^;{]+)\s*;", text, re.MULTILINE)
    namespace = ns_match.group(1).strip().lstrip("\\") if ns_match else ""
    rows: list[dict[str, str]] = []

    # The Adapter uses one named declaration per file today, but the generator
    # intentionally supports multiple named declarations for future releases.
    declaration = re.compile(
        r"^\s*(?:(?:final|abstract|readonly)\s+)*(class|interface|trait)\s+([A-Za-z_][A-Za-z0-9_]*)\b",
        re.MULTILINE,
    )
    for match in declaration.finditer(text):
        kind, short = match.groups()
        # Anonymous classes have no T_STRING/name and therefore do not match.
        symbol = f"{namespace}\\{short}" if namespace else short
        if not any(symbol.startswith(item) for item in namespace_prefixes):
            continue
        rows.append(
            {
                "symbol": symbol,
                "kind": kind,
                "file": rel,
                "git_blob_sha1": git_blob_sha1(raw),
            }
        )
    return rows


def generated_surface(zf: zipfile.ZipFile, profile: dict) -> tuple[dict, list[dict[str, str]]]:
    surface = profile.get("runtime_surface") or {}
    if surface.get("contract") != CONTRACT:
        raise SystemExit("runtime_surface contract missing or unsupported")

    include_prefixes = [str(item) for item in surface.get("include_prefixes") or [] if str(item)]
    namespace_prefixes = [str(item) for item in surface.get("namespace_prefixes") or [] if str(item)]
    if not include_prefixes or not namespace_prefixes:
        raise SystemExit("runtime_surface discovery policy is empty")

    root = archive_root(zf)
    files = selected_files(zf, root, include_prefixes)
    if not files:
        raise SystemExit("runtime_surface selected no PHP files")

    blob_rows = [(rel, git_blob_sha1(raw)) for rel, raw in files.items()]
    tree_payload = "".join(f"{rel}={blob}\n" for rel, blob in blob_rows).encode("utf-8")
    tree_sha256 = hashlib.sha256(tree_payload).hexdigest()

    symbols: list[dict[str, str]] = []
    for rel, raw in files.items():
        symbols.extend(declared_symbols(raw, rel, namespace_prefixes))

    symbols.sort(key=lambda row: (row["symbol"], row["kind"], row["file"]))
    seen: dict[str, str] = {}
    for row in symbols:
        previous = seen.get(row["symbol"])
        if previous and previous != row["file"]:
            raise SystemExit(f"duplicate runtime symbol {row['symbol']}: {previous} | {row['file']}")
        seen[row["symbol"]] = row["file"]

    generated = dict(surface)
    generated["file_count"] = len(files)
    generated["symbol_count"] = len(symbols)
    generated["tree_sha256"] = tree_sha256
    generated["generator"] = "tests/mcp-runtime-surface-profile.py"
    generated["fail_closed_on_unprofiled_symbol"] = True
    return generated, symbols


def normalized_symbols(rows) -> list[dict[str, str]]:
    if not isinstance(rows, list):
        return []
    out = []
    for row in rows:
        if not isinstance(row, dict):
            continue
        out.append(
            {
                "symbol": str(row.get("symbol") or ""),
                "kind": str(row.get("kind") or ""),
                "file": str(row.get("file") or ""),
                "git_blob_sha1": str(row.get("git_blob_sha1") or "").lower(),
            }
        )
    return sorted(out, key=lambda row: (row["symbol"], row["kind"], row["file"]))


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--archive", required=True)
    parser.add_argument("--profile", required=True)
    parser.add_argument("--version", required=True)
    parser.add_argument("--write", action="store_true")
    args = parser.parse_args()

    profile_path = Path(args.profile)
    document = json.loads(profile_path.read_text(encoding="utf-8"))
    provider_profiles = (document.get("providers") or {}).get("mcp_adapter") or {}
    profile = provider_profiles.get(args.version)
    if not isinstance(profile, dict):
        raise SystemExit(f"exact MCP Adapter profile unavailable: {args.version}")
    if str(profile.get("version") or "") != args.version:
        raise SystemExit("profile/version identity mismatch")

    with zipfile.ZipFile(args.archive) as zf:
        surface, symbols = generated_surface(zf, profile)

    if args.write:
        profile["runtime_surface"] = surface
        profile["runtime_symbols"] = symbols
        profile_path.write_text(json.dumps(document, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
        print(
            f"mad4b.mcp-runtime-surface.v1: WRITTEN "
            f"version={args.version} files={surface['file_count']} symbols={surface['symbol_count']} "
            f"tree={surface['tree_sha256']}"
        )
        return 0

    actual_surface = profile.get("runtime_surface") or {}
    for key in ("file_count", "symbol_count", "tree_sha256", "generator", "fail_closed_on_unprofiled_symbol"):
        if actual_surface.get(key) != surface.get(key):
            raise SystemExit(
                f"runtime_surface drift {key}: profile={actual_surface.get(key)!r} generated={surface.get(key)!r}"
            )

    actual_symbols = normalized_symbols(profile.get("runtime_symbols"))
    if actual_symbols != symbols:
        actual_map = {row["symbol"]: row for row in actual_symbols}
        expected_map = {row["symbol"]: row for row in symbols}
        missing = sorted(set(expected_map) - set(actual_map))
        extra = sorted(set(actual_map) - set(expected_map))
        changed = sorted(
            symbol for symbol in set(actual_map) & set(expected_map)
            if actual_map[symbol] != expected_map[symbol]
        )
        raise SystemExit(
            "runtime_symbols drift: "
            f"missing={missing[:20]} extra={extra[:20]} changed={changed[:20]}"
        )

    print(
        f"mad4b.mcp-runtime-surface.v1: PASS "
        f"version={args.version} files={surface['file_count']} symbols={surface['symbol_count']} "
        f"tree={surface['tree_sha256']}"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
