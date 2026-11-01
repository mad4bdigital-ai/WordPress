#!/usr/bin/env python3
"""Sign *historical* Native Operator evidence for a 306 Job, then ask 324 to reconcile.

Never executes the command, revives an expired grant, invents an 314 normal
receipt, or accepts arbitrary file paths. Can also preflight without writing.
"""
import argparse
import base64
import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import sqlite3
import stat
import subprocess
import sys
import time
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey

CONTRACT="mad4b.broker-late-native-reconciliation.v1"
HOST="srv2030908"
ROOT=Path("/opt/mad4b-company-transport")
KEY=ROOT/"broker-v1/host-ed25519-private.pem"
POLICY=ROOT/"native-operator-v1/host-authority.json"
RECEIPTS=ROOT/"native-operator-v1/receipts"
JOURNAL=ROOT/"dispatch-queue-v1.sqlite3"
EVIDENCE=Path("/opt/staging/mad4b-native-client-integration-20261101")
WP=["/usr/bin/docker","exec","-u","www-data","etg-commerce-staging-php","wp","--path=/var/www/html","--skip-themes"]
FIELDS=("contract","host_id","job_id","grant_id","transport_id",
  "company","site_uuid","origin","environment","principal_type","principal_id",
  "correlation_id","command_sha256","host_policy_sha256","native_receipt_sha256",
  "readback_sha256","expected_readback_sha256","intent","native_state",
  "started_epoch","finished_epoch","command_exit_code","readback_exit_code",
  "issued","expires","nonce")

def exact_root_file(path,max_bytes=65536):
    st=path.lstat()
    if not stat.S_ISREG(st.st_mode) or st.st_uid!=0 or st.st_mode & 0o077 or not 1<=st.st_size<=max_bytes:
        raise RuntimeError("ROOT_OWNED_EVIDENCE_FILE_REQUIRED")
    return path.read_bytes()

def load_job(job_id):
    if not re.fullmatch(r"[0-9a-f]{32}",job_id):
        raise RuntimeError("JOB_ID_INVALID")
    code=(f'$id="{job_id}";$j=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);'
          f'$c=get_option(MAD4B_Central_VPS_Broker_V1::CLAIM.$id,false);'
          'echo wp_json_encode(["job"=>$j,"claim"=>$c]);')
    r=subprocess.run(WP+["eval",code],check=True,capture_output=True,text=True,timeout=25)
    out=json.loads(r.stdout.strip().splitlines()[-1])
    if not isinstance(out.get("job"),dict) or not isinstance(out.get("claim"),dict):
        raise RuntimeError("ORIGINAL_WP_JOB_OR_CLAIM_MISSING")
    return out["job"],out["claim"]

def valid_time(value):
    if not isinstance(value,str):
        raise RuntimeError("NATIVE_TIMESTAMP_INVALID")
    dt=datetime.datetime.fromisoformat(value.replace("Z","+00:00"))
    if dt.tzinfo is None:
        raise RuntimeError("NATIVE_TIMESTAMP_NOT_UTC_AWARE")
    return int(dt.timestamp())

def journal_attests(correlation,job,claim,started,finished):
    if not JOURNAL.is_file():
        raise RuntimeError("ORIGINAL_DISPATCH_JOURNAL_MISSING")
    with sqlite3.connect(f"file:{JOURNAL}?mode=ro",uri=True,timeout=10) as db:
        rows=db.execute("SELECT at,event,detail FROM events WHERE job_id=? AND event IN (?,?) ORDER BY at",
              ("operator:"+correlation,"OPERATOR_EXECUTION_ATTEMPTED","OPERATOR_VERIFIED")).fetchall()
    events={}
    for at,event,raw in rows:
        if not isinstance(at,int) or at<job["created_epoch"] or at>job["expires_epoch"]:
            raise RuntimeError("HOST_JOURNAL_TIMESTAMP_OUT_OF_SCOPE")
        try: detail=json.loads(raw)
        except (ValueError,TypeError):
            raise RuntimeError("HOST_JOURNAL_DETAIL_INVALID")
        if detail.get("company")!="tours-central" or detail.get("environment")!="staging":
            raise RuntimeError("HOST_JOURNAL_SCOPE_MISMATCH")
        if detail.get("command_sha256")!=job["command_sha256"]:
            raise RuntimeError("HOST_JOURNAL_COMMAND_MISMATCH")
        events.setdefault(event,[]).append(at)
    if len(events.get("OPERATOR_EXECUTION_ATTEMPTED",[]))!=1 or len(events.get("OPERATOR_VERIFIED",[]))!=1:
        raise RuntimeError("EXACTLY_ONE_HOST_EXECUTION_REQUIRED")
    attempt=events["OPERATOR_EXECUTION_ATTEMPTED"][0]
    verified=events["OPERATOR_VERIFIED"][0]
    if attempt>verified or abs(attempt-started)>2 or abs(verified-finished)>2:
        raise RuntimeError("HOST_JOURNAL_NATIVE_TIME_MISMATCH")

def read_evidence(job_id):
    if os.geteuid()!=0 or not os.environ.get("SSH_CONNECTION"):
        raise RuntimeError("AUTHENTICATED_ROOT_SSH_REQUIRED")
    host=subprocess.run(["/usr/bin/hostname","-s"],capture_output=True,text=True,check=True,timeout=8).stdout.strip()
    if host!=HOST:raise RuntimeError("HOST_ID_MISMATCH")
    job,claim=load_job(job_id)
    now=int(time.time())
    if (job.get("job_id")!=job_id or job.get("state")!="BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY"
        or job.get("executed") is not False or job.get("principal_type")!="vps_native"
        or job.get("principal_id")!="vps-native:srv2030908"
        or job.get("company")!="tours-central" or job.get("environment")!="staging"
        or job.get("canonical_origin")!="https://tours.dev.mad4b.com"
        or job.get("target_host_id")!=HOST or job.get("broker_host_id")!=HOST
        or not isinstance(job.get("expires_epoch"),int)
        or not isinstance(claim.get("claimed_epoch"),int)
        or not job["expires_epoch"]<now<job["expires_epoch"]+7*86400):
        raise RuntimeError("NOT_AN_EXPIRED_EXACT_NATIVE_JOB")
    correlation=job.get("correlation_id")
    if not isinstance(correlation,str) or not re.fullmatch(r"[A-Za-z0-9_.-]{8,90}",correlation):
        raise RuntimeError("INVALID_NATIVE_CORRELATION")
    receipt_path=RECEIPTS/(correlation+".json")
    native_raw=exact_root_file(receipt_path)
    native=json.loads(native_raw)
    original={
        "contract":"mad4b.native-vps-operator.v1","host_id":HOST,
        "company":"tours-central","environment":"staging",
        "lane":"vps_ssh","kind":"shell","intent":"diagnostic",
        "state":"VERIFIED","execution_performed":True,
        "readback_matches_expected":True,"mutating":False,
        "mutation_performed":False,"command_sha256":job["command_sha256"],
        "exit_code":0,"readback_exit_code":0,
    }
    for k,v in original.items():
        if native.get(k)!=v:raise RuntimeError("DIRECT_NATIVE_RECEIPT_MISMATCH_"+k)
    rb=native.get("readback_sha256")
    if not isinstance(rb,str) or not re.fullmatch(r"[a-f0-9]{64}",rb) or rb!=native.get("expected_readback_sha256"):
        raise RuntimeError("NATIVE_READBACK_DIGEST_MISMATCH")
    start,end=valid_time(native.get("started_at")),valid_time(native.get("finished_at"))
    if start<claim["claimed_epoch"] or end<start or end>job["expires_epoch"]:
        raise RuntimeError("DIRECT_EXECUTION_NOT_PRE_EXPIRY")
    journal_attests(correlation,job,claim,start,end)
    raw_policy=exact_root_file(POLICY)
    policy=json.loads(raw_policy)
    scope=policy.get("scopes",{}).get("tours-central:staging",{})
    policy_sha=hashlib.sha256(raw_policy).hexdigest()
    if (policy.get("host_id")!=HOST or policy.get("contract")!="mad4b.native-vps-operator.v1"
        or policy.get("revoked") is not False or policy.get("allow_general_shell") is not True
        or scope.get("allow_operator") is not True
        or policy_sha!=job.get("host_policy_sha256")):
        raise RuntimeError("HOST_POLICY_REVOKED_OR_CHANGED")
    signer=serialization.load_pem_private_key(exact_root_file(KEY,16000),password=None)
    if not isinstance(signer,Ed25519PrivateKey):raise RuntimeError("PINNED_BROKER_SIGNER_MISSING")
    pub=subprocess.run(WP+["option","get","mad4b_central_broker_signing_public_v1"],
          capture_output=True,text=True,check=True,timeout=18)
    from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat
    if not secrets.compare_digest(base64.b64decode(pub.stdout.strip(),validate=True),
           signer.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)):
        raise RuntimeError("BROKER_SIGNING_KEY_UNPINNED")
    return job,native_raw,native,start,end,policy_sha,signer

def main():
    a=argparse.ArgumentParser(description=__doc__)
    a.add_argument("--job",required=True)
    a.add_argument("--apply",action="store_true",help="Record signed historical reconciliation; never run shell")
    args=a.parse_args()
    job,raw,native,start,end,policy_sha,key=read_evidence(args.job)
    result={"contract":CONTRACT,"job_id":job["job_id"],"state":"EVIDENCE_VERIFIED_PRE_EXPIRY",
        "host_journal_verified":True,"native_receipt_sha256":hashlib.sha256(raw).hexdigest(),
        "original_job_expired":True,"shell_executed_by_this_command":False,
        "central_completion_recorded":False}
    if not args.apply:
        print(json.dumps(result,sort_keys=True));return
    now=int(time.time())
    data={
        "contract":CONTRACT,"host_id":HOST,"job_id":job["job_id"],
        "grant_id":job["grant_id"],"transport_id":job["transport_id"],
        "company":job["company"],"site_uuid":job["site_uuid"],
        "origin":job["canonical_origin"],"environment":job["environment"],
        "principal_type":job["principal_type"],"principal_id":job["principal_id"],
        "correlation_id":job["correlation_id"],"command_sha256":job["command_sha256"],
        "host_policy_sha256":policy_sha,"native_receipt_sha256":hashlib.sha256(raw).hexdigest(),
        "readback_sha256":native["readback_sha256"],"expected_readback_sha256":native["expected_readback_sha256"],
        "intent":"diagnostic","native_state":"VERIFIED",
        "started_epoch":start,"finished_epoch":end,
        "command_exit_code":native["exit_code"],"readback_exit_code":native["readback_exit_code"],
        "issued":now,"expires":now+60,"nonce":secrets.token_hex(16)}
    if tuple(data)!=FIELDS:raise RuntimeError("ORIGINAL_SIGNED_FIELDS_MISMATCH")
    body="\n".join(str(data[k]) for k in FIELDS)
    data["signature"]=base64.b64encode(key.sign(body.encode())).decode()
    payload=base64.b64encode(json.dumps(data,separators=(",",":")).encode()).decode()
    # No command bytes, no shell invocation: original authorized WordPress CLI
    # ingests the independently signed historical evidence.
    r=subprocess.run(WP+["mad4b","broker-late-native","reconcile","--payload="+payload],
      capture_output=True,text=True,timeout=35)
    if r.returncode:
        raise RuntimeError("CENTRAL_LATE_RECEIPT_REJECTED: "+r.stderr.strip()[-300:])
    response=json.loads(r.stdout.strip().splitlines()[-1])
    if (response.get("verified") is not True
      or response.get("central_completion_recorded") is not True
      or response.get("job_id")!=job["job_id"]
      or response.get("state")!="GENERAL_SHELL_HISTORICAL_EXECUTION_RECONCILED"):
        raise RuntimeError("CENTRAL_READBACK_MISMATCH")
    print(json.dumps({"job_id":job["job_id"],"state":response["state"],
        "central_completion_recorded":True,
        "native_receipt_sha256":hashlib.sha256(raw).hexdigest(),
        "shell_executed_by_this_command":False},sort_keys=True))

if __name__=="__main__":
    try:main()
    except (RuntimeError,ValueError,TypeError,OSError,subprocess.CalledProcessError) as exc:
        print("LATE_NATIVE_RECONCILIATION_DENIED: "+str(exc),file=sys.stderr)
        raise SystemExit(2)
