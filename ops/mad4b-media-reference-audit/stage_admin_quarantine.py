#!/usr/bin/env python3
"""Stage a reversible, non-disruptive media quarantine REVIEW registry.

Never moves, deletes, disables, changes permissions or rewrites WordPress media.
"""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path

HIGH_RISK_TAGS = {
    "company_brand": ("company-brands/","branded_","logo","favicon","wayfa/"),
    "checkout": ("paypal","visa","master-card","stripe","amex","apple-pay"),
    "site_ui": ("app-store","google-play","download_","banner-shape","woocommerce-placeholder","landing-app"),
}

def digest(path):
    h=hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda:f.read(1024*1024), b""):
            h.update(chunk)
    return h.hexdigest()

def prepare(inventory_path, secondary_path, root):
    ids={r["id"]:r for r in map(json.loads, inventory_path.open(encoding="utf-8"))}
    secondary=json.loads(secondary_path.read_text())
    source_sha=digest(inventory_path)
    rows=[]
    for item in secondary["results"]:
        if item["classification"]!="local_references_not_found":continue
        id=item["attachment_id"]; base=ids[id]; rel=base["attached_path"]
        if base["classification"]!="unresolved_manual_review": raise ValueError("secondary mismatch")
        target=root/rel
        path_safe=bool(rel) and not target.is_symlink() and target.resolve().is_relative_to(root.resolve())
        present=path_safe and target.is_file()
        original_sha=digest(target) if present else None
        hints=[k for k,tokens in HIGH_RISK_TAGS.items() if any(x in rel.casefold() for x in tokens)]
        rows.append({
            "attachment_id":id,
            "attached_file":rel,
            "parent_state":base["parent_state"],
            "origin_file_exists":bool(present),
            "sha256":original_sha,
            "bytes":target.stat().st_size if present else None,
            "risk_hints":hints,
            "administrative_quarantine":"review_hold",
            "file_action":"NONE",
            "approval_for_deletion":False,
            "approval_for_physical_move":False,
            "external_consumer_check":"NOT_CERTIFIED",
            "clearance_to_publish_via_gateway":False,
            "restore_action":"clear_registry_hold_only_no_file_rollback_needed"
        })
    if len(rows)==0: raise ValueError("Nothing to stage")
    rows.sort(key=lambda x:x["attachment_id"])
    now=datetime.datetime.now(datetime.timezone.utc).isoformat()
    return {
        "contract":"mad4b.media-reversible-administrative-quarantine.v1",
        "created_utc":now,
        "tenant":"etg-commerce-staging",
        "source_inventory_sha256":source_sha,
        "record_count":len(rows),
        "files_present":sum(x["origin_file_exists"] for x in rows),
        "missing_files":sum(not x["origin_file_exists"] for x in rows),
        "files_moved":0,
        "files_deleted":0,
        "read_only_filesystem":True,
        "status":"ADMINISTRATIVE_REVIEW_HOLD_ONLY",
        "all_entries_require_manual_review":True,
        "records":rows,
        "reversible_by":"Removing administrative review hold after authorized review. Media never moved.",
    }

def main():
    p=argparse.ArgumentParser()
    p.add_argument("--inventory",required=True)
    p.add_argument("--secondary",required=True)
    p.add_argument("--origin-root",required=True)
    p.add_argument("--output",required=True)
    args=p.parse_args()
    root=Path(args.origin_root)
    if not root.is_absolute() or not root.is_dir(): raise ValueError("missing root")
    out=Path(args.output)
    if not out.is_absolute() or out.exists() or out.is_symlink(): raise ValueError("output must be new absolute file")
    out.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
    result=prepare(Path(args.inventory),Path(args.secondary),root)
    with out.open("x") as f:
        os.chmod(out,0o600)
        json.dump(result,f,ensure_ascii=False,indent=2)
        f.write("\n")
    print(json.dumps({k:result[k] for k in ("contract","record_count","files_present","missing_files","files_moved","files_deleted","status")}))
if __name__=="__main__":main()
