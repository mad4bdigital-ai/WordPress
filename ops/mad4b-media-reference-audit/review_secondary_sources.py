#!/usr/bin/env python3
"""Secondary non-authorizing review for unresolved media, including WPML and generated assets."""
import argparse,collections,datetime,hashlib,json,os
from pathlib import Path
from full_reference_inventory import Matcher,db_lines

def main():
    p=argparse.ArgumentParser()
    p.add_argument("--inventory",required=True)
    p.add_argument("--db-container",required=True)
    p.add_argument("--wp-content",required=True)
    p.add_argument("--out",required=True)
    a=p.parse_args()
    data=[json.loads(l) for l in Path(a.inventory).open(encoding="utf-8")]
    missing={r["id"]:r for r in data if r["classification"]=="unresolved_manual_review"}
    if not missing or len(missing)>10000: raise ValueError("No bounded unresolved sample to review")
    tokens=collections.defaultdict(set)
    for k,r in missing.items():
        path=r["attached_path"]
        if path:
            name=Path(path).name
            if len(name)>=7: tokens[name.casefold()].add(k)
            stem=Path(name).stem
            if len(stem)>=10: tokens[stem.casefold()].add(k)
            if len(path)>=10: tokens[path.casefold()].add(k)
    matcher=Matcher(tokens)
    hit=collections.defaultdict(lambda:collections.Counter())
    scanned=collections.Counter()
    def analyze(source, text):
        scanned[source]+=1
        for token in matcher.find(text.casefold()):
            for i in tokens[token]:
                hit[i][source]+=1
    for source,sql in [
        ("wpml_strings","SELECT HEX(value) FROM wp_icl_strings WHERE value<>''"),
        ("wpml_translations","SELECT HEX(CONCAT_WS(' ',value,mo_string)) FROM wp_icl_string_translations WHERE value IS NOT NULL OR mo_string IS NOT NULL"),
        ("content_hub_objects","SELECT HEX(payload_blob) FROM wp_mad4b_catalog_objects WHERE payload_bytes>0 AND payload_bytes<4000000"),
    ]:
        for parts in db_lines(a.db_container,sql):
            if len(parts)!=1: raise ValueError("Malformed %s value"%source)
            text=bytes.fromhex(parts[0]).decode("utf-8",errors="replace")
            analyze(source,text)
    root=Path(a.wp_content)
    if not root.is_dir(): raise ValueError("Missing wp-content")
    for subtree in ["uploads/elementor/css","uploads/custom-css-js","themes"]:
        path=root/subtree
        if not path.is_dir(): continue
        for parent, dirs, files in os.walk(path,followlinks=False):
            for f in files:
                pth=Path(parent)/f
                if pth.is_symlink() or pth.suffix.lower() not in (".css",".js",".html",".json",".svg"): continue
                if pth.stat().st_size>2000000: continue
                analyze("static_"+subtree.replace("/","_"),pth.read_text(errors="replace"))
    results=[]
    for i,r in missing.items():
        signals=dict(hit.get(i,{}))
        results.append({
            "attachment_id":i,
            "path_exists_in_origin":r["path_exists"],
            "additional_reference_sources":signals,
            "classification":"additional_reference_observed" if signals else "local_references_not_found",
            "files_moved":0,
            "delete_allowed":False,
            "physical_quarantine_approved":False,
            "external_reference_proof":False
        })
    out=Path(a.out)
    if out.exists(): raise ValueError("Report already exists")
    out.parent.mkdir(parents=True,exist_ok=True)
    with out.open("x") as fh:
        os.chmod(out,0o600)
        json.dump({
            "contract":"mad4b.media-secondary-local-review.v1",
            "utc":datetime.datetime.now(datetime.timezone.utc).isoformat(),
            "input_unresolved":len(missing),
            "scanned":dict(scanned),
            "additional_references_found":sum(bool(r["additional_reference_sources"]) for r in results),
            "remaining_unresolved":sum(not r["additional_reference_sources"] for r in results),
            "results":results,
            "mutations":0,
            "physical_quarantine_allowed":False,
            "reason":"external consumers, metadata variants and remote domains not certified",
        },fh,indent=2)
    print(json.dumps({"input":len(missing),"additional_refs":sum(bool(r["additional_reference_sources"]) for r in results),"still_unresolved":sum(not r["additional_reference_sources"] for r in results),"scanned":dict(scanned)}))
if __name__=="__main__":main()
