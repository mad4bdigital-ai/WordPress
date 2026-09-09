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


def source_evidence(path: Path, root: Path, tokens, limit=120) -> list:
    if not path.is_file():
        return []
    text = path.read_text("utf-8", errors="replace")
    out = []
    lowered_tokens = [token.lower() for token in tokens]
    for idx, line in enumerate(text.splitlines(), 1):
        lowered = line.lower()
        if any(token in lowered for token in lowered_tokens):
            out.append({
                "file": path.relative_to(root).as_posix(),
                "line": idx,
                "text": line.strip()[:500],
            })
            if len(out) >= limit:
                break
    return out


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


def parse_php_class_declarations(root: Path) -> list:
    class_index = []
    for path in sorted(root.rglob("*.php")):
        rel = path.relative_to(root).as_posix()
        text = path.read_text("utf-8", errors="replace")
        ns_match = re.search(r"^\s*namespace\s+([^;]+);", text, re.M)
        namespace = ns_match.group(1).strip() if ns_match else ""
        for kind, name in re.findall(r"^\s*(?:final\s+|abstract\s+)?(class|interface|trait)\s+([A-Za-z_][A-Za-z0-9_]*)", text, re.M):
            fqcn = (namespace + "\\" + name) if namespace else name
            class_index.append({"file": rel, "kind": kind, "fqcn": fqcn})
    return class_index


def scan_composer_autoload(root: Path) -> dict:
    composer_files = []
    psr4 = {}
    for path in sorted(root.rglob("composer.json")):
        if len(composer_files) >= 50:
            break
        rel = path.relative_to(root).as_posix()
        try:
            data = json.loads(path.read_text("utf-8", errors="replace"))
        except (json.JSONDecodeError, OSError):
            continue
        autoload = data.get("autoload") if isinstance(data, dict) else None
        entry = {"file": rel, "autoload": autoload if isinstance(autoload, dict) else {}}
        composer_files.append(entry)
        if isinstance(autoload, dict) and isinstance(autoload.get("psr-4"), dict):
            for prefix, target in autoload["psr-4"].items():
                psr4[str(prefix)] = target

    generated_psr4 = []
    for path in sorted(root.rglob("autoload_psr4.php")):
        generated_psr4.extend(
            source_evidence(path, root, ["BitApps\\\\Pi", "BitApps\\Pi", "Pi\\\\"], limit=80)
        )
        if len(generated_psr4) >= 160:
            break

    return {
        "composer_files": composer_files,
        "declared_psr4": psr4,
        "generated_psr4_evidence": generated_psr4[:160],
    }


def scan_bitflows(root: Path) -> dict:
    class_index = parse_php_class_declarations(root)
    class_by_fqcn = {item["fqcn"]: item for item in class_index}
    required_classes = [
        "BitApps\\Pi\\Model\\Flow",
        "BitApps\\Pi\\Model\\FlowNode",
        "BitApps\\Pi\\Model\\FlowHistory",
        "BitApps\\Pi\\src\\Flow\\FlowExecutor",
    ]
    required_class_files = {
        fqcn: class_by_fqcn.get(fqcn)
        for fqcn in required_classes
    }

    mcp_files = []
    route_evidence = []
    mcp_evidence = []
    server_registration_evidence = []
    bootstrap_evidence = []
    bootstrap_tokens = [
        "require", "include", "autoload", "add_action", "plugins_loaded", "init",
        "BITPI_VERSION", "BIT_PI_VERSION", "BitApps\\Pi", "load_plugin_textdomain",
    ]
    mcp_server_tokens = [
        "wp_register_ability", "create_server", "mcp_adapter_init", "register_rest_route",
        "mcpserver", "mcp server", "toolscall", "tools/list",
    ]

    priority_names = {
        "bit-pi.php", "bootstrap.php", "autoload.php", "app.php", "plugin.php",
        "loader.php", "application.php", "container.php",
    }

    for path in sorted(root.rglob("*.php")):
        rel = path.relative_to(root).as_posix()
        text = path.read_text("utf-8", errors="replace")
        lower_rel = rel.lower()
        lowered_text = text.lower()

        if "mcp" in lower_rel or "mcp" in lowered_text:
            mcp_files.append(rel)
            for idx, line in enumerate(text.splitlines(), 1):
                lowered = line.lower()
                if any(token.lower() in lowered for token in mcp_server_tokens):
                    mcp_evidence.append({"file": rel, "line": idx, "text": line.strip()[:500]})

        if "register_rest_route" in text:
            for idx, line in enumerate(text.splitlines(), 1):
                if "register_rest_route" in line:
                    route_evidence.append({"file": rel, "line": idx, "text": line.strip()[:500]})

        if any(token.lower() in lowered_text for token in mcp_server_tokens):
            for idx, line in enumerate(text.splitlines(), 1):
                lowered = line.lower()
                if any(token.lower() in lowered for token in mcp_server_tokens):
                    server_registration_evidence.append({"file": rel, "line": idx, "text": line.strip()[:500]})
                    if len(server_registration_evidence) >= 300:
                        break

        if path.name.lower() in priority_names or len(path.relative_to(root).parts) <= 2:
            bootstrap_evidence.extend(source_evidence(path, root, bootstrap_tokens, limit=80))
            if len(bootstrap_evidence) >= 400:
                bootstrap_evidence = bootstrap_evidence[:400]

    main_file = root / "bit-pi.php"
    main_file_evidence = source_evidence(main_file, root, bootstrap_tokens, limit=200)

    return {
        "mcp_files": sorted(set(mcp_files)),
        "class_index": class_index[:1500],
        "required_classes": required_classes,
        "required_class_files": required_class_files,
        "required_classes_present": all(required_class_files.values()),
        "mcp_evidence": mcp_evidence[:500],
        "rest_route_evidence": route_evidence[:300],
        "potential_server_registration_evidence": server_registration_evidence[:300],
        "main_file_bootstrap_evidence": main_file_evidence,
        "bootstrap_evidence": bootstrap_evidence[:400],
        "autoload": scan_composer_autoload(root),
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
        "contract": "mad4b.provider-refresh-public-discovery.v2",
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
        "bit_pi_required_classes_present": result["bit_pi"]["runtime_contract_discovery"]["required_classes_present"],
        "bit_pi_required_class_files": result["bit_pi"]["runtime_contract_discovery"]["required_class_files"],
    }, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
