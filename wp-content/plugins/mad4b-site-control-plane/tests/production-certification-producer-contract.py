from __future__ import annotations
import json
from pathlib import Path

root=Path(__file__).resolve().parents[1]
repo=root.parents[2]
plan=json.loads((root/"config/production-certification-plan.json").read_text(encoding="utf-8"))
registry=json.loads((root/"config/production-certification-producers.json").read_text(encoding="utf-8"))

def fail(code):
    raise SystemExit(code)

if registry.get("contract")!="mad4b.production-certification-producers.v1":
    fail("PRODUCTION_CERTIFICATION_PRODUCER_REGISTRY_CONTRACT_INVALID")
if registry.get("authorizing") is not False or registry.get("production_mutation") is not False or registry.get("environment")!="staging":
    fail("PRODUCTION_CERTIFICATION_PRODUCER_REGISTRY_SCOPE_INVALID")

plan_by={str(x.get("id") or ""):x for x in (plan.get("stages") or []) if isinstance(x,dict)}
rows=registry.get("stages")
if not isinstance(rows,list):
    fail("PRODUCTION_CERTIFICATION_PRODUCER_ROWS_INVALID")
reg_by={}
allowed={"workflow","release_root_trust","host_bridge_sequence","recovery_plane_sequence","wp_ability_readonly","governed_mcp_reversible_sequence","repository_workflow","host_bridge_vertical_slice"}
for row in rows:
    if not isinstance(row,dict):
        fail("PRODUCTION_CERTIFICATION_PRODUCER_ROW_INVALID")
    sid=str(row.get("stage_id") or "")
    if not sid or sid in reg_by:
        fail("PRODUCTION_CERTIFICATION_PRODUCER_STAGE_DUPLICATED:"+sid)
    if sid not in plan_by:
        fail("PRODUCTION_CERTIFICATION_PRODUCER_STAGE_UNKNOWN:"+sid)
    if row.get("kind") not in allowed:
        fail("PRODUCTION_CERTIFICATION_PRODUCER_KIND_INVALID:"+sid)
    if str(row.get("producer") or "")!=str(plan_by[sid].get("producer") or ""):
        fail("PRODUCTION_CERTIFICATION_PRODUCER_IDENTITY_DRIFT:"+sid)
    source_paths=row.get("source_paths")
    markers=row.get("required_markers")
    if not isinstance(source_paths,list) or not source_paths or not isinstance(markers,list) or not markers:
        fail("PRODUCTION_CERTIFICATION_PRODUCER_BINDING_INCOMPLETE:"+sid)
    combined=""
    for rel in source_paths:
        path=repo/str(rel)
        if not path.is_file():
            fail("PRODUCTION_CERTIFICATION_PRODUCER_SOURCE_MISSING:"+sid+":"+str(rel))
        combined+="\n"+path.read_text(encoding="utf-8",errors="replace")
    for marker in markers:
        if str(marker) not in combined:
            fail("PRODUCTION_CERTIFICATION_PRODUCER_MARKER_MISSING:"+sid+":"+str(marker))
    reg_by[sid]=row

missing=sorted(set(plan_by)-set(reg_by))
extra=sorted(set(reg_by)-set(plan_by))
if missing:
    fail("PRODUCTION_CERTIFICATION_PRODUCER_STAGE_MISSING:"+",".join(missing))
if extra:
    fail("PRODUCTION_CERTIFICATION_PRODUCER_STAGE_EXTRA:"+",".join(extra))

for sid,row in plan_by.items():
    if row.get("mutation_class")=="reversible_staging_mutation":
        if reg_by[sid]["kind"] not in {"host_bridge_sequence","recovery_plane_sequence","governed_mcp_reversible_sequence","host_bridge_vertical_slice"}:
            fail("PRODUCTION_CERTIFICATION_MUTATING_STAGE_PRODUCER_NOT_REVERSIBLE:"+sid)
        if row.get("rollback_required") is not True:
            fail("PRODUCTION_CERTIFICATION_MUTATING_STAGE_ROLLBACK_NOT_REQUIRED:"+sid)

print("mad4b.production-certification-producers.v1: PASS stages="+str(len(reg_by)))
