#!/usr/bin/env python3
"""Reproducible Competitive Experience evidence snapshot and semantic diff."""
from __future__ import annotations
import hashlib, json
from pathlib import Path
ROOT = Path(__file__).resolve().parent
PLUGIN = ROOT.parents[3] / "wp-content/plugins/mad4b-site-control-plane"
SUMMARY = PLUGIN / "config/competitive-evidence-summary.json"
def canonical(value): return json.dumps(value,ensure_ascii=False,sort_keys=True,separators=(",",":")).encode()
def sha(value): return hashlib.sha256(canonical(value)).hexdigest()
def load(path): return json.loads(Path(path).read_text(encoding="utf-8"))
def build_snapshot(root: Path = ROOT):
    manifest,matrix,sources,ledger=(load(root/n) for n in ("artifact-manifest.json","capability-matrix.json","source-index.json","task-ledger.generated.json"))
    source_map={r["id"]:r for r in sources["evidence"]}; task_map={r["task_id"]:r for r in ledger["tasks"]}
    packages=sorted(({"id":r["id"],"version":r["version"],"sha256":r["sha256"],"license":r["license"],"entry_point":r["entry_point"],"file_count":r["file_count"],"uncompressed_bytes":r["uncompressed_bytes"]} for r in manifest["packages"]),key=lambda x:x["id"])
    capabilities=[]
    for r in matrix["capabilities"]:
        evidence_ids=sorted(r.get("evidence_ids",[])); task_ids=sorted(r.get("task_ids",[]))
        capabilities.append({"id":r["id"],"title":r["title"],"workstream_id":r["workstream_id"],"family":r["family"],"evidence_ids":evidence_ids,
            "evidence_classes":sorted({source_map[e]["kind"] for e in evidence_ids}),"mad4b_foundation_paths":sorted(r.get("mad4b_baseline_paths",[])),
            "baseline_assessment":r["baseline_assessment"],"implementation_status":r["implementation_status"],"runtime_parity_claimed":bool(r["runtime_parity_claimed"]),
            "risk_class":r["risk_class"],"task_ids":task_ids,"task_statuses":{t:task_map[t]["status"] for t in task_ids}})
    capabilities.sort(key=lambda x:x["id"])
    basis={"contract":"mad4b.competitive-evidence-snapshot.v1","packages":packages,"capabilities":capabilities,"source_generation":sha(sources),"task_generation":sha(ledger),"matrix_source_sha":matrix["baseline_source_sha"]}
    return {**basis,"generation_sha256":sha(basis),"authorizing":False,"runtime_certification_inferred":False,"marketing_claims_promoted":False}
def semantic_diff(before,after):
    if before.get("contract")!="mad4b.competitive-evidence-snapshot.v1" or after.get("contract")!="mad4b.competitive-evidence-snapshot.v1": raise ValueError("snapshot_contract_invalid")
    b={r["id"]:r for r in before["capabilities"]}; a={r["id"]:r for r in after["capabilities"]}
    changed=[]
    for key in sorted(set(a)&set(b)):
        if sha(a[key])!=sha(b[key]): changed.append({"id":key,"fields":sorted(k for k in set(a[key])|set(b[key]) if a[key].get(k)!=b[key].get(k)),"before_sha256":sha(b[key]),"after_sha256":sha(a[key])})
    return {"contract":"mad4b.competitive-evidence-diff.v1","before_generation_sha256":before.get("generation_sha256",""),"after_generation_sha256":after.get("generation_sha256",""),"added":sorted(set(a)-set(b)),"removed":sorted(set(b)-set(a)),"changed":changed,"source_drift":before.get("source_generation")!=after.get("source_generation"),"authorizing":False}
def operator_summary(snapshot):
    counts={}; rows=[]
    for r in snapshot["capabilities"]:
        counts[r["implementation_status"]]=counts.get(r["implementation_status"],0)+1
        rows.append({"id":r["id"],"title":r["title"],"status":r["implementation_status"],"risk_class":r["risk_class"],"task_ids":r["task_ids"],"evidence_ids":r["evidence_ids"],"evidence_classes":r["evidence_classes"]})
    payload={"contract":"mad4b.competitive-evidence-summary.v1","snapshot_generation_sha256":snapshot["generation_sha256"],"package_count":len(snapshot["packages"]),"capability_count":len(snapshot["capabilities"]),"status_counts":dict(sorted(counts.items())),"packages":snapshot["packages"],"capabilities":rows,"authorizing":False}
    payload["summary_sha256"]=sha(payload); return payload
def verify(root: Path = ROOT, summary_path: Path = SUMMARY):
    snapshot=build_snapshot(root); expected=operator_summary(snapshot); current=load(summary_path)
    if current!=expected: raise SystemExit("COMPETITIVE_EVIDENCE_SUMMARY_DRIFT")
    print("mad4b.competitive-evidence-snapshot.v1: PASS"); print(f"generation={snapshot['generation_sha256']} packages={len(snapshot['packages'])} capabilities={len(snapshot['capabilities'])}")
    return snapshot
if __name__=="__main__": verify()
