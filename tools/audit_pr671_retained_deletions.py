#!/usr/bin/env python3
"""Read-only semantic triage of deleted PR files retained by local integration."""
import argparse,json,subprocess
from collections import Counter
from pathlib import Path

def git(*args,allow_fail=False):
    p=subprocess.run(["git",*args],text=True,capture_output=True)
    if p.returncode and not allow_fail:raise RuntimeError(p.stderr)
    return p.stdout.strip() if p.returncode==0 else None

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("--base",default="24aff1af0ba")
    parser.add_argument("--pr",default="origin/pr/671")
    parser.add_argument("--local",default="HEAD")
    parser.add_argument("--out",required=True)
    args=parser.parse_args()
    rows=[]
    for line in git("diff","--name-status","--no-renames",args.base,args.pr).splitlines():
        code,path=line.split("\t",1)
        if code!="D":continue
        local_blob=git("rev-parse",args.local+":"+path,allow_fail=True)
        if not local_blob:continue
        baseline_blob=git("rev-parse",args.base+":"+path)
        changed=local_blob!=baseline_blob
        area=path.split("/")[0]
        is_workflow=path.startswith(".github/workflows/")
        rows.append({"path":path,"baseline_blob":baseline_blob,"retained_local_blob":local_blob,"modified_since_baseline":changed,"area":area,"workflow_permission_required":is_workflow,"review":"PENDING_SEMANTIC_DELETE_OR_RETAIN"})
    result={"contract":"mad4b.pr671.retained-delete-triage.v1","base":git("rev-parse",args.base),"pr":git("rev-parse",args.pr),"candidate":git("rev-parse",args.local),"retained_deleted_paths":len(rows),"unchanged_retained":sum(not x["modified_since_baseline"] for x in rows),"changed_retained":sum(x["modified_since_baseline"] for x in rows),"retained_workflows":sum(x["workflow_permission_required"] for x in rows),"by_area":dict(Counter(x["area"] for x in rows)),"decision":"REVIEW_REQUIRED_NO_AUTOMATIC_DELETES","items":rows}
    Path(args.out).write_text(json.dumps(result,indent=2)+"\n")
    print("RETAINED_PR_DELETIONS",len(rows),"UNCHANGED",result["unchanged_retained"],"CHANGED",result["changed_retained"],"WORKFLOWS",result["retained_workflows"])
    assert len(rows)==len({r["path"] for r in rows}),"duplicate entries"
if __name__=="__main__":main()
