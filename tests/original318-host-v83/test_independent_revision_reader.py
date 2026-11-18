#!/usr/bin/env python3
"""Pure crypto regression, ephemeral synthetic keys; no original grants."""
from __future__ import annotations
import base64
import copy
import hashlib
import importlib.util
from pathlib import Path
import time
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding, PublicFormat

ROOT = Path(__file__).resolve().parents[2]
MODULE = ROOT / "deploy/host-authority/v83/independent_current_reader.py"
spec = importlib.util.spec_from_file_location("readback", MODULE)
assert spec is not None and spec.loader is not None
reader = importlib.util.module_from_spec(spec)
spec.loader.exec_module(reader)
gate = reader._v81_revision_verifier()
now = int(time.time())
broker = Ed25519PrivateKey.generate()
independent = Ed25519PrivateKey.generate()
pub = lambda key: key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
job = {
 "company":"all-royal-egypt","environment":"staging",
 "site_uuid":"49c562d1-8f2f-456f-b454-26816c6ba4cb",
 "target_host_id":"hosting-2","ssh_principal":"u948953904",
 "operation_id":"company.host-authority.anchor.issue",
 "job_id":"1"*32, "anchor_revision":2,"grant_revision":3,
 "job_type":"signed_remote_hostinger_wpcli_v2",
 "principal_type":"signed_host_principal_v2",
 "state":"BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY",
 "executed":False,
}
ledger = {
 "contract":gate.LEDGER,"company":job["company"],
 "environment":job["environment"],"site_uuid":job["site_uuid"],
 "target_host_id":job["target_host_id"],"ssh_principal":job["ssh_principal"],
 "revision":2,"grant_revision":3,"anchor_nonce":"a"*32,"state":"ACTIVE",
 "event_id":"2"*32,"previous_event_sha256":"0"*64,
 "issued_epoch":now-20,"expires_epoch":now+200
}
ledger["signature"]=base64.b64encode(broker.sign(
    gate.body(ledger,gate.FIELDS,"MAD4B_ORIGINAL_HOST_LEDGER_V1")
)).decode()
checkpoint = {
 "contract":gate.CHECKPOINT,"company":job["company"],
 "environment":job["environment"],"site_uuid":job["site_uuid"],
 "revision":2,"grant_revision":3,"anchor_nonce":"a"*32,
 "state":"ACTIVE",
 "event_sha256":hashlib.sha256(
    gate.body(ledger,gate.FIELDS,"MAD4B_ORIGINAL_HOST_LEDGER_V1")
 ).hexdigest(),
 "issued_epoch":now-1,"expires_epoch":now+35,
}
checkpoint["signature"]=base64.b64encode(independent.sign(
    gate.body(checkpoint,gate.C_FIELDS,"MAD4B_ORIGINAL_HOST_CHECKPOINT_V1")
)).decode()
wp = {
 "contract":"mad4b.original318.host-anchor-current-readback.v2",
 "source":"original318_fresh_wp_readback",
 "company":job["company"],"environment":job["environment"],
 "site_uuid":job["site_uuid"],"state":"ACTIVE","revision":2,
 "anchor_nonce":"a"*32,"observed_epoch":now,
 "readback_verified":True,"current_anchor_signature_verified":True,
 "signed_anchor_active_in_option":True,"current_anchor_eligible":False,"fresh_central_highwater_verified":False,
 "original313_certified":False,"write_authorized":False,
}
checks=0
def verify(j=job,l=ledger,c=checkpoint,w=wp,b=pub(broker),i=pub(independent),t=now):
    return reader.verify_signed_current(j,l,c,w,b,i,now=t)
def deny(operation):
    global checks
    try:operation()
    except (gate.RevisionDenied, reader.CurrentReadbackDenied):
        checks+=1
    else:raise AssertionError(f"expected DENIAL at check {checks+1}")
result=verify()
assert result["state"]=="CURRENT_ORIGINAL_REVISION_MATCHED__NONAUTHORIZING"
assert result["execution_authorized"] is False and result["original313_certified"] is False
checks+=1
j=copy.deepcopy(job);j["anchor_revision"]=1;deny(lambda:verify(j=j))
j=copy.deepcopy(job);j["company"]="egypt-tour-gates";deny(lambda:verify(j=j))
l=copy.deepcopy(ledger);l["revision"]=3;deny(lambda:verify(l=l))
l=copy.deepcopy(ledger);l["signature"]="AAA";deny(lambda:verify(l=l))
c=copy.deepcopy(checkpoint);c["event_sha256"]="f"*64;deny(lambda:verify(c=c))
c=copy.deepcopy(checkpoint);c["revision"]=1;deny(lambda:verify(c=c))
w=copy.deepcopy(wp);w["readback_verified"]=False;deny(lambda:verify(w=w))
w=copy.deepcopy(wp);w["state"]="REVOKED";deny(lambda:verify(w=w))
w=copy.deepcopy(wp);w["fresh_central_highwater_verified"]=True;deny(lambda:verify(w=w))
w=copy.deepcopy(wp);w["original313_certified"]=True;deny(lambda:verify(w=w))
w=copy.deepcopy(wp);w["observed_epoch"]=now-40;deny(lambda:verify(w=w))
deny(lambda:verify(i=pub(broker)))
deny(lambda:verify(t=now+60))
j=copy.deepcopy(job);j["company"]="../staging";deny(lambda:verify(j=j))
j=copy.deepcopy(job);j["operation_id"]="company.code.release";deny(lambda:verify(j=j))
j=copy.deepcopy(job);j["executed"]=True;deny(lambda:verify(j=j))
j=copy.deepcopy(job);j["principal_type"]="wp_admin";deny(lambda:verify(j=j))
w=copy.deepcopy(wp);w["current_anchor_eligible"]=True;deny(lambda:verify(w=w))
w=copy.deepcopy(wp);w["signed_anchor_active_in_option"]=False;deny(lambda:verify(w=w))
deny(lambda:reader.read_current(job)) # Missing independent root event is HOLD
print({"state":"PASS","tests":checks,
       "execution_authorized":False,"original313_signed":False,
       "no_issuer_or_private_key_in_module":True})
