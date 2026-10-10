#!/usr/bin/env python3
"""Pure refusal/acceptance cases for Staging Host sandbox preflight."""
import importlib.util
import pathlib
import subprocess

ROOT = pathlib.Path(__file__).resolve().parents[4]
SCRIPT = ROOT / "tools" / "mad4b_staging_host_preflight.py"
spec = importlib.util.spec_from_file_location("mad4b_staging_host_preflight", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class FakeRun:
    def __init__(self, fail=False):
        self.calls = []
        self.fail = fail

    def __call__(self, args, **kwargs):
        self.calls.append((args, kwargs))
        return subprocess.CompletedProcess(args, 1 if self.fail else 0)


def check(r, state, blocker=None):
    assert r["status"] == state, (r["status"], r["blockers"])
    assert r["read_only"] and not r["authorizing"]
    assert not r["mutation_performed"] and not r["production_mutation_allowed"]
    assert not r["signed_host_receipt"] and not r["developer_execution_certified"]
    if blocker:
        assert blocker in r["blockers"], r["blockers"]


both = {"prlimit": "/usr/bin/prlimit", "bubblewrap": "/usr/bin/bwrap", "unshare_net": None}
no_execute = FakeRun()
r = module.evaluate(binaries=both, linux=True, host_environment="production", uid=1000,
                    canary_requested=True, run=no_execute)
check(r, "BLOCKED", "separately_enrolled_staging_host_identity_not_confirmed")
assert len(no_execute.calls) == 0

r = module.evaluate(binaries=both, linux=True, host_environment="staging", uid=0,
                    canary_requested=True, run=no_execute)
check(r, "BLOCKED", "nonroot_host_worker_required")
assert not no_execute.calls

r = module.evaluate(binaries=both, linux=False, host_environment="staging", uid=1000,
                    canary_requested=True, run=no_execute)
check(r, "BLOCKED", "linux_host_required")
assert not no_execute.calls

r = module.evaluate(binaries=both, linux=True, host_environment="staging", uid=1000,
                    canary_requested=False, run=no_execute)
check(r, "BLOCKED", "behavioral_canary_not_executed")
assert not no_execute.calls

r = module.evaluate(binaries={"prlimit": None, "bubblewrap": None, "unshare_net": None},
                    linux=True, host_environment="staging", uid=1000,
                    canary_requested=True, run=no_execute)
check(r, "BLOCKED", "resource_limiter_unavailable")

ok = FakeRun()
r = module.evaluate(binaries=both, linux=True, host_environment="staging", uid=1000,
                    canary_requested=True, run=ok)
check(r, "PRECHECK_PASSED_HOST_REVIEW_REQUIRED")
assert len(ok.calls) == 2 and r["prlimit_behavior_pass"] and r["sandbox_behavior_pass"]
assert ok.calls[0][0][0] == "/usr/bin/prlimit"
assert "--unshare-net" in ok.calls[1][0]
assert all(call[1]["timeout"] == 8 for call in ok.calls)
assert all(call[1]["stdin"] is subprocess.DEVNULL for call in ok.calls)

fail = FakeRun(fail=True)
r = module.evaluate(binaries=both, linux=True, host_environment="staging", uid=1000,
                    canary_requested=True, run=fail)
check(r, "BLOCKED", "network_isolation_behavior_failed")
assert len(fail.calls) == 2

unshare = {"prlimit": "/usr/bin/prlimit", "bubblewrap": None, "unshare_net": "/usr/bin/unshare"}
ok_unshare = FakeRun()
r = module.evaluate(binaries=unshare, linux=True, host_environment="staging", uid=1000,
                    canary_requested=True, run=ok_unshare)
check(r, "PRECHECK_PASSED_HOST_REVIEW_REQUIRED")
assert "--net" in ok_unshare.calls[1][0]

print("PASS: 8 Staging host preflight refuse/canary scenarios")
