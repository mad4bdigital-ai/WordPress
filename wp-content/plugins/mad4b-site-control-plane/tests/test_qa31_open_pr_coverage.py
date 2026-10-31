#!/usr/bin/env python3
"""Hermetic QA31 peer-PR coverage checks. No GitHub requests or mutations."""
import importlib.util
import pathlib
import tempfile
import json

source = pathlib.Path(__file__).with_name("qa31-open-pr-coverage.py")
spec = importlib.util.spec_from_file_location("qa31_open_pr_coverage", source)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
prs = [
 {"number":658,"title":"QA31","head":{"sha":"head658"},"base":{"ref":"master"},"draft":True},
 {"number":670,"title":"Resolver","head":{"sha":"head670"},"base":{"ref":"master"},"draft":False},
 {"number":669,"title":"Rollout","head":{"sha":"head669"},"base":{"ref":"master"},"draft":True},
]
files = {
 670:[{"filename":"tools/resolver.py"},{"filename":"tools/test_resolver.py"},{"filename":".github/workflows/resolver.yml"}],
 669:[{"filename":"wp-content/plugins/mad4b-site-control-plane/includes/shared.php"},{"filename":"docs/rollout.md"}],
}
report = module.classify(prs, files, {"wp-content/plugins/mad4b-site-control-plane/includes/shared.php"}, 658)
assert report["authorizing"] is False and report["promotion_authorized"] is False
assert report["open_peer_pr_count"] == 2 and report["all_open_peer_paths"] == 5
assert [r["number"] for r in report["prs"]] == [670,669]
assert report["prs"][0]["coverage_status"] == "TEST_FILES_PRESENT_REVIEW_REQUIRED"
assert report["prs"][1]["coverage_status"] == "NO_TEST_FILES_CHANGED_REVIEW_REQUIRED"
assert report["prs"][1]["overlap_with_qa31_paths"] == ["wp-content/plugins/mad4b-site-control-plane/includes/shared.php"]
assert all(r["integration_status"] == "NOT_MERGED_NOT_COVERED_BY_QA31" for r in report["prs"])
try:
 module.classify(prs, {**files,670:files[670]+[files[670][0]]}, set(), 658)
except ValueError:
 pass
else:
 raise AssertionError("duplicate path accepted")
with tempfile.TemporaryDirectory() as directory:
 fixture = pathlib.Path(directory)/"fixture.json"
 output = pathlib.Path(directory)/"output.json"
 fixture.write_text(json.dumps({"prs":prs,"files":{str(k):v for k,v in files.items()},"qa31_files":["wp-content/plugins/mad4b-site-control-plane/includes/shared.php"]}),encoding="utf-8")
 import subprocess
 import sys
 subprocess.run([sys.executable,str(source),"--fixture",str(fixture),"--out",str(output)],check=True)
 result=json.loads(output.read_text(encoding="utf8"))
 assert result["prs"] == report["prs"]
print("QA31_OPEN_PR_COVERAGE_CONTRACT: PASS")
