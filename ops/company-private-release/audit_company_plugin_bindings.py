#!/usr/bin/env python3
"""Read-only company plugin / theme / MU bindings inventory.

Never emits literal email addresses, GTM/GA IDs, OAuth/API tokens, or line contents.
Does not contact WordPress/DB or mutate any site. All matches are *signals* requiring
manual interpretation, not proof that a configuration is live/active.
"""
import argparse
from collections import Counter, defaultdict
import json
from pathlib import Path
import re
import sys

PATTERNS = {
    "hardcoded_email_literal": re.compile(r"\b[A-Za-z0-9._%+-]{2,64}@[A-Za-z0-9.-]{2,120}\.[A-Za-z]{2,24}\b"),
    "hardcoded_gtm_id": re.compile(r"\bGTM-[A-Z0-9]{4,}\b"),
    "hardcoded_ga4_id": re.compile(r"\bG-[A-Z0-9]{7,}\b"),
    "hardcoded_ads_id": re.compile(r"\bAW-\d{7,}\b"),
    "facebook_pixel_init": re.compile(r"\bfbq\s*\(\s*['\"]init['\"]\s*,\s*['\"]?\d{8,}"),
    "cross_company_domain": re.compile(r"\b(?:allroyalegypt\.com|egypttourgates\.com|tours\.dev\.mad4b\.com)\b", re.I),
    "explicit_tracking_setting": re.compile(r"\b(?:gtm_id|gtm4wp|google_tag_manager|google_analytics_id|measurement_id|facebook_pixel_id|meta_pixel_id|dataLayer)\b", re.I),
    "email_delivery_binding": re.compile(r"\b(?:smtp_host|smtp_user|smtp_password|mail_from|from_email|recipient_email|admin_email|notification_email|wp_mail)\b", re.I),
    "service_secret_reference": re.compile(r"\b(?:client_secret|access_token|refresh_token|webhook_secret|api_secret|api_key|private_key)\b", re.I),
    "webhook_endpoint_binding": re.compile(r"\b(?:webhook_url|callback_url|endpoint_url|webhook_endpoint)\b", re.I),
}
EXTS={".php",".js",".css",".html",".htm",".json",".twig",".inc",".phtml"}
SKIP_DIR={"node_modules","vendor","tests","test","docs","documentation","cache","logs","backups","uploads",
          ".git",".github","languages","translations","fonts","images","assets","build-test"}
MAX_FILE=4*1024*1024
MAX_PER_CATEGORY=150

def location_kind(base, path):
    parts=path.relative_to(base).parts
    if len(parts)>=3 and parts[:2]==("site","wp-content"):
        if len(parts)>=5 and parts[2] in ("plugins","themes"):
            return parts[2]+"/"+parts[3]
        if len(parts)>=4 and parts[2]=="mu-plugins":
            return "mu/"+(parts[3] if len(parts)>4 else Path(parts[3]).stem)
    if len(parts)>=4 and parts[:2]==("pending-review","mu-files"):
        return "review-mu/"+(parts[3] if len(parts)>4 else parts[-1])
    return "other"

def candidates(root):
    dirs=[root/"site/wp-content/plugins",root/"site/wp-content/mu-plugins",
          root/"site/wp-content/themes",root/"pending-review/mu-files"]
    for source in dirs:
        if not source.exists():continue
        for path in source.rglob("*"):
            if not path.is_file() or path.is_symlink() or path.suffix.lower() not in EXTS:continue
            relative=path.relative_to(source)
            if any(part.startswith(".") or part in SKIP_DIR for part in relative.parts[:-1]):continue
            if path.stat().st_size>MAX_FILE:continue
            yield path

def scan(root):
    counters=Counter()
    by_component=defaultdict(Counter)
    examples=defaultdict(list)
    files=0
    scanned_components=set()
    for path in candidates(root):
        files+=1
        component=location_kind(root,path)
        scanned_components.add(component)
        try:
            text=path.read_text(encoding="utf-8",errors="replace")
        except OSError:
            continue
        for category,regex in PATTERNS.items():
            found=list(regex.finditer(text))
            if not found:continue
            counters[category]+=len(found)
            by_component[component][category]+=len(found)
            if len(examples[category])<MAX_PER_CATEGORY:
                for match in found[:3]:
                    if len(examples[category])>=MAX_PER_CATEGORY:break
                    line=text.count("\n",0,match.start())+1
                    examples[category].append({"source":str(path.relative_to(root)), "line":line, "component":component})
    return {
        "contract":"mad4b.company-plugin-tenant-binding-inventory.v1",
        "read_only":True,
        "raw_values_in_report":False,
        "all_findings_require_review":True,
        "files_scanned":files,
        "components_scanned":len(scanned_components),
        "totals":dict(sorted(counters.items())),
        "components":{k:dict(v) for k,v in sorted(by_component.items())},
        "examples":dict(examples),
        "limitations":["String matches are not evidence of active plugin behavior","Vendor examples and third-party static code can cause false positives","Configuration may live only in options/tables, generated web pages or GTM container",
                       "No password, email, token or measurement ID value is included in this report"]
    }

def main():
    p=argparse.ArgumentParser()
    p.add_argument("--candidate",type=Path,required=True)
    p.add_argument("--output",type=Path,required=True)
    args=p.parse_args()
    if not args.candidate.is_dir() or args.candidate.is_symlink():
        raise ValueError("Candidate root missing")
    if args.output.exists():raise ValueError("Refuse overwrite")
    result=scan(args.candidate)
    args.output.parent.mkdir(parents=True,exist_ok=True)
    import os
    fd=os.open(args.output,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
    with os.fdopen(fd,"w") as f:
        json.dump(result,f,ensure_ascii=False,indent=2)
        f.write("\n")
    print(json.dumps({"files_scanned":result["files_scanned"],"components_scanned":result["components_scanned"],
                      "totals":result["totals"]},ensure_ascii=False))
if __name__=="__main__":
    sys.exit(main())
