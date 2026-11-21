#!/usr/bin/env python3
"""Independent MAD4B Owner Ed25519 device-side helper (NO network).

Run ONLY on the Owner's own computer. Generate an encrypted, local private
key; export a public proof-of-possession and, once the genuine Original313
signed offer is available and verified, sign a short-lived exact intent.

THIS IS NOT an authority registrar. Public proof is pending independent
first-party identity enrollment; no certificate/grant is created by init.
"""
from __future__ import annotations
import argparse
import base64
from getpass import getpass
import hashlib
import json
import os
from pathlib import Path
import re
import sys
import time

from cryptography.exceptions import InvalidSignature
from cryptography.hazmat.primitives.asymmetric.ed25519 import (
    Ed25519PrivateKey, Ed25519PublicKey)
from cryptography.hazmat.primitives.serialization import (
    BestAvailableEncryption, Encoding, PrivateFormat,
    PublicFormat, load_pem_private_key)

CONTRACT="mad4b.original305.owner-v3-exact-stepup-consent.v1"
DOMAIN="MAD4B_ORIGINAL305_OWNER_V3_EXACT_STEPUP"
FIELDS=("contract","company","environment","site_uuid","target_host_id",
        "enrollment_sha256","predecessor_sha256","source_commit_sha",
        "operation_id","request_id","actor_digest_sha256","intent_nonce",
        "decision","issued_epoch","expires_epoch")
OFFER_CONTRACT="mad4b.original313.owner-remote-handoff.v1"
OFFER_DOMAIN="MAD4B_ORIGINAL313_OWNER_REMOTE_HANDOFF_V1"
OFFER_FIELDS=("contract","company","environment","site_uuid","target_host_id",
    "request_id","plan_sha256","predecessor_sha256","enrollment_sha256",
    "source_commit_sha","owner_stepup_public_b64",
    "owner_stepup_enrollment_sha256","original305_issuer_public_b64",
    "original305_issuer_enrollment_sha256","identity_authority_confirmed",
    "monotonic_predecessor_verified","issued_epoch","expires_epoch","state")
ORIGINAL313_PUB=base64.b64decode(
    "4jwcLgOUf2d/j2uDYbVysCnkSMJW64cXnhMPaeix11Y=",validate=True)
SCOPE=("mad4b-central","staging",
       "e5e64bca-ed62-48d2-b2b3-dff693cd702a","hostinger-primary")
OPERATION="company.host-authority.enrollment.v3.issue"
PRIVATE_FILE="mad4b-owner-device-ed25519-private.pem"
PUBLIC_FILE="mad4b-owner-device-public-proof.json"
HEX32=re.compile(r"[a-f0-9]{32}\Z")
HEX40=re.compile(r"[a-f0-9]{40}\Z")
HEX64=re.compile(r"[a-f0-9]{64}\Z")

class ProofRejected(ValueError):
    pass

def need(x, reason):
    if not x:raise ProofRejected(reason)

def canonical(data,keys,domain):
    need(type(data) is dict and all(k in data for k in keys),
         "MISSING_CANONICAL_FIELDS")
    return (domain+"\n"+json.dumps({k:data[k] for k in keys},
        ensure_ascii=False,sort_keys=True,separators=(",",":"))).encode()

def json_file(path):
    need(path.is_file() and not path.is_symlink()
         and path.stat().st_size<=65536,"INVALID_JSON_INPUT")
    obj=json.loads(path.read_bytes())
    need(type(obj) is dict,"INVALID_JSON_OBJECT")
    return obj

def output_file(path,content):
    need(not path.is_symlink() and not path.exists(),
         "OUTPUT_ALREADY_EXISTS")
    path.parent.mkdir(mode=0o700,parents=True,exist_ok=True)
    fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,"wb") as f:
        f.write(content)
        f.flush()
        os.fsync(f.fileno())

def public_bytes(key):
    return key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)

def load_device_key(path,password):
    need(path.is_file() and not path.is_symlink()
         and path.stat().st_size<16384,"DEVICE_KEY_NOT_PRESENT")
    loaded=load_pem_private_key(path.read_bytes(),password=password)
    need(isinstance(loaded,Ed25519PrivateKey),"DEVICE_KEY_TYPE_INVALID")
    return loaded

def create_device_key(directory,password):
    need(type(password) is bytes and len(password)>=12,
         "OWNER_KEY_PASSWORD_AT_LEAST_12_CHARACTERS")
    need(directory.is_absolute() and not directory.is_symlink(),
         "OWNER_DEVICE_DIRECTORY_INVALID")
    keypath=directory/PRIVATE_FILE
    pubpath=directory/PUBLIC_FILE
    need(not keypath.exists() and not pubpath.exists(),
         "OWNER_KEY_ALREADY_EXISTS_DO_NOT_REPLACE")
    # Non-enrolled identity. This is a real private Ed25519 key but NOT
    # an Original305 or Original313 authorization or signed certificate.
    private=Ed25519PrivateKey.generate()
    encoded=private.private_bytes(Encoding.PEM,PrivateFormat.PKCS8,
                                  BestAvailableEncryption(password))
    public=public_bytes(private)
    challenge={
      "contract":"mad4b.owner-v143.local-device-proof-only.v1",
      "company":SCOPE[0],"environment":SCOPE[1],
      "site_uuid":SCOPE[2],"target_host_id":SCOPE[3],
      "owner_model_requested":"SINGLE_OWNER",
      "owner_device_public_b64":base64.b64encode(public).decode(),
      "nonce":os.urandom(16).hex(),"issued_epoch":int(time.time()),
      "state":"UNENROLLED_PENDING_FIRSTPARTY_OWNER_IDENTITY_REGISTRAR",
    }
    domain="MAD4B_OWNER_V143_DEVICE_KEY_POSSESSION_ONLY"
    proof=dict(challenge,signature=base64.b64encode(
         private.sign(canonical(challenge,tuple(challenge),domain))).decode())
    # Write the encrypted private file first. If public output fails,
    # do not silently overwrite or mint another key on retry.
    output_file(keypath,encoded)
    output_file(pubpath,(json.dumps(proof,indent=2,sort_keys=True)+"\n").encode())
    return pubpath,hashlib.sha256(public).hexdigest()

def verify_original313_offer(offer,*,key_public,now,source_commit=None):
    need(type(now) is int and now>0
         and type(offer) is dict and set(offer)==set(OFFER_FIELDS)|{"signature"}
         and offer.get("contract")==OFFER_CONTRACT,
         "ORIGINAL313_SIGNED_OFFER_SHAPE_INVALID")
    try:
        signature=base64.b64decode(offer["signature"],validate=True)
        need(len(signature)==64,"ORIGINAL313_OFFER_SIGNATURE_SIZE_INVALID")
        Ed25519PublicKey.from_public_bytes(ORIGINAL313_PUB).verify(signature,
           canonical(offer,OFFER_FIELDS,OFFER_DOMAIN))
    except (InvalidSignature,ValueError,TypeError) as e:
        raise ProofRejected("ORIGINAL313_SIGNATURE_NOT_VALID") from e
    need(tuple(offer.get(k) for k in (
           "company","environment","site_uuid","target_host_id"))==SCOPE
         and offer.get("state")=="READY_FOR_OWNER_STEPUP"
         and offer.get("identity_authority_confirmed") is True
         and offer.get("monotonic_predecessor_verified") is True,
         "ORIGINAL313_OFFER_IDENTITY_OR_SCOPE_INVALID")
    need(type(offer.get("issued_epoch")) is int
         and type(offer.get("expires_epoch")) is int
         and now-600<=offer["issued_epoch"]<=now+5
         and now<offer["expires_epoch"]<=offer["issued_epoch"]+600,
         "ORIGINAL313_OFFER_EXPIRED_OR_NOT_FRESH")
    try:offer_public=base64.b64decode(
        offer.get("owner_stepup_public_b64",""),validate=True)
    except (ValueError,TypeError) as e:
        raise ProofRejected("ORIGINAL313_OWNER_PUB_INVALID") from e
    need(offer_public==key_public,
         "OWNER_DEVICE_KEY_NOT_THE_ENROLLED_SIGNER")
    need(type(offer.get("request_id")) is str
         and HEX32.fullmatch(offer["request_id"]) is not None
         and all(type(offer.get(k)) is str
                 and HEX64.fullmatch(offer[k]) is not None for k in (
                   "enrollment_sha256","predecessor_sha256","plan_sha256",
                   "owner_stepup_enrollment_sha256",
                   "original305_issuer_enrollment_sha256"))
         and type(offer.get("source_commit_sha")) is str
         and HEX40.fullmatch(offer["source_commit_sha"]) is not None
         and (source_commit is None or offer["source_commit_sha"]==source_commit),
         "ORIGINAL313_OFFER_NONCE_OR_SOURCE_INVALID")
    need(type(offer.get("original305_issuer_public_b64")) is str,
         "ORIGINAL305_ISSUER_PUBLIC_MISSING")
    return offer

def sign_exact_intent(offer,key,actor_digest,*,now,source_commit=None):
    public=public_bytes(key)
    verify_original313_offer(offer,key_public=public,now=now,
                             source_commit=source_commit)
    need(type(actor_digest) is str and HEX64.fullmatch(actor_digest) is not None,
         "FIRSTPARTY_OWNER_ACTOR_DIGEST_REQUIRED")
    data={
       "contract":CONTRACT,"company":SCOPE[0],"environment":SCOPE[1],
       "site_uuid":SCOPE[2],"target_host_id":SCOPE[3],
       "enrollment_sha256":offer["enrollment_sha256"],
       "predecessor_sha256":offer["predecessor_sha256"],
       "source_commit_sha":offer["source_commit_sha"],
       "operation_id":OPERATION,"request_id":offer["request_id"],
       "actor_digest_sha256":actor_digest,"intent_nonce":os.urandom(16).hex(),
       "decision":"APPROVE","issued_epoch":now,
       "expires_epoch":min(now+180,offer["expires_epoch"]),
    }
    need(data["expires_epoch"]>now,"OWNER_INTENT_NO_TIME_LEFT")
    result=dict(data,signature=base64.b64encode(
        key.sign(canonical(data,FIELDS,DOMAIN))).decode())
    Ed25519PublicKey.from_public_bytes(public).verify(
        base64.b64decode(result["signature"]),canonical(result,FIELDS,DOMAIN))
    return result

def main():
    p=argparse.ArgumentParser(description=__doc__)
    sub=p.add_subparsers(dest="command",required=True)
    init=sub.add_parser("init",help="Generate device-local encrypted key, public proof only")
    init.add_argument("--device-dir",required=True,type=Path)
    signer=sub.add_parser("sign-offer",help="Sign one genuine Original313 offer")
    signer.add_argument("--device-dir",required=True,type=Path)
    signer.add_argument("--offer-json",required=True,type=Path)
    signer.add_argument("--actor-digest",required=True)
    signer.add_argument("--expected-source-commit",required=True)
    signer.add_argument("--output-json",required=True,type=Path)
    args=p.parse_args()
    if args.command=="init":
        password=getpass("Choose a local encryption passphrase (never send it): ")
        confirm=getpass("Confirm local passphrase: ")
        need(password==confirm,"OWNER_KEY_PASSWORDS_DIFFER")
        result,fingerprint=create_device_key(
            args.device_dir.resolve(),password.encode())
        print(json.dumps({"state":"LOCAL_OWNER_KEY_GENERATED_NOT_ENROLLED",
          "public_proof":str(result),"public_fingerprint_sha256":fingerprint,
          "private_key_stored_encrypted_locally":True,"private_key_transmitted":False,"write_authorized":False}))
    else:
        password=getpass("Local Owner key passphrase: ")
        key=load_device_key(args.device_dir/PRIVATE_FILE,password.encode())
        offer=json_file(args.offer_json)
        now=int(time.time())
        verify_original313_offer(offer,key_public=public_bytes(key),now=now,
                                 source_commit=args.expected_source_commit)
        print("Independent Original313 signed offer VERIFIED for:")
        print(json.dumps({k:offer[k] for k in ("company","environment",
          "site_uuid","target_host_id","source_commit_sha","request_id",
          "expires_epoch")},indent=2))
        need(input("Type APPROVE MAD4B STAGING ENROLLMENT to sign: ").strip()
             =="APPROVE MAD4B STAGING ENROLLMENT",
             "OWNER_INTERACTIVE_EXACT_CONFIRMATION_REQUIRED")
        # Re-check after the human has reviewed; expiry can lapse.
        result=sign_exact_intent(offer,key,args.actor_digest,now=int(time.time()),
                                 source_commit=args.expected_source_commit)
        output_file(args.output_json,
                    (json.dumps(result,sort_keys=True,indent=2)+"\n").encode())
        print(json.dumps({"state":"OWNER_EXACT_INTENT_SIGNED_LOCAL_ONLY",
          "output":str(args.output_json),"write_authorized":False,
          "original305_approval_issued":False,
          "next_step":"Submit signed intent through authorized Root verification"}))
    return 0

if __name__=="__main__":
    try:sys.exit(main())
    except (ProofRejected,ValueError,KeyError,FileExistsError,
            PermissionError) as e:
        print("OWNER_LOCAL_SIGNING_REJECTED:"+str(e),file=sys.stderr)
        sys.exit(3)
