#!/usr/bin/env python3
"""Host orchestrator for the original, policy-governed MAD4B Readiness CLI.

Discovers managed PHP WordPress containers and invokes ONLY the native
WP-CLI MAD4B readiness plan/run contract. Never changes approval policy,
contents, WordPress user, site identity, media, or Original313 trust.
"""
from __future__ import annotations

import argparse
import fcntl
import json
import re
import subprocess
import sys
from pathlib import Path

CONTAINER = re.compile(r"^php-[a-z0-9]{20,48}$")
SHA256 = re.compile(r"^[a-f0-9]{64}$")
UUID = re.compile(r"^[a-f0-9-]{36}$")
CONTRACT = "mad4b.cli-readiness.v1.plan"
CONFIRM = "RUN SCHEDULED READINESS SCAN"
LOCK = Path("/run/lock/mad4b-content-readiness-supervisor.lock")


def command(args: list[str], timeout: int = 60) -> tuple[int, str, str]:
    run = subprocess.run(args, text=True, capture_output=True, timeout=timeout,
                         check=False, stdin=subprocess.DEVNULL)
    return run.returncode, run.stdout, run.stderr


def containers() -> list[str]:
    code, stdout, _ = command(["docker", "ps", "--format", "{{.Names}}"], 20)
    if code != 0:
        raise RuntimeError("docker_container_discovery_failed")
    return sorted(n.strip() for n in stdout.splitlines() if CONTAINER.fullmatch(n.strip()))


def wp_plan(container: str) -> dict:
    code, stdout, _ = command(
        ["docker", "exec", "-u", "33:33", container, "wp", "mad4b", "readiness", "plan"],
        90,
    )
    if code != 0:
        raise RuntimeError("native_plan_unavailable")
    plan = json.loads(stdout)
    if (plan.get("contract") != CONTRACT or not UUID.fullmatch(plan.get("site_uuid", "")) or
            plan.get("operation") != "content_readiness.scheduled_scan" or
            plan.get("site_uuid") != (plan.get("context") or {}).get("site_uuid")):
        raise RuntimeError("native_site_identity_mismatch")
    return plan


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--dry-run", action="store_true", help="read plans only; make no changes")
    args = parser.parse_args()
    LOCK.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
    with LOCK.open("a+") as lock:
        try:
            fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            print(json.dumps({"status": "already_running"}))
            return 0
        results = []
        planned = []
        # Resolve every site plan first. Duplicate UUIDs across containers
        # are an identity conflict, not two independent scan targets.
        for container in containers():
            try:
                plan = wp_plan(container)
                planned.append((container, plan))
            except (ValueError, RuntimeError, subprocess.TimeoutExpired) as ex:
                results.append({
                    "container": container, "result": "failed",
                    "reason": ex.__class__.__name__ if not isinstance(ex, RuntimeError)
                    else str(ex),
                })
        by_uuid = {}
        for container, plan in planned:
            by_uuid.setdefault(plan["site_uuid"], []).append(container)
        duplicate = {uuid: sites for uuid, sites in by_uuid.items() if len(sites) > 1}
        if duplicate:
            for uuid, sites in duplicate.items():
                for container in sites:
                    results.append({"site_uuid": uuid, "container": container,
                                    "result": "failed", "reason": "duplicate_site_identity"})
            planned = [(container, plan) for container, plan in planned
                       if plan["site_uuid"] not in duplicate]

        for container, plan in planned:
            try:
                item = {
                    "site_uuid": plan["site_uuid"], "container": container,
                    "eligible": plan.get("eligible") is True,
                    "due": plan.get("due") is True,
                }
                if not item["eligible"]:
                    item["result"] = "policy_blocked"
                    item["blockers"] = plan.get("blockers", [])
                elif not item["due"]:
                    item["result"] = "not_due"
                elif args.dry_run:
                    item["result"] = "due_dry_run"
                else:
                    sha = plan.get("plan_sha256", "")
                    if not isinstance(sha, str) or not SHA256.fullmatch(sha):
                        raise RuntimeError("plan_hash_invalid")
                    c, stdout, _ = command(
                        ["docker", "exec", "-u", "33:33", container,
                         "wp", "mad4b", "readiness", "run",
                         "--expected-plan=" + sha, "--confirm=" + CONFIRM], 180,
                    )
                    if c != 0:
                        raise RuntimeError("native_runner_rejected")
                    receipt = json.loads(stdout)
                    if receipt.get("contract") != "mad4b.cli-readiness.v1.run":
                        raise RuntimeError("native_receipt_mismatch")
                    replan = wp_plan(container)
                    if replan.get("due") is True:
                        raise RuntimeError("native_post_run_due_still_true")
                    item["result"] = "native_run_verified"
                    item["issue_registry_count"] = receipt.get("issue_registry_count")
                results.append(item)
            except (ValueError, RuntimeError, subprocess.TimeoutExpired) as ex:
                results.append({"site_uuid": plan["site_uuid"], "container": container,
                                "result": "failed",
                                "reason": ex.__class__.__name__ if not isinstance(ex, RuntimeError)
                                else str(ex)})
        print(json.dumps({"contract": "mad4b.readiness-supervisor.v1",
                          "dry_run": args.dry_run, "results": results},
                         separators=(",", ":")))
        return 1 if any(r.get("result") == "failed" for r in results) else 0


if __name__ == "__main__":
    sys.exit(main())
