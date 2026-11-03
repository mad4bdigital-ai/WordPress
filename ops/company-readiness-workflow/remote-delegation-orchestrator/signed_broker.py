#!/usr/bin/env python3
"""Root-protected fixed 305 broker adapter; not an arbitrary shell executor."""
from __future__ import annotations
import base64
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import time
import secrets

from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding, PublicFormat
from remote_delegation import Refused, Target

CONTRACT = "mad4b.remote-host-305-request.v1"
RESPONSE = "mad4b.remote-host-305-readback.v1"
HOST = "srv2030908"
ROOT = Path("/opt/mad4b-company-transport/broker-v1")
PRIVATE = ROOT/"host-ed25519-private.pem"
PUBLIC = ROOT/"host-ed25519-public.base64"
HANDLER = Path("/opt/mad4b-company-provisioning/source-candidates/issue724-remote-authority/remote_authority.py")
FIELDS = ("contract","action","host_id","company","environment",
          "site_uuid","canonical_origin","issued","expires","nonce")

def root_protected(path:Path,limit:int,executable=False)->bytes:
    try:st=path.lstat()
    except OSError as exc:raise Refused("ORIGINAL_BROKER_FILE_MISSING") from exc
    if (path.is_symlink() or not stat.S_ISREG(st.st_mode) or
        st.st_uid!=0 or st.st_size>limit or
        (st.st_mode&0o022 if executable else st.st_mode&0o077)):
        raise Refused("ORIGINAL_BROKER_FILE_NOT_TRUSTED")
    return path.read_bytes()

def signed_request(action:str, target:Target, now:int, private_key, public_bytes:bytes)->dict:
    if action!="reconcile305" or target.environment!="staging":
        raise Refused("BROKER_FIXED_RECONCILE305_ONLY")
    if not isinstance(private_key,Ed25519PrivateKey):
        raise Refused("BROKER_ED25519_PRIVATE_REQUIRED")
    if private_key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)!=public_bytes:
        raise Refused("BROKER_PINNED_KEY_MISMATCH")
    fields={"contract":CONTRACT,"action":action,"host_id":HOST,"company":target.company,
            "environment":target.environment,"site_uuid":target.site_uuid,
            "canonical_origin":target.canonical_origin,"issued":now,
            "expires":now+55,"nonce":secrets.token_hex(16)}
    wire=json.dumps({k:fields[k] for k in FIELDS},sort_keys=True,separators=(",",":"),ensure_ascii=True).encode()
    fields["signature"]=base64.b64encode(private_key.sign(wire)).decode("ascii")
    return fields

class SignedOriginal305Broker:
    def __init__(self, runner=None, private:Path=PRIVATE, public:Path=PUBLIC,handler:Path=HANDLER):
        self.runner=runner or subprocess.run
        self.private=private
        self.public=public
        self.handler=handler
    def __call__(self,action:str,target:Target)->dict:
        if os.geteuid()!=0:
            raise Refused("ORIGINAL_ROOT_BROKER_REQUIRED")
        if action!="reconcile305" or target.environment!="staging":
            raise Refused("BROKER_OPERATION_DENIED")
        # The handler and both keys are fixed root-protected local resources;
        # no caller-supplied executable, SSH shell, flags or command template.
        root_protected(self.handler,100000,executable=True)
        raw=root_protected(self.private,512)
        pub=base64.b64decode(root_protected(self.public,512).strip(),validate=True)
        if len(pub)!=32:raise Refused("BROKER_PINNED_PUBLIC_INVALID")
        try:key=serialization.load_pem_private_key(raw,password=None)
        except (ValueError,TypeError) as exc:raise Refused("BROKER_SIGNER_INVALID") from exc
        req=signed_request(action,target,int(time.time()),key,pub)
        try:
            r=self.runner([sys.executable,str(self.handler)],
                input=json.dumps(req,sort_keys=True,separators=(",",":")),
                capture_output=True,text=True,timeout=100,check=False)
        except (subprocess.SubprocessError, OSError) as exc:
            raise Refused("ORIGINAL_305_DISPATCH_UNCERTAIN") from exc
        if r.returncode!=0:raise Refused("ORIGINAL_305_DISPATCH_DENIED_OR_UNCERTAIN")
        if len(r.stdout)>32768:raise Refused("ORIGINAL_305_RECEIPT_TOO_LARGE")
        try:receipt=json.loads(r.stdout)
        except ValueError as exc:raise Refused("ORIGINAL_305_RECEIPT_INVALID") from exc
        if (not isinstance(receipt,dict) or receipt.get("contract")!=RESPONSE or
            receipt.get("target")!=target.company+":staging" or
            receipt.get("action")!=action or
            receipt.get("production_mutated") is not False):
            raise Refused("ORIGINAL_305_EXACT_RECEIPT_MISMATCH")
        return receipt

