#!/usr/bin/env python3
"""Read-only source assembly audit; --release blocks missing source."""
import argparse
import re
from pathlib import Path


def inspect(root: Path) -> list[str]:
    loader = root / "mu-plugins" / "shared-product-core-loader.php"
    if not loader.is_file():
        return ["shared-product-core-loader.php"]
    content = loader.read_text()
    # A literal import must close immediately with a semicolon; a folder prefix
    # followed by a variable is not an import of the folder itself.
    required = re.findall(r"require_once\s+\$root\s*\.\s*'([^']+)'\s*;", content)
    # Recognize the source's bounded static foreach lists, without evaluating PHP.
    loops = re.findall(
        r"foreach\s*\(\s*\[(.*?)\]\s*as\s*\$([A-Za-z_][A-Za-z_0-9]*)\s*\)\s*"
        r"\{\s*require_once\s+\$root\s*\.\s*'([^']+)'\s*\.\s*\$([A-Za-z_][A-Za-z_0-9]*)\s*;",
        content, flags=re.S,
    )
    for items, variable, prefix, suffix_variable in loops:
        if variable != suffix_variable:
            continue
        required.extend(prefix + name for name in re.findall(r"'([^']+\.php)'", items))
    return sorted({
        part for part in required
        if not (loader.parent / "shared-product-core" / part).is_file()
    })


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--release", action="store_true", help="Fail on source assembly gaps")
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    missing = inspect(root)
    print(f"LOADER_SOURCE_ASSEMBLY missing={len(missing)}")
    for path in missing:
        print("MISSING " + path)
    if missing and args.release:
        raise SystemExit("RELEASE_BLOCKED: required source not present")
    if missing:
        print("AUDIT_ONLY: no release approval")
    else:
        print("STATIC_ASSEMBLY_OK: runtime parity and dynamic dependency review still required")
