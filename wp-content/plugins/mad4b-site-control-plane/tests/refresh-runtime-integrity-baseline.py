#!/usr/bin/env python3
"""Refresh runtime critical-file manifests from exact certified provider ZIPs.

This is an operator/repository maintenance tool. It never executes provider PHP.
It derives critical candidates from the existing packaged-provider scanner's
contract evidence + native MCP inventory, then records SHA-256 values relative
to each installed plugin root.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import tempfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
SCANNER_PATH = HERE / "scan-packaged-providers.py"

spec = importlib.util.spec_from_file_location("mad4b_provider_scanner", SCANNER_PATH)
scanner = importlib.util.module_from_spec(spec)
assert spec and spec.loader
spec.loader.exec_module(scanner)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--plugins-dir", default="wp-content/plugins")
    parser.add_argument("--baseline", default="wp-content/plugins/mad4b-site-control-plane/config/certified-providers.json")
    args = parser.parse_args()

    plugins_dir = Path(args.plugins_dir).resolve()
    baseline_path = Path(args.baseline).resolve()
    baseline = json.loads(baseline_path.read_text("utf-8"))
    providers = baseline.get("providers") or {}

    with tempfile.TemporaryDirectory(prefix="mad4b-runtime-integrity-") as tmp:
        temp_root = Path(tmp)
        for provider, cfg in scanner.PROVIDERS.items():
            if provider not in providers:
                raise RuntimeError(f"Provider {provider} is missing from certified baseline")
            result = scanner.scan_provider(provider, cfg, plugins_dir, temp_root)
            if not result.get("present") or not result.get("required_contracts_pass"):
                raise RuntimeError(f"Provider {provider} packaged contract is not certifiable: {result.get('missing_required')}")

            headers = result.get("plugin_headers") or []
            if not headers:
                raise RuntimeError(f"Provider {provider} has no plugin header")
            primary_header = Path(headers[0]["file"])
            package_root = primary_header.parent
            extract_dir = temp_root / provider

            candidates = {primary_header.as_posix()}
            for evidence in (result.get("contracts") or {}).values():
                for hit in evidence.get("hits") or []:
                    if hit.get("file"):
                        candidates.add(str(hit["file"]))
            for rel in ((result.get("native_mcp") or {}).get("files") or []):
                candidates.add(str(rel))

            # MCP 0.6.1 normalizes an MCP client's empty {} to null for abilities
            # without input schemas. Runtime acceptance depends on this exact file,
            # so its deployed bytes are part of the fail-closed transport contract.
            if provider == "mcp_adapter":
                candidates.add(
                    (package_root / "includes/Domain/Utils/AbilityArgumentNormalizer.php").as_posix()
                )

            manifest = {}
            prefix = package_root.as_posix().rstrip("/")
            for candidate in sorted(candidates):
                candidate_path = Path(candidate)
                if package_root != Path("."):
                    try:
                        relative = candidate_path.relative_to(package_root)
                    except ValueError:
                        continue
                else:
                    relative = candidate_path
                source = extract_dir / candidate_path
                if not source.is_file():
                    raise RuntimeError(f"Critical candidate disappeared: {provider}:{candidate}")
                manifest[relative.as_posix()] = scanner.sha256(source)

            if not manifest:
                raise RuntimeError(f"Provider {provider} produced an empty runtime integrity manifest")
            providers[provider]["require_runtime_integrity"] = True
            providers[provider]["critical_files"] = manifest

    baseline_path.write_text(json.dumps(baseline, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print("mad4b.site-control-plane.runtime-integrity-baseline.v1: refreshed")
    for provider, data in providers.items():
        print(f"{provider}: {len(data.get('critical_files') or {})} critical files")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
