from __future__ import annotations
import argparse, copy, hashlib, json, re, tempfile
from pathlib import Path

root=Path(__file__).resolve().parents[1]
repo=root.parents[2]
plan=json.loads((root/"config/production-certification-plan.json").read_text(encoding="utf-8"))
policy=json.loads((root/"config/production-readiness-policy.json").read_text(encoding="utf-8"))

ap=argparse.ArgumentParser()
ap.add_argument("--bundle",default="")
ap.add_argument("--enforce-ready",action="store_true")
ap.add_argument("--self-test",action="store_true")
ap.add_argument("--output",default="")
args=ap.parse_args()

HEX40=re.compile(r"^[a-f0-9]{40}$")
HEX64=re.compile(r"^[a-f0-9]{64}$")

def fail(code):
    raise SystemExit(code)

def validate(bundle):
    if not isinstance(bundle,dict) or bundle.get("contract")!="mad4b.production-live-evidence-bundle.v1":
        fail("PRODUCTION_LIVE_BUNDLE_CONTRACT_INVALID")
    if bundle.get("profile")!="control_plane_core" or bundle.get("environment")!="staging":
        fail("PRODUCTION_LIVE_BUNDLE_SCOPE_INVALID")
    if bundle.get("authorizing") is not False or bundle.get("production_authorized") is not False:
        fail("PRODUCTION_LIVE_BUNDLE_MUST_NOT_AUTHORIZE")
    identity=bundle.get("candidate_identity")
    if not isinstance(identity,dict):
        fail("PRODUCTION_LIVE_BUNDLE_IDENTITY_MISSING")
    sha=str(identity.get("source_commit_sha") or "").lower()
    build=str(identity.get("build_fingerprint") or "").lower()
    package=str(identity.get("package_manifest_digest") or "").lower()
    if not HEX40.fullmatch(sha) or not HEX64.fullmatch(build) or not HEX64.fullmatch(package):
        fail("PRODUCTION_LIVE_BUNDLE_IDENTITY_INVALID")
    if bundle.get("runtime_identity_match") is not True or bundle.get("root_trust_verified") is not True or bundle.get("rollback_retention_verified") is not True:
        fail("PRODUCTION_LIVE_BUNDLE_FOUNDATION_EVIDENCE_INCOMPLETE")

    stages=plan.get("stages") or []
    stage_by_gate={str(x.get("gate") or ""):x for x in stages if isinstance(x,dict)}
    rows=bundle.get("evidence")
    if not isinstance(rows,list):
        fail("PRODUCTION_LIVE_BUNDLE_EVIDENCE_INVALID")
    by_gate={}
    for row in rows:
        if not isinstance(row,dict) or row.get("contract")!="mad4b.production-live-gate-evidence.v1":
            fail("PRODUCTION_LIVE_GATE_EVIDENCE_CONTRACT_INVALID")
        gate=str(row.get("gate") or "")
        if gate in by_gate:
            fail("PRODUCTION_LIVE_GATE_DUPLICATED:"+gate)
        if gate not in stage_by_gate:
            fail("PRODUCTION_LIVE_GATE_UNSCOPED:"+gate)
        by_gate[gate]=row
        if row.get("environment")!="staging" or row.get("production_mutation") is not False or row.get("authorizing") is not False:
            fail("PRODUCTION_LIVE_GATE_SCOPE_INVALID:"+gate)
        if row.get("ready") is not True:
            fail("PRODUCTION_LIVE_GATE_NOT_READY:"+gate)
        if row.get("candidate_identity")!=identity:
            fail("PRODUCTION_LIVE_GATE_IDENTITY_MISMATCH:"+gate)
        digest=str(row.get("producer_evidence_sha256") or "").lower()
        if not HEX64.fullmatch(digest):
            fail("PRODUCTION_LIVE_GATE_EVIDENCE_DIGEST_INVALID:"+gate)
        producer_contract=str(row.get("producer_contract") or "")
        expected=str(stage_by_gate[gate].get("evidence_contract") or "")
        if expected!="mad4b.production-live-gate-evidence.v1" and producer_contract!=expected:
            fail("PRODUCTION_LIVE_GATE_PRODUCER_CONTRACT_MISMATCH:"+gate)
        cls=str(stage_by_gate[gate].get("mutation_class") or "")
        if cls=="read_only":
            if row.get("mutation_performed") is not False:
                fail("PRODUCTION_LIVE_READ_ONLY_MUTATED:"+gate)
        elif cls=="reversible_staging_mutation":
            if row.get("mutation_performed") is not True or row.get("rollback_verified") is not True or row.get("postcondition_verified") is not True:
                fail("PRODUCTION_LIVE_REVERSIBLE_MUTATION_EVIDENCE_INCOMPLETE:"+gate)
        else:
            fail("PRODUCTION_LIVE_GATE_MUTATION_CLASS_INVALID:"+gate)

    required=set(stage_by_gate)
    missing=sorted(required-set(by_gate))
    if missing:
        fail("PRODUCTION_LIVE_GATE_MISSING:"+",".join(missing))

    core=((policy.get("profiles") or {}).get("control_plane_core") or {})
    optional=sorted(str(x) for x in (core.get("optional_workstream_ids") or []))
    disabled=sorted(str(x) for x in (bundle.get("optional_capabilities_disabled") or []))
    if disabled!=optional:
        fail("PRODUCTION_LIVE_OPTIONAL_CAPABILITY_FAIL_CLOSED_SET_INVALID")

    return {
        "contract":"mad4b.production-live-evidence-verdict.v1",
        "profile":"control_plane_core",
        "candidate_identity":identity,
        "evidence_gate_count":len(required),
        "production_ready":True,
        "full_feature007_complete":False,
        "production_authorized":False,
        "promotion_required":True,
        "authorizing":False,
    }

def synthetic_bundle():
    identity={
        "source_commit_sha":"a"*40,
        "build_fingerprint":"b"*64,
        "package_manifest_digest":"c"*64,
    }
    rows=[]
    for stage in plan.get("stages") or []:
        cls=str(stage.get("mutation_class") or "")
        row={
            "contract":"mad4b.production-live-gate-evidence.v1",
            "gate":stage["gate"],
            "environment":"staging",
            "candidate_identity":identity,
            "producer_contract":stage["evidence_contract"] if stage["evidence_contract"]!="mad4b.production-live-gate-evidence.v1" else "mad4b.synthetic-self-test.v1",
            "producer_evidence_sha256":hashlib.sha256(stage["gate"].encode()).hexdigest(),
            "ready":True,
            "mutation_performed":cls=="reversible_staging_mutation",
            "production_mutation":False,
            "authorizing":False,
        }
        if cls=="reversible_staging_mutation":
            row["rollback_verified"]=True
            row["postcondition_verified"]=True
        rows.append(row)
    optional=sorted(str(x) for x in (((policy.get("profiles") or {}).get("control_plane_core") or {}).get("optional_workstream_ids") or []))
    return {
        "contract":"mad4b.production-live-evidence-bundle.v1",
        "profile":"control_plane_core",
        "environment":"staging",
        "candidate_identity":identity,
        "runtime_identity_match":True,
        "root_trust_verified":True,
        "rollback_retention_verified":True,
        "optional_capabilities_disabled":optional,
        "evidence":rows,
        "production_authorized":False,
        "authorizing":False,
    }

if args.self_test:
    good=synthetic_bundle()
    result=validate(good)
    if result.get("production_ready") is not True or result.get("production_authorized") is not False:
        fail("PRODUCTION_LIVE_SELF_TEST_GOOD_FIXTURE_FAILED")
    bad=copy.deepcopy(good)
    bad["evidence"][0]["production_mutation"]=True
    try:
        validate(bad)
    except SystemExit as exc:
        if "PRODUCTION_LIVE_GATE_SCOPE_INVALID" not in str(exc):
            raise
    else:
        fail("PRODUCTION_LIVE_SELF_TEST_PRODUCTION_MUTATION_SURVIVED")
    bad=copy.deepcopy(good)
    bad["evidence"][0]["candidate_identity"]["source_commit_sha"]="d"*40
    try:
        validate(bad)
    except SystemExit as exc:
        if "PRODUCTION_LIVE_GATE_IDENTITY_MISMATCH" not in str(exc):
            raise
    else:
        fail("PRODUCTION_LIVE_SELF_TEST_IDENTITY_DRIFT_SURVIVED")
    print("mad4b.production-live-evidence-bundle.v1: SELF_TEST_PASS")
    raise SystemExit(0)

if not args.bundle:
    fail("PRODUCTION_LIVE_BUNDLE_REQUIRED")
bundle=json.loads(Path(args.bundle).read_text(encoding="utf-8"))
result=validate(bundle)
if args.output:
    out=Path(args.output)
    out.parent.mkdir(parents=True,exist_ok=True)
    out.write_text(json.dumps(result,indent=2,sort_keys=True)+"\n",encoding="utf-8")
print(json.dumps(result,sort_keys=True))
if args.enforce_ready and result.get("production_ready") is not True:
    fail("PRODUCTION_LIVE_NOT_READY")
print("mad4b.production-live-evidence-bundle.v1: PASS")
