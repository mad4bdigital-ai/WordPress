#!/usr/bin/env python3
"""Dynamic PHP resolution for original independent 313 read-only canaries."""
from pathlib import Path
import json
import os
import re
import stat
import subprocess
import sys

ROOT=Path("/opt/mad4b-company-transport/native-313-independent")
NATIVE_MODULE=ROOT.parent/"native-operator-v1"
sys.path.insert(0,str(NATIVE_MODULE))
from native_operator_target import target as resolve_native

REGISTRY=NATIVE_MODULE/"target-runtime-bindings.v1.json"
PROFILE_PHP=(
    '$s=class_exists("MAD4B_SCP_Site_Profile",false)?MAD4B_SCP_Site_Profile::status():[];'
    'echo wp_json_encode(["origin"=>untrailingslashit(home_url("/")),'
    '"site_uuid"=>$s["site_uuid"]??null,"environment"=>wp_get_environment_type(),'
    '"bound"=>!empty($s["configured"])&&!empty($s["origin_match"])'
    '&&!empty($s["deployment_binding_match"])&&!empty($s["environment_match"])]);'
)


def original_scope(origin,site_uuid):
    st=REGISTRY.lstat()
    if (not stat.S_ISREG(st.st_mode) or st.st_uid!=0 or st.st_mode&0o077
        or REGISTRY.is_symlink() or st.st_size>65536):
        raise RuntimeError("ORIGINAL_HOST_TARGET_REGISTRY_UNTRUSTED")
    doc=json.loads(REGISTRY.read_text())
    if doc.get("contract")!="mad4b.company-native-target-runtime-bindings.v1":
        raise RuntimeError("ORIGINAL_TARGET_CONTRACT_UNSUPPORTED")
    found=[]
    for key,row in (doc.get("targets") or {}).items():
        if (isinstance(row,dict) and row.get("site_uuid")==site_uuid
            and row.get("canonical_origin")==origin and ":" in key):
            company,environment=key.split(":",1)
            found.append((company,environment))
    if len(found)!=1:
        raise RuntimeError("ORIGINAL_313_COMPANY_SCOPE_NOT_UNIQUE")
    return found[0]


def discover(origin,site_uuid):
    company,environment=original_scope(origin,site_uuid)
    resolved=resolve_native(company,environment)
    if (resolved.get("site_uuid")!=site_uuid or
        resolved.get("canonical_origin")!=origin or
        resolved.get("environment")!=environment):
        raise RuntimeError("ORIGINAL_313_RUNTIME_SCOPE_MISMATCH")
    container_id=resolved.get("target_container_id","")
    if not re.fullmatch(r"[a-f0-9]{64}",container_id):
        raise RuntimeError("ORIGINAL_313_CONTAINER_ID_UNVERIFIED")
    inspection=subprocess.run(["docker","inspect",container_id,
        "--format","{{json .Mounts}}"],capture_output=True,text=True,timeout=12)
    if inspection.returncode:
        raise RuntimeError("ORIGINAL_313_MOUNT_UNAVAILABLE")
    mounts=json.loads(inspection.stdout)
    sites=[m for m in mounts if m.get("Destination")=="/var/www/html"
           and m.get("Type")=="bind" and m.get("RW") is True]
    if len(sites)!=1:
        raise RuntimeError("ORIGINAL_313_SITE_MOUNT_AMBIGUOUS")
    source=Path(sites[0].get("Source",""))
    if not source.is_dir() or source.is_symlink():
        raise RuntimeError("ORIGINAL_313_SITE_MOUNT_UNVERIFIED")
    process=subprocess.run(["docker","exec","-u","33:33",container_id,
        "/usr/local/bin/wp","--path=/var/www/html","--skip-themes",
        "eval",PROFILE_PHP],capture_output=True,text=True,timeout=35)
    if process.returncode:
        raise RuntimeError("ORIGINAL_313_SITE_PROFILE_UNAVAILABLE")
    try:
        actual=json.loads(process.stdout.strip().splitlines()[-1])
    except (IndexError,ValueError):
        raise RuntimeError("ORIGINAL_313_SITE_PROFILE_INVALID")
    if (actual.get("origin")!=origin or actual.get("site_uuid")!=site_uuid
        or actual.get("environment")!=environment
        or actual.get("bound") is not True):
        raise RuntimeError("ORIGINAL_313_SITE_PROFILE_NOT_BOUND")
    return container_id


if __name__=="__main__":
    try:
        doc=json.loads(REGISTRY.read_text())
        entries=[v for v in (doc.get("targets") or {}).values() if isinstance(v,dict)]
        if len(entries)!=1:
            raise RuntimeError("EXPLICIT_SCOPED_313_JOB_REQUIRED")
        row=entries[0]
        ident=discover(row["canonical_origin"],row["site_uuid"])
        label=subprocess.run(["docker","inspect",ident,"--format",
            '{{index .Config.Labels "coolify.serviceUuid"}}'],
            capture_output=True,text=True,timeout=12)
        if label.returncode or not re.fullmatch(r"[a-z0-9]{20,64}",label.stdout.strip()):
            raise RuntimeError("ORIGINAL_313_RESOURCE_LABEL_UNVERIFIED")
        print(json.dumps({"state":"EXACT_COOLIFY_PHP_RUNTIME_VERIFIED",
            "runtime_container":ident,"runtime_container_id":ident,
            "service_uuid":label.stdout.strip(),
            "discovery":"exact_dynamic_company_registry",
            "execution_authorized":False,"read_only":True}))
    except Exception as exc:
        print(json.dumps({"state":"RUNTIME_DISCOVERY_HOLD",
            "reason":str(exc),"execution_authorized":False,"read_only":True}))
        raise SystemExit(2)
