#!/usr/bin/env python3
"""First-Anchor Original306 QUEUED source capture; never an issuer.

Only ROOT may capture one live, original WordPress QUEUED record BEFORE
Original306 Claim. This is *historical evidence*, not authorization, consent,
an executable Broker Job, or an Original313 signature.

No user-supplied JSON/plan/signer or alternative site/tenant/path. Captures
the exact original305, Original328, Original318, original306 Broker Pending
row and QUEUED Job after independent first-party v95 checks.

Future root-only issuer must re-verify these originals against the genuine
CLAIMED job/claim and fresh live WordPress readback before signing.
"""
from __future__ import annotations

import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import time

ROOT=Path("/opt/mad4b-company-transport/native-313-independent")
REGISTRY=ROOT/"dynamic-operation-registry-v1/enrolled"
PLAN_ROOT=Path("/var/lib/mad4b/dynamic-operation-registry/plans")
STORE=Path("/var/lib/mad4b/original-host-v2/queued-evidence")
V95=REGISTRY/"host-anchor-v95/original306_claim_only.py"
V95_SHA="ebb3fb1efe58a1506516b7d8b76f2ff4281b3f2b0c0ee8361b606dcfa3240b64"
SIGNER=ROOT.parent/"broker-v1/native-vps-client-principal.py"
SIGNER_SHA="ebb4971efef114e5b984a9c9ea60d46b5fe7250db9991b5d94cd61708651dc26"
PLAN="company.host-authority.anchor.issue"
CONTRACT="mad4b.original306.first-anchor-queued-preimage.v1"
HEX32=re.compile(r"[a-f0-9]{32}\Z")
HEX64=re.compile(r"[a-f0-9]{64}\Z")

class CaptureHeld(ValueError): pass

def require(condition,code):
    if not condition: raise CaptureHeld(code)

def digest(row):
    return hashlib.sha256(json.dumps(row,sort_keys=True,
        separators=(",",":"),ensure_ascii=False).encode()).hexdigest()

def module(path,sha,name):
    require(path.is_file() and not path.is_symlink(),
        "ORIGINAL_FIRST_ANCHOR_PIN_CHANGED")
    st=path.lstat()
    require(stat.S_ISREG(st.st_mode) and st.st_uid==0 and st.st_gid==0
        and st.st_nlink==1 and not st.st_mode&0o077
        and hashlib.sha256(path.read_bytes()).hexdigest()==sha,
        "ORIGINAL_FIRST_ANCHOR_PIN_CHANGED")
    spec=importlib.util.spec_from_file_location(name,path)
    require(spec is not None and spec.loader is not None,
            "FIRST_ANCHOR_PIN_IMPORT_FAILED")
    m=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(m)
    return m

def owner_state(job_id):
    """One bounded, read-only original Central WordPress WP-CLI snapshot."""
    require(os.geteuid()==0,"FIRST_ANCHOR_ROOT_REQUIRED")
    require(isinstance(job_id,str) and HEX32.fullmatch(job_id),
            "FIRST_ANCHOR_JOB_ID_INVALID")
    issuer=module(SIGNER,SIGNER_SHA,"_host_118_original_broker")
    php='''
$id="'''+job_id+'''";
$c="all-royal-egypt";$e="staging";
$j=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);
$r=get_option(MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$id,false);
$g=is_array($r)?get_option(MAD4B_Central_SSH_Command_Intake_V1::PREFIX.($r["grant_id"]??""),false):false;
$slot=get_option(MAD4B_Company_Native_WPCLI_Enrollment_V1::SLOT.$c."_".$e,false);
$p=is_array($slot)?get_option(MAD4B_Central_SSH_Command_Intake_V1::PREFIX.($slot["grant_id"]??""),false):false;
$t=is_array($slot)?get_option(MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.($slot["transport_id"]??""),false):false;
$a=MAD4B_Native_Host_Exact_Grant_V1::current_anchor_readback_v2($c,$e);
$active=is_array($p)&&is_array($t)&&MAD4B_Original_Remote_Hostinger_328_V1::active($t,$p);
$pending=array_values(array_filter(MAD4B_Central_VPS_Broker_V1::pending(30),
 static fn($x)=>is_array($x)&&($x["job_id"]??null)===$id));
echo wp_json_encode(["queued"=>$j,"pending"=>$pending,"request"=>$r,
 "grant"=>$g,"parent"=>$p,"transport"=>$t,
 "site"=>["original328_active"=>$active,
 "anchor_option_present"=>get_option(MAD4B_Native_Host_Exact_Grant_V1::DYNAMIC_ANCHOR_PREFIX.$c."_".$e,false)!==false,
 "anchor_readback"=>$a]],JSON_UNESCAPED_SLASHES);
'''
    try:
        result=subprocess.run(issuer.original_central_wp()+["eval",php],
            capture_output=True,text=True,timeout=32)
    except subprocess.TimeoutExpired as exc:
        raise CaptureHeld("FIRST_ANCHOR_WPCLI_BUSY") from exc
    require(result.returncode==0,"FIRST_ANCHOR_WPCLI_READ_DENIED")
    lines=[x for x in result.stdout.splitlines() if x.startswith("{")]
    require(len(lines)==1 and len(lines[0])<50000,
            "FIRST_ANCHOR_WPCLI_READ_AMBIGUOUS")
    try: values=json.loads(lines[0])
    except ValueError as exc:
        raise CaptureHeld("FIRST_ANCHOR_WPCLI_JSON_INVALID") from exc
    require(type(values) is dict,"FIRST_ANCHOR_ORIGINALS_NOT_OBJECT")
    return values

def validate_queued(index, original, plan, job_id, *, now=None):
    """Revalidate through original PINNED V95. No synthesized QUEUED job."""
    from registry import Registry
    current=int(time.time()) if now is None else now
    require(type(index) is Registry and index.root==REGISTRY,
            "FIRST_ANCHOR_ORIGINAL_REGISTRY_REQUIRED")
    require(isinstance(job_id,str) and HEX32.fullmatch(job_id),
            "FIRST_ANCHOR_JOB_ID_INVALID")
    require(type(original) is dict and set(original)==
      {"queued","pending","request","grant","parent","transport","site"},
      "FIRST_ANCHOR_LIVE_ORIGINAL_SET_REQUIRED")
    q=original["queued"]
    request=original["request"]
    pending=original["pending"]
    require(type(pending) is list and len(pending)==1
        and all(type(original[k]) is dict for k in
          ("queued","request","grant","parent","transport","site")),
        "FIRST_ANCHOR_QUEUED_AND_OWNER_ORIGINALS_REQUIRED")
    require(q.get("job_id")==job_id
        and q.get("state")=="QUEUED_FOR_VPS_BROKER_CLAIM"
        and request.get("request_id")==job_id
        and q.get("company")=="all-royal-egypt"
        and q.get("environment")=="staging"
        and q.get("target_host_id")=="hosting-2"
        and q.get("operation_id")==PLAN
        and q.get("host_anchor_issuer_commissioned") is False
        and type(q.get("expires_epoch")) is int
        and type(request.get("expires_epoch")) is int
        and q["expires_epoch"]>=current+135
        and request["expires_epoch"]>=current+135,
        "FIRST_ANCHOR_OWNER_JOB_NOT_FRESH_QUEUED")
    require(plan.get("plan_sha256")==q.get("plan_sha256")
        and isinstance(q.get("plan_sha256"),str)
        and HEX64.fullmatch(q["plan_sha256"]),
        "FIRST_ANCHOR_IMMUTABLE_ROOT_PLAN_REQUIRED")
    verifier=module(V95,V95_SHA,"_host_v118_original305_306")
    passed=verifier.verify_against_originals(
      index,pending[0],q,plan,
      original["request"],original["grant"],original["parent"],
      original["transport"],original["site"],now=current)
    require(passed.get("state")=="VERIFIED_ORIGINAL305_OWNER_BOOTSTRAP__CLAIM_ONLY"
        and passed.get("execution_authorized") is False
        and passed.get("original313_certified") is False
        and passed.get("anchor_issued") is False,
        "FIRST_ANCHOR_QUEUED_ORIGINAL_UNVERIFIED")
    return {"contract":CONTRACT,"job_id":job_id,
        "company":"all-royal-egypt","environment":"staging",
        "plan_sha256":q["plan_sha256"],
        "captured_epoch":current,
        "originals":original,
        "issuer_execution_authorized":False,
        "anchor_issued":False}

def require_store(root,*,create=False):
    require(os.geteuid()==0,"FIRST_ANCHOR_ROOT_REQUIRED")
    require(root.is_absolute() and not root.is_symlink(),
            "FIRST_ANCHOR_QUEUE_STORE_UNTRUSTED")
    require(root.parent.is_dir() and not root.parent.is_symlink(),
            "FIRST_ANCHOR_QUEUE_PARENT_MISSING")
    st=root.parent.stat()
    require(st.st_uid==0 and st.st_gid==0
        and stat.S_IMODE(st.st_mode)==0o700,
        "FIRST_ANCHOR_QUEUE_PARENT_UNTRUSTED")
    if not root.exists() and create:root.mkdir(mode=0o700)
    require(root.is_dir() and not root.is_symlink()
        and root.stat().st_uid==0 and root.stat().st_gid==0
        and stat.S_IMODE(root.stat().st_mode)==0o700,
        "FIRST_ANCHOR_QUEUE_STORE_NOT_ROOT_PROTECTED")

def read_preimage(job_id,root=STORE):
    require_store(root)
    require(isinstance(job_id,str) and HEX32.fullmatch(job_id),
            "FIRST_ANCHOR_JOB_ID_INVALID")
    p=root/(job_id+".json")
    require(p.is_file() and not p.is_symlink(),
            "FIRST_ANCHOR_QUEUED_PREIMAGE_MISSING")
    st=p.stat()
    require(st.st_uid==0 and st.st_gid==0 and st.st_nlink==1
        and stat.S_IMODE(st.st_mode)==0o600
        and 100<st.st_size<32000,
        "FIRST_ANCHOR_QUEUED_PREIMAGE_UNTRUSTED")
    data=json.loads(p.read_bytes())
    sha=data.pop("capture_sha256",None)
    require(type(sha) is str and HEX64.fullmatch(sha)
        and digest(data)==sha and data.get("job_id")==job_id
        and data.get("contract")==CONTRACT
        and data.get("issuer_execution_authorized") is False
        and data.get("anchor_issued") is False,
        "FIRST_ANCHOR_QUEUED_PREIMAGE_CHANGED")
    return data

def store_preimage(bundle,root=STORE):
    """O_EXCL + fsync; duplicate identical is idempotent, drift fails."""
    require(type(bundle) is dict
        and set(bundle)=={"contract","job_id","company","environment",
              "plan_sha256","captured_epoch","originals",
              "issuer_execution_authorized","anchor_issued"}
        and bundle.get("contract")==CONTRACT
        and bundle.get("company")=="all-royal-egypt"
        and bundle.get("environment")=="staging"
        and bundle.get("issuer_execution_authorized") is False
        and bundle.get("anchor_issued") is False,
        "FIRST_ANCHOR_PREIMAGE_BUNDLE_INVALID")
    require_store(root,create=True)
    jid=bundle["job_id"]
    require(type(jid) is str and HEX32.fullmatch(jid),
            "FIRST_ANCHOR_JOB_ID_INVALID")
    path=root/(jid+".json")
    record={**bundle,"capture_sha256":digest(bundle)}
    raw=json.dumps(record,sort_keys=True,separators=(",",":"),
        ensure_ascii=False).encode()+b"\n"
    require(len(raw)<32000,"FIRST_ANCHOR_QUEUE_BUNDLE_TOO_LARGE")
    try:
        fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    except FileExistsError:
        old=read_preimage(jid,root=root)
        # Every retry must freshly pass v95, but no new historical timestamp
        # is minted for the exact same queued original. Root capture is
        # first-write-wins; job/request/Grant/328/318 bytes cannot drift.
        require(old.get("captured_epoch",0)<=bundle["captured_epoch"]
            and {k:v for k,v in old.items() if k!="captured_epoch"}
             =={k:v for k,v in bundle.items() if k!="captured_epoch"},
            "FIRST_ANCHOR_DUPLICATE_QUEUE_CAPTURE_CHANGED")
        return "ORIGINAL306_QUEUED_PREIMAGE_ALREADY_STORED"
    try:
        with os.fdopen(fd,"wb") as f:
            f.write(raw);f.flush();os.fsync(f.fileno())
        d=os.open(root,os.O_RDONLY|os.O_DIRECTORY)
        try:os.fsync(d)
        finally:os.close(d)
    except BaseException:
        path.unlink(missing_ok=True)
        raise
    return "ORIGINAL306_QUEUED_PREIMAGE_CAPTURED"

def capture_live(job_id):
    """Standalone API for future fixed Original306 claim hook only."""
    require(os.geteuid()==0,"FIRST_ANCHOR_ROOT_REQUIRED")
    if str(REGISTRY) not in sys.path:sys.path.insert(0,str(REGISTRY))
    from registry import Registry, checked_json
    index=Registry(REGISTRY)
    original=owner_state(job_id)
    q=original.get("queued")
    require(type(q) is dict and isinstance(q.get("plan_sha256"),str)
            and HEX64.fullmatch(q["plan_sha256"]),
            "FIRST_ANCHOR_ORIGINAL_PLAN_NOT_QUEUED")
    plan=checked_json(PLAN_ROOT/(q["plan_sha256"]+".json"),PLAN_ROOT)
    bundle=validate_queued(index,original,plan,job_id)
    return store_preimage(bundle,root=STORE)

def main(argv=None):
    import argparse
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument("--job-id",required=True)
    p.add_argument("--capture",action="store_true",
        help="Root first-party capture only; no claim/sign/issue")
    args=p.parse_args(argv)
    try:
        require(os.geteuid()==0,"FIRST_ANCHOR_ROOT_REQUIRED")
        require(HEX32.fullmatch(args.job_id),"FIRST_ANCHOR_JOB_ID_INVALID")
        if args.capture:
            state=capture_live(args.job_id)
            output={"state":state,"job_id":args.job_id,
                    "anchor_issued":False,"signing_performed":False}
            code=0
        else:
            original=owner_state(args.job_id)
            q=original.get("queued")
            present=type(q) is dict and q.get("state")=="QUEUED_FOR_VPS_BROKER_CLAIM"
            output={"state":"QUEUED_ORIGINAL_PRESENT" if present
                    else "HOLD_NO_CURRENT_QUEUED_ORIGINAL",
                    "queued":bool(present),"signing_performed":False,
                    "anchor_issued":False}
            code=0 if present else 2
    except (CaptureHeld,ValueError,KeyError,TypeError) as exc:
        reason=str(exc)
        reason=reason if re.fullmatch(r"[A-Z0-9_]{4,100}",reason) else "FIRST_ANCHOR_CAPTURE_DENIED"
        output={"state":"HOLD","reason":reason,"anchor_issued":False,
                "signing_performed":False}
        code=2
    print(json.dumps(output,sort_keys=True))
    return code

if __name__=="__main__":
    raise SystemExit(main())
