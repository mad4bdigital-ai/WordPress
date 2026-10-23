#!/usr/bin/env python3
"""GitHub-authorized, owner-triggered Staging-only release publisher.

Must run from trusted master workflow_dispatch, never from PR source. Does not
merge PRs, install plugins, update master release pointers or enable Production.
"""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import zipfile

REPO = "mad4bdigital-ai/WordPress"
TAG = "mad4b-site-control-plane-update-channel"
WORKFLOW = ".github/workflows/mad4b-staging-ci-candidate-publish.yml"
CONFIRM = "PUBLISH EXACT STAGING CI CANDIDATE"
REQUIRED = {
    "MAD4B Control Plane Package", "MAD4B Plugin Package",
    "Feature 007 Spec Quality CI", "Feature 007 Pre-Staging Hybrid Audit",
    "MAD4B Dynamic Skills", "MAD4B ETG ARE Staging External HTTP Readiness",
    "MAD4B Local Transport OAuth Isolation", "MAD4B Full Staging Authority Contract",
    "MAD4B Operator Asymmetric Attestation",
}

def check(ok, error):
    if not ok:
        raise ValueError("BLOCKED " + error)

def sha(data):
    return hashlib.sha256(data).hexdigest()

def gh(*args):
    p = subprocess.run(["gh", *args], capture_output=True, text=True, timeout=120)
    check(p.returncode == 0, "GitHub command failed: " + " ".join(args[:4]))
    return p.stdout

def api(path):
    return json.loads(gh("api", "-H", "Accept: application/vnd.github+json", path))

def validate_event(env, args):
    check(env.get("GITHUB_REPOSITORY") == REPO, "repository mismatch")
    check(env.get("GITHUB_REF") == "refs/heads/master", "master dispatcher required")
    check(env.get("GITHUB_EVENT_NAME") == "workflow_dispatch", "manual dispatch required")
    check(env.get("GITHUB_ACTOR") == "mad4bdigital-ai", "repository owner actor required")
    check(env.get("GITHUB_WORKFLOW_REF") == REPO + "/" + WORKFLOW +
          "@refs/heads/master", "untrusted workflow ref")
    check(args.get("confirmation") == CONFIRM, "typed confirmation mismatch")
    head = str(args.get("source_sha", "")).lower()
    package = str(args.get("archive_sha256", "")).lower()
    pr = str(args.get("pull_request", ""))
    check(bool(re.fullmatch("[a-f0-9]{40}", head)), "exact source SHA invalid")
    check(bool(re.fullmatch("[a-f0-9]{64}", package)), "ZIP SHA invalid")
    check(bool(re.fullmatch("[0-9]{1,6}", pr)) and int(pr) > 0, "PR invalid")
    check(head != env.get("GITHUB_SHA"), "not a PR candidate")
    return int(pr), head, package

def verify_pr(item, pr, sha_value):
    check(item.get("number") == pr and item.get("state") == "open", "PR not open")
    branch = item.get("head") or {}
    check(branch.get("sha") == sha_value, "PR moved")
    check((branch.get("repo") or {}).get("full_name") == REPO, "fork not permitted")
    ref = branch.get("ref")
    check(isinstance(ref, str) and len(ref) > 0, "PR head branch missing")
    return ref

def verify_ci(runs, pr, head, branch):
    selected = [x for x in runs if x.get("head_sha") == head and
                x.get("head_branch") == branch and x.get("event") == "pull_request"
                and any(p.get("number") == pr for p in x.get("pull_requests") or [])]
    check(len(selected) >= 60, "less than 60 exact-source CI runs")
    check(all(x.get("status") == "completed" and x.get("conclusion") == "success"
              for x in selected), "CI incomplete or failed")
    checks = {}
    for item in selected:
        check(isinstance(item.get("id"), int) and item["id"] > 0, "run ID invalid")
        name = item.get("name")
        check(isinstance(name, str) and name not in checks, "duplicate workflow name")
        checks[name] = item
    check(REQUIRED.issubset(checks), "critical required checks missing")
    canonical = checks["MAD4B Control Plane Package"]
    check(canonical.get("path") == ".github/workflows/mad4b-control-plane-package.yml",
          "package builder identity invalid")
    return canonical["id"]

def list_ci(head):
    result = []
    for page in range(1, 7):
        data = api("repos/" + REPO + "/actions/runs?head_sha=" + head +
                   "&per_page=100&page=" + str(page))
        items = data.get("workflow_runs") or []
        check(isinstance(items, list), "CI query invalid")
        result.extend(items)
        if len(items) < 100:
            break
    return result

def validate_zip(archive, receipt_path, expected_head, expected_digest):
    check(archive.is_file() and receipt_path.is_file(), "missing CI package files")
    check(not archive.is_symlink() and not receipt_path.is_symlink(), "symlink package input")
    b = archive.read_bytes()
    check(0 < len(b) <= 16*1024*1024 and sha(b) == expected_digest, "ZIP hash changed")
    receipt = json.loads(receipt_path.read_text())
    check(receipt.get("contract") == "mad4b.deterministic-control-plane-package.v1",
          "receipt contract incorrect")
    check(receipt.get("source_commit_sha") == expected_head and
          receipt.get("archive_sha256") == expected_digest and
          receipt.get("archive_bytes") == len(b) and
          receipt.get("archive_compression") == "stored", "receipt mismatch")
    with zipfile.ZipFile(archive) as z:
        files = z.infolist()
        check(z.testzip() is None and len(files) == receipt["archive_file_count"], "ZIP damaged")
        check(len({x.filename.casefold() for x in files}) == len(files), "duplicate ZIP paths")
        for item in files:
            check(item.filename.startswith("mad4b-site-control-plane/") and
                  ".." not in Path(item.filename).parts and
                  "\\" not in item.filename and not item.filename.endswith("/") and
                  ((item.external_attr >> 16) & 0o170000) == 0o100000 and
                  item.compress_type == zipfile.ZIP_STORED, "unsafe ZIP entry")
        proof = json.loads(z.read("mad4b-site-control-plane/MAD4B-BUILD-PROVENANCE.json"))
        check(proof.get("source_commit_sha") == expected_head, "embedded SHA mismatch")
        for key in ("build_fingerprint", "package_manifest_digest"):
            check(proof.get(key) == receipt.get(key), "embedded provenance mismatch")
        rows = proof["package_files"]
        check(len(rows) == len(files)-1, "package file count mismatch")
        for row in rows:
            data = z.read("mad4b-site-control-plane/" + row["path"])
            check(sha(data) == row["sha256"] and len(data) == row["bytes"], "file hash mismatch")
    return receipt

def build_manifest(receipt, head, run_id):
    check(run_id > 0, "trusted publisher run missing")
    return dict(
        contract="mad4b.control-plane-update-channel.v1",
        repository=REPO, release_tag=TAG, version=receipt["control_plane_version"],
        source_commit_sha=head, archive_sha256=receipt["archive_sha256"],
        build_fingerprint=receipt["build_fingerprint"],
        package_manifest_digest=receipt["package_manifest_digest"],
        size_bytes=receipt["archive_bytes"],
        package_url="https://github.com/" + REPO + "/releases/download/" +
                    TAG + "/mad4b-site-control-plane-" + head + ".zip",
        staging_candidate_certified=True, staging_offline_candidate=False,
        release_verdict_success=True, release_verdict_run_id=run_id,
        published_from_master=False, release_root_trust_verified=False,
        production_authorized=False, production_auto_update_enabled=False,
    )

def publish_assets(items, out):
    gh("release", "view", TAG, "--repo", REPO)
    data = json.loads(gh("release", "view", TAG, "--repo", REPO, "--json", "assets"))
    existing = {item["name"] for item in data["assets"]}
    out.mkdir()
    for file in items:
        if file.name in existing:
            gh("release", "download", TAG, "--repo", REPO,
               "--pattern", file.name, "--dir", str(out))
            check(sha((out/file.name).read_bytes()) == sha(file.read_bytes()),
                  "existing immutable asset differs")
    for file in items:
        if file.name not in existing:
            gh("release", "upload", TAG, str(file), "--repo", REPO)
    for file in items:
        target = out/file.name
        if not target.exists():
            gh("release", "download", TAG, "--repo", REPO,
               "--pattern", file.name, "--dir", str(out))
        check(sha(target.read_bytes()) == sha(file.read_bytes()), "release readback mismatch")
    print("PUBLISHED_IMMUTABLE_STAGING_ASSETS=2 MASTER_POINTER_UNCHANGED")

def self_test():
    head = "a"*40
    template = dict(id=100, name="MAD4B Control Plane Package",
                    path=".github/workflows/mad4b-control-plane-package.yml",
                    event="pull_request", head_sha=head, head_branch="feat",
                    pull_requests=[{"number":482}], status="completed", conclusion="success")
    runs = [dict(template, id=1000+i, name=n) for i,n in enumerate(sorted(REQUIRED))]
    runs += [dict(template, id=2000+i, name="extra-"+str(i))
             for i in range(60-len(runs))]
    assert verify_ci(runs, 482, head, "feat") > 0
    for bad in ([*runs[:-1], dict(runs[-1], conclusion="failure")],
                [dict(x, pull_requests=[{"number":123}]) for x in runs]):
        try:
            verify_ci(bad, 482, head, "feat")
        except ValueError:
            pass
        else:
            raise AssertionError("must fail closed")
    m = build_manifest({"control_plane_version":"0.4.0-rc.103",
                        "archive_sha256":"b"*64,"build_fingerprint":"c"*64,
                        "package_manifest_digest":"d"*64,"archive_bytes":99},head,121)
    assert m["staging_candidate_certified"] and not m["production_authorized"]
    assert not m["published_from_master"] and not m["release_root_trust_verified"]
    print("STAGING_CI_PUBLISHER_SELFTEST_PASS")

def main():
    data = json.loads(Path(os.environ["GITHUB_EVENT_PATH"]).read_text())
    pr, head, zipsum = validate_event(os.environ, data.get("inputs") or {})
    branch = verify_pr(api("repos/" + REPO + "/pulls/" + str(pr)),pr,head)
    run = verify_ci(list_ci(head),pr,head,branch)
    with tempfile.TemporaryDirectory(prefix="mad4b-ci-staging-") as folder:
        root = Path(folder)
        gh("run","download",str(run),"--repo",REPO,"-n",
           "mad4b-site-control-plane-general-distribution-kit-"+head,"-D",str(root))
        archive = list(root.glob("mad4b-site-control-plane-*.zip"))
        check(len(archive)==1,"ambiguous canonical ZIP")
        receipt=validate_zip(archive[0],root/"CANONICAL-PACKAGE-RECEIPT.json",head,zipsum)
        check(verify_pr(api("repos/"+REPO+"/pulls/"+str(pr)),pr,head)==branch,
              "PR moved while reviewing")
        verify_ci(list_ci(head),pr,head,branch)
        outzip=root/("mad4b-site-control-plane-"+head+".zip")
        archive[0].rename(outzip)
        document=root/("mad4b-site-control-plane-update-"+head+".json")
        document.write_text(json.dumps(build_manifest(receipt,head,int(os.environ["GITHUB_RUN_ID"])),
                                       indent=2,sort_keys=True)+"\n")
        publish_assets([outzip,document],root/"readback")

if __name__ == "__main__":
    if "--self-test" in sys.argv:
        self_test()
    else:
        main()
