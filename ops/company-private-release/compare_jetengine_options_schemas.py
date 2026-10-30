#!/usr/bin/env python3
"""Compare registered JetEngine Options Page schemas, never field values.

Input: outputs of audit_jetengine_options_schema.php. No WordPress write.
Schema differences become an additive plan, not an automatic import.
"""
import argparse
import json
from pathlib import Path
import sys

CONTRACT="mad4b.jetengine.options-page-schema-audit.v1"

def compare(target, reference):
    for source in [target,reference]:
        if source.get("contract")!=CONTRACT or source.get("stored_option_values_exported") is not False or source.get("mutation_performed") is not False:
            raise ValueError("Untrusted schema audit format")
        if source.get("field_defaults_exported") is not False:
            raise ValueError("Schema audit contains defaults; fail closed")
    target_pages=target.get("pages",{})
    source_pages=reference.get("pages",{})
    names=sorted(set(target_pages)|set(source_pages))
    results=[]
    can_merge=True
    for slug in names:
        a=target_pages.get(slug)
        b=source_pages.get(slug)
        if not a or not b:
            results.append({"page_slug":slug,"status":"PAGE_REGISTRATION_MISSING","requires_manual_registration":True})
            can_merge=False
            continue
        x={p["name"]:p for p in a["fields"]}
        y={p["name"]:p for p in b["fields"]}
        if len(x)!=len(a["fields"]) or len(y)!=len(b["fields"]):
            raise ValueError("Duplicate or ambiguous field name")
        conflicts=[]
        for field in x.keys() & y.keys():
            if (x[field]["type"],x[field].get("has_repeater_fields")) != (y[field]["type"],y[field].get("has_repeater_fields")):
                conflicts.append(field)
        if conflicts:can_merge=False
        additive=[
          {"field":k,"type":y[k]["type"],"default_from_reference":False,"value_from_reference":False}
          for k in sorted(y.keys()-x.keys())
        ]
        results.append({
            "page_slug":slug,
            "target_field_count":len(x),
            "reference_field_count":len(y),
            "shared_field_count":len(x.keys()&y.keys()),
            "additive_fields":additive,
            "target_only_fields":sorted(x.keys()-y.keys()),
            "type_conflicts":sorted(conflicts),
            "status":"SCHEMA_ADDITIVE_CANDIDATE" if additive and not conflicts else ("SCHEMA_CONFLICT" if conflicts else "SCHEMA_PARITY")
        })
    return {"contract":"mad4b.jetengine.schema-only-compare.v1",
       "target_tenant":target["tenant"],"reference_tenant":reference["tenant"],
       "schema_structure_can_be_reviewed":can_merge,
       "all_existing_target_field_values_preserved":True,
       "donor_saved_values_included":False,
       "applied_to_wordpress":False,
       "production_mutated":False,
       "release_allowed":False,
       "pages":results}

def main():
    p=argparse.ArgumentParser()
    p.add_argument("--target",required=True,type=Path)
    p.add_argument("--reference",required=True,type=Path)
    p.add_argument("--output",type=Path)
    args=p.parse_args()
    result=compare(json.loads(args.target.read_text()),json.loads(args.reference.read_text()))
    rendered=json.dumps(result,ensure_ascii=False,indent=2)+"\n"
    if args.output:
        if args.output.exists():raise ValueError("Refuse to overwrite reviewed plan")
        args.output.write_text(rendered)
    print(rendered)
    return 0 if result["schema_structure_can_be_reviewed"] else 2

if __name__=="__main__":
    try:sys.exit(main())
    except (ValueError,OSError,KeyError,TypeError,json.JSONDecodeError) as exc:
        print("SCHEMA_COMPARE_BLOCKED: "+str(exc),file=sys.stderr)
        sys.exit(3)
