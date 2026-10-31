#!/usr/bin/env python3
"""Bounded local Git MU-plugin lifecycle acceptance for central Staging only.

No production writes, no arbitrary paths, no package certification required.
Canary is inert and removed after verification; other MU files are untouched.
"""
from __future__ import annotations
import hashlib
import json
from pathlib import Path
import os
import subprocess
import sys
import time

SOURCE=Path("/opt/staging/mad4b-local-git-mu-canary-20261031/source")
MU=Path("/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/mu-plugins")
NAME="999-mad4b-native-mu-git-canary.php"
TARGET=MU/NAME
BACKUP=Path("/opt/staging/mad4b-local-git-mu-canary-20261031/acceptance.json")
WP=["docker","exec","-u","www-data","etg-commerce-staging-php","wp",
    "--path=/var/www/html","--skip-themes","--user=1"]

def run(argv,timeout=60):
    p=subprocess.run(argv,capture_output=True,timeout=timeout)
    if p.returncode:
        raise RuntimeError("failed "+repr(argv[:5])+" "+p.stderr.decode(errors="replace")[-500:])
    return p.stdout.decode().strip()

def state():
    expression=(
        'require_once ABSPATH."wp-admin/includes/plugin.php";'
        '$m=get_mu_plugins();'
        '$p=WPMU_PLUGIN_DIR."/999-mad4b-native-mu-git-canary.php";'
        'echo wp_json_encode(["site"=>home_url(),"env"=>wp_get_environment_type(),'
        '"present"=>isset($m["999-mad4b-native-mu-git-canary.php"]),'
        '"exists"=>is_file($p),"sha"=>is_file($p)?hash_file("sha256",$p):""],'
        'JSON_UNESCAPED_SLASHES);'
    )
    return json.loads(run(WP+["eval",expression],timeout=75))

def check_state(present,sha=""):
    st=state()
    if (st["site"]!="https://tours.dev.mad4b.com" or st["env"]!="staging"
        or st["present"] is not present or st["exists"] is not present
        or (present and st["sha"]!=sha)):
        raise RuntimeError("MU plugin independent WordPress readback failed: "+repr(st))
    return st

def source():
    if not SOURCE.is_dir() or not (SOURCE/".git").is_dir() or SOURCE.stat().st_uid!=0:
        raise RuntimeError("untrusted MU canary Local Git source")
    if run(["git","-C",str(SOURCE),"status","--porcelain"]):
        raise RuntimeError("uncommitted MU source changes")
    commit=run(["git","-C",str(SOURCE),"rev-parse","HEAD"])
    if len(commit)!=40 or any(x not in "0123456789abcdef" for x in commit):
        raise RuntimeError("unfixed MU source commit")
    names=run(["git","-C",str(SOURCE),"ls-tree","-r","--name-only","HEAD"]).splitlines()
    if names!=[NAME]:raise RuntimeError("source contains unexpected files")
    payload=subprocess.run(["git","-C",str(SOURCE),"show",commit+":"+NAME],
                           capture_output=True,check=True,timeout=20).stdout
    if len(payload)>4096 or not payload.startswith(b"<?php") or b"Plugin Name: MAD4B Native MU Deploy Canary" not in payload:
        raise RuntimeError("unexpected MU payload")
    return commit,payload,hashlib.sha256(payload).hexdigest()

def test():
    if sys.argv[1:] not in (["preflight"],["test-local-mu-canary"]):
        raise RuntimeError("unsupported MU operation")
    commit,payload,digest=source()
    if TARGET.exists() or TARGET.is_symlink():raise RuntimeError("MU canary target already exists")
    check_state(False)
    if sys.argv[1:]==["preflight"]:
        print(json.dumps({"state":"LOCAL_MU_SOURCE_PREFLIGHT_PASS","source_commit":commit,"file_sha256":digest,"mutation":False}))
        return
    if BACKUP.exists():raise RuntimeError("prior MU canary acceptance exists")
    previous=MU.stat()
    installed=False
    try:
        with TARGET.open("xb") as f:
            f.write(payload);f.flush();os.fsync(f.fileno())
        os.chown(TARGET,previous.st_uid,previous.st_gid)
        TARGET.chmod(0o644)
        installed=True
        run(["docker","exec","etg-commerce-staging-php","php","-l",
             "/var/www/html/wp-content/mu-plugins/"+NAME])
        check_state(True,digest)
        if run(["curl","-sS","-o","/dev/null","-w","%{http_code}",
                "--max-time","18","https://tours.dev.mad4b.com/"],timeout=25)!="200":
            raise RuntimeError("central Staging HTTP health failure")
        # Actual rollback: remove MU source and independently verify absence.
        TARGET.unlink();installed=False
        check_state(False)
        # Replacement/reinstall check: same Git blob, no module side effects.
        with TARGET.open("xb") as f:
            f.write(payload);f.flush();os.fsync(f.fileno())
        installed=True
        os.chown(TARGET,previous.st_uid,previous.st_gid)
        TARGET.chmod(0o644)
        check_state(True,digest)
        TARGET.unlink();installed=False
        check_state(False)
    finally:
        if installed and TARGET.is_file() and hashlib.sha256(TARGET.read_bytes()).hexdigest()==digest:
            TARGET.unlink()
    if TARGET.exists() or TARGET.is_symlink():
        raise RuntimeError("MU canary cleanup not complete")
    BACKUP.parent.mkdir(parents=True,exist_ok=True)
    record={
        "contract":"mad4b.local-git-mu-staging-canary-acceptance.v1",
        "source_commit":commit,"file_sha256":digest,
        "central_site":"https://tours.dev.mad4b.com",
        "target_file":NAME,
        "checks":{"install":True,"readback":True,"smoke":True,
                  "rollback":True,"reinstall":True,"cleanup":True},
        "release_scope":"staging_mu_test_only",
        "production_authorized":False,
        "existing_mu_plugins_modified":False,
        "tested_at":int(time.time())
    }
    with BACKUP.open("x") as f:
        os.fchmod(f.fileno(),0o600)
        json.dump(record,f,sort_keys=True,indent=2)
        f.write("\n")
    print(json.dumps({"state":"STAGING_MU_CANARY_PASS","source_commit":commit,
                      "checks":record["checks"],"remaining_canary_file":False,
                      "production_authorized":False}))

if __name__=="__main__":
    try:test()
    except BaseException as exc:
        print("MU_CANARY_FAILED: "+str(exc),file=sys.stderr)
        sys.exit(1)
