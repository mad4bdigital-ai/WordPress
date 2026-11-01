#!/usr/bin/env python3
"""Schema-backed JetEngine listing activation and tenant-native WPML group mapping."""
import hashlib
import json
import os
import pathlib
import subprocess
import sys
import tempfile
from datetime import datetime, timezone

BASE=pathlib.Path("/opt/mad4b-company-provisioning")
BLUEPRINT=BASE/"blueprints/tourism-foundation-v1/elementor-and-jet-design.json"
MANIFEST=BASE/"blueprints/tourism-foundation-v1/foundation-manifest.json"
SCHEMA=BASE/"reports/wayfatours-schema-compatibility.json"
SCRIPT=BASE/"bin/wayfa-jet-config-autoload.php"
BACKUP=BASE/"private/wayfatours-design-activation-20261030"
REPORT=BASE/"reports/wayfatours-jet-config-autoload.json"

def source():
    raw=BLUEPRINT.read_bytes()
    fingerprint=hashlib.sha256(raw).hexdigest()
    manifest=json.loads(MANIFEST.read_text())
    if manifest["files"]["elementor-and-jet-design.json"]["sha256"]!=fingerprint:
        raise RuntimeError("SOURCE_BLUEPRINT_HASH_MISMATCH")
    obj=json.loads(raw)
    groups=set();items=[];ids=set()
    for x in obj["posts"]:
        p=x["post"]
        if p["post_type"]!="jet-engine":continue
        id=int(p["ID"])
        if id in ids:raise RuntimeError("DUPLICATE_SOURCE_JET_ID")
        ids.add(id)
        lang=x["language"];groups.add(str(lang["trid"]))
        items.append({"id":id,"status":p["post_status"],
                      "source_trid":str(lang["trid"]),"lang":lang["language_code"]})
    if len(items)!=332 or len(groups)!=83:raise RuntimeError("SOURCE_JET_COUNT_MISMATCH")
    subprocess.run(["python3",str(BASE/"bin/wayfa-schema-compatibility.py"),"--probe"],
                   check=True,stdout=subprocess.DEVNULL,timeout=230)
    schema=json.loads(SCHEMA.read_text())
    if schema["state"]!="SCHEMA_PARITY_CONFIRMED":
        print(json.dumps({"state":"SECONDARY_CONFIG_RESOLUTION",
                          "diffs":schema["diffs"],"changes_applied":0}))
        raise RuntimeError("CONFIG_DRIFT_NEEDS_RESOLUTION")
    return {"contract":"mad4b.wayfatours-jet-config-autoload.v1",
            "blueprint_sha256":fingerprint,"rows":items,"schema":schema}

def cli(action, payload,plan_hash=None,backup_confirmed=False):
    c=["docker","exec","-i","-u","33:33",
       "-e","MAD4B_JET_ACTION="+action,
       "-e","MAD4B_JET_PAYLOAD="+json.dumps(payload,separators=(",",":"))]
    if plan_hash:c+=["-e","MAD4B_JET_EXPECTED_PLAN="+plan_hash]
    if backup_confirmed:c+=["-e","MAD4B_JET_BACKUP_CONFIRMED=1"]
    c+=["wayfatours-production-php","php","-d","memory_limit=768M",
        "/usr/local/bin/wp","eval-file","-","--path=/var/www/html",
        "--skip-plugins","--skip-themes"]
    r=subprocess.run(c,input=SCRIPT.read_text(),text=True,capture_output=True,timeout=140)
    if r.returncode:raise RuntimeError("JET_CLI_REJECTED:"+r.stderr[-650:])
    for line in reversed(r.stdout.splitlines()):
        if line.startswith("{"):return json.loads(line)
    raise RuntimeError("JET_CLI_NO_RESULT")

def write(path,record):
    path.parent.mkdir(parents=True,exist_ok=True)
    fd,temp=tempfile.mkstemp(dir=path.parent,prefix=".jet-autoload-")
    try:
        with os.fdopen(fd,"w") as f:json.dump(record,f,ensure_ascii=False,indent=2)
        os.chmod(temp,0o600);os.replace(temp,path)
    finally:
        if os.path.exists(temp):os.unlink(temp)

def main():
    argv=sys.argv[1:]
    if argv not in ([],["--plan"],["--apply"]):
        raise RuntimeError("USAGE --plan|--apply")
    payload=source()
    plan=cli("plan",payload)
    print(json.dumps({k:v for k,v in plan.items() if k!="eligible_ids"},ensure_ascii=False))
    if argv!=["--apply"]:return
    backup=cli("backup",payload)
    if backup["state"]!="JET_BACKUP" or backup["plan_sha256"]!=plan["plan_sha256"] or len(backup["statuses"])!=332:
        raise RuntimeError("JET_BACKUP_PLAN_MISMATCH")
    timestamp=datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    file=BACKUP/("jet-autoload-backup-"+timestamp+".json")
    write(file,backup)
    if not file.is_file() or file.stat().st_size<2000:raise RuntimeError("JET_BACKUP_UNAVAILABLE")
    result=cli("apply",payload,plan_hash=plan["plan_sha256"],backup_confirmed=True)
    if result.get("state")!="JET_CONFIG_AUTO_APPLIED" or result.get("activated")!=plan["eligible"]:
        raise RuntimeError("JET_APPLY_NOT_VERIFIED")
    write(REPORT,{"timestamp":datetime.now(timezone.utc).isoformat(),
        "backup_path":str(file),"result":result})
    print(json.dumps({k:v for k,v in result.items() if k!="eligible_ids"},ensure_ascii=False))

if __name__=="__main__":main()

