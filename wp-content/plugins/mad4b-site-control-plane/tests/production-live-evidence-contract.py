from __future__ import annotations
import argparse, copy, hashlib, json, re, time
from pathlib import Path

root=Path(__file__).resolve().parents[1]
plan=json.loads((root/"config/production-certification-plan.json").read_text(encoding="utf-8"))
policy=json.loads((root/"config/production-readiness-policy.json").read_text(encoding="utf-8"))

ap=argparse.ArgumentParser()
ap.add_argument("--bundle",default=""); ap.add_argument("--enforce-ready",action="store_true"); ap.add_argument("--self-test",action="store_true"); ap.add_argument("--output",default="")
args=ap.parse_args()
HEX40=re.compile(r"^[a-f0-9]{40}$"); HEX64=re.compile(r"^[a-f0-9]{64}$")
def fail(code): raise SystemExit(code)
def canonical_digest(value): return hashlib.sha256(json.dumps(value,sort_keys=True,separators=(",",":"),ensure_ascii=False).encode("utf-8")).hexdigest()

def validate(bundle):
    if not isinstance(bundle,dict) or bundle.get("contract")!="mad4b.production-live-evidence-bundle.v1": fail("PRODUCTION_LIVE_BUNDLE_CONTRACT_INVALID")
    if bundle.get("profile")!="control_plane_core" or bundle.get("environment")!="staging": fail("PRODUCTION_LIVE_BUNDLE_SCOPE_INVALID")
    if bundle.get("authorizing") is not False or bundle.get("production_authorized") is not False: fail("PRODUCTION_LIVE_BUNDLE_MUST_NOT_AUTHORIZE")
    identity=bundle.get("candidate_identity")
    if not isinstance(identity,dict): fail("PRODUCTION_LIVE_BUNDLE_IDENTITY_MISSING")
    if not HEX40.fullmatch(str(identity.get("source_commit_sha") or "").lower()) or not HEX64.fullmatch(str(identity.get("build_fingerprint") or "").lower()) or not HEX64.fullmatch(str(identity.get("package_manifest_digest") or "").lower()): fail("PRODUCTION_LIVE_BUNDLE_IDENTITY_INVALID")

    stages=plan.get("stages") or []; stage_by_gate={str(x.get("gate") or ""):x for x in stages if isinstance(x,dict)}
    rows=bundle.get("evidence")
    if not isinstance(rows,list): fail("PRODUCTION_LIVE_BUNDLE_EVIDENCE_INVALID")
    by_gate={}
    for row in rows:
        if not isinstance(row,dict) or row.get("contract")!="mad4b.production-live-gate-evidence.v1": fail("PRODUCTION_LIVE_GATE_EVIDENCE_CONTRACT_INVALID")
        gate=str(row.get("gate") or "")
        if gate in by_gate: fail("PRODUCTION_LIVE_GATE_DUPLICATED:"+gate)
        stage=stage_by_gate.get(gate)
        if not stage: fail("PRODUCTION_LIVE_GATE_UNSCOPED:"+gate)
        by_gate[gate]=row
        if row.get("environment")!="staging" or row.get("production_mutation") is not False or row.get("authorizing") is not False: fail("PRODUCTION_LIVE_GATE_SCOPE_INVALID:"+gate)
        if row.get("ready") is not True: fail("PRODUCTION_LIVE_GATE_NOT_READY:"+gate)
        if row.get("candidate_identity")!=identity: fail("PRODUCTION_LIVE_GATE_IDENTITY_MISMATCH:"+gate)
        if str(row.get("producer") or "")!=str(stage.get("producer") or ""): fail("PRODUCTION_LIVE_GATE_PRODUCER_IDENTITY_MISMATCH:"+gate)
        expected_contract=str(stage.get("producer_contract") or "")
        if not expected_contract or str(row.get("producer_contract") or "")!=expected_contract: fail("PRODUCTION_LIVE_GATE_EXACT_PRODUCER_CONTRACT_MISMATCH:"+gate)
        evidence=row.get("producer_evidence")
        if not isinstance(evidence,dict) or evidence.get("contract")!=expected_contract: fail("PRODUCTION_LIVE_GATE_PRODUCER_EVIDENCE_CONTRACT_MISMATCH:"+gate)
        digest=str(row.get("producer_evidence_sha256") or "").lower()
        if not HEX64.fullmatch(digest) or digest!=canonical_digest(evidence): fail("PRODUCTION_LIVE_GATE_EVIDENCE_DIGEST_MISMATCH:"+gate)
        if evidence.get("production_mutation") is True or evidence.get("production_authorized") is True or evidence.get("authorizing") is True: fail("PRODUCTION_LIVE_GATE_PRODUCER_EVIDENCE_AUTHORITY_WIDENED:"+gate)
        for key in ("ready","mutation_performed","production_mutation","authorizing","rollback_verified","postcondition_verified"):
            if key in evidence and key in row and evidence[key] is not row[key]: fail("PRODUCTION_LIVE_GATE_OUTER_EVIDENCE_FLAG_MISMATCH:"+gate+":"+key)
        cls=str(stage.get("mutation_class") or "")
        if cls=="read_only":
            if row.get("mutation_performed") is not False: fail("PRODUCTION_LIVE_READ_ONLY_MUTATED:"+gate)
        elif cls=="reversible_staging_mutation":
            if row.get("mutation_performed") is not True or row.get("rollback_verified") is not True or row.get("postcondition_verified") is not True: fail("PRODUCTION_LIVE_REVERSIBLE_MUTATION_EVIDENCE_INCOMPLETE:"+gate)
        else: fail("PRODUCTION_LIVE_GATE_MUTATION_CLASS_INVALID:"+gate)

        mode=str(stage.get("trust_mode") or "")
        if mode=="runtime_recompute":
            if stage.get("attestation_method")!="runtime_recompute" or stage.get("producer_ability")!="mad4b/production-certification-readonly-evidence": fail("PRODUCTION_LIVE_RUNTIME_RECOMPUTE_BINDING_INVALID:"+gate)
        elif mode=="signed_attestation":
            att=row.get("evidence_attestation")
            if not isinstance(att,dict) or att.get("contract")!="mad4b.production-evidence-attestation.v1": fail("PRODUCTION_LIVE_SIGNED_ATTESTATION_MISSING:"+gate)
            claim=att.get("claim"); sig=att.get("signature")
            if not isinstance(claim,dict) or not isinstance(sig,dict) or not str(att.get("claim_sha256") or ""): fail("PRODUCTION_LIVE_SIGNED_ATTESTATION_INVALID:"+gate)
            issued=int(claim.get("issued_at") or 0); expires=int(claim.get("expires_at") or 0)
            if issued<1 or expires<=issued: fail("PRODUCTION_LIVE_SIGNED_ATTESTATION_TIME_INVALID:"+gate)
            material=claim.get("material")
            if not isinstance(material,dict) or material.get("producer_evidence_sha256")!=digest or material.get("producer_contract")!=expected_contract or material.get("gate")!=gate: fail("PRODUCTION_LIVE_SIGNED_ATTESTATION_BINDING_INVALID:"+gate)
        else: fail("PRODUCTION_LIVE_GATE_TRUST_MODE_INVALID:"+gate)

    missing=sorted(set(stage_by_gate)-set(by_gate))
    if missing: fail("PRODUCTION_LIVE_GATE_MISSING:"+",".join(missing))
    optional=sorted(str(x) for x in (((policy.get("profiles") or {}).get("control_plane_core") or {}).get("optional_workstream_ids") or []))
    if sorted(str(x) for x in (bundle.get("optional_capabilities_disabled") or []))!=optional: fail("PRODUCTION_LIVE_OPTIONAL_CAPABILITY_FAIL_CLOSED_SET_INVALID")
    return {"contract":"mad4b.production-live-evidence-prevalidation.v2","structurally_valid":True,"runtime_evaluation_required":True,"production_ready":False,"production_authorized":False,"authorizing":False}

def synthetic_bundle():
    identity={"source_commit_sha":"a"*40,"build_fingerprint":"b"*64,"package_manifest_digest":"c"*64}; rows=[]
    for stage in plan.get("stages") or []:
        cls=str(stage.get("mutation_class") or ""); contract=str(stage.get("producer_contract") or "")
        evidence={"contract":contract,"candidate_identity":copy.deepcopy(identity),"ready":True,"mutation_performed":cls=="reversible_staging_mutation","production_mutation":False,"authorizing":False}
        row={"contract":"mad4b.production-live-gate-evidence.v1","stage_id":stage["id"],"gate":stage["gate"],"environment":"staging","candidate_identity":copy.deepcopy(identity),"producer":stage["producer"],"producer_contract":contract,"producer_evidence":evidence,"producer_evidence_sha256":canonical_digest(evidence),"ready":True,"mutation_performed":cls=="reversible_staging_mutation","production_mutation":False,"authorizing":False}
        if cls=="reversible_staging_mutation": row["rollback_verified"]=True; row["postcondition_verified"]=True
        if stage.get("trust_mode")=="signed_attestation":
            material={"contract":"mad4b.production-live-gate-evidence.v1","stage_id":stage["id"],"gate":stage["gate"],"environment":"staging","candidate_identity":copy.deepcopy(identity),"producer":stage["producer"],"producer_contract":contract,"producer_evidence_sha256":row["producer_evidence_sha256"],"ready":True,"mutation_performed":cls=="reversible_staging_mutation","production_mutation":False,"authorizing":False,"rollback_verified":cls=="reversible_staging_mutation","postcondition_verified":cls=="reversible_staging_mutation","trust_mode":"signed_attestation","attestation_method":stage["attestation_method"]}
            claim={"contract":"mad4b.production-evidence-attestation.v1","material":material,"issued_at":1700000000,"expires_at":1700001800,"authorizing":False}
            row["evidence_attestation"]={"contract":"mad4b.production-evidence-attestation.v1","claim":claim,"claim_sha256":canonical_digest(claim),"signature":{"contract":"mad4b.detached-signature.v1","profile_id":"production-evidence-rs256-v1","kid":"synthetic"},"authorizing":False}
        rows.append(row)
    optional=sorted(str(x) for x in (((policy.get("profiles") or {}).get("control_plane_core") or {}).get("optional_workstream_ids") or []))
    return {"contract":"mad4b.production-live-evidence-bundle.v1","profile":"control_plane_core","environment":"staging","candidate_identity":identity,"optional_capabilities_disabled":optional,"evidence":rows,"production_authorized":False,"authorizing":False}

if args.self_test:
    good=synthetic_bundle(); result=validate(good)
    if result.get("structurally_valid") is not True or result.get("production_ready") is not False or result.get("runtime_evaluation_required") is not True: fail("PRODUCTION_LIVE_SELF_TEST_PREVALIDATION_FAILED")
    bad=copy.deepcopy(good)
    for row in bad["evidence"]:
        if "evidence_attestation" in row: row.pop("evidence_attestation"); break
    try: validate(bad)
    except SystemExit as exc:
        if "PRODUCTION_LIVE_SIGNED_ATTESTATION_MISSING" not in str(exc): raise
    else: fail("PRODUCTION_LIVE_SELF_TEST_UNSIGNED_EXTERNAL_SURVIVED")
    bad=copy.deepcopy(good); bad["evidence"][0]["producer_contract"]="mad4b.synthetic.v1"
    try: validate(bad)
    except SystemExit as exc:
        if "PRODUCTION_LIVE_GATE_EXACT_PRODUCER_CONTRACT_MISMATCH" not in str(exc): raise
    else: fail("PRODUCTION_LIVE_SELF_TEST_ARBITRARY_CONTRACT_SURVIVED")
    print("mad4b.production-live-evidence-prevalidation.v2: SELF_TEST_PASS"); raise SystemExit(0)

if not args.bundle: fail("PRODUCTION_LIVE_BUNDLE_REQUIRED")
bundle=json.loads(Path(args.bundle).read_text(encoding="utf-8")); result=validate(bundle)
if args.output:
    out=Path(args.output); out.parent.mkdir(parents=True,exist_ok=True); out.write_text(json.dumps(result,indent=2,sort_keys=True)+"\n",encoding="utf-8")
print(json.dumps(result,sort_keys=True))
if args.enforce_ready: fail("PRODUCTION_LIVE_CANONICAL_RUNTIME_EVALUATION_REQUIRED")
print("mad4b.production-live-evidence-prevalidation.v2: PASS")
