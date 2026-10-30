#!/usr/bin/env python3
"""Read-only second-stage resolver for actual Company configuration differences."""
import json
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path("/opt/mad4b-company-provisioning")
ROUTES = {
    "source_brand": "company-brand-field-map",
    "brand": "company-brand-field-map",
    "media": "media-provenance-asset-map",
    "attachment": "media-provenance-asset-map",
    "source_host": "tenant-internal-url-map",
    "condition": "elementor-display-condition-map",
    "widget": "provider-and-form-binding",
    "query": "jetengine-query-binding",
    "schema": "schema-source-reconciliation",
    "not_eligible_source_state": "source-publication-preservation",
}
def route(reason):
    for fragment, handler in ROUTES.items():
        if fragment in reason:
            return handler
    return "dependency-specific-resolution"

def run(schema, template_reasons, jet_reasons):
    issues = []
    for key, diff in schema.get("diffs", {}).items():
        issues.append({"component":"schema","reason":key,"resolver":"schema-source-reconciliation",
                       "evidence":diff,"state":"MAPPING_REQUIRED"})
    for component, reasons in (("elementor",template_reasons),("jet-engine",jet_reasons)):
        for reason, count in sorted(reasons.items()):
            if count:
                issues.append({"component":component,"reason":reason,"count":count,
                               "resolver":route(reason),"state":"MAPPING_REQUIRED"})
    return {"contract":"mad4b.company-compatibility-resolution.v1",
            "state":"DIFFERENCES_DETECTED" if issues else "NO_DIFFERENCES",
            "mode":"on-difference","site":"wayfatours.mad4b.com",
            "resolutions":issues,"automatic_safe_rules":["matching_schema_use_existing_ids",
            "verified_WPML_tenant_group","verified_tenant_url"],
            "media_requires_provenance":True,"production_mutated":False,
            "generated_utc":datetime.now(timezone.utc).isoformat()}

if __name__=="__main__":
    schema=json.loads((ROOT/"reports/wayfatours-schema-compatibility.json").read_text())
    t=ROOT/"reports/wayfatours-design-activation.json"
    j=ROOT/"reports/wayfatours-jet-config-autoload.json"
    t_reasons=(json.loads(t.read_text()).get("result") or {}).get("blocked_reasons",{}) if t.exists() else {}
    j_reasons=(json.loads(j.read_text()).get("result") or {}).get("blocked_reason_counts",{}) if j.exists() else {}
    report=run(schema,t_reasons,j_reasons)
    dest=ROOT/"reports/wayfatours-compatibility-diff-resolution.json"
    dest.write_text(json.dumps(report,indent=2,ensure_ascii=False)+"\n")
    print(json.dumps({"state":report["state"],"issues":len(report["resolutions"]),
                       "production_mutated":False}))
