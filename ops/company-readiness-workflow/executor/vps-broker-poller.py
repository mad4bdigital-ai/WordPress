#!/usr/bin/env python3
"""MAD4B VPS Broker poller: connects the existing Dispatch V1 service to WordPress
Dispatch/Claim using a host-owned signing key. Never executes queued shell commands.
The host-only execution stage requires independent SSH authority and exact bytes.
"""
import argparse
import base64
import datetime
import fcntl
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import sqlite3
import sys
import time

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives import serialization

CONTRACT = "mad4b.central-vps-broker.v1"
ROOT = Path("/opt/mad4b-company-transport/broker-v1")
KEY = ROOT / "host-ed25519-private.pem"
PUB = ROOT / "host-ed25519-public.base64"
JOURNAL = ROOT / "broker-events.jsonl"
DISPATCH_DB = Path("/opt/mad4b-company-transport/dispatch-queue-v1.sqlite3")
CONFIG = ROOT / "broker-config.json"
LOCK = ROOT / "broker-poll.lock"
DOCKER = "/usr/bin/docker"
CONTAINER = "etg-commerce-staging-php"
HOST = "srv2030908"
WP = "/usr/local/bin/wp"
WP_BASE = [DOCKER, "exec", "-u", "www-data", CONTAINER, WP,
           "--path=/var/www/html", "--skip-themes", "--user=1", "mad4b", "broker"]

def assert_root():
    if os.geteuid() != 0:
        raise RuntimeError("Root-owned VPS broker is required")

def setup_key():
    assert_root()
    ROOT.mkdir(parents=True, exist_ok=True, mode=0o700)
    if KEY.exists() or PUB.exists():
        if not KEY.exists() or not PUB.exists():
            raise RuntimeError("Partial key state. Manual recovery required")
        return False
    secret = Ed25519PrivateKey.generate()
    serialized = secret.private_bytes(
        serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8,
        serialization.NoEncryption())
    fd = os.open(str(KEY), os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, "wb") as f:
        f.write(serialized)
    public = secret.public_key().public_bytes(
        serialization.Encoding.Raw, serialization.PublicFormat.Raw)
    PUB.write_text(base64.b64encode(public).decode("ascii") + "\n")
    os.chmod(PUB, 0o600)
    return True

def sign(fields):
    secret = serialization.load_pem_private_key(KEY.read_bytes(), password=None)
    assert isinstance(secret, Ed25519PrivateKey)
    msg = "\n".join(str(v) for v in fields).encode("utf-8")
    return base64.b64encode(secret.sign(msg)).decode("ascii")

def wp_call(args, timeout=35):
    cmd = WP_BASE + args
    out = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout,
                         env={**os.environ, "LC_ALL":"C"}, check=False)
    if out.returncode != 0:
        raise RuntimeError("WPCLI_FAILURE_" + str(out.returncode))
    lines = [s for s in out.stdout.splitlines() if s.lstrip().startswith("{")]
    if not lines:
        raise RuntimeError("NO_JSON_RECEIPT")
    data = json.loads(lines[-1])
    if not isinstance(data, dict):
        raise RuntimeError("INVALID_JSON_RECEIPT")
    return data

def audit(event, **details):
    row = {"contract":CONTRACT, "at":datetime.datetime.now(
        datetime.timezone.utc).isoformat(), "event":event, **details}
    with JOURNAL.open("a", encoding="utf-8") as f:
        os.chmod(JOURNAL, 0o600)
        f.write(json.dumps(row, sort_keys=True) + "\n")
        f.flush()
        os.fsync(f.fileno())
    # The original Dispatch V1 SQLite event log stays the common audit
    # surface. The WordPress Broker does not create a competing job queue.
    if not DISPATCH_DB.is_file():
        raise RuntimeError("ORIGINAL_DISPATCH_EVENT_JOURNAL_MISSING")
    job_id = str(details.get("job_id", "host"))[:64]
    event_detail = json.dumps(
        {k:v for k,v in details.items() if k not in ("command","signature","secret")},
        sort_keys=True)[:300]
    with sqlite3.connect(str(DISPATCH_DB),timeout=6) as db:
        db.execute("INSERT INTO events(job_id,at,event,detail) VALUES(?,?,?,?)",
                   ("broker:"+job_id,int(time.time()),event,event_detail))
        db.commit()

def heartbeat():
    now = int(time.time())
    nonce = secrets.token_hex(16)
    expiry = now + 45
    signature = sign([CONTRACT, "heartbeat", HOST, nonce, now, expiry])
    result = wp_call(["heartbeat", "--host="+HOST, "--nonce="+nonce,
                      "--issued="+str(now), "--expires="+str(expiry),
                      "--signature="+signature])
    if result.get("host_id") != HOST or result.get("verified") is not True:
        raise RuntimeError("CENTRAL_HEARTBEAT_READBACK_MISMATCH")
    audit("HEARTBEAT_VERIFIED", host_id=HOST, central_at=result.get("at"))
    return result

def poll(limit):
    config = json.loads(CONFIG.read_text())
    if config.get("contract") != "mad4b.vps-broker-poller.v1":
        raise RuntimeError("POLICY_CONTRACT_MISMATCH")
    if config.get("host_id") != HOST:
        raise RuntimeError("HOST_BINDING_MISMATCH")
    allowed = config.get("companies", {})
    report = wp_call(["pending", "--limit="+str(min(30,max(1,limit)))])
    if report.get("contract") != CONTRACT or not isinstance(report.get("pending"),list):
        raise RuntimeError("PENDING_LIST_INVALID")
    claims = []
    for job in report["pending"]:
        name = job.get("company")
        policy = allowed.get(name, {})
        matched = (policy.get("allow_claim") is True
                   and job.get("target_host_id") == HOST
                   and job.get("site_uuid") == policy.get("site_uuid")
                   and job.get("environment") == policy.get("environment")
                   and re.fullmatch(r"[a-f0-9]{32}",str(job.get("job_id","")))
                   and isinstance(job.get("expires_epoch"),int)
                   and job["expires_epoch"] > time.time() + 5)
        if not matched:
            audit("PENDING_NOT_ELIGIBLE", job_id=str(job.get("job_id",""))[:32],
                  company=str(name)[:48])
            continue
        job_id = job["job_id"]
        # Record intent before claim. A failed or ambiguous claim is NOT an
        # execution receipt, and never causes command replay.
        audit("CLAIM_ATTEMPTED",job_id=job_id,company=name,host_id=HOST)
        issued = int(time.time())
        expiry = issued + 45
        nonce = secrets.token_hex(16)
        signature = sign([CONTRACT, "claim", job_id, name,
            job["site_uuid"],job["environment"],HOST,nonce,issued,expiry])
        try:
            claimed = wp_call(["claim", "--job="+job_id, "--nonce="+nonce,
                "--issued="+str(issued), "--expires="+str(expiry),
                "--signature="+signature], timeout=45)
            if (claimed.get("job_id") != job_id or claimed.get("state") !=
                "BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY"):
                raise RuntimeError("INVALID_CLAIM_READBACK")
            audit("CLAIM_VERIFIED_NOT_EXECUTED",job_id=job_id,company=name)
            claims.append({"job_id":job_id,"state":claimed["state"]})
        except Exception as err:
            audit("CLAIM_RESULT_UNCERTAIN",job_id=job_id,reason=type(err).__name__)
            claims.append({"job_id":job_id,"state":"UNKNOWN_CHECK_CENTRAL_JOURNAL"})
    return {"contract":CONTRACT,"pending_count":len(report["pending"]),
            "claims":claims,"production_mutation_performed":False,
            "queued_shell_execution_performed":False}

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("action",choices=["init","selftest","sweep","status"])
    parser.add_argument("--limit",type=int,default=10)
    opts=parser.parse_args()
    assert_root()
    os.umask(0o077)
    ROOT.mkdir(mode=0o700,parents=True,exist_ok=True)
    if opts.action == "init":
        created=setup_key()
        print(json.dumps({"contract":CONTRACT,"key_created":created,
                          "public_key_path":str(PUB),"secret_exposed":False}))
        return
    if not KEY.exists() or not PUB.exists():
        raise SystemExit("BROKER_SIGNING_KEY_NOT_ENROLLED")
    if opts.action == "selftest":
        from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
        public=Ed25519PublicKey.from_public_bytes(base64.b64decode(PUB.read_text().strip()))
        now=int(time.time()); nonce=secrets.token_hex(16)
        fields=[CONTRACT,"heartbeat",HOST,nonce,now,now+45]
        public.verify(base64.b64decode(sign(fields)),
                      "\n".join(map(str,fields)).encode("utf-8"))
        print(json.dumps({"state":"HOST_SIGNATURE_VERIFIED","contract":CONTRACT}))
        return
    if opts.action == "status":
        print(json.dumps({"contract":CONTRACT,"config_present":CONFIG.exists(),
                          "last_audit":JOURNAL.stat().st_mtime if JOURNAL.exists() else None}))
        return
    with LOCK.open("a+") as f:
        fcntl.flock(f.fileno(),fcntl.LOCK_EX|fcntl.LOCK_NB)
        heart=heartbeat()
        result=poll(opts.limit)
    print(json.dumps({"heartbeat":"VERIFIED","host_id":HOST,
                      **result},sort_keys=True))

if __name__ == "__main__":
    main()
