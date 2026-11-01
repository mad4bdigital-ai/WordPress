#!/usr/bin/env python3
"""Deterministic QA-only plugin archives plus dependency hashes (NOT production approval)."""
from pathlib import Path
import hashlib
import json
import sys
import zipfile

ROOT = Path(__file__).resolve().parents[2]
PLUGINS = ROOT / "wp-content/plugins"
OUT = Path(sys.argv[1]) if len(sys.argv) > 1 else ROOT / "dist/country-nationality"
OUT.mkdir(parents=True, exist_ok=True)
result = {"status": "qa_candidate_not_production_certified", "plugins": {}, "control_plane_sha256": {}}
for name in ("mad4b-country-switcher", "etg-traveller-nationality-switcher"):
    root = PLUGINS / name
    archive = OUT / f"{name}-qa.zip"
    with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for path in sorted(p for p in root.rglob("*") if p.is_file()):
            info = zipfile.ZipInfo(str(path.relative_to(PLUGINS)).replace("\\", "/"), (2026, 10, 25, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = (0o100644 << 16)
            z.writestr(info, path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    result["plugins"][name] = {"zip": archive.name, "sha256": hashlib.sha256(archive.read_bytes()).hexdigest()}
for rel in ("mad4b-site-control-plane.php", "includes/class-mad4b-scp-market-profiles.php",
            "includes/class-mad4b-scp-market-context.php", "includes/class-mad4b-scp-market-preferences.php",
            "includes/class-mad4b-scp-switcher-sources.php", "data/market-language-territories.json"):
    f = PLUGINS / "mad4b-site-control-plane" / rel
    result["control_plane_sha256"][rel] = hashlib.sha256(f.read_bytes()).hexdigest()
(OUT / "qa-dependency-manifest.json").write_text(json.dumps(result, indent=2, sort_keys=True) + "\n")
print(json.dumps(result, indent=2))
