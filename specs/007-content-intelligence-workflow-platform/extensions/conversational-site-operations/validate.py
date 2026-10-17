#!/usr/bin/env python3
"""CSO01 offline spec integrity validator. Never connects to WordPress."""
from pathlib import Path
import json
import re
import sys

ROOT = Path(__file__).resolve().parent

def validate(root=ROOT):
    root = Path(root)
    faults = []
    def json_read(rel):
        try:
            return json.loads((root / rel).read_text(encoding="utf-8"))
        except (OSError, ValueError, TypeError):
            faults.append("invalid_json_or_missing:" + rel)
            return {}
    manifest = json_read("manifest.json")
    expected = {
        "contract": "mad4b.cso01.spec-kit.v1",
        "extension_id": "CSO01",
        "parent_feature_id": "007",
        "parent_integration_pr": 258,
        "status": "SPEC_BACKLOG_ONLY",
        "design_review_status": "OWNER_REVIEW_REQUIRED",
        "authorizing": False,
        "production_authorized": False,
        "release_closure_included": False,
        "spec_only": True,
        "execution_enabled": False,
        "mutations_enabled": False,
        "secret_collection_enabled": False,
        "external_credential_storage_claimed": False,
        "no_parent_task_denominator_changes": True,
    }
    for k, v in expected.items():
        if manifest.get(k) != v:
            faults.append("manifest_invariant:" + k)
    inventory = manifest.get("required_files", [])
    if not isinstance(inventory, list) or len(inventory) < 30 or len(inventory) != len(set(inventory)):
        faults.append("manifest_inventory_invalid")
        inventory = []
    for rel in inventory:
        if not isinstance(rel, str) or rel.startswith("/") or ".." in Path(rel).parts:
            faults.append("unsafe_inventory_path")
            continue
        target = root / rel
        if not target.is_file() or target.stat().st_size == 0:
            faults.append("missing_required_file:" + rel)
    for must in ("README.md","validate.py","test_validate.py","requirements.json","tasks.json",
                 "gates.json","abilities.json","use-cases.json","acceptance.md","traceability.md",
                 "contracts/secret-handoff.md","schemas/secret-handoff.schema.json"):
        if must not in inventory:
            faults.append("inventory_lacks:" + must)

    r = json_read("requirements.json")
    t = json_read("tasks.json")
    g = json_read("gates.json")
    a = json_read("abilities.json")
    u = json_read("use-cases.json")
    contracts = {
        "requirements": "mad4b.cso01.requirements.v1",
        "tasks": "mad4b.cso01.tasks.v1",
        "gates": "mad4b.cso01.gates.v1",
        "abilities": "mad4b.cso01.ability-catalog.v1",
        "use_cases": "mad4b.cso01.use-cases.v1",
    }
    for label, obj in (("requirements",r),("tasks",t),("gates",g),("abilities",a),("use_cases",u)):
        if obj.get("contract") != contracts[label]:
            faults.append("contract_invalid:" + label)
    requirements = r.get("requirements", [])
    tasks = t.get("tasks", [])
    gates = g.get("gates", [])
    abilities = a.get("abilities", [])
    use_cases = u.get("use_cases", [])
    for label, obj in (("requirements",requirements),("tasks",tasks),("gates",gates),
                       ("abilities",abilities),("use_cases",use_cases)):
        if not isinstance(obj,list):
            faults.append("not_array:" + label)
            return faults
    if any(manifest.get(k) != len(v) for k,v in (
        ("requirement_count",requirements),("task_count",tasks),("gate_count",gates),
        ("operation_count",abilities),("use_case_count",use_cases))):
        faults.append("registry_count_drift")

    def unique_ids(rows, field, label):
        ids = [x.get(field) for x in rows if isinstance(x,dict)]
        if len(ids)!=len(rows) or any(not isinstance(x,str) or not x for x in ids) or len(ids)!=len(set(ids)):
            faults.append("duplicate_or_invalid_ids:"+label)
        return set(x for x in ids if isinstance(x,str))
    rid=unique_ids(requirements,"id","requirements")
    tid=unique_ids(tasks,"id","tasks")
    gid=unique_ids(gates,"id","gates")
    uid=unique_ids(use_cases,"id","use_cases")
    abilities_ids=unique_ids(abilities,"ability","abilities")
    if not all(x.startswith("CSO-R") for x in rid): faults.append("requirement_id_family")
    if not all(x.startswith("CSO-T") for x in tid): faults.append("task_id_family")
    if not all(x.startswith("CSO-G") for x in gid): faults.append("gate_id_family")
    if not all(x.startswith("UC") for x in uid): faults.append("use_case_id_family")

    for row in requirements:
        if not isinstance(row,dict): continue
        if row.get("status")!="OPEN" or row.get("mutations_enabled") is not False:
            faults.append("requirement_false_done:"+str(row.get("id")))
        if row.get("primary_gate") not in gid:
            faults.append("requirement_missing_gate:"+str(row.get("id")))
        refs=row.get("task_ids")
        if not isinstance(refs,list) or len(refs)!=3 or not set(refs).issubset(tid):
            faults.append("requirement_task_refs:"+str(row.get("id")))
        if not row.get("negative_case") or row.get("evidence_class")!="DESIGN_DERIVED":
            faults.append("requirement_negative_or_evidence:"+str(row.get("id")))
    rnames=set(x.get("name") for x in requirements if isinstance(x,dict))
    for row in tasks:
        if not isinstance(row,dict): continue
        if row.get("requirement_id") not in rid or row.get("gate_id") not in gid or row.get("status")!="OPEN":
            faults.append("task_ref_or_false_done:"+str(row.get("id")))
        if row.get("stage") not in ("design","implement","accept"):
            faults.append("task_stage:"+str(row.get("id")))
    for row in requirements:
        if not isinstance(row,dict): continue
        if set(row.get("task_ids",[])) != {x.get("id") for x in tasks if isinstance(x,dict) and x.get("requirement_id")==row.get("id")}:
            faults.append("task_ownership:"+str(row.get("id")))

    graph={}
    for row in gates:
        if not isinstance(row,dict): continue
        key=row.get("id")
        deps=row.get("depends_on",[])
        if not isinstance(deps,list): deps=[]
        graph[key]=deps
        if not set(deps).issubset(gid) or key in deps:
            faults.append("gate_missing_or_self_dependency:"+str(key))
        if row.get("status")!="OPEN" or row.get("claim_complete") is not False or row.get("authorizing") is not False:
            faults.append("gate_false_pass:"+str(key))
        if not row.get("exit_criterion"):
            faults.append("gate_missing_evidence:"+str(key))
    pending=set()
    done=set()
    def visit(node):
        if node in done: return
        if node in pending:
            faults.append("gate_cycle:"+str(node))
            return
        pending.add(node)
        for dep in graph.get(node,[]):
            if dep in graph: visit(dep)
        pending.remove(node)
        done.add(node)
    for node in graph: visit(node)

    for row in abilities:
        if not isinstance(row,dict): continue
        key=row.get("ability","")
        if not key.startswith("cso/") or row.get("domain") not in rnames or row.get("status")!="PROPOSED_NOT_REGISTERED":
            faults.append("unsafe_ability_registration:"+key)
        if row.get("lane") not in ("read","plan","write","approval","secure_handoff"):
            faults.append("unknown_ability_lane:"+key)
        for field, expected in (
            ("permission_callback_required",True),
            ("arbitrary_sql_allowed",False),
            ("production_authority_inherited",False),
            ("credential_plaintext_in_conversation",False)):
            if row.get(field)!=expected:
                faults.append("ability_security:"+key+":"+field)
        if row.get("lane")=="write" and (row.get("idempotency_required") is not True or row.get("independent_readback_required") is not True):
            faults.append("write_ability_missing_fences:"+key)
        if not row.get("input_schema_contract") or not row.get("scope"):
            faults.append("ability_missing_identity:"+key)
    for row in use_cases:
        if not isinstance(row,dict): continue
        if row.get("requirement_id") not in rid or not row.get("negative_case") or row.get("status")!="DESIGN_ONLY":
            faults.append("use_case_trace_or_false_pass:"+str(row.get("id")))

    schema_names=("form-descriptor","operation-envelope","operation-receipt","secret-handoff")
    schemas={name:json_read("schemas/"+name+".schema.json") for name in schema_names}
    for name,obj in schemas.items():
        if obj.get("$schema")!="http://json-schema.org/draft-04/schema#" or obj.get("type")!="object" or obj.get("additionalProperties") is not False:
            faults.append("schema_not_closed:"+name)
        if not obj.get("required") or not isinstance(obj.get("properties"),dict):
            faults.append("schema_missing_required:"+name)
    handoff=schemas["secret-handoff"].get("properties",{})
    receipt=schemas["operation-receipt"].get("properties",{})
    if handoff.get("plaintext_in_chat",{}).get("enum") != [False] or        receipt.get("secret_values_present",{}).get("enum") != [False]:
        faults.append("secret_plaintext_schema_bypass")
    if "values" not in schemas["operation-envelope"].get("properties",{}):
        faults.append("operation_envelope_missing_nonsecret_values")
    form_schema=schemas["form-descriptor"].get("properties",{})
    if "fields" not in form_schema:
        faults.append("form_schema_missing_fields")

    try:
        trace=(root/"traceability.md").read_text(encoding="utf-8")
        spec=(root/"spec.md").read_text(encoding="utf-8")
        acceptance=(root/"acceptance.md").read_text(encoding="utf-8")
        for row in requirements:
            code=row.get("id","")
            if code and (code not in trace or code not in spec):
                faults.append("requirement_not_traced:"+code)
        for row in use_cases:
            code=row.get("id","")
            if code and code not in acceptance:
                faults.append("use_case_not_accepted:"+code)
        for row in gates:
            code=row.get("id","")
            if code and code not in acceptance:
                faults.append("gate_not_accepted:"+code)
    except OSError:
        faults.append("traceability_document_missing")

    # Supplementary configurable suites never change the frozen parent denominator.
    try:
        from acceptance.validate_dynamic_acceptance import validate_bundle_from_path
        faults.extend(validate_bundle_from_path(root))
    except Exception as exc:
        faults.append("dynamic_acceptance_validator_unavailable:" + type(exc).__name__)
    return sorted(set(faults))

if __name__=="__main__":
    issues=validate()
    for issue in issues: print("FAIL",issue)
    print("CSO01_SPEC_VALIDATION:", "PASS" if not issues else "FAIL", len(issues),"issue(s)")
    sys.exit(0 if not issues else 1)
