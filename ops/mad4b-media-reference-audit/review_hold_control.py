#!/usr/bin/env python3
"""Review-only quarantine registry controls. No media operations and no publication grants."""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path

def verify(data):
    if data.get("contract")!="mad4b.media-reversible-administrative-quarantine.v1":
        raise ValueError("Unrecognized registry")
    if data.get("files_moved")!=0 or data.get("files_deleted")!=0:
        raise ValueError("Registry is not a zero-file-action contract")
    ids=[i["attachment_id"] for i in data["records"]]
    if len(ids)!=len(set(ids)):raise ValueError("duplicate attachment id")
    return data

def status(path):
    data=verify(json.loads(path.read_text()))
    active=[r for r in data["records"] if r["administrative_quarantine"]=="review_hold"]
    return {"records":len(data["records"]),"active_review_holds":len(active),
            "released_holds":len(data["records"])-len(active),"media_files_changed":0}

def release(path, item_id, reason):
    if len(reason.strip())<18 or len(reason)>500:
        raise ValueError("Review reason must be 18-500 chars")
    data=verify(json.loads(path.read_text()))
    record=next((r for r in data["records"] if r["attachment_id"]==item_id),None)
    if record is None:raise ValueError("unknown attachment id")
    if record["administrative_quarantine"]!="review_hold":raise ValueError("already released")
    record["administrative_quarantine"]="released_for_manual_followup"
    record["review_note"]=reason.strip()
    record["released_at"]=datetime.datetime.now(datetime.timezone.utc).isoformat()
    record["file_action"]="NONE"
    record["approval_for_deletion"]=False
    record["approval_for_physical_move"]=False
    record["clearance_to_publish_via_gateway"]=False
    # Atomic, auditable registry state only; historical file bytes unchanged.
    payload=(json.dumps(data,ensure_ascii=False,indent=2)+"\n").encode()
    tmp=path.parent/(path.name+".tmp")
    with tmp.open("xb") as f:
        os.chmod(tmp,0o600)
        f.write(payload)
        f.flush()
        os.fsync(f.fileno())
    os.replace(tmp,path)
    return {"attachment_id":item_id,"state":"released_for_manual_followup",
            "file_change":False,"publication_approved":False}

def main():
    p=argparse.ArgumentParser()
    p.add_argument("--registry",required=True)
    p.add_argument("--action",required=True,choices=["status","release"])
    p.add_argument("--attachment-id",type=int)
    p.add_argument("--reason")
    args=p.parse_args()
    path=Path(args.registry)
    if not path.is_absolute() or path.is_symlink() or not path.is_file():
        raise ValueError("Invalid registry path")
    if args.action=="status": result=status(path)
    else:
        if not args.attachment_id or not args.reason: raise ValueError("missing explicit review fields")
        result=release(path,args.attachment_id,args.reason)
    print(json.dumps(result))
if __name__=="__main__":main()
