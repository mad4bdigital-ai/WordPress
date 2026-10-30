#!/usr/bin/env python3
"""Read-only media candidacy audit. NEVER grants publication or tenant ownership."""
import argparse, csv, hashlib, json, mimetypes, os, re
from pathlib import Path

IMG_MIME = {
    "image/jpeg": {".jpg", ".jpeg"},
    "image/png": {".png"},
    "image/webp": {".webp"},
    "image/gif": {".gif"},
    "image/avif": {".avif"},
}
KEYS = ["attachment_id", "parent_id", "attachment_status", "parent_status", "mime", "relative_path", "test_only"]
def inspect_row(row, root):
    ident, parent, status, parent_status, mime, raw_path, test = row
    result = {"attachment_id": int(ident), "parent_id": int(parent), "candidate_only": True, "publish_authorized": False}
    relative = raw_path.strip()
    reasons = []
    if test != "0": reasons.append("test_classification")
    if status != "inherit": reasons.append("attachment_not_inherit")
    if parent_status != "publish": reasons.append("parent_not_published")
    if not relative or relative.startswith("/") or "\\" in relative or "%" in relative or "\x00" in relative:
        reasons.append("unsafe_or_missing_path")
    parts = relative.split("/")
    if not all(p not in (".", "..", "") and not p.startswith(".") and re.fullmatch(r"[^/\\\x00-\x1f]+",p) for p in parts):
        reasons.append("invalid_path_segment")
    if mime not in IMG_MIME or Path(relative).suffix.lower() not in IMG_MIME.get(mime,set()):
        reasons.append("non_matching_image_mime")
    candidate = root / relative
    if not reasons:
        probe = root
        for p in parts:
            probe = probe / p
            if probe.is_symlink():
                reasons.append("symlink_path")
                break
        if not reasons:
            try:
                if not candidate.is_file() or not candidate.resolve().is_relative_to(root.resolve()):
                    reasons.append("file_not_present_within_origin")
                else:
                    result["size"] = candidate.stat().st_size
            except (OSError, ValueError):
                reasons.append("file_unreadable")
    result["relative_path"] = relative
    result["source_mime"] = mime
    result["reason_codes"] = reasons or ["review_required_for_tenant_ownership_and_public_visibility"]
    result["eligible_for_review"] = not bool(reasons)
    return result

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument("--input-tsv",required=True)
    ap.add_argument("--origin-root",required=True)
    ap.add_argument("--output-jsonl",required=True)
    ap.add_argument("--summary",required=True)
    ap.add_argument("--tenant-key",required=True)
    args=ap.parse_args()
    root=Path(args.origin_root)
    if not root.is_dir() or not root.is_absolute(): raise ValueError("origin root must exist")
    output=Path(args.output_jsonl)
    output.parent.mkdir(parents=True,exist_ok=True)
    stats={"contract":"mad4b.media-candidates.audit.v1","tenant_key":args.tenant_key,"status":"REVIEW_ONLY_NO_PUBLIC_AUTHORITY","attachments":0,"eligible_for_manual_review":0,"ineligible":0,"reasons":{},"public_approved_count":0}
    with open(args.input_tsv,newline="",encoding="utf-8") as source, open(output,"w",encoding="utf-8") as dest:
        for fields in csv.reader(source,delimiter="\t"):
            if len(fields)!=7: raise ValueError("unexpected TSV field count")
            item=inspect_row(fields,root)
            stats["attachments"]+=1
            if item["eligible_for_review"]: stats["eligible_for_manual_review"]+=1
            else: stats["ineligible"]+=1
            for reason in item["reason_codes"]:
                stats["reasons"][reason]=stats["reasons"].get(reason,0)+1
            dest.write(json.dumps(item,ensure_ascii=False,separators=(",",":"))+"\n")
    stats["source_input_sha256"]=hashlib.file_digest(open(args.input_tsv,"rb"),"sha256").hexdigest()
    stats["candidate_jsonl_sha256"]=hashlib.file_digest(open(output,"rb"),"sha256").hexdigest()
    Path(args.summary).write_text(json.dumps(stats,indent=2,ensure_ascii=False)+"\n")
    print(json.dumps(stats,ensure_ascii=False))

if __name__=="__main__":
    main()
