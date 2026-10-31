#!/usr/bin/env python3
"""Exact local Git Content Hub Staging install/rollback acceptance.

Restricted to a pre-registered source and artifact; not a general-purpose
deployment endpoint. No Production or MU plugin writes, no certificate gate.
"""
from __future__ import annotations
import fcntl
import hashlib
import json
import os
from pathlib import Path
import shutil
import signal
import subprocess
import sys
import time
import zipfile

SOURCE = Path("/opt/mad4b/operations/central-release/local-git-sources/mad4b-content-hub")
PLAN = Path("/opt/staging/mad4b-content-hub-local-git-artifact-20261031/CONTENT-HUB-LOCAL-GIT-SOURCE-PLAN.json")
SITE = Path("/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy")
LIVE = SITE / "wp-content/plugins/mad4b-content-hub"
HOST = Path("/opt/hostinger-migrations/hosting-3/etg-commerce-staging/runtime")
KEY = HOST / "credentials/central-release-issuer-private.pem"
PUB = HOST / "native-ability-authority/central-release-issuer-public.pem"
JOURNAL = HOST / "native-ability-authority/central-release-readbacks"
BACKUP_ROOT = Path("/opt/staging/mad4b-content-hub-native-upgrade-20261031")
ZIP_ROOT = PLAN.parent
EXPECTED_COMMIT = "03b2c26d27ef9d74e59b492b06c900ee0e741d0e"
EXPECTED_ARTIFACT = "cb22e6eb0703c807390b416642c9ce1f050194f44d5584372b48ccc43fd4d550"
EXPECTED_MANIFEST = "92cc4a3b7dd001718eb3f19084bb56a5f1fd294e7575cfd2355d6cb6432982e6"
MAIN = "mad4b-content-hub/mad4b-content-hub.php"
WP = ["docker","exec","-u","www-data","etg-commerce-staging-php","wp",
      "--path=/var/www/html","--skip-themes","--user=1"]
MODE = "staging_only"

def run(args, timeout=60, stdin=None):
    p = subprocess.run(args, input=stdin, capture_output=True, timeout=timeout)
    if p.returncode:
        raise RuntimeError("command failed: " + repr(args[:8]) + " :: " +
                           p.stderr.decode(errors="replace")[-700:])
    return p.stdout.decode(errors="replace").strip()

def wp(expr: str) -> str:
    return run(WP + ["eval", expr], timeout=85)

def json_canonical(x):
    return json.dumps(x,sort_keys=True,separators=(",",":"),ensure_ascii=False).encode()

def file_manifest(root: Path):
    arr = []
    for p in root.rglob("*"):
        if p.is_symlink():
            raise RuntimeError("symlink in plugin directory")
        if p.is_file():
            arr.append({"path":str(p.relative_to(root)).replace(os.sep,"/"),
                        "sha256":hashlib.sha256(p.read_bytes()).hexdigest()})
    return sorted(arr,key=lambda x:x["path"])

def sha_file(p:Path):
    h=hashlib.sha256()
    with p.open("rb") as f:
        for block in iter(lambda:f.read(1024*1024),b""):
            h.update(block)
    return h.hexdigest()

def wp_readback():
    expr = ('$p=WP_PLUGIN_DIR."/mad4b-content-hub/mad4b-content-hub.php";'
            'require_once ABSPATH."wp-admin/includes/plugin.php";'
            '$d=get_plugins();'
            'echo wp_json_encode(["url"=>home_url(),'
            '"env"=>wp_get_environment_type(),'
            '"active"=>is_plugin_active("mad4b-content-hub/mad4b-content-hub.php"),'
            '"version"=>$d["mad4b-content-hub/mad4b-content-hub.php"]["Version"]??"",'
            '"main_sha"=>is_file($p)?hash_file("sha256",$p):"missing",'
            '"php"=>PHP_VERSION,"wordpress"=>get_bloginfo("version")],'
            'JSON_UNESCAPED_SLASHES);')
    return json.loads(wp(expr))

def preflight():
    if SOURCE.is_symlink() or not SOURCE.is_dir() or SOURCE.stat().st_uid != 0:
        raise RuntimeError("untrusted local Git source")
    if run(["git","-C",str(SOURCE),"rev-parse","HEAD"])!=EXPECTED_COMMIT or run(["git","-C",str(SOURCE),"status","--porcelain"]):
        raise RuntimeError("registered local Git source changed")
    plan=json.loads(PLAN.read_text())
    if (plan.get("source_commit")!=EXPECTED_COMMIT or
        plan.get("artifact_sha256")!=EXPECTED_ARTIFACT or
        plan.get("file_manifest_sha256")!=EXPECTED_MANIFEST or
        plan.get("production_promotion_eligible") is not False):
        raise RuntimeError("untrusted source plan")
    archive=ZIP_ROOT/("mad4b-content-hub-"+EXPECTED_COMMIT+".zip")
    if not archive.is_file() or archive.is_symlink() or sha_file(archive)!=EXPECTED_ARTIFACT:
        raise RuntimeError("artifact identity mismatch")
    items=[]
    with zipfile.ZipFile(archive) as z:
        for info in z.infolist():
            if info.is_dir():continue
            name=info.filename
            prefix="mad4b-content-hub/"
            if not name.startswith(prefix):raise RuntimeError("wrong package root")
            rel=name[len(prefix):]
            if not rel or "\\" in rel or any(x in ("",".","..") for x in rel.split("/")):
                raise RuntimeError("unsafe archive path")
            if ((info.external_attr>>16)&0o170000)==0o120000 or info.file_size>2_000_000:
                raise RuntimeError("unsafe archive file")
            items.append((rel,hashlib.sha256(z.read(info)).hexdigest()))
    if len(items)!=50 or len(set(k for k,v in items))!=50:
        raise RuntimeError("unexpected artifact file count")
    manifest=[{"path":k,"sha256":v} for k,v in sorted(items)]
    if hashlib.sha256(json_canonical(manifest)).hexdigest()!=EXPECTED_MANIFEST:
        raise RuntimeError("artifact manifest mismatch")
    if MAIN.split("/",1)[1] not in (x[0] for x in items):
        raise RuntimeError("main WordPress plugin file missing")
    if file_manifest(LIVE)!=manifest or file_manifest(SOURCE)!=manifest:
        raise RuntimeError("live Staging files have drifted from exact source")
    state=wp_readback()
    if state["url"]!="https://tours.dev.mad4b.com" or state["env"]!="staging" or not state["active"] or state["version"]!="0.1.0-dev":
        raise RuntimeError("wrong site or activation state")
    if (state["main_sha"] != dict(items)["mad4b-content-hub.php"] or
        not KEY.is_file() or KEY.stat().st_uid!=0 or (KEY.stat().st_mode&0o077) or
        not PUB.is_file() or not JOURNAL.is_dir()):
        raise RuntimeError("site or key precondition failed")
    receipt=JOURNAL/(EXPECTED_ARTIFACT+".json")
    if receipt.exists():raise RuntimeError("receipt already exists - refuse replay")
    return archive, manifest, state

def smoke(manifest, baseline):
    if file_manifest(LIVE)!=manifest:raise RuntimeError("installed file manifest mismatch")
    st=wp_readback()
    for k in ("url","env","version","active","main_sha"):
        if st[k]!=baseline[k]:raise RuntimeError("WordPress readback mismatch: "+k)
    # Health check verifies actual frontend PHP runtime, not only CLI bootstrap.
    code=run(["curl","-sS","-o","/dev/null","-w","%{http_code}",
              "--max-time","18","https://tours.dev.mad4b.com/"],timeout=25)
    if code!="200":raise RuntimeError("Staging homepage HTTP "+code)
    return st

def prepare_stage(archive,manifest,stage):
    stage.mkdir(mode=0o700,parents=False)
    with zipfile.ZipFile(archive) as z:
        for item in manifest:
            p=stage/item["path"]
            p.parent.mkdir(parents=True,exist_ok=True)
            p.write_bytes(z.read("mad4b-content-hub/"+item["path"]))
            p.chmod(0o644)
    owner=LIVE.stat()
    for p in [stage]+list(stage.rglob("*")):
        os.chown(p,owner.st_uid,owner.st_gid)
        if p.is_dir():p.chmod(0o755)
    if file_manifest(stage)!=manifest:
        raise RuntimeError("extract mismatch")

def emit_readback(manifest,state):
    checks={"install":True,"smoke":True,"rollback":True}
    record={
        "contract":"mad4b.central-staging-release-readback.v1",
        "staging_origin":"https://tours.dev.mad4b.com",
        "environment":"staging",
        "source_type":"local_git","source_commit":EXPECTED_COMMIT,
        "artifact_sha256":EXPECTED_ARTIFACT,"file_manifest_sha256":EXPECTED_MANIFEST,
        "rollout_scope":"staging_verified_only",
        "staging_pass":True,"tested_at":int(time.time()),
        "checks":checks,
        "requirements":{"target_plugin_file":MAIN,"target_version":state["version"],
                        "php_min":state["php"],"wordpress_min":state["wordpress"],
                        "required_plugins":[],"requires_schema_migration":False}
    }
    payload=json_canonical(record)
    sig=subprocess.run(["openssl","dgst","-sha256","-sign",str(KEY)],
                       input=payload,capture_output=True,timeout=20,check=True).stdout
    # Verify independently before publishing a server-owned readback.
    import tempfile
    with tempfile.TemporaryDirectory() as tmp:
        sp=Path(tmp)/"sig";dp=Path(tmp)/"payload"
        sp.write_bytes(sig);dp.write_bytes(payload)
        run(["openssl","dgst","-sha256","-verify",str(PUB),
             "-signature",str(sp),str(dp)])
    import base64
    envelope={"contract":"mad4b.central-release-evidence-envelope.v1",
              "receipt":record,"signature_b64":base64.b64encode(sig).decode()}
    target=JOURNAL/(EXPECTED_ARTIFACT+".json")
    tmp=JOURNAL/("."+EXPECTED_ARTIFACT+"."+str(os.getpid())+".tmp")
    if target.exists():raise RuntimeError("refusing to overwrite existing evidence")
    try:
        with tmp.open("x") as f:
            os.fchmod(f.fileno(),0o644)
            json.dump(envelope,f,sort_keys=True,separators=(",",":"),ensure_ascii=False)
            f.write("\n");f.flush();os.fsync(f.fileno())
        os.replace(tmp,target)
    finally:
        if tmp.exists():tmp.unlink()
    return target

def execute():
    if sys.argv[1:] not in (["preflight"],["test-registered-staging-content-hub"]):
        raise RuntimeError("unsupported operation")
    archive,manifest,state=preflight()
    if sys.argv[1:] == ["preflight"]:
        print(json.dumps({"state":"SOURCE_PREFLIGHT_PASS","files":len(manifest),
                          "source_commit":EXPECTED_COMMIT,"artifact_sha256":EXPECTED_ARTIFACT,
                          "initial_plugin_active":state["active"],"mutation":False}))
        return
    BACKUP_ROOT.mkdir(mode=0o700,parents=True,exist_ok=True)
    lock=BACKUP_ROOT/"executor.lock"
    with lock.open("w") as lf:
        fcntl.flock(lf.fileno(),fcntl.LOCK_EX|fcntl.LOCK_NB)
        backup=BACKUP_ROOT/"original-plugin"
        stage=LIVE.parent/(".mad4b-new-"+EXPECTED_ARTIFACT[:12])
        old=LIVE.parent/(".mad4b-old-"+EXPECTED_ARTIFACT[:12])
        if backup.exists() or stage.exists() or old.exists():
            raise RuntimeError("prior transaction state exists - manual recovery required")
        shutil.copytree(LIVE,backup,symlinks=False,copy_function=shutil.copy2)
        backup.chmod(0o700)
        if file_manifest(backup)!=manifest:
            raise RuntimeError("backup readback mismatch")
        swapped=False
        rounds=0
        try:
            prepare_stage(archive,manifest,stage)
            for n in range(2):
                os.rename(LIVE,old)
                try:
                    os.rename(stage,LIVE)
                except BaseException:
                    os.rename(old,LIVE)
                    raise
                swapped=True
                smoke(manifest,state)
                rounds+=1
                if n==0:
                    # Genuine restore from the original pre-test directory.
                    os.rename(LIVE,stage)
                    os.rename(old,LIVE)
                    swapped=False
                    smoke(manifest,state)
            # Final release files match the original baseline; discard the
            # hidden previous directory, retaining root-only external backup.
            if file_manifest(old)!=manifest:raise RuntimeError("old path changed")
            shutil.rmtree(old)
            swapped=False
        except BaseException:
            if old.exists():
                if LIVE.exists():
                    failed=LIVE.parent/(".mad4b-failed-"+EXPECTED_ARTIFACT[:12])
                    if failed.exists():raise RuntimeError("failed recovery folder exists")
                    os.rename(LIVE,failed)
                os.rename(old,LIVE)
            elif not LIVE.exists() and backup.exists():
                shutil.copytree(backup,LIVE)
            smoke(manifest,state)
            raise
        finally:
            if stage.exists():shutil.rmtree(stage)
        smoke(manifest,state)
        signed=emit_readback(manifest,state)
        print(json.dumps({"state":"STAGING_CONTENT_HUB_TEST_PASS","install_rounds":rounds,
            "rollback_test":"PASS","readback":"PASS","artifact_sha256":EXPECTED_ARTIFACT,
            "source_commit":EXPECTED_COMMIT,"signed_readback":str(signed),
            "rollout_scope":"staging_verified_only","company_production_authorized":False,
            "external_backup":str(backup)}))

if __name__=="__main__":
    try:execute()
    except BaseException as e:
        print("CONTENT_HUB_STAGING_CYCLE_FAILED: "+str(e),file=sys.stderr)
        sys.exit(1)
