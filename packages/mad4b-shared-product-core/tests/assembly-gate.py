#!/usr/bin/env python3
"""Read-only loader assembly audit; release mode blocks missing source."""
import argparse
import re
from pathlib import Path

def inspect(root: Path) -> list[str]:
    loader = root / "mu-plugins" / "shared-product-core-loader.php"
    if not loader.is_file():
        return ["shared-product-core-loader.php"]
    content = loader.read_text()
    # Only literal require_once $root.'...'; dynamic foreach requires manual review.
    required = re.findall(r"require_once\s+\$root\s*\.\s*'([^']+)'", content)
    missing = sorted({part for part in required if not (loader.parent / "shared-product-core" / part).is_file()})
    return missing

if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--release", action="store_true", help="Fail on source assembly gaps")
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    missing = inspect(root)
    print(f"LOADER_LITERAL_ASSEMBLY missing={len(missing)}")
    for path in missing:
        print("MISSING " + path)
    if missing and args.release:
        raise SystemExit("RELEASE_BLOCKED: required source not present")
    if missing:
        print("AUDIT_ONLY: no release approval")
    else:
        print("LITERAL_ASSEMBLY_OK: dynamic references and runtime parity still need separate certification")
