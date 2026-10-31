#!/usr/bin/env python3
"""Merge current open PR heads on an isolated integration branch; never auto-resolve conflicts."""
import json
import os
import subprocess
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
os.chdir(ROOT)
url = "https://api.github.com/repos/mad4bdigital-ai/WordPress/pulls?state=open&per_page=100"
request = urllib.request.Request(url, headers={
    "Accept": "application/vnd.github+json",
    "User-Agent": "MAD4B-governed-integration",
    "Authorization": "Bearer " + os.environ["GITHUB_TOKEN"],
})
with urllib.request.urlopen(request, timeout=25) as response:
    items = json.load(response)
if not items or len(items) >= 100:
    raise SystemExit(f"Fail-closed: empty or possibly truncated open PR list ({len(items)})")
prs = [dict(number=p["number"], head=p["head"]["ref"], head_sha=p["head"]["sha"],
            base=p["base"]["ref"], draft=p["draft"], title=p["title"]) for p in items]
by_head = {p["head"]: p for p in prs}
pending = {p["number"]: p for p in prs}
ordered = []
while pending:
    ready = [p for p in pending.values()
             if p["base"] not in by_head or by_head[p["base"]]["number"] not in pending]
    if not ready:
        ready = [min(pending.values(), key=lambda p: p["number"])]
    for p in sorted(ready, key=lambda p: (p["draft"], p["number"])):
        ordered.append(p)
        del pending[p["number"]]
subprocess.run(["git", "config", "user.name", "MAD4B Integration CI"], check=True)
subprocess.run(["git", "config", "user.email", "integration-ci@users.noreply.github.com"], check=True)
subprocess.run(["git", "fetch", "-q", "origin", "+refs/pull/*/head:refs/remotes/origin/pr/*"], check=True)
def resolve_exact_pr675_conflict(item_number, conflicts):
    """Reviewed one-file union of brand/style localization codes; fail closed otherwise."""
    if item_number != 675 or conflicts != [
        "wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-readiness-alerts.php"
    ]:
        return False
    path = Path(conflicts[0])
    source = path.read_text(encoding="utf-8")
    left = "                $localization=preg_match('/^loc_(?:layout|text|missing|h1|brand)_(?:ar|de|en|es|fr|it)$/D',$code) &&"
    right = "                $localization=preg_match('/^loc_(?:layout|text|missing|h1|style)_(?:ar|de|en|es|fr|it)$/D',$code) &&"
    combined = "                $localization=preg_match('/^loc_(?:layout|text|missing|h1|brand|style)_(?:ar|de|en|es|fr|it)$/D',$code) &&"
    expected = chr(10).join(["<<<<<<< HEAD", left, "=======", right, ">>>>>>> refs/remotes/origin/pr/675"])
    # Git's conflict label may use origin/pr/675 rather than the full ref.
    alternatives = [
        expected,
        expected.replace("refs/remotes/origin/pr/675", "origin/pr/675"),
    ]
    matches = [v for v in alternatives if source.count(v) == 1]
    if len(matches) != 1 or source.count("<<<<<<<") != 1:
        return False
    path.write_text(source.replace(matches[0], combined), encoding="utf-8")
    subprocess.run(["git", "add", str(path)], check=True)
    return True

out = []
for item in ordered:
    ref = "refs/remotes/origin/pr/" + str(item["number"])
    actual = subprocess.check_output(["git", "rev-parse", ref], text=True).strip()
    if actual != item["head_sha"]:
        raise SystemExit("PR moved during integration: #" + str(item["number"]))
    if subprocess.run(["git", "merge-base", "--is-ancestor", ref, "HEAD"]).returncode == 0:
        status, conflicts = "already_included", []
        diagnostic = ""
    elif subprocess.run(["git", "merge-base", "HEAD", ref], capture_output=True).returncode != 0:
        status, conflicts = "unrelated_history", []
        diagnostic = "No common Git ancestor; manual source-intake review required"
    else:
        result = subprocess.run(["git", "merge", "--no-commit", "--no-ff", "--no-edit", ref],
                                text=True, capture_output=True, timeout=100)
        if result.returncode != 0:
            conflicts = subprocess.run(["git", "diff", "--name-only", "--diff-filter=U"],
                                       capture_output=True, text=True).stdout.splitlines()
            if resolve_exact_pr675_conflict(item["number"], conflicts):
                subprocess.run(["git", "diff", "--cached", "--check"], check=True)
                subprocess.run(["git", "commit", "-m",
                                "integration: reconcile PR675 brand/style localization alerts"],
                               check=True, capture_output=True, text=True, timeout=60)
                status, conflicts, diagnostic = "merged", [], ""
            else:
                subprocess.run(["git", "merge", "--abort"], check=True)
                diagnostic = (result.stderr + "\\n" + result.stdout)[-1200:]
                status = ("unrelated_history" if "refusing to merge unrelated histories" in diagnostic
                          else "conflict")
        else:
            subprocess.run(["git", "commit", "-m", f"integration: include open PR #{item['number']}"], check=True,
                           capture_output=True, text=True, timeout=60)
            status, conflicts = "merged", []
            diagnostic = ""
    out.append(dict(number=item["number"], status=status, conflicts=conflicts, diagnostic=diagnostic if status in ("conflict", "unrelated_history") else "",
                    base=item["base"], head_sha=item["head_sha"], draft=item["draft"]))
# Reconcile transient first-pass conflicts against the final merged HEAD.
# A later dependency merge may include an earlier conflicting PR verbatim.
for row in out:
    if row["status"] not in ("conflict", "unrelated_history"):
        continue
    ref = "refs/remotes/origin/pr/" + str(row["number"])
    if subprocess.run(["git", "merge-base", "--is-ancestor", ref, "HEAD"],
                      capture_output=True).returncode == 0:
        row["status"] = "included_by_later_merge"
        row["conflicts"] = []
        row["diagnostic"] = "Exact PR commit is now an ancestor of final integration HEAD"
Path("governance/integration").mkdir(parents=True, exist_ok=True)
report = {"contract": "mad4b.open-prs-consolidation.v1", "production_mutation": False,
          "all_prs_attempted": len(prs), "merged": sum(p["status"] == "merged" for p in out),
          "already_included": sum(p["status"] in ("already_included", "included_by_later_merge") for p in out),
          "blocked_conflicts": sum(p["status"] == "conflict" for p in out),
          "unrelated_histories": sum(p["status"] == "unrelated_history" for p in out),
          "merge_ready": not any(p["status"] in ("conflict", "unrelated_history") for p in out),
          "results": out}
Path("governance/integration/open-prs-20261031.json").write_text(json.dumps(report, indent=2) + "\n")
subprocess.run(["git", "add", "governance/integration/open-prs-20261031.json"], check=True)
subprocess.run(["git", "commit", "-m", "governance: record exact open PR merge attempts and unresolved conflicts"], check=True)
# Never publish a partial result as a fully consolidated branch.
if not report["merge_ready"]:
    raise SystemExit("CONSOLIDATION_BLOCKED: " + str(report["blocked_conflicts"]) +
                     " merge conflicts and " + str(report["unrelated_histories"]) +
                     " unrelated-history PRs; report retained as CI artifact")
subprocess.run(["git", "push", "origin", "HEAD:refs/heads/integration/all-open-prs-20261031"], check=True)
print(json.dumps({key: report[key] for key in ("all_prs_attempted","merged","already_included","blocked_conflicts","merge_ready")}))
