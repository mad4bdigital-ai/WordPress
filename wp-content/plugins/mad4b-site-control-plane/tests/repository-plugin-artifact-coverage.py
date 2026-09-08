#!/usr/bin/env python3
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLUGINS = ROOT.parent
MANIFEST = ROOT / "config" / "repository-plugin-artifacts.json"
CATALOG = ROOT / "config" / "adapter-support-catalog.json"
FAMILY_ADAPTER = ROOT / "includes" / "adapters" / "class-mad4b-scp-repository-family-adapter.php"
BOOTSTRAP = ROOT / "mad4b-site-control-plane.php"


def require(condition, message):
    if not condition:
        raise SystemExit(message)


def family_for_archive(name, catalog):
    synthetic = name[:-4] + "/" if name.endswith(".zip") else name
    for family in catalog.get("families", []):
        for prefix in family.get("match", []):
            if synthetic.startswith(prefix):
                return family
    return catalog.get("default", {})


def main():
    manifest = json.loads(MANIFEST.read_text(encoding="utf-8"))
    catalog = json.loads(CATALOG.read_text(encoding="utf-8"))
    adapter_source = FAMILY_ADAPTER.read_text(encoding="utf-8")
    bootstrap = BOOTSTRAP.read_text(encoding="utf-8")

    require(manifest.get("contract") == "mad4b.repository-plugin-artifacts.v1", "repository artifact manifest contract drift")
    require(manifest.get("authority") == "inventory_only", "repository artifact manifest must remain inventory-only")
    require(manifest.get("unknown_artifact_policy") == "ci_fail_closed", "unknown repository artifacts must fail CI closed")
    require(manifest.get("runtime_write_default") == "deny", "repository artifact runtime write default must remain deny")

    families = manifest.get("families", {})
    require(isinstance(families, dict) and families, "repository artifact family manifest is empty")
    flattened = []
    owner = {}
    for family_id, descriptor in families.items():
        require(isinstance(descriptor, dict), f"invalid family descriptor: {family_id}")
        require(descriptor.get("support_mode"), f"support mode missing: {family_id}")
        require(descriptor.get("mutation_scope"), f"mutation scope missing: {family_id}")
        artifacts = descriptor.get("artifacts", [])
        require(isinstance(artifacts, list) and artifacts, f"artifact list missing: {family_id}")
        for artifact in artifacts:
            require(isinstance(artifact, str) and artifact, f"invalid artifact in {family_id}")
            require(artifact not in owner, f"artifact assigned to multiple adapters: {artifact} => {owner.get(artifact)}, {family_id}")
            owner[artifact] = family_id
            flattened.append(artifact)

    repository_artifacts = sorted([p.name for p in PLUGINS.glob("*.zip")] + ["hello.php", "mad4b-site-control-plane"])
    manifest_artifacts = sorted(flattened)
    missing = sorted(set(repository_artifacts) - set(manifest_artifacts))
    stale = sorted(set(manifest_artifacts) - set(repository_artifacts))
    require(not missing, "repository plugin artifacts missing adapter mapping: " + ", ".join(missing))
    require(not stale, "adapter manifest contains stale/non-repository artifacts: " + ", ".join(stale))
    require(len(repository_artifacts) == len(manifest_artifacts), "repository artifact manifest cardinality mismatch")

    require("class MAD4B_SCP_Repository_Family_Adapter" in adapter_source, "generic repository family adapter missing")
    require("class MAD4B_SCP_Repository_Plugins_Adapter" in adapter_source, "repository inventory adapter missing")
    for marker in ["repository-plugins/inventory", "repository-plugins/get-artifact", "read_only_non_authorizing", "'mutation_exposed'=>false"]:
        require(marker in adapter_source, f"repository adapter safety marker missing: {marker}")
    require("class-mad4b-scp-repository-family-adapter.php" in bootstrap, "repository family adapter is not loaded by plugin bootstrap")

    unresolved = []
    catalog_rows = []
    for artifact in sorted(p.name for p in PLUGINS.glob("*.zip")):
        family = family_for_archive(artifact, catalog)
        strategy = family.get("strategy", "adapter_required")
        adapter_id = family.get("adapter_id", "")
        catalog_rows.append({"artifact": artifact, "family": family.get("id", "unknown"), "strategy": strategy, "adapter_id": adapter_id})
        if strategy == "adapter_required" or not adapter_id:
            unresolved.append(artifact)
    require(not unresolved, "repository archives still require unimplemented adapters: " + ", ".join(unresolved))

    high_risk = {"better-search-replace.zip", "code-snippets.zip", "custom-css-js.zip", "user-role-editor.zip", "wp-file-manager.zip"}
    for artifact in high_risk:
        family = family_for_archive(artifact, catalog)
        require(family.get("strategy") == "excluded_high_risk", f"high-risk artifact escaped exclusion: {artifact}")
        require(family.get("mutation_scope") == "breakglass_review_only", f"high-risk artifact mutation scope drift: {artifact}")

    evidence = {
        "contract": "mad4b.repository-plugin-artifact-coverage.v2",
        "repository_artifact_count": len(repository_artifacts),
        "manifest_artifact_count": len(manifest_artifacts),
        "family_count": len(families),
        "unresolved_adapter_count": len(unresolved),
        "runtime_write_default": "deny",
        "unknown_repository_artifact_policy": "ci_fail_closed",
        "high_risk_normal_write_allowed": False,
        "catalog": catalog_rows,
    }
    print(json.dumps(evidence, indent=2, sort_keys=True))
    print(f"mad4b.repository-plugin-artifact-coverage.v2: PASS artifacts={len(repository_artifacts)} families={len(families)} unresolved=0")


if __name__ == "__main__":
    main()
