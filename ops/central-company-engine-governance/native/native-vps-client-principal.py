#!/usr/bin/env python3
"""Native VPS Client enrollment / exact-job intake using the ORIGINAL root-owned broker signer.

This does not claim a job, execute Shell, or write a completion receipt.
WordPress persists the signed native principal as distinct from any WP user.
"""
import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import stat
import subprocess
import sys
import time
from urllib.request import Request, urlopen
from urllib.error import HTTPError
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey

CONTRACT="mad4b.native-vps-client-principal.v1"
ORIGIN="https://tours.dev.mad4b.com"
HOST="srv2030908"
COMPANY="tours-central"
UUID="e5e64bca-ed62-48d2-b2b3-dff693cd702a"
ROOT=Path("/opt/mad4b-company-transport")
KEY=ROOT/"broker-v1/host-ed25519-private.pem"
POLICY=ROOT/"native-operator-v1/host-authority.json"
HOSTKEY=Path("/etc/ssh/ssh_host_ed25519_key.pub")
COMMAND_ROOT=Path("/opt/staging/mad4b-central-host-commands")
FIELDS=("contract","action","host_id","company","site_uuid","canonical_origin",
 "environment","operation","host_key_fingerprint","policy_sha256",
 "command_sha256","correlation_id","issued","expires","nonce")
HEX64=re.compile(r"^[a-f0-9]{64}$")
CORRELATION=re.compile(r"^[A-Za-z0-9_.-]{8,100}$")

def root_file(path,maximum):
    if path.is_symlink():
        raise RuntimeError("SYMLINK_NOT_ALLOWED")
    st=path.stat()
    if not stat.S_ISREG(st.st_mode) or st.st_uid!=0 or st.st_mode & 0o077:
        raise RuntimeError("ROOT_ONLY_REGULAR_FILE_REQUIRED")
    if st.st_size>maximum:
        raise RuntimeError("FILE_TOO_LARGE")
    return path.read_bytes()

def authority():
    if os.geteuid()!=0:
        raise RuntimeError("ORIGINAL_ROOT_NATIVE_OPERATOR_REQUIRED")
    raw=root_file(POLICY,65536)
    data=json.loads(raw)
    scope=data.get("scopes",{}).get("tours-central:staging",{})
    if (data.get("contract")!="mad4b.native-vps-operator.v1"
        or data.get("host_id")!=HOST or data.get("revoked") is not False
        or data.get("allow_general_shell") is not True
        or scope.get("allow_operator") is not True):
        raise RuntimeError("STAGING_HOST_POLICY_NOT_AUTHORIZED")
    return hashlib.sha256(raw).hexdigest()

def original_signer():
    private=serialization.load_pem_private_key(root_file(KEY,16000),password=None)
    if not isinstance(private,Ed25519PrivateKey):
        raise RuntimeError("ORIGINAL_ED25519_BROKER_SIGNER_REQUIRED")
    return private

def host_fingerprint():
    public=HOSTKEY.read_text()
    if not public.startswith("ssh-ed25519 "):
        raise RuntimeError("HOST_KEY_TYPE_CHANGED")
    p=subprocess.run(["ssh-keygen","-lf",str(HOSTKEY),"-E","sha256"],
                     check=True,text=True,capture_output=True,timeout=12)
    parts=p.stdout.strip().split()
    if len(parts)<2 or not re.fullmatch(r"SHA256:[A-Za-z0-9+/]{43}=?",parts[1]):
        raise RuntimeError("HOST_FINGERPRINT_INVALID")
    return parts[1]

def check_original_wordpress_signer(key):
    wp=["docker","exec","-u","www-data","etg-commerce-staging-php","wp",
        "--path=/var/www/html","--skip-themes","option","get",
        "mad4b_central_broker_signing_public_v1"]
    p=subprocess.run(wp,text=True,capture_output=True,check=True,timeout=25)
    pinned=base64.b64decode(p.stdout.strip(),validate=True)
    from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat
    local=key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
    if not secrets.compare_digest(pinned,local):
        raise RuntimeError("BROKER_SIGNER_NOT_PINNED_BY_WORDPRESS")

def signed_proof(action, policy_sha, fingerprint, digest, correlation, key):
    now=int(time.time())
    fields={
        "contract":CONTRACT,"action":action,"host_id":HOST,"company":COMPANY,
        "site_uuid":UUID,"canonical_origin":ORIGIN,"environment":"staging",
        "operation":"maintenance.shell","host_key_fingerprint":fingerprint,
        "policy_sha256":policy_sha,"command_sha256":digest,
        "correlation_id":correlation,"issued":now,"expires":now+40,
        "nonce":secrets.token_hex(16)}
    assert tuple(fields)==FIELDS
    text="\n".join(str(fields[f]) for f in FIELDS).encode()
    fields["signature"]=base64.b64encode(key.sign(text)).decode()
    return fields

def command_file(path):
    try:
        real=path.resolve(strict=True)
    except OSError as exc:
        raise RuntimeError("COMMAND_FILE_MISSING") from exc
    if real.parent!=COMMAND_ROOT:
        raise RuntimeError("COMMAND_FILE_NOT_IN_ENROLLED_STAGING_DIRECTORY")
    raw=root_file(path,4096)
    if not raw or b"\x00" in raw:
        raise RuntimeError("COMMAND_BYTES_INVALID")
    return raw.decode("utf-8")

def run(action,correlation,command_path):
    policy_sha=authority()
    private=original_signer()
    check_original_wordpress_signer(private)
    fingerprint=host_fingerprint()
    if action in ("enroll","rotate"):
        content=""
        digest="0"*64
        correlation="enrollment"
    else:
        if not CORRELATION.fullmatch(correlation or ""):
            raise RuntimeError("EXACT_CORRELATION_REQUIRED")
        if command_path is None:
            raise RuntimeError("EXACT_COMMAND_FILE_REQUIRED")
        content=command_file(command_path)
        digest=hashlib.sha256(content.encode()).hexdigest()
    proof=signed_proof(action,policy_sha,fingerprint,digest,correlation,private)
    route=f"{ORIGIN}/wp-json/mad4b/v1/native-vps-client/{action}"
    payload=json.dumps({"proof":proof,"command":content},
                       separators=(",",":"),ensure_ascii=False).encode("utf-8")
    request=Request(route,data=payload,method="POST",headers={
        "Content-Type":"application/json","User-Agent":"MAD4B-Root-Native-Principal/1.0"})
    try:
        with urlopen(request,timeout=30) as reply:
            response=json.loads(reply.read(16384))
    except HTTPError as exc:
        # A policy denial is not a host connectivity outage. Surface its
        # original Core reason but never retry or convert it to Shell.
        try:
            body=json.loads(exc.read(16384))
        except (ValueError,UnicodeError):
            body={}
        code=str(body.get("code",""))[:120]
        if exc.code==409 and code=="mad4b_native_vps_signed_policy_rotation_required":
            raise RuntimeError("SIGNED_POLICY_ROTATION_REQUIRED_NO_REPLAY") from None
        raise RuntimeError("NATIVE_HTTP_"+str(exc.code)+":"+code) from None
    if response.get("contract") not in (CONTRACT,"mad4b.central-vps-broker.v1"):
        raise RuntimeError("UNEXPECTED_CENTRAL_RESPONSE_CONTRACT")
    if action=="rotate":
        if response.get("state")!="SIGNED_STANDING_POLICY_ROTATED":
            raise RuntimeError("SIGNED_POLICY_ROTATION_NOT_COMMITTED")
        if response.get("principal_id")!="vps-native:srv2030908":
            raise RuntimeError("SIGNED_POLICY_ROTATION_PRINCIPAL_MISMATCH")
        if (response.get("old_grant_revoked") is not True
            or response.get("old_transport_revoked") is not True
            or response.get("execution_authorized") is not False
            or response.get("execution_performed") is not False):
            raise RuntimeError("SIGNED_POLICY_ROTATION_READBACK_UNCERTAIN")
        print(json.dumps({"state":response["state"],"principal_id":response["principal_id"],
            "grant_id":response.get("grant_id"),
            "transport_id":response.get("transport_id"),
            "old_grant_revoked":True,"shell_executed":False},sort_keys=True))
    elif action=="submit":
        if response.get("state")!="QUEUED_FOR_VPS_BROKER_CLAIM":
            raise RuntimeError("JOB_NOT_QUEUED")
        if response.get("command_sha256")!=digest:
            raise RuntimeError("DISPATCH_COMMAND_DIGEST_CHANGED")
        print(json.dumps({"state":response["state"],"job_id":response["job_id"],
          "command_sha256":digest,"shell_executed":False,
          "independent_host_execution_still_required":True},sort_keys=True))
    else:
        if response.get("principal_id")!="vps-native:srv2030908":
            raise RuntimeError("ENROLLMENT_PRINCIPAL_MISMATCH")
        print(json.dumps({"state":response["state"],"principal_id":response["principal_id"],
          "grant_id":response["grant_id"],"transport_id":response["transport_id"],
          "shell_executed":False},sort_keys=True))

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument("action",choices=["enroll","rotate","submit"])
    p.add_argument("--correlation",default="")
    p.add_argument("--command-file",type=Path)
    a=p.parse_args()
    run(a.action,a.correlation,a.command_file)
if __name__=="__main__":
    try:
        main()
    except (OSError,ValueError,RuntimeError,subprocess.SubprocessError) as exc:
        print("NATIVE_PRINCIPAL_NOT_ENROLLED_OR_SUBMITTED: "+str(exc),file=sys.stderr)
        raise SystemExit(2)
