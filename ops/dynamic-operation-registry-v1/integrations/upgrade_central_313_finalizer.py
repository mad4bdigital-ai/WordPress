#!/usr/bin/env python3
"""First-party upgrade of Central 313 dynamic proof to original native_finish.

The original native_finish remains unchanged and still validates its signed
Broker completion, exact original 312 grant and independent persisted proof.
Only the 313b verifier adds a fallback for enrolled dynamic proof; legacy
v1/v2/v3 evidence processing remains intact. Dispatch stays disabled.
"""
from __future__ import annotations
import argparse
import base64
import importlib.util
import json
import os
from pathlib import Path
import re
import sys
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import registry
from first_party_install import atomic_private
from patch_original_313b import transform

SOURCE=Path(__file__).resolve().parents[1]
HOST=Path("/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled")
CENTRAL=Path("/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/plugins/mad4b-platform/modules")
TARGET=CENTRAL/"authority/dynamic313"
LEGACY=CENTRAL/"services/313b-mad4b-native-independent-verifier.php"
BACKUP=Path("/opt/mad4b-company-provisioning/reports/company-code-release-git-local-20261108/dynamic-313-finalizer-v2-backup")
DEST={
    TARGET/"class-mad4b-dynamic-313-verifier.php":
        SOURCE/"php/class-mad4b-dynamic-313-verifier.php",
    TARGET/"class-mad4b-dynamic-313-wordpress.php":
        SOURCE/"php/class-mad4b-dynamic-313-wordpress.php",
}

def require(ok,why):
    if not ok:
        raise registry.RegistryDenied(why)

def bundle():
    index=registry.Registry(HOST)
    provider=index.resolve("company.code.release")
    require(provider["version"]==2 and provider["evidence_schema"]=="code_release.v2",
            "V2_HOST_PROVIDER_REQUIRED")
    original=LEGACY.read_text()
    require("supports_dynamic_v4" not in original,
            "LIVE_ORIGINAL_DYNAMIC_V4_PRESENT_USE_V4_SCHEMA_RECONCILIATION")
    require(original.count("MAD4B_DYNAMIC_313_WP_READ_ONLY_V1")==1 and
            "DYNAMIC_FINALIZER_BRIDGE" not in original,
            "EXISTING_313B_SOURCE_UNEXPECTED")
    public=index.independent_verifier_public_key
    pub=base64.b64encode(public).decode()
    trusted={
        "registry_contract":registry.INDEX_CONTRACT,
        "first_party_enrolled":True,
        "provider":provider,
        "evidence_schema":index.evidence_definition("company.code.release"),
        "independent_verifier_public_key_b64":pub,
    }
    match=re.search(r"const PUBLIC_KEY_B64='([^']+)'",original)
    require(match is not None and match.group(1)==pub,
            "FIRST_PARTY_LEGACY_313_SIGNING_KEY_MISMATCH")
    config={
        "contract":"mad4b.dynamic-313-first-party-config.v1",
        "trusted":trusted,
        "original_313_finalizer_connected":True,
        "operational_ready":False,
        "production_mutated":False,
    }
    encoded=base64.b64encode(registry.canonical(config)).decode()
    php=("<"+"?php\nreturn json_decode(base64_decode('"+encoded+
         "'),true);\n").encode()
    return {**{key:path.read_bytes() for key,path in DEST.items()},
            TARGET/"config.php":php,
            LEGACY:transform(original).encode()}

def upgrade(install=False):
    require(os.geteuid()==0,"FIRST_PARTY_ROOT_REQUIRED")
    changes=bundle()
    originals={}
    for path in changes:
        require(path.is_file() and not path.is_symlink(),"CENTRAL_313_FILE_ABSENT")
        originals[path]=path.read_bytes()
    state={
        "contract":"mad4b.dynamic-313-original-finalizer-upgrade.v1",
        "state":"READY_FOR_ORIGINAL_313_V2_FIRST_PARTY_UPGRADE",
        "original_native_finish_replaced":False,
        "dynamic_dispatch_ready":False,
        "original_receipt_issued":False,
        "wayfa_production_mutated":False,
    }
    if not install:
        return state
    require(not BACKUP.exists(),"FINALIZER_UPGRADE_BACKUP_ALREADY_EXISTS")
    BACKUP.mkdir(mode=0o700,parents=True)
    os.chmod(BACKUP,0o700)
    for path,raw in originals.items():
        name=path.name if path!=LEGACY else "313b.original-before-v2.php"
        atomic_private(BACKUP/name,raw)
    written=[]
    try:
        # The original first-party 313b verifier is published LAST.
        for dest,contents in changes.items():
            atomic_private(dest,contents)
            os.chmod(dest,0o644)
            os.chown(dest,0,0)
            written.append(dest)
        require("DYNAMIC_FINALIZER_BRIDGE='v1'" in LEGACY.read_text(),
                "ORIGINAL_FINALIZER_BRIDGE_NOT_READBACK")
        require((TARGET/"config.php").read_bytes()==changes[TARGET/"config.php"],
                "FIRST_PARTY_CONFIG_CHANGED")
    except BaseException:
        for path in reversed(written):
            atomic_private(path,originals[path])
            os.chmod(path,0o644)
            os.chown(path,0,0)
        raise
    state["state"]="ORIGINAL_CENTRAL_313_DYNAMIC_FINALIZER_BRIDGED"
    state["original_independent_evidence_schema"]="code_release.v2"
    state["rollback_directory"]=str(BACKUP)
    return state

if __name__=="__main__":
    p=argparse.ArgumentParser()
    group=p.add_mutually_exclusive_group(required=True)
    group.add_argument("--check",action="store_true")
    group.add_argument("--install",action="store_true")
    opts=p.parse_args()
    print(json.dumps(upgrade(install=opts.install),sort_keys=True))
