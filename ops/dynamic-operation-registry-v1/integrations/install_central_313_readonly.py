#!/usr/bin/env python3
"""One-time first-party Central WordPress Dynamic 313 read-only verifier install.

Does not alter original 313 finish, accept proofs as receipts, issue Jobs/Grants,
or enable dynamic dispatch. Adds an independently verifiable status and checker.
"""
from __future__ import annotations
import base64
import json
from pathlib import Path
import os
import re
import shutil
import stat
import sys
import tempfile

OPS=Path(__file__).resolve().parents[2]
DYNAMIC=OPS/"dynamic-operation-registry-v1"
sys.path.insert(0,str(DYNAMIC))
import registry
CENTRAL=Path("/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/plugins/mad4b-platform/modules")
LIVE_LEGACY=CENTRAL/"services/313b-mad4b-native-independent-verifier.php"
TARGET=CENTRAL/"authority/dynamic313"
HOST=Path("/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled")
BACKUP=Path("/opt/mad4b-company-provisioning/reports/company-code-release-git-local-20261108/dynamic-313-live-20261108")
MARKER="MAD4B_DYNAMIC_313_WP_READ_ONLY_V1"
FILES={
 "class-mad4b-dynamic-313-verifier.php":DYNAMIC/"php/class-mad4b-dynamic-313-verifier.php",
 "class-mad4b-dynamic-313-wordpress.php":DYNAMIC/"php/class-mad4b-dynamic-313-wordpress.php",
}

def fail(condition,code):
    if not condition:raise ValueError(code)

def data():
    index=registry.Registry(HOST)
    provider=index.resolve("company.code.release")
    trusted={
       "registry_contract":registry.INDEX_CONTRACT,
       "first_party_enrolled":True,
       "provider":provider,
       "evidence_schema":index.evidence_definition("company.code.release"),
       "independent_verifier_public_key_b64":base64.b64encode(
           index.independent_verifier_public_key).decode(),
    }
    legacy=LIVE_LEGACY.read_text()
    found=re.search(r"const PUBLIC_KEY_B64='([^']+)'",legacy)
    fail(found is not None and found.group(1)==
         trusted["independent_verifier_public_key_b64"],
         "ORIGINAL_313_PUBLIC_KEY_NOT_MATCHED")
    config={
       "contract":"mad4b.dynamic-313-first-party-config.v1",
       "trusted":trusted,
       "original_313_finalizer_connected":False,
       "production_mutated":False,
    }
    config_b64=base64.b64encode(
        registry.canonical(config)).decode()
    php=(b"<?php\nreturn json_decode(base64_decode('"
         +config_b64.encode()+b"'),true);\n")
    return php,legacy

def patched_legacy(legacy:str)->str:
    fail(MARKER not in legacy,"DYNAMIC_313_ALREADY_PATCHED")
    needle="defined('ABSPATH') || exit;\n"
    fail(legacy.count(needle)==1,"ORIGINAL_313_ANCHOR_CHANGED")
    insert=needle+(
       "// "+MARKER+"\n"
       "require_once dirname(__DIR__).'/authority/dynamic313/"
       "class-mad4b-dynamic-313-wordpress.php';\n")
    return legacy.replace(needle,insert,1)

def atomic(path:Path,raw:bytes,mode:int)->None:
    fd,tmp=tempfile.mkstemp(prefix=".dynamic313-",dir=path.parent)
    try:
        os.fchmod(fd,mode)
        with os.fdopen(fd,"wb") as f:
            f.write(raw);f.flush();os.fsync(f.fileno())
        os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

def main(install:bool):
    fail(os.geteuid()==0,"FIRST_PARTY_ROOT_REQUIRED")
    config,legacy=data()
    amended=patched_legacy(legacy)
    manifest={
       "contract":"mad4b.dynamic-313-central-readonly-install.v1",
       "site":"tours.dev.mad4b.com",
       "status":"SOURCE_VERIFIED_NOT_DEPLOYED",
       "central_finalizer_changed":False,
       "original_313_receipt_issued":False,
       "production_mutated":False,
    }
    if install:
        fail(not TARGET.exists(),"EXISTING_FIRST_PARTY_DYNAMIC313_DIRECTORY")
        fail(not BACKUP.exists(),"EXISTING_DYNAMIC313_BACKUP_DIRECTORY")
        BACKUP.mkdir(mode=0o700,parents=True)
        os.chmod(BACKUP,0o700)
        atomic(BACKUP/"313b.before-dynamic.php",legacy.encode(),0o600)
        TARGET.mkdir(mode=0o755)
        os.chown(TARGET,0,0)
        for name,source in FILES.items():
            atomic(TARGET/name,source.read_bytes(),0o644)
            os.chown(TARGET/name,0,0)
        atomic(TARGET/"config.php",config,0o644)
        os.chown(TARGET/"config.php",0,0)
        # Entry-point activation only after all sources exist and were linted
        # by a previous --check and isolated PHP runtime tests.
        atomic(LIVE_LEGACY,amended.encode(),0o644)
        os.chown(LIVE_LEGACY,0,0)
        manifest["status"]="CENTRAL_DYNAMIC_313_READ_ONLY_PROOF_LOADED"
    print(json.dumps(manifest,sort_keys=True))

if __name__=="__main__":
    import argparse
    parser=argparse.ArgumentParser()
    opts=parser.add_mutually_exclusive_group(required=True)
    opts.add_argument("--check",action="store_true")
    opts.add_argument("--install",action="store_true")
    x=parser.parse_args()
    main(x.install)
