#!/usr/bin/env python3
"""Independent read-only source audit for PR750's newly recovered runtime."""
import hashlib
import pathlib
import re
import shutil
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[4]
BASE = "ba0db6f92c76772d5dff12b11106357193743815"
sys.path.insert(0, str(ROOT / "tools"))
import production_readiness_registry as registry

def need(valid, label):
    if not valid:
        raise SystemExit("PR750_SOURCE_ACCEPTANCE_FAIL: " + label)

def git(*args):
    return subprocess.check_output(["git", "-C", str(ROOT), *args], text=True).strip()

head = git("rev-parse", "HEAD")
need(subprocess.run(["git", "-C", str(ROOT), "merge-base", "--is-ancestor", BASE, head]).returncode==0, "base_not_ancestor")
changed = registry.changed_paths(BASE, head)
_, _, abilities, removed = registry.new_source_ids(BASE, head, changed)
need(not removed, "unreviewed_api_removal")
need(len(abilities)>=100, "missing_expected_runtime_surfaces")
source_paths = sorted({p for status,p in changed
    if status in ("A","M","T") and p.startswith("wp-content/plugins/mad4b-site-control-plane/")
    and p.endswith(".php") and "/tests/" not in p})
need(len(source_paths)>=54, "source_inventory_incomplete")
lint = shutil.which("php")
checks=0
for relative in source_paths:
    path=ROOT/relative
    data=path.read_bytes()
    need(data.startswith(b"<?php"), "php_header_missing:"+relative)
    # Git's exact blob must match the bytes tested on this checkout.
    raw=b"blob "+str(len(data)).encode()+b"\0"+data
    need(hashlib.sha1(raw).hexdigest()==git("rev-parse",head+":"+relative),
         "source_blob_mismatch:"+relative)
    if lint:
        run=subprocess.run([lint,"-l",str(path)],capture_output=True,text=True,timeout=20)
        need(run.returncode==0,"php_lint_failed:"+relative+":"+run.stderr[:180])
    checks+=1
for identifier,relative in sorted(abilities.items()):
    path=ROOT/relative
    need(path.is_file(),"interface_source_missing:"+identifier)
    content=path.read_text(errors="replace")
    if identifier.startswith("service:"):
        need("class " in content or "trait " in content or "interface " in content,
             "uninspectable_source:"+identifier)
    elif identifier.startswith("ability:"):
        name=identifier.split(":",1)[1]
        need(name in content,"ability_not_declared_in_file:"+identifier)
    elif identifier.startswith("rest:"):
        need("register_rest_route" in content and "permission_callback" in content,
             "rest_route_missing_permission_callback:"+identifier)
    elif identifier.startswith("cron:"):
        need("hourly" in content,"cron_scheduler_source_missing:"+identifier)
    checks+=1
inc=ROOT/"wp-content/plugins/mad4b-site-control-plane/includes"
def guarded(file, *needles):
    content=(inc/file).read_text()
    for needle in needles:
        need(needle in content,file+":missing:"+needle)
    return content
guarded("adapters/class-mad4b-scp-chatbotx-governed-writes.php",
        "can_mutate","current_user_can","idempotency","post_status")
guarded("adapters/class-mad4b-scp-chatbotx-write-preflight.php",
        "current_user_can","create_posts")
guarded("class-mad4b-scp-company-migration-decisions.php",
        "current_user_can","permission_callback","nonce","source_sha")
guarded("class-mad4b-scp-company-tenant-migration.php",
        "current_user_can","permission_callback","nonce")
guarded("class-mad4b-scp-staging-brand-trial.php",
        "wp_get_environment_type","current_user_can","permission_callback")
staff_guard=guarded("class-mad4b-scp-company-user-lifecycle.php",
        "rest_pre_dispatch","guardScopedREST",
        "wp_authenticate_application_password_errors")
need("add_filter('rest_authentication_errors'" not in staff_guard,
     "company_user_lifecycle_globally_intercepts_rest_authentication")
guarded("class-mad4b-scp-company-local-wpcli.php",
        "mad4b/company/local_wpcli_identity","read_only","MAD4B_SCP_Operation_Pipeline")
guarded("class-mad4b-scp-content-readiness-alerts.php",
        "mad4b/content_readiness/provider_job_intake",
        "mad4b/content_readiness/valid_pricing_alert",
        "requires_governed_content_job_admission")
bootstrap=(ROOT/"wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php").read_text()
need(bootstrap.index("class-mad4b-scp-private-operations-boundary.php") <
     bootstrap.index("MAD4B_SCP_VERSION"),"private_booking_boundary_moved_after_fastpath")
# Companion behavioral tests are executable and fail closed. CI provides PHP.
if lint:
    for relative in [
        "wp-content/plugins/mad4b-site-control-plane/tests/company-admin-menu-hermetic.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/readiness-live-provider-contract.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/company-user-lifecycle-rest-isolation-hermetic.php",
    ]:
        result=subprocess.run([lint,str(ROOT/relative)],capture_output=True,text=True,timeout=50)
        need(result.returncode==0,"hermetic_behavior_regression:"+relative+":"+result.stderr[:240])
else:
    print("PR750_PHP_EXECUTION_DEFERRED: PHP CLI unavailable on this host; CI must run it")
print(f"PR750_SOURCE_ACCEPTANCE: PASS sources={len(source_paths)} interfaces={len(abilities)} checks={checks} php_lint={'complete' if lint else 'deferred'}")
