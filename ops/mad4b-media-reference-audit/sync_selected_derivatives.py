#!/usr/bin/env python3
"""Exact, add-only sync of selected regenerated Staging image sizes into VPS origin.

Consumes a reviewed WordPress image-metadata export. All writes are backed
by SHA-256 journal, no overwrites, no directory delete, no company hosting.
"""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import re

IMAGE_EXTS={".png",".jpg",".jpeg",".webp",".gif",".avif"}

def require(ok,message):
    if not ok: raise ValueError(message)

def digest(path):
    h=hashlib.sha256()
    with path.open("rb") as f:
        for b in iter(lambda:f.read(1048576),b""):h.update(b)
    return h.hexdigest()

def resolve(root,relative):
    require(isinstance(relative,str) and len(relative)>0 and len(relative)<900, "bad relative file")
    require("%" not in relative and "\\" not in relative and "\x00" not in relative, "unsafe filename")
    p=Path(relative)
    require(not p.is_absolute() and all(x not in (".","..","") and not x.startswith(".") for x in relative.split("/")),"unsafe segments")
    require(p.suffix.lower() in IMAGE_EXTS,"unexpected file type")
    target=root/p
    require(target.resolve().is_relative_to(root.resolve()),"path outside root")
    cur=root
    for part in p.parts:
        cur=cur/part
        require(not cur.is_symlink(),"symlink not permitted")
    return target

def plan(metadata,stage,origin):
    lines=[json.loads(s) for s in metadata.open()]
    ids={x["id"] for x in lines}
    require(len(lines)==len(ids)==17,"selected attachment export was incomplete")
    paths={p for row in lines for p in row["existing_derivatives"]}
    require(paths and len(paths)<1000,"invalid derivative list")
    entries=[]
    for relative in sorted(paths):
        source=resolve(stage,relative)
        require(source.is_file(),"WP derivative does not exist")
        target=resolve(origin,relative)
        checksum=digest(source)
        if target.exists():
            require(target.is_file() and digest(target)==checksum,"origin derivative content conflict")
            continue
        entries.append({"relative_path":relative,"bytes":source.stat().st_size,"sha256":checksum})
    return {
        "contract":"mad4b.media.derivative-addonly-sync.v1",
        "environment":"ETG Commerce Staging",
        "status":"REVIEW_ONLY_PLAN",
        "generated_utc":datetime.datetime.now(datetime.timezone.utc).isoformat(),
        "selected_attachment_ids":sorted(ids),
        "files_to_create":len(entries),
        "entries":entries,
        "source_not_deleted":True,
        "no_production_or_dns_changes":True
    }

def write_new(path,payload):
    require(not path.exists() and path.is_absolute(),"refuse overwrite")
    path.parent.mkdir(parents=True,exist_ok=True)
    with path.open("x") as f:
        os.chmod(path,0o600)
        json.dump(payload,f,ensure_ascii=False,indent=2)
        f.write("\n");f.flush();os.fsync(f.fileno())

def apply(plan_path,stage,origin,status,expected,receipt):
    require(status.read_text().strip()=="LOCAL_RESTORE_VERIFIED","unverified backup")
    require(digest(plan_path)==expected,"plan was modified")
    p=json.loads(plan_path.read_text())
    require(p["contract"]=="mad4b.media.derivative-addonly-sync.v1","wrong contract")
    require(not receipt.exists(),"receipt exists")
    actual=[]
    for row in p["entries"]:
        source=resolve(stage,row["relative_path"])
        target=resolve(origin,row["relative_path"])
        require(source.is_file() and digest(source)==row["sha256"],"source moved")
        require(not target.exists(),"target exists; cannot overwrite")
        target.parent.mkdir(parents=True,exist_ok=True)
        fd=os.open(target,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o644)
        with os.fdopen(fd,"wb") as dst,source.open("rb") as src:
            while (buf:=src.read(1048576)):
                dst.write(buf)
            dst.flush();os.fsync(dst.fileno())
        require(target.stat().st_size==row["bytes"] and digest(target)==row["sha256"],"origin checksum mismatch")
        actual.append(row)
        # Partial progress journal survives interruption.
        with (receipt.parent/(receipt.name+".progress.jsonl")).open("a") as f:
            os.chmod(f.name,0o600)
            f.write(json.dumps(row,ensure_ascii=False)+"\n");f.flush();os.fsync(f.fileno())
    data={"contract":"mad4b.media.derivative-addonly-sync.receipt.v1",
          "plan_sha256":expected,"created_file_count":len(actual),
          "entries":actual,"status":"VERIFIED","media_origin_only":True,
          "staging_file_changes":0,"production_changes":0}
    write_new(receipt,data)
    print(json.dumps({"status":"VERIFIED","created_in_origin":len(actual),"sha256":digest(receipt)}))
def rollback(receipt,origin,expected):
    require(digest(receipt)==expected,"rollback receipt checksum mismatch")
    payload=json.loads(receipt.read_text())
    require(payload.get("contract")=="mad4b.media.derivative-addonly-sync.receipt.v1","invalid rollback receipt")
    rows=payload.get("entries",[])
    require(isinstance(rows,list) and len(rows)==payload.get("created_file_count"),"receipt cardinality mismatch")
    # Validate every byte before deleting ANY newly-added file.
    paths=[]
    for item in rows:
        target=resolve(origin,item["relative_path"])
        require(target.is_file() and digest(target)==item["sha256"],"modified derivative; rollback refused")
        paths.append(target)
    for path in reversed(paths):
        path.unlink()
    print(json.dumps({"status":"ROLLED_BACK_VERIFIED","new_files_removed":len(paths),"existing_files_deleted":0}))

def main():
    p=argparse.ArgumentParser()
    p.add_argument("action",choices=["plan","apply","rollback"])
    p.add_argument("--metadata",type=Path)
    p.add_argument("--stage",required=True,type=Path)
    p.add_argument("--origin",required=True,type=Path)
    p.add_argument("--plan",required=True,type=Path)
    p.add_argument("--backup-status",type=Path)
    p.add_argument("--sha")
    p.add_argument("--receipt",type=Path)
    a=p.parse_args()
    require(a.stage.is_absolute() and a.origin.is_absolute() and a.stage.is_dir() and a.origin.is_dir(),"invalid root")
    if a.action=="plan":
        require(a.metadata is not None and a.metadata.is_file(),"missing metadata")
        data=plan(a.metadata,a.stage,a.origin)
        write_new(a.plan,data)
        print(json.dumps({"planned_new_files":data["files_to_create"],"plan_sha256":digest(a.plan)}))
    elif a.action=="apply":
        require(a.backup_status and a.sha and re.fullmatch("[a-f0-9]{64}",a.sha) and a.receipt,"missing authorization inputs")
        apply(a.plan,a.stage,a.origin,a.backup_status,a.sha,a.receipt)
    else:
        require(a.sha and re.fullmatch("[a-f0-9]{64}",a.sha) and a.receipt,"missing rollback receipt")
        rollback(a.receipt,a.origin,a.sha)
if __name__=="__main__":main()
