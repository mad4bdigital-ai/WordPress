#!/usr/bin/env python3
"""Live isolated diagnostic and deny-path contract for the optional VPS worker."""
import base64, importlib.util, json, pathlib, tempfile, time, os
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
source=pathlib.Path(__file__).resolve().parents[1]/"tools/runtime-runner-worker.py"
spec=importlib.util.spec_from_file_location("worker",source)
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
site=Ed25519PrivateKey.generate(); runner=Ed25519PrivateKey.generate()
now=int(time.time())
req={"contract":m.CONTRACT,"site":"staging-site-test","operation":"runtime.status.read",
 "nonce":"ab"*16,"issued_at":now-1,"expires_at":now+50,
 "target":"isolated-diagnostic","plan_sha256":"1"*64}
def sign(x):return {"request":x,"signature":base64.b64encode(site.sign(m.canonical(x))).decode()}
class Result:
 returncode=0
 stdout="MAD4B_DOCKER_RUNNER_OK"
calls=[]
def sandbox(argv):
 calls.append(argv)
 assert "--network" in argv and argv[argv.index("--network")+1]=="none"
 assert "--read-only" in argv and "--cap-drop" in argv and "--pids-limit" in argv
 return Result()
with tempfile.TemporaryDirectory() as d:
 nonces=pathlib.Path(d)
 receipt=m.process(sign(req),site.public_key(),runner,nonces,"staging-site-test",now,sandbox)
 runner.public_key().verify(m.b64decode(receipt["signature"]),m.canonical(receipt["receipt"]))
 assert receipt["receipt"]["result"]=="ok" and not receipt["receipt"]["production_authorized"]
 assert len(calls)==1
 def denied(e):
  try:m.process(e,site.public_key(),runner,nonces,"staging-site-test",now,sandbox)
  except Exception:return
  raise AssertionError("denial bypass")
 denied(sign(req)) # replay
 x=dict(req,nonce="cd"*16,operation="shell.execute"); denied(sign(x))
 x=dict(req,nonce="ef"*16,site="production-site"); denied(sign(x))
 x=dict(req,nonce="12"*16,expires_at=now-2); denied(sign(x))
 x=dict(req,nonce="34"*16); e=sign(x);e["request"]["target"]="arbitrary-shell";denied(e)
 assert len(calls)==1
 print("PASS: SIGNED_RUNNER_RECEIPT_REPLAY_SCOPE_SANDBOX")
