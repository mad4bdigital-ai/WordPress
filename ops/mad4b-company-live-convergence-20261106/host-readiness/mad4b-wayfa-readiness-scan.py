#!/usr/bin/env python3
"""MAD4B unified Content Readiness local operator for enrolled Wayfatours.

Uses the same exact-plan WP-CLI contract as Tours.dev staging. It does not
impersonate a WP user, enable generic mutations or touch external tasks.
The existing 15-minute systemd timer and site-controlled interval remain.
"""
import fcntl
import json
import os
import re
import subprocess
import sys

CONTAINER = "php-guxhp9y6vgh7oouuw8plxrlr"
SITE_UUID = "a3a7cc36-db7e-4a14-8456-b41857def05c"
SITE_ORIGIN = "https://wayfatours.com"
LOCK_PATH = "/run/lock/mad4b-wayfa-readiness.lock"
CONTRACT = "mad4b.cli-readiness.v1"

def call_wp(args, timeout):
    return subprocess.run(
        ["/usr/bin/docker", "exec", "-u", "www-data", CONTAINER,
         "wp", "--path=/var/www/html", "--skip-themes", "mad4b", "readiness", *args],
        text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        timeout=timeout, check=False,
    )

def fail(reason, code=72):
    print("WAYFA_READINESS_BLOCKED=" + reason, file=sys.stderr)
    return code

def main():
    os.makedirs("/run/lock", exist_ok=True)
    with open(LOCK_PATH, "a", encoding="utf-8") as lock:
        try:
            fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            print("WAYFA_READINESS_DEFERRED=runner_lock_busy")
            return 0
        result=call_wp(["plan"], 75)
        if result.returncode:
            if "WPCLI_BUSY" in (result.stderr + result.stdout):
                print("WAYFA_READINESS_DEFERRED=wpcli_busy")
                return 0
            return fail("plan_failed:" + result.stderr.strip()[-200:])
        try:
            plan=json.loads(result.stdout)
        except (ValueError, TypeError):
            return fail("invalid_plan_json", 73)
        ctx=plan.get("context", {})
        if (plan.get("contract") != CONTRACT + ".plan"
            or plan.get("operation") != "content_readiness.scheduled_scan"
            or plan.get("site_uuid") != SITE_UUID
            or ctx.get("site_uuid") != SITE_UUID
            or ctx.get("canonical_origin") != SITE_ORIGIN
            or ctx.get("environment") != "production"
            or ctx.get("contract") != "mad4b.cli-execution-context.v1"
            or not ctx.get("host_site_binding_verified")
            or not ctx.get("local_operation_authorized")):
            return fail("unverified_exact_site_or_operation", 74)
        if not plan.get("eligible"):
            return fail("site_policy_blocked:" + ",".join(plan.get("blockers", [])))
        if not plan.get("due"):
            print("WAYFA_READINESS_RECEIPT=" + json.dumps({
                "status": "not_due",
                "next_eligible_at": plan.get("due_at_utc"),
                "source": "mad4b.cli-readiness.v1.plan",
            }))
            return 0
        digest=plan.get("plan_sha256", "")
        if not isinstance(digest, str) or not re.fullmatch(r"[a-f0-9]{64}", digest):
            return fail("invalid_exact_plan_digest", 75)
        run=call_wp([
            "run", "--expected-plan=" + digest,
            "--confirm=RUN SCHEDULED READINESS SCAN",
        ], 180)
        if run.returncode:
            return fail("run_failed:" + run.stderr.strip()[-260:], 76)
        try:
            data=json.loads(run.stdout)
        except (ValueError, TypeError):
            return fail("invalid_receipt_json", 77)
        if (data.get("contract") != CONTRACT+".run"
            or data.get("source_of_write") != "MAD4B_SCP_Content_Readiness_Alerts::run_scheduled"
            or data.get("external_tasks_created") is not False):
            return fail("unverified_receipt_contract", 78)
        print("WAYFA_READINESS_RECEIPT=" + json.dumps({
            "contract": data["contract"],
            "status": data.get("status"),
            "issue_registry_count": data.get("issue_registry_count"),
            "external_tasks_created": data.get("external_tasks_created"),
        }, separators=(",", ":")))
        return 0

if __name__ == "__main__":
    try:
        sys.exit(main())
    except subprocess.TimeoutExpired:
        sys.exit(fail("wp_cli_timeout", 79))
    except Exception as exc:
        sys.exit(fail(type(exc).__name__, 80))
