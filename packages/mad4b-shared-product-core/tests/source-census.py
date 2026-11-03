#!/usr/bin/env python3
"""Deterministic source-only engine inventory. No filesystem/network writes."""
import argparse
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
SOURCE = ROOT / "packages" / "mad4b-shared-product-core" / "mu-plugins"
CORE = SOURCE / "shared-product-core"
PLUGINS = ROOT / "wp-content" / "plugins"

ENGINE_AREAS = {
    "pricing": CORE / "pricing",
    "scheduling": CORE / "scheduling",
    "admin": CORE / "admin",
    "adapters": CORE / "adapters",
    "contracts": CORE / "contracts",
    "tests": CORE / "tests",
    "assets": CORE / "assets",
}
VENDOR_NAMES = ["woocommerce", "jet-engine", "elementor", "fluentform"]
OWNED_REGISTRIES = [
    "mad4b-pricing-registry",
    "mad4b-age-occupancy-registry",
    "mad4b-site-control-plane",
]
SITE_IDS = re.compile(
    r"""(?:template_id|listing_id|query_id|form_id)\s*['"]?\s*(?:=>|:)\s*[1-9][0-9]{2,}""",
    re.IGNORECASE,
)

def produce():
    areas = {}
    flagged = []
    for name, directory in ENGINE_AREAS.items():
        found = sorted(directory.rglob("*")) if directory.exists() else []
        files = [p for p in found if p.is_file()]
        areas[name] = len(files)
        for path in files:
            if path.suffix != ".php":
                continue
            body = path.read_text(encoding="utf-8", errors="replace")
            reasons = []
            if "SHARED_PRODUCT_LAB_ONLY" in body:
                reasons.append("lab_flag")
            if "wp_get_environment_type()" in body and "'local'" in body:
                reasons.append("environment_local")
            if SITE_IDS.search(body):
                reasons.append("possible_site_bound_numeric_id")
            if reasons:
                flagged.append({"file": path.relative_to(ROOT).as_posix(), "flags": reasons})
    return {
        "contract": "mad4b.commerce.source-census.v1",
        "source_exists": CORE.is_dir(),
        "areas": areas,
        "source_only_file_count": sum(1 for p in SOURCE.rglob("*") if p.is_file()),
        "source_flags": flagged,
        "known_plugin_dependencies": [
            {"name": name, "source_dir_present": (PLUGINS / name).is_dir()}
            for name in OWNED_REGISTRIES
        ],
        "vendor_components_not_bundled": VENDOR_NAMES,
        "cptwoo_owner_verified": False,
        "customer_data_included": False,
        "safe_to_auto_deploy": False,
    }

if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--release", action="store_true")
    args = parser.parse_args()
    data = produce()
    print(json.dumps(data, indent=2, ensure_ascii=False, sort_keys=True))
    if args.release and (
        not data["source_exists"]
        or data["source_flags"]
        or not data["cptwoo_owner_verified"]
    ):
        raise SystemExit("COMMERCE_TENANT_RELEASE_BLOCKED")
