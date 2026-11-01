#!/usr/bin/env python3
"""Staging-only native VPS dispatcher: original signed submit -> claim -> 312 -> direct Native Operator -> signed 314.

Requires an exact root-owned manifest AND exact root-owned command/readback files.
No command bytes are retrieved from WordPress. Never retries execution after a Native receipt exists.
"""
import argparse
import base64
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import secrets
import stat
import subprocess
import sys
import time

ROOT=Path("/opt/mad4b-company-transport")
BROKER=ROOT/"broker-v1"
OPERATOR=ROOT/"native-operator-v1/vps-native-operator.py"
RECEIPTS=ROOT/"native-operator-v1/receipts"
CLIENT=BROKER/"native-vps-client-principal.py"
VERIFIER=BROKER/"vps-broker-exact-authority.py"
COMMANDS=Path("/opt/staging/mad4b-central-host-commands")
INBOX=COMMANDS/"dispatch-inbox"
WP=["/usr/bin/docker","exec","-u","www-data","etg-commerce-staging-php","wp","--path=/var/www/html","--skip-themes"]
CONTRACT="mad4b.native-central-dispatch.v1"
SIGNED_FIELDS=("contract","host_id","job_id","target_id","grant_id",
 "transport_id","company","site_uuid","origin","environment","operation",
 "command_sha256","readback_sha256","expected_readback_sha256",
 "state","command_exit_code","readback_exit_code","issued","expires","nonce")
CORR=re.compile(r"[A-Za-z0-9_.-]{8,90}")
JOB=re.compile(r"[a-f0-9]{32}")

def reject(reason):
    raise RuntimeError(reason)

def owned(path,limit=4096):
    if not path.is_absolute() or path.is_symlink():reject("ROOT_ABSOLUTE_NON_SYMLINK_REQUIRED")
    st=path.lstat()
    if not stat.S_ISREG(st.st_mode) or st.st_uid!=0 or st.st_mode & 0o077:
        reject("ROOT_ONLY_REGULAR_FILE_REQUIRED")
    if not 1<=st.st_size<=limit:reject("FILE_LENGTH_REJECTED")
    return path.read_bytes()

def source(filename,name):
    spec=importlib.util.spec_from_file_location(name,str(filename))
    module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

def wp_read(job_id):
    if not JOB.fullmatch(job_id):reject("INVALID_JOB_ID")
    code=('$id="'+job_id+'";$j=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);'
          '$r=get_option(MAD4B_Broker_General_Shell_Receipt_V1::PREFIX.$id,false);'
          'echo wp_json_encode(["job"=>$j,"receipt"=>$r]);')
    p=subprocess.run(WP+["eval",code],check=True,capture_output=True,text=True,timeout=22)
    data=json.loads(p.stdout.strip().splitlines()[-1])
    if not isinstance(data.get("job"),dict):reject("ORIGINAL_WP_JOB_MISSING")
    return data

def cli_json(cmd,timeout):
    p=subprocess.run(cmd,check=False,capture_output=True,text=True,timeout=timeout)
    if p.returncode:reject("ORIGINAL_ADMISSION_OR_EXECUTION_REJECTED:"+p.stderr.strip()[-170:])
    try:return json.loads(p.stdout.strip().splitlines()[-1])
    except (ValueError,IndexError) as exc:raise RuntimeError("ORIGINAL_CLI_READBACK_INVALID") from exc

def validate_manifest(manifest):
    data=json.loads(owned(manifest,3500))
    if (not isinstance(data,dict) or set(data)!={"contract","environment","company",
          "correlation_id","command_file","readback_file","expected_output_file"}
       or data["contract"]!=CONTRACT or data["environment"]!="staging"
       or data["company"]!="tours-central"
       or not isinstance(data["correlation_id"],str)
       or not CORR.fullmatch(data["correlation_id"])):
        reject("EXACT_STAGING_MANIFEST_INVALID")
    files={}
    for name in ("command_file","readback_file","expected_output_file"):
        path=Path(data[name])
        if path.parent!=COMMANDS or not path.is_absolute():
            reject("COMMAND_PATH_NOT_ENROLLED")
        files[name]=(path,owned(path,4096))
    if not files["expected_output_file"][1]:reject("EXPECTED_OUTPUT_REQUIRED")
    return data,files

def sign_claim(job,signer):
    now=int(time.time());nonce=secrets.token_hex(16);expiration=now+40
    fields=["mad4b.central-vps-broker.v1","claim",job["job_id"],job["company"],
            job["site_uuid"],job["environment"],job["broker_host_id"],
            nonce,str(now),str(expiration)]
    sig=base64.b64encode(signer.sign("\n".join(fields).encode())).decode()
    got=cli_json(WP+["mad4b","broker","claim",
        "--job="+job["job_id"],"--nonce="+nonce,"--issued="+str(now),
        "--expires="+str(expiration),"--signature="+sig],27)
    if (got.get("state")!="BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY"
        or got.get("job_id")!=job["job_id"]):
        reject("SIGNED_BROKER_CLAIM_MISMATCH")

def submit_and_execute(manifest):
    if os.geteuid()!=0:reject("ROOT_OPERATOR_REQUIRED")
    if not os.environ.get("SSH_CONNECTION"):reject("INDEPENDENT_ROOT_SSH_REQUIRED")
    config,files=validate_manifest(manifest)
    command_file,command=files["command_file"]
    readback_file,_=files["readback_file"]
    expected=files["expected_output_file"][1]
    digest=hashlib.sha256(command).hexdigest()
    expected_digest=hashlib.sha256(expected).hexdigest()
    correlation=config["correlation_id"]
    if (RECEIPTS/(correlation+".json")).exists():
        reject("NATIVE_CORRELATION_ALREADY_CONSUMED_DO_NOT_REEXECUTE")
    client=source(CLIENT,"mad4b_origin_native_client")
    verifier=source(VERIFIER,"mad4b_origin_broker_authority")
    if client.authority()!=hashlib.sha256(client.root_file(client.POLICY,65536)).hexdigest():
        reject("HOST_POLICY_UNEXPECTED")
    signer=client.original_signer()
    client.check_original_wordpress_signer(signer)
    # Input bytes are always taken from the exact protected local file.
    if client.command_file(command_file)!=command.decode("utf-8"):
        reject("EXACT_LOCAL_COMMAND_CHANGED")
    job_result=cli_json([sys.executable,str(CLIENT),"submit","--correlation",correlation,
             "--command-file",str(command_file)],45)
    job_id=job_result.get("job_id")
    if not isinstance(job_id,str) or not JOB.fullmatch(job_id):
        reject("ORIGINAL_SUBMIT_JOB_ID_INVALID")
    row=wp_read(job_id)["job"]
    if (row.get("job_id")!=job_id or row.get("principal_type")!="vps_native"
      or row.get("principal_id")!="vps-native:srv2030908"
      or row.get("company")!="tours-central" or row.get("environment")!="staging"
      or row.get("target_host_id")!="srv2030908"
      or row.get("state")!="QUEUED_FOR_VPS_BROKER_CLAIM"
      or row.get("correlation_id")!=correlation
      or row.get("command_sha256")!=digest):
        reject("ORIGINAL_306_JOB_SCOPE_MISMATCH")
    if row["expires_epoch"]<=time.time()+85:
        reject("CENTRAL_JOB_WINDOW_TOO_SHORT_BEFORE_CLAIM")
    sign_claim(row,signer)
    auth=verifier.verify(job_id,"srv2030908",digest)
    if not (auth.get("verified") is True and auth.get("eligible") is True
      and auth.get("principal_type")=="vps_native"
      and auth.get("correlation_id")==correlation
      and auth.get("grant_id")==row["grant_id"]
      and auth.get("transport_id")==row["transport_id"]):
        reject("SIGNED_312_AUTHORIZATION_INVALID")
    if auth["expires_epoch"]<=time.time()+45:
        reject("CENTRAL_JOB_WINDOW_TOO_SHORT_BEFORE_APPLY")
    opts=["--kind","shell","--intent","diagnostic","--company","tours-central",
        "--environment","staging","--file",str(command_file),"--sha256",digest,
        "--readback-file",str(readback_file),
        "--expected-readback-sha256",expected_digest]
    plan=cli_json([sys.executable,str(OPERATOR),"plan"]+opts,22)
    if plan.get("state")!="ADMITTED_REQUIRES_DIRECT_OPERATOR_APPLY" or plan.get("mutating") is not False:
        reject("NATIVE_OPERATOR_DIAGNOSTIC_NOT_ADMITTED")
    result=cli_json([sys.executable,str(OPERATOR),"apply"]+opts+
                    ["--correlation-id",correlation,"--timeout","15"],37)
    if not (result.get("state")=="VERIFIED" and result.get("exit_code")==0
       and result.get("readback_exit_code")==0
       and result.get("readback_matches_expected") is True
       and result.get("readback_sha256")==expected_digest
       and result.get("command_sha256")==digest):
        reject("NATIVE_EXECUTION_UNCERTAIN_RECONCILE_ONLY")
    native=json.loads(owned(RECEIPTS/(correlation+".json"),65536))
    if native["state"]!="VERIFIED" or native["command_sha256"]!=digest:
        reject("NATIVE_DURABLE_RECEIPT_MISMATCH")
    if row["expires_epoch"]<=time.time()+6:
        reject("LATE_EXECUTION_REQUIRES_HISTORICAL_RECONCILIATION")
    now=int(time.time())
    values=[
      ("contract","mad4b.broker-general-shell-completion.v1"),
      ("host_id","srv2030908"),("job_id",job_id),("target_id",row["target_host_id"]),
      ("grant_id",row["grant_id"]),("transport_id",row["transport_id"]),
      ("company",row["company"]),("site_uuid",row["site_uuid"]),
      ("origin",row["canonical_origin"]),("environment",row["environment"]),
      ("operation",row["operation"]),("command_sha256",digest),
      ("readback_sha256",native["readback_sha256"]),
      ("expected_readback_sha256",native["expected_readback_sha256"]),
      ("state","VERIFIED"),("command_exit_code",native["exit_code"]),
      ("readback_exit_code",native["readback_exit_code"]),
      ("issued",now),("expires",now+40),("nonce",secrets.token_hex(16))
    ]
    if tuple(k for k,_ in values)!=SIGNED_FIELDS:
        reject("ORIGINAL_314_SIGNATURE_SCHEMA_CHANGED")
    proof=dict(values)
    proof["signature"]=base64.b64encode(signer.sign("\n".join(str(v) for _,v in values).encode())).decode()
    payload=base64.b64encode(json.dumps(proof,separators=(",",":")).encode()).decode()
    receipt=cli_json(WP+["mad4b","broker-general-shell","receipt","--payload="+payload],30)
    if not (receipt.get("verified") is True
       and receipt.get("central_completion_recorded") is True
       and receipt.get("state")=="GENERAL_SHELL_EXECUTION_READBACK_VERIFIED"
       and receipt.get("job_id")==job_id):
        reject("ORIGINAL_SIGNED_314_COMPLETION_MISSING")
    persisted=wp_read(job_id)
    if not (persisted["job"].get("state")=="GENERAL_SHELL_EXECUTION_READBACK_VERIFIED"
       and persisted["job"].get("executed") is True
       and persisted["job"].get("independent_readback_verified") is True
       and isinstance(persisted.get("receipt"),dict)):
        reject("CENTRAL_314_READBACK_NOT_PERSISTED")
    return {"contract":CONTRACT,"job_id":job_id,"correlation_id":correlation,
            "state":"CENTRAL_JOB_EXECUTION_AND_RECEIPT_VERIFIED",
            "native_receipt_state":native["state"],
            "central_state":persisted["job"]["state"],
            "production_mutation":False}

if __name__=="__main__":
    try:
        p=argparse.ArgumentParser(description=__doc__)
        p.add_argument("manifest",type=Path)
        args=p.parse_args()
        print(json.dumps(submit_and_execute(args.manifest),sort_keys=True))
    except (OSError,ValueError,TypeError,RuntimeError,subprocess.SubprocessError) as exc:
        print("NATIVE_DISPATCH_STOPPED: "+str(exc),file=sys.stderr)
        raise SystemExit(2)
