#!/usr/bin/env python3
import hashlib
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CONFIG = ROOT / "wp-content/plugins/mad4b-site-control-plane/config/certified-providers.json"


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def plugin_version(plugin_file: Path) -> str:
    text = plugin_file.read_text("utf-8", errors="replace")[:32768]
    m = re.search(r"^\s*\*?\s*Version:\s*([^\r\n]+)", text, re.I | re.M)
    return m.group(1).strip() if m else ""


def critical_snapshot(provider: str, plugin_root: Path, contract: dict) -> dict:
    hashes = {}
    missing = []
    for rel in sorted((contract.get("critical_files") or {}).keys()):
        path = plugin_root / rel
        if path.is_file():
            hashes[rel] = sha256(path)
        else:
            missing.append(rel)
    return {"hashes": hashes, "missing": missing}


def scan_elementor(root: Path) -> dict:
    mcp_root = root / "modules/mcp"
    ability_ids = set()
    registrations = []
    files = []
    if mcp_root.is_dir():
        for path in sorted(mcp_root.rglob("*.php")):
            rel = path.relative_to(root).as_posix()
            files.append(rel)
            text = path.read_text("utf-8", errors="replace")
            ability_ids.update(re.findall(r"['\"](elementor/[a-z0-9][a-z0-9_-]*)['\"]", text, re.I))
            for idx, line in enumerate(text.splitlines(), 1):
                if "wp_register_ability" in line or "register_ability" in line or "ability_name" in line:
                    registrations.append({"file": rel, "line": idx, "text": line.strip()[:500]})
    return {
        "mcp_files": files,
        "ability_ids": sorted(ability_ids),
        "registration_evidence": registrations[:200],
    }


def scan_bitflows(root: Path) -> dict:
    mcp_files = []
    class_index = []
    route_evidence = []
    mcp_evidence = []
    for path in sorted(root.rglob("*.php")):
        rel = path.relative_to(root).as_posix()
        text = path.read_text("utf-8", errors="replace")
        ns_match = re.search(r"^\s*namespace\s+([^;]+);", text, re.M)
        namespace = ns_match.group(1).strip() if ns_match else ""
        for kind, name in re.findall(r"^\s*(?:final\s+|abstract\s+)?(class|interface|trait)\s+([A-Za-z_][A-Za-z0-9_]*)", text, re.M):
            fqcn = (namespace + "\\" + name) if namespace else name
            if any(token in fqcn.lower() for token in ("flow", "mcp")):
                class_index.append({"file": rel, "kind": kind, "fqcn": fqcn})
        if "mcp" in rel.lower() or "mcp" in text.lower():
            mcp_files.append(rel)
            for idx, line in enumerate(text.splitlines(), 1):
                lowered = line.lower()
                if "register_rest_route" in lowered or "wp_register_ability" in lowered or "create_server" in lowered or "mcp" in lowered:
                    mcp_evidence.append({"file": rel, "line": idx, "text": line.strip()[:500]})
        if "register_rest_route" in text:
            for idx, line in enumerate(text.splitlines(), 1):
                if "register_rest_route" in line:
                    route_evidence.append({"file": rel, "line": idx, "text": line.strip()[:500]})
    return {
        "mcp_files": sorted(set(mcp_files)),
        "class_index": class_index[:500],
        "mcp_evidence": mcp_evidence[:500],
        "rest_route_evidence": route_evidence[:300],
    }


def main() -> int:
    if len(sys.argv) != 6:
        print("usage: discover-provider-refresh.py ELEMENTOR_ROOT ELEMENTOR_ZIP BITFLOWS_ROOT BITFLOWS_ZIP OUT_JSON", file=sys.stderr)
        return 2
    elementor_root = Path(sys.argv[1]).resolve()
    elementor_zip = Path(sys.argv[2]).resolve()
    bitflows_root = Path(sys.argv[3]).resolve()
    bitflows_zip = Path(sys.argv[4]).resolve()
    out = Path(sys.argv[5]).resolve()
    cfg = json.loads(CONFIG.read_text("utf-8"))
    providers = cfg["providers"]

    result = {
        "contract": "mad4b.provider-refresh-public-discovery.v1",
        "sources": {
            "elementor": "https://downloads.wordpress.org/plugin/elementor.4.2.4.zip",
            "bit_pi": "https://downloads.wordpress.org/plugin/bit-pi.1.29.0.zip",
        },
        "elementor": {
            "archive_sha256": sha256(elementor_zip),
            "installed_package_version": plugin_version(elementor_root / "elementor.php"),
            "critical_files": critical_snapshot("elementor", elementor_root, providers["elementor"]),
            "runtime_contract_discovery": scan_elementor(elementor_root),
        },
        "bit_pi": {
            "archive_sha256": sha256(bitflows_zip),
            "installed_package_version": plugin_version(bitflows_root / "bit-pi.php"),
            "critical_files": critical_snapshot("bit_pi", bitflows_root, providers["bit_pi"]),
            "runtime_contract_discovery": scan_bitflows(bitflows_root),
        },
    }
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", "utf-8")
    print(json.dumps({
        "contract": result["contract"],
        "elementor_version": result["elementor"]["installed_package_version"],
        "elementor_archive_sha256": result["elementor"]["archive_sha256"],
        "elementor_ability_ids": result["elementor"]["runtime_contract_discovery"]["ability_ids"],
        "bit_pi_version": result["bit_pi"]["installed_package_version"],
        "bit_pi_archive_sha256": result["bit_pi"]["archive_sha256"],
        "bit_pi_mcp_file_count": len(result["bit_pi"]["runtime_contract_discovery"]["mcp_files"]),
        "bit_pi_class_count": len(result["bit_pi"]["runtime_contract_discovery"]["class_index"]),
    }, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
