#!/usr/bin/env python3
"""Contract checks for schema-first activation and on-difference escalation."""
import copy
import importlib.util
from pathlib import Path

ROOT = Path(__file__).resolve().parent
def load(name, path):
    spec=importlib.util.spec_from_file_location(name,str(path))
    obj=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(obj)
    return obj

def test():
    compat=load("compat",ROOT/"wayfa-schema-compatibility.py")
    resolver=load("resolver",ROOT/"wayfa-template-diff-resolver.py")
    source={"origin":"https://tours.dev.mad4b.com","database":"etg_commerce_staging",
      "tables":{"jet_post_types":{"count":307,"sha256":"a"*64},
                "jet_taxonomies":{"count":34,"sha256":"b"*64}},
      "providers":{"jet-engine":True,"elementor":True,"elementor-pro":True,
                    "jet-smart-filters":True},
      "registered_post_types":{p:True for p in
        ["jet-engine","elementor_library","tours-and-activities","properties","transportations"]}}
    target=copy.deepcopy(source)
    target["origin"]="https://wayfatours.mad4b.com"
    target["database"]="wayfatours_production"
    assert compat.compare(source,target)=={}
    drift=copy.deepcopy(target)
    drift["tables"]["jet_post_types"]["sha256"]="0"*64
    assert "jet_post_types" in compat.compare(source,drift)
    drift=copy.deepcopy(target)
    drift["providers"]["jet-engine"]=False
    assert compat.compare(source,drift)["provider:jet-engine"]=="MISSING_ACTIVE_PROVIDER"
    schema={"state":"SCHEMA_PARITY_CONFIRMED","diffs":{}}
    result=resolver.run(schema,{"unresolved_source_brand":5,"unmapped_media_asset":3},{})
    assert result["state"]=="DIFFERENCES_DETECTED"
    assert result["production_mutated"] is False
    assert len(result["resolutions"])==2
    assert {x["resolver"] for x in result["resolutions"]}=={
       "company-brand-field-map","media-provenance-asset-map"}
    assert resolver.run(schema,{}, {})["state"]=="NO_DIFFERENCES"
    print("PASS: schema parity, provider drift, source diff routing and no-mutation resolver")

if __name__=="__main__":test()
