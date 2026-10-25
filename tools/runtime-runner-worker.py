#!/usr/bin/env python3
"""MAD4B isolated runner protocol v1: bounded diagnostics; NOT Shell/SQL authority.

CLI input: JSON {request: {...}, signature: base64 Ed25519 signature}.
Provision the site public key and the worker private key OUTSIDE the webroot.
No user-controlled command, host, container image, path, URL, or mounts.
"""
import argparse, base64, hashlib, json, os, pathlib, re, subprocess, sys, time
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey, Ed25519PrivateKey
from cryptography.hazmat.primitives import serialization

CONTRACT = "mad4b.runtime-runner.v1"
OPERATIONS = {"runtime.status.read"}
IMAGE = "alpine:3.20"
def canonical(obj):
    return json.dumps(obj, separators=(",", ":"), sort_keys=True, ensure_ascii=True).encode()
def b64decode(s):
    return base64.b64decode(s, validate=True)
def process(envelope, site_public_key, worker_private_key, nonce_dir, enrolled_site, now=None, executor=None):
    now = int(time.time() if now is None else now)
    if not isinstance(envelope, dict) or set(envelope) != {"request", "signature"}:
        raise ValueError("invalid_envelope")
    request = envelope["request"]
    if not isinstance(request, dict) or set(request) != {"contract","site","operation","nonce","issued_at","expires_at","target","plan_sha256"}:
        raise ValueError("invalid_request")
    if request["contract"] != CONTRACT or request["site"] != enrolled_site or request["operation"] not in OPERATIONS:
        raise ValueError("scope_denied")
    nonce = request["nonce"]
    if not isinstance(nonce, str) or not re.fullmatch(r"[a-f0-9]{32}", nonce):
        raise ValueError("nonce_invalid")
    if request["target"] != "isolated-diagnostic" or not re.fullmatch(r"[a-f0-9]{64}", str(request["plan_sha256"])):
        raise ValueError("target_denied")
    issued, expires = request["issued_at"], request["expires_at"]
    if type(issued) is not int or type(expires) is not int or not issued <= now <= expires or expires - issued > 120 or now - issued > 120:
        raise ValueError("expired_request")
    site_public_key.verify(b64decode(envelope["signature"]), canonical(request))
    nonce_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
    # Exclusive creation before execution: every signed request has one attempt.
    fd = os.open(str(nonce_dir / nonce), os.O_WRONLY|os.O_CREAT|os.O_EXCL, 0o600)
    with os.fdopen(fd, "w") as out:
        out.write(hashlib.sha256(canonical(request)).hexdigest())
    command = ["docker","run","--rm","--network","none","--read-only",
        "--cap-drop","ALL","--security-opt","no-new-privileges",
        "--user","65534:65534","--pids-limit","16","--memory","64m",
        "--cpus","0.2",IMAGE,"sh","-c","printf MAD4B_DOCKER_RUNNER_OK"]
    if executor is None:
        def executor(argv):
            return subprocess.run(argv, capture_output=True, text=True, timeout=20, check=False)
    result = executor(command)
    body = {
        "contract":CONTRACT+".receipt","site":enrolled_site,"operation":request["operation"],
        "nonce":nonce,"request_sha256":hashlib.sha256(canonical(request)).hexdigest(),
        "exit_code":result.returncode,"stdout_sha256":hashlib.sha256(result.stdout.encode()).hexdigest(),
        "result": "ok" if result.returncode == 0 and result.stdout == "MAD4B_DOCKER_RUNNER_OK" else "failed",
        "produced_at":int(time.time()),"production_authorized":False,"mutation_performed":False,
    }
    signature = worker_private_key.sign(canonical(body))
    return {"receipt":body,"signature":base64.b64encode(signature).decode()}
def main():
    p=argparse.ArgumentParser()
    p.add_argument("--site-key",required=True)
    p.add_argument("--worker-key",required=True)
    p.add_argument("--nonce-dir",required=True)
    p.add_argument("--enrolled-site",required=True)
    args=p.parse_args()
    try:
        data=json.load(sys.stdin)
        pub=serialization.load_pem_public_key(pathlib.Path(args.site_key).read_bytes())
        key=serialization.load_pem_private_key(pathlib.Path(args.worker_key).read_bytes(),password=None)
        if not isinstance(pub,Ed25519PublicKey) or not isinstance(key,Ed25519PrivateKey):
            raise ValueError("invalid_key_type")
        print(json.dumps(process(data,pub,key,pathlib.Path(args.nonce_dir),args.enrolled_site),sort_keys=True))
    except Exception as exc:
        print(json.dumps({"contract":CONTRACT,"ok":False,"error":type(exc).__name__}),file=sys.stderr)
        return 1
    return 0
if __name__=="__main__":
    sys.exit(main())
