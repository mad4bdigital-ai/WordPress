#!/usr/bin/env python3
"""Site-pinned MAD4B Host WP-CLI signer. Host root only; never accepts web input."""
import base64
import hashlib
import json
import os
import secrets
import subprocess
import sys
import time
from pathlib import Path
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey

ROOT=Path("/etc/mad4b")
KEY=ROOT/"wayfa-host-agent-ed25519.raw"
EXPECTED_SITE="a3a7cc36-db7e-4a14-8456-b41857def05c"
EXPECTED_ORIGIN="https://wayfatours.com"
EXPECTED_ENV="production"
CONTRACT="mad4b.host-agent-execution.v1"
FILE="/var/www/html/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-host-agent-bridge.php"
def run(args, **kw):
    return subprocess.run(args,check=True,capture_output=True,text=True,timeout=35,**kw)
def identity(container):
    js='$s=MAD4B_SCP_Site_Profile::status();echo wp_json_encode(["site_uuid"=>(string)$s["site_uuid"],"origin"=>rtrim(home_url("/"),"/"),"environment"=>(string)$s["environment"],"database_sha256"=>hash("sha256",DB_NAME)],JSON_UNESCAPED_SLASHES)."\\n";'
    data=json.loads(run(["docker","exec","-u","www-data",container,
       "wp","eval",js,"--skip-themes"]).stdout.strip().splitlines()[-1])
    if data.get("site_uuid")!=EXPECTED_SITE or data.get("origin")!=EXPECTED_ORIGIN or data.get("environment")!=EXPECTED_ENV:
        raise RuntimeError("host-target-identity-mismatch")
    return data
def main():
    if os.geteuid()!=0:raise RuntimeError("root-ssh-host-executor-required")
    if len(sys.argv)<2 or sys.argv[1] not in ("status","bind","review","policy","media_intake","media_proposal","media_strategy","media_reconcile","media_close","media_associate","media_apply"):
        raise RuntimeError("usage: mad4b-wayfa-host-agent status|bind|review [json-file]")
    op=sys.argv[1]
    if (op in ("review","policy","media_intake","media_proposal","media_strategy","media_reconcile","media_close","media_associate","media_apply") and len(sys.argv)!=3) or (op not in ("review","policy","media_intake","media_proposal","media_strategy","media_reconcile","media_close","media_associate","media_apply") and len(sys.argv)!=2):
        raise RuntimeError("invalid-arguments")
    ids=run(["docker","ps","--filter","label=coolify.resourceName=wayfatours-production",
      "--filter","label=com.docker.compose.service=php","--format","{{.ID}}"]).stdout.split()
    if len(ids)!=1:raise RuntimeError("non-unique-wayfa-php-container")
    container=ids[0]
    facts=identity(container)
    if op=="status":
        print(run(["docker","exec","-u","www-data",container,
          "wp","mad4b","host-agent","status","--skip-themes"]).stdout)
        return
    if not KEY.is_file() or KEY.is_symlink() or (KEY.stat().st_mode&0o077):
        raise RuntimeError("root-only-private-signer-missing")
    if op=="bind":body={}
    else:
        src=Path(sys.argv[2])
        if not src.is_file() or src.is_symlink() or src.stat().st_size>12000:
            raise RuntimeError("review-body-file-invalid")
        body=json.loads(src.read_text())
        if not isinstance(body,dict):raise RuntimeError("review-body-must-be-object")
        if op=="policy":
            if set(body)!={"mode","confirmation"}:
                raise RuntimeError("exact-policy-body-required")
            body={"mode":body["mode"],"confirmation":body["confirmation"]}
    body_json=json.dumps(body,sort_keys=True,ensure_ascii=False,separators=(",",":"))
    now=int(time.time())
    payload={"contract":CONTRACT,**facts,"operation":op,
     "body_sha256":hashlib.sha256(body_json.encode()).hexdigest(),
     "nonce":secrets.token_hex(16),"issued_at":now,"expires_at":now+45}
    canonical=json.dumps(payload,sort_keys=True,ensure_ascii=False,separators=(",",":"))
    signer=Ed25519PrivateKey.from_private_bytes(KEY.read_bytes())
    signature=base64.b64encode(signer.sign(canonical.encode())).decode()
    envelope={"contract":CONTRACT,"payload":payload,"body":body,"signature_b64":signature}
    raw=json.dumps(envelope,sort_keys=True,ensure_ascii=False,separators=(",",":"))
    res=subprocess.run(["docker","exec","-i","-u","www-data",container,
       "wp","mad4b","host-agent","execute","--skip-themes"],
       input=raw,capture_output=True,text=True,timeout=60)
    if res.returncode:raise RuntimeError("WPCLI_{}".format((res.stderr or res.stdout).strip()[:240]))
    print(res.stdout.strip())
if __name__=="__main__":
    try: main()
    except Exception as exc:
        print("HOST_AGENT_ERROR="+str(exc),file=sys.stderr)
        sys.exit(1)
