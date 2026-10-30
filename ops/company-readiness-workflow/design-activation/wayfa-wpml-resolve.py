#!/usr/bin/env python3
"""Governed Wayfatours Elementor WPML mapping, source-derived and tenant-minted."""
import datetime, hashlib, json, os, pathlib, subprocess, sys, tempfile
ROOT=pathlib.Path("/opt/mad4b-company-provisioning")
BLUEPRINT=ROOT/"blueprints/tourism-foundation-v1/elementor-and-jet-design.json"
MANIFEST=ROOT/"blueprints/tourism-foundation-v1/foundation-manifest.json"
SCRIPT=pathlib.Path("/opt/mad4b-company-provisioning/bin/wayfa-wpml-resolve.php")
BACKUP_DIR=ROOT/"private/wayfatours-design-activation-20261030"
CONTAINER="wayfatours-production-php"
def source():
    data=BLUEPRINT.read_bytes()
    sha=hashlib.sha256(data).hexdigest()
    manifest=json.loads(MANIFEST.read_text())
    if manifest["files"]["elementor-and-jet-design.json"]["sha256"]!=sha:
        raise RuntimeError("BLUEPRINT_SHA_MISMATCH")
    obj=json.loads(data)
    if obj["mode"]!="draft_only" or obj["source"]!="tours.dev.mad4b.com":
        raise RuntimeError("UNTRUSTED_SOURCE_BLUEPRINT")
    groups={}
    for row in obj["posts"]:
        p=row.get("post",{})
        if p.get("post_type")!="elementor_library":continue
        info=row.get("language",{})
        origin_id=int(p["ID"])
        target_id=70006 if origin_id==6 else origin_id
        lang=info["language_code"]
        grp=str(info["trid"])
        groups.setdefault(grp,[]).append({"id":target_id,"lang":lang})
    if len(groups)!=176 or sum(len(g) for g in groups.values())!=470:
        raise RuntimeError("UNEXPECTED_GROUP_CARDINALITY")
    return {"contract":"mad4b.wayfatours-wpml-resolution.v1",
            "source_sha256":sha,"groups":list(groups.values())}
def wordpress(action,payload,backup_confirmed=False):
    env=[ "-e", "MAD4B_DESIGN_ACTION="+action,
          "-e","MAD4B_DESIGN_WPML_MAP="+json.dumps(payload,separators=(",",":")) ]
    if backup_confirmed:env+=["-e","MAD4B_DESIGN_BACKUP_CONFIRMED=1"]
    cmd=["docker","exec","-i","-u","33:33",*env,CONTAINER,
         "php","-d","memory_limit=512M","/usr/local/bin/wp","eval-file","-",
         "--path=/var/www/html","--skip-plugins","--skip-themes"]
    p=subprocess.run(cmd,input=SCRIPT.read_text(),text=True,capture_output=True,timeout=110)
    if p.returncode:raise RuntimeError("WORDPRESS_DENIED:"+p.stderr[-800:])
    for line in reversed(p.stdout.splitlines()):
        if line.startswith("{"):return json.loads(line)
    raise RuntimeError("NO_RESULT")
def persist(path,obj):
    path.parent.mkdir(parents=True,exist_ok=True)
    fd,tmp=tempfile.mkstemp(prefix=".wayfa-resolve-",dir=path.parent)
    try:
        with os.fdopen(fd,"w") as f:json.dump(obj,f,ensure_ascii=False,indent=2)
        os.chmod(tmp,0o600);os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
def main():
    mode=(sys.argv[1:] or ["--plan"])[0]
    if mode not in ("--plan","--apply"):raise RuntimeError("USAGE --plan|--apply")
    payload=source()
    print(json.dumps(wordpress("plan",payload)))
    if mode=="--plan":return
    snapshot=wordpress("backup",payload)
    if snapshot.get("state")!="WPML_BEFORE" or len(snapshot.get("target_ids",[]))!=470:
        raise RuntimeError("BACKUP_MISMATCH")
    before=BACKUP_DIR/"wpml-before.json"
    persist(before,snapshot)
    if not before.is_file() or before.stat().st_size<500:
        raise RuntimeError("BACKUP_NOT_STORED")
    result=wordpress("apply",payload,True)
    if result.get("state")!="WPML_LANGUAGE_RELATIONS_RESTORED":
        raise RuntimeError("APPLY_NOT_CONFIRMED")
    persist(BACKUP_DIR/"wpml-apply-receipt.json",
      {"time_utc":datetime.datetime.now(datetime.timezone.utc).isoformat(),
       "source_sha256":payload["source_sha256"],"result":result})
    print(json.dumps(result))
if __name__=="__main__":main()

