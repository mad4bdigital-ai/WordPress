#!/usr/bin/env python3
"""Source-state-aware, fail-closed Wayfatours design activation. Never activates Theme Builder conditions."""
import datetime
import hashlib
import json
import os
import pathlib
import subprocess
import sys
import tempfile

ROOT=pathlib.Path("/opt/mad4b-company-provisioning")
BLUEPRINT=ROOT/"blueprints/tourism-foundation-v1/elementor-and-jet-design.json"
MANIFEST=ROOT/"blueprints/tourism-foundation-v1/foundation-manifest.json"
SCRIPT=ROOT/"bin/wayfa-design-activate.php"
BACKUP=ROOT/"private/wayfatours-design-activation-20261030"
REPORT=ROOT/"reports/wayfatours-design-activation.json"
SITE="wayfatours-production-php"

def source():
    raw=BLUEPRINT.read_bytes()
    sha=hashlib.sha256(raw).hexdigest()
    manifest=json.loads(MANIFEST.read_text())
    if sha != manifest["files"]["elementor-and-jet-design.json"]["sha256"]:
        raise RuntimeError("SOURCE_BLUEPRINT_HASH_MISMATCH")
    src=json.loads(raw)
    if src.get("source")!="tours.dev.mad4b.com" or src.get("mode")!="draft_only":
        raise RuntimeError("SOURCE_SCOPE_MISMATCH")
    templates={}
    for entry in src["posts"]:
        p=entry.get("post",{})
        if p.get("post_type")!="elementor_library":continue
        meta=entry.get("meta",{})
        if isinstance(meta,list):
            m={z.get("meta_key"):z.get("meta_value") for z in meta if isinstance(z,dict)}
        elif isinstance(meta,dict):m=meta
        else:raise RuntimeError("SOURCE_META_SCHEMA_MISMATCH")
        target=str(70006 if int(p["ID"])==6 else int(p["ID"]))
        if target in templates:raise RuntimeError("SOURCE_DUPLICATE_TEMPLATE_ID")
        templates[target]={"status":p["post_status"],
            "kind":m.get("_elementor_template_type",""),"lang":entry["language"]["language_code"]}
    if len(templates)!=470:raise RuntimeError("SOURCE_TEMPLATE_COUNT_MISMATCH")
    return {"contract":"mad4b.wayfatours-template-source.v1",
            "source_sha256":sha,"templates":templates}

def wp(action,payload,*,plan="",backup=False):
    cmd=["docker","exec","-i","-u","33:33",
         "-e","MAD4B_DESIGN_ACTION="+action,
         "-e","MAD4B_DESIGN_TEMPLATE_SOURCE="+json.dumps(payload,separators=(",",":"))]
    if plan:cmd.extend(["-e","MAD4B_DESIGN_EXPECTED_PLAN="+plan])
    if backup:cmd.extend(["-e","MAD4B_DESIGN_BACKUP_CONFIRMED=1"])
    cmd.extend([SITE,"php","-d","memory_limit=512M","/usr/local/bin/wp","eval-file","-",
                "--path=/var/www/html","--skip-plugins","--skip-themes"])
    result=subprocess.run(cmd,input=SCRIPT.read_text(),capture_output=True,text=True,timeout=110)
    if result.returncode:
        raise RuntimeError("WP_FAIL_CLOSED:"+result.stderr[-800:])
    for line in reversed(result.stdout.splitlines()):
        if line.startswith("{"):return json.loads(line)
    raise RuntimeError("WP_INVALID_READBACK")

def persist(file,obj):
    file.parent.mkdir(parents=True,exist_ok=True)
    fd,tmp=tempfile.mkstemp(dir=file.parent,prefix=".design-resolve-")
    try:
        with os.fdopen(fd,"w") as f:
            json.dump(obj,f,ensure_ascii=False,indent=2)
            f.write("\n")
        os.chmod(tmp,0o600)
        os.replace(tmp,file)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

def main():
    args=sys.argv[1:]
    if args not in ([],["--plan"],["--apply"]):raise RuntimeError("USE --plan / --apply")
    p=source()
    plan=wp("plan",p)
    if plan.get("state")!="DESIGN_ACTIVATION_PLAN" or plan.get("site_uuid")!="b0725ffa-87e1-47e3-b0c0-c4aa4eb8882b":
        raise RuntimeError("PLAN_IDENTITY_FAILURE")
    print(json.dumps(plan,ensure_ascii=False))
    if args!=["--apply"]:return
    backup=wp("backup",p)
    if backup.get("state")!="DESIGN_ACTIVATION_BACKUP" or backup.get("plan_sha256")!=plan["plan_sha256"] or len(backup.get("records",[]))!=plan["eligible"]:
        raise RuntimeError("EXACT_BACKUP_MISMATCH")
    stamp=datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    backup_file=BACKUP/("selective-template-backup-"+stamp+".json")
    persist(backup_file,backup)
    if not backup_file.exists():raise RuntimeError("BACKUP_MISSING")
    result=wp("apply",p,plan=plan["plan_sha256"],backup=True)
    if result.get("state")!="DESIGN_ACTIVATION_APPLIED" or result.get("activated")!=plan["eligible"]:
        raise RuntimeError("APPLY_READBACK_MISMATCH")
    receipt={"timestamp_utc":datetime.datetime.now(datetime.timezone.utc).isoformat(),
        "status":"ACTIVATED_BOUNDED_COMPANY_NEUTRAL_TEMPLATES",
        "source_sha256":p["source_sha256"],"site_uuid":plan["site_uuid"],
        "backup_file":str(backup_file),
        "plan_sha256":plan["plan_sha256"],"result":result}
    persist(REPORT,receipt)
    print(json.dumps(result,ensure_ascii=False))
if __name__=="__main__":main()

