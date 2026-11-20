#!/usr/bin/env python3
"""Reconcile existing first-party Central dynamic 313 v4 to installed schema v2.

Does NOT replace v4 with older fallback, change the original native_finish,
create a Job, or permit execution. Preserves dual independent v4 signatures.
"""
from __future__ import annotations
import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import tempfile
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import registry

SOURCE=Path(__file__).resolve().parents[1]
ROOT=Path("/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled")
CENTRAL=Path("/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/plugins/mad4b-platform/modules")
WP=CENTRAL/"authority/dynamic313"
B3=CENTRAL/"services/313b-mad4b-native-independent-verifier.php"
REPORT=Path("/opt/mad4b-company-provisioning/reports/company-code-release-git-local-20261108/dynamic-313-v4-evidence-schema-v2-backup")
DEST={
  WP/"class-mad4b-dynamic-313-wordpress.php": SOURCE/"php/class-mad4b-dynamic-313-wordpress.php",
  WP/"class-mad4b-dynamic-313-verifier.php": SOURCE/"php/class-mad4b-dynamic-313-verifier.php",
  B3: SOURCE/"php/313b-first-party-dynamic-v4.php",
}
PINNED_OLD={
 "class-mad4b-dynamic-313-wordpress.php":"40109c8bbfc96e9daa9d17ad2f9aa6258a556e7432c225e7fa07dde9d30ac871",
 "class-mad4b-dynamic-313-verifier.php":"9296331791cd88b9d3fb9a43e58902a4e794b0eabfdfff4a12b0477eb623a5f5",
 "313b-mad4b-native-independent-verifier.php":"c1e71a46d6d6bfe9944afcf513df4372acf9a2c00276026bb00347c2d3bbfda6",
 "config.php":"151ac1bcf40120d2139ea1dbf481a999ae1d001e21d4c10ea00520c5b20ee98b",
}

def require(cond,reason):
    if not cond:raise registry.RegistryDenied(reason)

def atomically_write_wp(path:Path,data:bytes):
    require(not path.is_symlink() and path.parent.is_dir() and
            not path.parent.is_symlink() and
            not path.parent.stat().st_mode & 0o022,
            "UNSAFE_CENTRAL_MODULE_DIRECTORY")
    fd,tmp=tempfile.mkstemp(prefix=".mad4b-313-v4-",dir=path.parent)
    try:
        os.fchmod(fd,0o644)
        os.fchown(fd,0,0)
        with os.fdopen(fd,"wb") as stream:
            stream.write(data);stream.flush();os.fsync(stream.fileno())
        os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

def expected():
    index=registry.Registry(ROOT)
    m=index.resolve("company.code.release")
    require(m["version"]==2 and m["evidence_schema"]=="code_release.v2",
            "ORIGINAL_ENROLLED_PROVIDER_V2_REQUIRED")
    for path in (*DEST,WP/"config.php"):
        meta=path.lstat()
        require(stat.S_ISREG(meta.st_mode) and
                not path.is_symlink() and meta.st_uid==0 and
                not meta.st_mode & 0o022 and
                hashlib.sha256(path.read_bytes()).hexdigest()==PINNED_OLD[path.name],
                "CHANGED_LIVE_313_SOURCE_ABORT:"+path.name)
    live=B3.read_text()
    require("supports_dynamic_v4" in live and
            "recorded_exact_for_original_313" in live and
            "DYNAMIC_FINALIZER_BRIDGE" not in live,
            "LIVE_ORIGINAL_V4_CONTRACT_CHANGED")
    trusted={
      "registry_contract":registry.INDEX_CONTRACT,
      "first_party_enrolled":True,
      "provider":m,
      "evidence_schema":index.evidence_definition("company.code.release"),
      "independent_verifier_public_key_b64":base64.b64encode(
         index.independent_verifier_public_key).decode(),
    }
    original=config=WP/"config.php"
    raw=config.read_text()
    match=re.search(r"base64_decode\('([^']+)'\)",raw)
    require(match is not None,"CURRENT_CENTRAL_CONFIG_NOT_PINNED")
    doc=json.loads(base64.b64decode(match.group(1),validate=True))
    require(doc["original_313_finalizer_connected"] is False and
            doc["trusted"]["provider"]["version"]==1 and
            doc["trusted"]["independent_verifier_public_key_b64"]==
              trusted["independent_verifier_public_key_b64"],
            "CENTRAL_V1_PROOF_CONFIG_UNEXPECTED")
    new={
        "contract":"mad4b.dynamic-313-first-party-config.v1",
        "trusted":trusted,
        "original_313_finalizer_connected":True,
        "operational_ready":False,
        "production_mutated":False,
    }
    payload=base64.b64encode(registry.canonical(new)).decode()
    php=("<?php\nreturn json_decode(base64_decode('"+payload+
         "'),true);\n").encode()
    output={path:src.read_bytes() for path,src in DEST.items()}
    output[WP/"config.php"]=php
    require(b"supports_dynamic_v4" in output[B3] and
            b"enrolled_schema_id()" in output[B3] and
            b"operational_ready" in output[WP/"class-mad4b-dynamic-313-wordpress.php"],
            "V4_AUTHORITATIVE_SOURCE_NOT_PRESERVED")
    return output

def migrate(install=False):
    require(os.geteuid()==0,"ROOT_FIRST_PARTY_OPERATOR_REQUIRED")
    new=expected()
    state={
        "contract":"mad4b.dynamic-313-v4-evidence-v2-reconcile.v1",
        "state":"V4_EVIDENCE_V2_SOURCE_VERIFIED_NOT_INSTALLED",
        "schema_id":"code_release.v2","native_finish_replaced":False,
        "original_v4_dual_signature_preserved":True,
        "operational_ready":False,"jobs_issued":False,"grants_issued":False,
        "wayfa_production_mutated":False,
    }
    if not install:return state
    require(not REPORT.exists(),"V4_EVIDENCE_V2_UPGRADE_BACKUP_ALREADY_EXISTS")
    REPORT.mkdir(mode=0o700,parents=True)
    os.chmod(REPORT,0o700)
    originals={p:p.read_bytes() for p in new}
    for path,old in originals.items():
        name=path.name if path!=B3 else "313b-v4-before-schema-v2.php"
        dest=REPORT/name
        dest.write_bytes(old)
        os.chmod(dest,0o600)
    written=[]
    try:
        # Publish verifier, dynamic reader, static v4 adapter, then root-owned
        # Central config LAST, after all source/content checkouts passed.
        for path in (*DEST,WP/"config.php"):
            atomically_write_wp(path,new[path])
            written.append(path)
        for path in new:
            require(path.read_bytes()==new[path],
                    "ORIGINAL_V4_PUBLISHED_READBACK_DRIFT")
    except BaseException:
        for path in reversed(written):
            atomically_write_wp(path,originals[path])
        raise
    state["state"]="CENTRAL_DYNAMIC_V4_EVIDENCE_V2_CONNECTED"
    state["protected_backup"]=str(REPORT)
    return state

if __name__=="__main__":
    p=argparse.ArgumentParser()
    g=p.add_mutually_exclusive_group(required=True)
    g.add_argument("--check",action="store_true")
    g.add_argument("--install",action="store_true")
    args=p.parse_args()
    print(json.dumps(migrate(install=args.install),sort_keys=True))
