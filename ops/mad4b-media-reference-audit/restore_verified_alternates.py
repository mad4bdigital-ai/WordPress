#!/usr/bin/env python3
"""Exact-match, no-overwrite restoration of missing Staging WordPress image paths.

Two explicit modes: prepare a reviewed immutable plan, apply that same plan,
or rollback only files created by the receipt with unchanged exact SHA-256.
No DB edits, no deletions of pre-existing files, no Production connections.
"""
import argparse
from collections import defaultdict
import datetime
import hashlib
import json
import os
from pathlib import Path
import re

EXT_MIME = {
    ".jpg":"image/jpeg", ".jpeg":"image/jpeg", ".png":"image/png",
    ".webp":"image/webp", ".gif":"image/gif", ".avif":"image/avif"
}

def require(ok, reason):
    if not ok: raise ValueError(reason)

def sha256(path):
    h=hashlib.sha256()
    with path.open("rb") as f:
        for buf in iter(lambda:f.read(4*1024*1024),b""):h.update(buf)
    return h.hexdigest()

def read_jsonl(path):
    return [json.loads(s) for s in path.open(encoding="utf-8")]

def relpath(raw):
    require(isinstance(raw,str) and 0<len(raw)<900 and "%" not in raw and "\\" not in raw and "\x00" not in raw, "unsafe pathname")
    p=Path(raw)
    require(not p.is_absolute() and all(v not in ("..",".","") and not v.startswith(".") for v in raw.split("/")), "unsafe path segments")
    return p

def inside(root, relative, *, must_exist):
    p=root/relpath(relative)
    require(p.resolve().is_relative_to(root.resolve()),"path escaped root")
    cur=root
    for part in relpath(relative).parts:
        cur=cur/part
        require(not cur.is_symlink(),"symlink in media path")
    require(p.is_file() if must_exist else not p.exists(), "file existence invariant failed")
    return p

def plan(meta, sizes, dims, backup, stage, origin):
    expected={x["id"]:x for x in read_jsonl(meta)}
    expected_sizes={x["id"]:x for x in read_jsonl(sizes)}
    dim_records=read_jsonl(dims)
    by_target=defaultdict(list)
    rejected=defaultdict(int)
    for rec in dim_records:
        id=rec["id"]
        m=expected.get(id); z=expected_sizes.get(id)
        if not m or not z or m["path"]!=rec["expected_file"]:
            rejected["metadata_conflict"]+=1;continue
        target=m["path"]; ext=Path(target).suffix.lower()
        if ext not in EXT_MIME or z.get("expected_mime")!=EXT_MIME[ext]:
            rejected["extension_or_meta_mime_mismatch"]+=1;continue
        mime=z.get("expected_mime")
        size=z.get("expected_size")
        if not isinstance(size,int) or size<=0:
            rejected["no_expected_filesize"]+=1;continue
        if not isinstance(m.get("width"),int) or not isinstance(m.get("height"),int):
            rejected["no_dimensions"]+=1;continue
        matches={}
        for a in rec["alternates"]:
            path=a["relative_path"]
            if Path(path).name != Path(target).name:
                continue
            if a.get("width")!=m["width"] or a.get("height")!=m["height"] or a.get("mime")!=mime:continue
            source=inside(backup,path,must_exist=True)
            if source.stat().st_size != size:continue
            matches[path]=sha256(source)
        content=set(matches.values())
        if len(content)!=1:
            rejected["no_unique_verified_content"]+=1;continue
        chosen=sorted(matches)[0]
        by_target[target].append({"id":id,"source":chosen,"sha256":next(iter(content)),
                                  "size":size,"width":m["width"],"height":m["height"],"mime":mime})
    entries=[]
    for relative, candidates in sorted(by_target.items()):
        if len({x["sha256"] for x in candidates})!=1:
            rejected["conflicting_ids_for_same_target"]+=len(candidates);continue
        for root in (stage,origin):
            inside(root,relative,must_exist=False)
        chosen=sorted(candidates,key=lambda x:(x["source"],x["id"]))[0]
        entries.append({
          "dest":relative,
          "source_in_backup":chosen["source"],
          "sha256":chosen["sha256"],
          "size":chosen["size"],
          "mime":chosen["mime"],
          "expected_dimensions":[chosen["width"],chosen["height"]],
          "attachment_ids":sorted(x["id"] for x in candidates)
        })
    result={
      "contract":"mad4b.media.verified-alternate-restore.v1",
      "mode":"staging_only",
      "source_backup":"ETG Commerce Staging local restored-and-verified backup 2026-10-29",
      "dry_run":True,
      "created_utc":datetime.datetime.now(datetime.timezone.utc).isoformat(),
      "entries":entries,
      "attachment_records_recoverable":sum(len(x["attachment_ids"]) for x in entries),
      "unique_image_paths_recoverable":len(entries),
      "rejected_causes":dict(rejected),
      "database_edits":0,
      "production_edits":0,
      "requires_reviewed_exact_sha_before_apply":True
    }
    return result

def write_once(path,obj):
    require(path.is_absolute() and not path.exists(),"receipt/report exists")
    path.parent.mkdir(parents=True,exist_ok=True)
    with path.open("x") as f:
        os.chmod(path,0o600)
        json.dump(obj,f,ensure_ascii=False,indent=2)
        f.write("\n")
        f.flush()
        os.fsync(f.fileno())

def copy_exclusive(src,dst,expected):
    require(sha256(src)==expected,"backup source checksum changed")
    require(not dst.exists() and not dst.is_symlink(),"destination already exists")
    dst.parent.mkdir(parents=True,exist_ok=True)
    fd=os.open(dst,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o644)
    try:
        with os.fdopen(fd,"wb") as out,src.open("rb") as incoming:
            while (data:=incoming.read(4*1024*1024)):
                out.write(data)
            out.flush()
            os.fsync(out.fileno())
    except BaseException:
        if dst.exists() and sha256(dst)==expected:dst.unlink()
        raise
    require(sha256(dst)==expected,"post-copy checksum failed")

def apply(report,backup,stage,origin,backup_status,journal,reviewed_sha):
    require(backup_status.read_text().strip()=="LOCAL_RESTORE_VERIFIED","unverified backup")
    require(sha256(report)==reviewed_sha,"plan checksum mismatch; refuse apply")
    plan=json.loads(report.read_text())
    require(plan.get("contract")=="mad4b.media.verified-alternate-restore.v1" and plan.get("mode")=="staging_only", "wrong plan contract")
    require(len(plan["entries"])>0,"empty plan")
    require(not journal.exists(),"prior journal exists; refuse duplicate apply")
    created=[]
    try:
        for item in plan["entries"]:
            src=inside(backup,item["source_in_backup"],must_exist=True)
            require(sha256(src)==item["sha256"],"backup bytes drifted")
            for root,label in ((stage,"staging"),(origin,"vps-origin")):
                dst=inside(root,item["dest"],must_exist=False)
                copy_exclusive(src,dst,item["sha256"])
                created.append({"root":label,"path":item["dest"],"sha256":item["sha256"],"size":item["size"]})
                # Durable journal after each created destination protects interrupted runs.
                with journal.open("a") as fh:
                    os.chmod(journal,0o600)
                    fh.write(json.dumps(created[-1],ensure_ascii=False)+"\n")
                    fh.flush()
                    os.fsync(fh.fileno())
    except BaseException:
        # Physical files, if any, are recorded in the append-only journal for
        # an explicit reviewed rollback instead of silently erasing evidence.
        raise
    receipt=journal.parent/(journal.stem+".RECEIPT.json")
    write_once(receipt,{
      "contract":"mad4b.media.verified-alternate-restore.receipt.v1",
      "created_utc":datetime.datetime.now(datetime.timezone.utc).isoformat(),
      "plan_sha256":reviewed_sha,
      "status":"STAGING_COPY_RESTORED_VERIFIED",
      "created_file_count":len(created),
      "created":created,
      "attachments_covered":plan["attachment_records_recoverable"],
      "database_changed":False,
      "production_changed":False,
      "backup_unchanged":True
    })
    print(json.dumps({"status":"STAGING_COPY_RESTORED_VERIFIED","new_files":len(created),"attachments":plan["attachment_records_recoverable"],"receipt":str(receipt)}))

def rollback(receipt,stage,origin,expected_sha):
    require(sha256(receipt)==expected_sha, "rollback receipt changed")
    data=json.loads(receipt.read_text())
    require(data.get("contract")=="mad4b.media.verified-alternate-restore.receipt.v1","bad rollback receipt")
    files=data["created"]
    for x in files:
        root={"staging":stage,"vps-origin":origin}[x["root"]]
        f=inside(root,x["path"],must_exist=True)
        require(sha256(f)==x["sha256"],"modified restored file; refuse rollback")
    # Rollback is a second explicit command, never automatic.
    for x in reversed(files):
        root={"staging":stage,"vps-origin":origin}[x["root"]]
        (root/relpath(x["path"])).unlink()
    print(json.dumps({"rolled_back_new_files":len(files),"prior_existing_files_deleted":0}))

def main():
    p=argparse.ArgumentParser()
    p.add_argument("action",choices=["plan","apply","rollback"])
    p.add_argument("--metadata",type=Path)
    p.add_argument("--sizes",type=Path)
    p.add_argument("--dimensions",type=Path)
    p.add_argument("--backup",required=True,type=Path)
    p.add_argument("--staging",required=True,type=Path)
    p.add_argument("--origin",required=True,type=Path)
    p.add_argument("--backup-status",type=Path)
    p.add_argument("--report",required=True,type=Path)
    p.add_argument("--journal",type=Path)
    p.add_argument("--sha",default="")
    args=p.parse_args()
    for v in (args.backup,args.staging,args.origin):
        require(v.is_absolute() and v.is_dir() and not v.is_symlink(),"invalid root")
    if args.action=="plan":
        require(all(x and x.is_file() for x in (args.metadata,args.sizes,args.dimensions)),"missing metadata")
        result=plan(args.metadata,args.sizes,args.dimensions,args.backup,args.staging,args.origin)
        write_once(args.report,result)
        print(json.dumps({"sha256":sha256(args.report),"entries":result["unique_image_paths_recoverable"],"attachment_records":result["attachment_records_recoverable"],"rejected":result["rejected_causes"]}))
    elif args.action=="apply":
        require(args.journal and args.backup_status and re.fullmatch("[a-f0-9]{64}",args.sha or ""),"missing exact authorization context")
        apply(args.report,args.backup,args.staging,args.origin,args.backup_status,args.journal,args.sha)
    else:
        require(re.fullmatch("[a-f0-9]{64}",args.sha or ""),"missing rollback sha")
        rollback(args.report,args.staging,args.origin,args.sha)
if __name__=="__main__":
    main()
