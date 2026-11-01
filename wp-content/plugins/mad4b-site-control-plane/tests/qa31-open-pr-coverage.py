#!/usr/bin/env python3
"""QA31 non-authorizing inventory of currently open PRs, source overlap, and test evidence.
GitHub read-only API; report is diagnostic, NOT a merge, test-success, or review approval.
"""
import argparse
import json
import os
from concurrent.futures import ThreadPoolExecutor, as_completed
from urllib.request import Request, urlopen
from urllib.parse import quote

def get(url, token):
    req = Request(url, headers={"Accept": "application/vnd.github+json", "Authorization": "Bearer " + token, "X-GitHub-Api-Version":"2022-11-28"})
    with urlopen(req, timeout=25) as response:
        return json.load(response)

def pages(url, token):
    result = []
    for n in range(1, 21):
        sep = "&" if "?" in url else "?"
        chunk = get(url + sep + "per_page=100&page=" + str(n), token)
        if not isinstance(chunk, list):
            raise ValueError("unexpected API response")
        result.extend(chunk)
        if len(chunk) < 100:
            break
    else:
        raise ValueError("pagination limit reached")
    return result

def classify(prs, file_map, baseline, qa31):
    records = []
    all_changed = set()
    for pr in prs:
        number = int(pr["number"])
        if number == qa31:
            continue
        files = [f["filename"] if isinstance(f, dict) else f for f in file_map[number]]
        if len(files) != len(set(files)):
            raise ValueError("duplicate changed path PR " + str(number))
        overlap = sorted(set(files) & baseline)
        test_files = sorted(x for x in files if "/tests/" in x or x.startswith("tests/") or x.endswith("_test.py") or x.endswith("test_resolver.py"))
        workflow = sorted(x for x in files if x.startswith(".github/workflows/"))
        source = sorted(x for x in files if x.endswith((".php", ".py", ".mjs", ".js")) and x not in test_files and not x.startswith(".github/"))
        records.append({
            "number": number, "title": pr["title"], "head_sha": pr["head"]["sha"],
            "base_ref": pr["base"]["ref"], "draft": bool(pr["draft"]),
            "changed_files": len(files), "overlap_with_qa31_paths": overlap,
            "tests_changed": test_files, "workflows_changed": workflow,
            "source_changed": source,
            "coverage_status": "TEST_FILES_PRESENT_REVIEW_REQUIRED" if test_files else ("NO_TEST_FILES_CHANGED_REVIEW_REQUIRED" if source else "NON_RUNTIME_REVIEW_REQUIRED"),
            "integration_status": "NOT_MERGED_NOT_COVERED_BY_QA31",
        })
        all_changed.update(files)
    records.sort(key=lambda x: x["number"], reverse=True)
    return {"contract":"mad4b.qa31-open-pr-coverage.v1", "authorizing": False,
            "qa31_pr":qa31, "open_peer_pr_count":len(records),
            "all_open_peer_paths":len(all_changed), "prs":records,
            "meaning":"File/test inventory only; presence of a test does not prove execution or passing.",
            "promotion_authorized":False}

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--repo", default=os.getenv("GITHUB_REPOSITORY", "mad4bdigital-ai/WordPress"))
    parser.add_argument("--qa31", type=int, default=658)
    parser.add_argument("--out", default="qa31-open-pr-coverage.json")
    parser.add_argument("--fixture", help="offline deterministic test input")
    args=parser.parse_args()
    if args.fixture:
        with open(args.fixture,encoding="utf8") as f:
            fixture=json.load(f)
        report=classify(fixture["prs"], {int(k):v for k,v in fixture["files"].items()}, set(fixture["qa31_files"]), args.qa31)
    else:
        token=os.getenv("GITHUB_TOKEN","")
        if not token:
            raise SystemExit("GITHUB_TOKEN missing; do not claim coverage")
        base="https://api.github.com/repos/"+quote(args.repo, safe="/")
        prs=pages(base+"/pulls?state=open",token)
        changed=pages(base+"/pulls/"+str(args.qa31)+"/files",token)
        file_map={}
        peer_numbers=[int(pr["number"]) for pr in prs if int(pr["number"])!=args.qa31]
        # Bounded parallel reads prevent dozens of sequential API round-trips
        # while keeping the GitHub token and repository strictly read-only.
        with ThreadPoolExecutor(max_workers=6) as pool:
            futures={pool.submit(pages,base+"/pulls/"+str(number)+"/files",token):number
                     for number in peer_numbers}
            for future in as_completed(futures):
                file_map[futures[future]]=future.result()
        report=classify(prs,file_map,{x["filename"] for x in changed},args.qa31)
    with open(args.out,"w",encoding="utf8") as f:
        json.dump(report,f,indent=2,ensure_ascii=False)
        f.write("\n")
    print("QA31_PR_COVERAGE_INVENTORY: "+str(report["open_peer_pr_count"])+" open PRs; diagnostic only")
if __name__=="__main__":
    main()
