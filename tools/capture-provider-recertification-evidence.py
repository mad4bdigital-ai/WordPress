#!/usr/bin/env python3
import hashlib
import json
import re
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLUGIN_ROOT = ROOT / "wp-content/plugins"
CONFIG = PLUGIN_ROOT / "mad4b-site-control-plane/config/certified-providers.json"
OUT = Path(sys.argv[1]) if len(sys.argv) > 1 else ROOT / "provider-recertification-evidence.json"

contract = json.loads(CONFIG.read_text(encoding="utf-8"))
providers = contract["providers"]

SPEC = {
    "jetengine": {
        "archive": "jet-engine.zip",
        "entry_suffix": "jet-engine.php",
        "baseline": providers["jetengine"],
        "authority_gate": "premium_semantic_attestation_required",
    },
    "jetsmartfilters": {
        "archive": "jet-smart-filters.zip",
        "entry_suffix": "jet-smart-filters.php",
        "baseline": providers["jetsmartfilters"],
        "authority_gate": "premium_semantic_attestation_required",
    },
    "bit_pi": {
        "archive": "bit-pi.zip",
        "entry_suffix": "bit-pi.php",
        "baseline": providers["bit_pi"],
        "authority_gate": "behavioral_recertification_required",
    },
}

def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()

def version_from_header(raw: bytes, label: str) -> str:
    text = raw.decode("utf-8", errors="replace")[:65536]
    m = re.search(r"^\s*\*?\s*Version:\s*([^\r\n]+)", text, re.I | re.M)
    if not m:
        raise SystemExit(f"{label}: Version header not found")
    return m.group(1).strip()

def find_member(names, entry_suffix):
    candidates = [n for n in names if n == entry_suffix or n.endswith("/" + entry_suffix)]
    candidates = [n for n in candidates if "__MACOSX/" not in n]
    if len(candidates) != 1:
        raise SystemExit(f"{entry_suffix}: expected one main plugin entry, got {candidates}")
    return candidates[0]

def inspect_archive(provider_id, archive_name, entry_suffix, baseline):
    path = PLUGIN_ROOT / archive_name
    raw_archive = path.read_bytes()
    with zipfile.ZipFile(path) as zf:
        names = [n for n in zf.namelist() if not n.endswith("/")]
        entry = find_member(names, entry_suffix)
        prefix = entry.rsplit("/", 1)[0] + "/" if "/" in entry else ""
        version = version_from_header(zf.read(entry), provider_id)
        critical = {}
        missing = []
        for rel in baseline.get("critical_files", {}):
            exact = prefix + rel
            if exact in names:
                critical[rel] = sha256(zf.read(exact))
                continue
            suffix_matches = [n for n in names if n.endswith("/" + rel) or n == rel]
            if len(suffix_matches) == 1:
                critical[rel] = sha256(zf.read(suffix_matches[0]))
            else:
                missing.append(rel)
    baseline_hashes = baseline.get("critical_files", {})
    mismatched = sorted(
        rel for rel, digest in critical.items()
        if baseline_hashes.get(rel) and baseline_hashes.get(rel) != digest
    )
    changed = sorted(set(mismatched) | set(missing))
    return {
        "archive": archive_name,
        "archive_sha256": sha256(raw_archive),
        "archive_bytes": len(raw_archive),
        "plugin_entry": entry,
        "version": version,
        "baseline_version": baseline.get("version", ""),
        "version_drift": version != baseline.get("version", ""),
        "critical_file_count": len(critical),
        "critical_files": critical,
        "critical_files_missing": missing,
        "critical_files_changed_from_baseline": changed,
        "critical_manifest_complete": not missing,
        "baseline_archive_sha256": baseline.get("archive_sha256", ""),
        "archive_changed_from_baseline": sha256(raw_archive) != baseline.get("archive_sha256", ""),
    }

evidence = {
    "contract": "mad4b.provider-recertification-evidence.v1",
    "source_commit_sha": "",
    "providers": {},
}

for provider_id, spec in SPEC.items():
    item = inspect_archive(
        provider_id,
        spec["archive"],
        spec["entry_suffix"],
        spec["baseline"],
    )
    item["authority_gate"] = spec["authority_gate"]
    evidence["providers"][provider_id] = item

composite = providers["wp-import-export"]
components = {}
for component_id, component in composite["components"].items():
    item = inspect_archive(
        f"wp-import-export:{component_id}",
        component["archive"],
        Path(component["plugin_file"]).name,
        component,
    )
    item["authority_gate"] = "composite_behavioral_recertification_required"
    components[component_id] = item

evidence["providers"]["wp-import-export"] = {
    "contract_mode": composite.get("contract_mode"),
    "authority_gate": "composite_behavioral_recertification_required",
    "components": components,
    "component_drift": any(
        x["version_drift"] or x["archive_changed_from_baseline"] or x["critical_files_changed_from_baseline"]
        for x in components.values()
    ),
}

OUT.parent.mkdir(parents=True, exist_ok=True)
OUT.write_text(json.dumps(evidence, indent=2, sort_keys=True) + "\n", encoding="utf-8")

for provider_id, item in evidence["providers"].items():
    if provider_id == "wp-import-export":
        for component_id, component in item["components"].items():
            print(
                f"{provider_id}:{component_id}: "
                f"version={component['version']} baseline={component['baseline_version']} "
                f"archive_sha256={component['archive_sha256']} "
                f"critical={component['critical_file_count']} "
                f"missing={len(component['critical_files_missing'])} "
                f"changed={len(component['critical_files_changed_from_baseline'])}"
            )
    else:
        print(
            f"{provider_id}: version={item['version']} baseline={item['baseline_version']} "
            f"archive_sha256={item['archive_sha256']} critical={item['critical_file_count']} "
            f"missing={len(item['critical_files_missing'])} "
            f"changed={len(item['critical_files_changed_from_baseline'])}"
        )
print(f"evidence={OUT}")
