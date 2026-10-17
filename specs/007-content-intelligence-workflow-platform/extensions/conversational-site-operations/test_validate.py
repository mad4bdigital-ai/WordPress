#!/usr/bin/env python3
"""Adversarial CSO01 spec validator tests; operates in a disposable temp copy."""
from pathlib import Path
import json
import shutil
import tempfile
from validate import validate, ROOT

def check_fault(label, mutate, expected):
    with tempfile.TemporaryDirectory(prefix="cso01-spec-test-") as folder:
        dest=Path(folder)
        manifest=json.loads((ROOT/"manifest.json").read_text(encoding="utf-8"))
        for rel in manifest["required_files"]:
            source=ROOT/rel
            target=dest/rel
            target.parent.mkdir(parents=True,exist_ok=True)
            shutil.copyfile(source,target)
        mutate(dest)
        issues=validate(dest)
        assert any(expected in issue for issue in issues), (label,issues)
        print("PASS negative:",label)

def change_json(path, mutate):
    p=path
    obj=json.loads(p.read_text(encoding="utf-8"))
    mutate(obj)
    p.write_text(json.dumps(obj,indent=2)+"\n",encoding="utf-8")

def run():
    problems=validate()
    assert not problems, problems
    print("PASS base: all CSO01 design artifacts coherent and non-authorizing")
    check_fault("authority granted",lambda d:change_json(d/"manifest.json",lambda x:x.update({"authorizing":True})),"manifest_invariant:authorizing")
    check_fault("production admitted",lambda d:change_json(d/"manifest.json",lambda x:x.update({"production_authorized":True})),"manifest_invariant:production_authorized")
    check_fault("new unsafe operation",lambda d:change_json(d/"abilities.json",lambda x:x["abilities"][0].update({"arbitrary_sql_allowed":True})),"ability_security:")
    check_fault("write without idempotency",lambda d:change_json(d/"abilities.json",lambda x:next(a for a in x["abilities"] if a["lane"]=="write").update({"idempotency_required":False})),"write_ability_missing_fences")
    check_fault("gate false pass",lambda d:change_json(d/"gates.json",lambda x:x["gates"][0].update({"claim_complete":True})),"gate_false_pass")
    check_fault("cyclic gate",lambda d:change_json(d/"gates.json",lambda x:x["gates"][0].update({"depends_on":["CSO-G10"]})),"gate_cycle:")
    check_fault("orphan task",lambda d:change_json(d/"tasks.json",lambda x:x["tasks"][0].update({"requirement_id":"CSO-R999"})),"task_ref_or_false_done")
    check_fault("secret handoff in chat",lambda d:change_json(d/"schemas/secret-handoff.schema.json",lambda x:x["properties"]["plaintext_in_chat"].update({"enum":[True]})),"secret_plaintext_schema_bypass")
    check_fault("secret in receipt",lambda d:change_json(d/"schemas/operation-receipt.schema.json",lambda x:x["properties"]["secret_values_present"].update({"enum":[True]})),"secret_plaintext_schema_bypass")
    check_fault("missing runtime schema",lambda d:(d/"schemas/form-descriptor.schema.json").unlink(),"missing_required_file:")
    check_fault("missing requirement acceptance",lambda d:(d/"traceability.md").write_text("missing all requirement IDs",encoding="utf-8"),"requirement_not_traced:")
    check_fault("task falsely done",lambda d:change_json(d/"tasks.json",lambda x:x["tasks"][0].update({"status":"DONE"})),"task_ref_or_false_done")
    print("CSO01_ADVERSARIAL_VALIDATION: PASS")

if __name__=="__main__":
    run()
