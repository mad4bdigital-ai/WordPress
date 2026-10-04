from __future__ import annotations
import json
from pathlib import Path

root=Path(__file__).resolve().parents[1]
repo=root.parents[2]
plan=json.loads((root/"config/production-certification-plan.json").read_text(encoding="utf-8"))
policy=json.loads((root/"config/production-readiness-policy.json").read_text(encoding="utf-8"))

def fail(code):
    raise SystemExit(code)

if plan.get("contract")!="mad4b.production-certification-plan.v1":
    fail("PRODUCTION_CERTIFICATION_PLAN_INVALID")
if plan.get("profile")!="control_plane_core" or plan.get("environment")!="staging":
    fail("PRODUCTION_CERTIFICATION_SCOPE_INVALID")
if plan.get("target_environment")!="production" or plan.get("authorizing") is not False or plan.get("production_mutation_performed") is not False:
    fail("PRODUCTION_CERTIFICATION_AUTHORITY_BOUNDARY_INVALID")

profile=(policy.get("profiles") or {}).get("control_plane_core") or {}
required=set(str(x) for x in (profile.get("required_workstream_ids") or []))
closure=json.loads((repo/"specs/007-content-intelligence-workflow-platform/implementation-closure.json").read_text(encoding="utf-8"))
by_id={str(x.get("id") or ""):x for x in (closure.get("workstreams") or []) if isinstance(x,dict)}
required_gates=set()
for wid in required:
    if wid not in by_id:
        fail("PRODUCTION_CERTIFICATION_WORKSTREAM_UNKNOWN:"+wid)
    gate=str(by_id[wid].get("gate") or "")
    if not gate:
        fail("PRODUCTION_CERTIFICATION_WORKSTREAM_GATE_MISSING:"+wid)
    required_gates.add(gate)

stages=plan.get("stages")
if not isinstance(stages,list) or not stages:
    fail("PRODUCTION_CERTIFICATION_STAGES_MISSING")
seen={}
allowed_classes={"read_only","reversible_staging_mutation"}
for row in stages:
    if not isinstance(row,dict):
        fail("PRODUCTION_CERTIFICATION_STAGE_INVALID")
    sid=str(row.get("id") or "")
    gate=str(row.get("gate") or "")
    cls=str(row.get("mutation_class") or "")
    if not sid or not gate or cls not in allowed_classes:
        fail("PRODUCTION_CERTIFICATION_STAGE_SCOPE_INVALID:"+sid)
    seen.setdefault(gate,[]).append(sid)
    if not str(row.get("producer") or "") or not str(row.get("evidence_contract") or ""):
        fail("PRODUCTION_CERTIFICATION_STAGE_EVIDENCE_MISSING:"+sid)
    if cls=="reversible_staging_mutation" and row.get("rollback_required") is not True:
        fail("PRODUCTION_CERTIFICATION_ROLLBACK_REQUIRED:"+sid)
    if cls=="read_only" and row.get("rollback_required") is not False:
        fail("PRODUCTION_CERTIFICATION_READ_ONLY_ROLLBACK_INVALID:"+sid)

missing=sorted(required_gates-set(seen))
extra=sorted(set(seen)-required_gates)
duplicates=sorted(g for g,ids in seen.items() if len(ids)!=1)
if missing:
    fail("PRODUCTION_CERTIFICATION_GATE_MISSING:"+",".join(missing))
if extra:
    fail("PRODUCTION_CERTIFICATION_UNSCOPED_GATE:"+",".join(extra))
if duplicates:
    fail("PRODUCTION_CERTIFICATION_GATE_DUPLICATED:"+",".join(duplicates))

env=plan.get("evidence_envelope") or {}
if env.get("contract")!="mad4b.production-live-gate-evidence.v1":
    fail("PRODUCTION_CERTIFICATION_EVIDENCE_ENVELOPE_INVALID")
for field in ("source_commit_sha","build_fingerprint","package_manifest_digest"):
    if field not in (env.get("candidate_identity_fields") or []):
        fail("PRODUCTION_CERTIFICATION_IDENTITY_FIELD_MISSING:"+field)
for field in ("producer","producer_evidence","producer_evidence_sha256"):
    if field not in (env.get("required_fields") or []):
        fail("PRODUCTION_CERTIFICATION_EVIDENCE_FIELD_MISSING:"+field)
if env.get("producer_evidence_sha256_mode")!="canonical_json_sha256" or env.get("embedded_producer_evidence_required") is not True:
    fail("PRODUCTION_CERTIFICATION_EVIDENCE_CONTENT_ADDRESSING_INVALID")

terminal=plan.get("terminal") or {}
for key in ("all_stages_ready_required","exact_identity_equal_across_all_stages","optional_capabilities_remain_fail_closed","producer_evidence_content_addressed","production_promotion_separate"):
    if terminal.get(key) is not True:
        fail("PRODUCTION_CERTIFICATION_TERMINAL_INVARIANT_MISSING:"+key)
if terminal.get("production_ready_result_authorizing") is not False:
    fail("PRODUCTION_CERTIFICATION_READY_MUST_NOT_AUTHORIZE")

print("mad4b.production-certification-plan.v1: PASS gates="+str(len(required_gates)))
