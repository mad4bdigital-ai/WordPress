#!/usr/bin/env python3
"""Bounded Host Bridge consumer for enrolled Staging read-only diagnostics.

No Shell, Raw SQL, WordPress mutation, user-supplied commands or approval
consumption. A standalone SSH-invoked read-only pilot, NOT the general executor.
Execution modifies ONLY Host Bridge queue state and writes signed receipts.
"""
import argparse
import base64
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import (
    Encoding, PrivateFormat, PublicFormat, NoEncryption
)
from importlib.machinery import SourceFileLoader

validator = SourceFileLoader(
    "mad4b_readonly_preflight", str(Path(__file__).with_name("queue-preflight.py"))
).load_module()

SITE = None  # Required from a pinned deployment profile on CLI.
SPOOL = None
BASE = None
WPCLI = None
RUNNER_PROFILE = None
RUNNER = BASE / "runner.sh"
SIGNER = BASE / "readonly-receipt-ed25519.key"
PUB = BASE / "readonly-receipt-ed25519.pub"
MAX_OUTPUT_BYTES = 8192
MAX_JSON = 131072


def utcnow():
    return datetime.datetime.now(datetime.timezone.utc).isoformat()


def canonical(payload):
    return json.dumps(payload, sort_keys=True, separators=(",", ":"),
                      ensure_ascii=False, allow_nan=False).encode("utf-8")


def load_signing_key():
    if SIGNER.is_symlink() or PUB.is_symlink():
        raise RuntimeError("signer symlink denied")
    if SIGNER.exists():
        if SIGNER.stat().st_mode & 0o077:
            raise RuntimeError("signer permissions unsafe")
        key_bytes = SIGNER.read_bytes()
        if len(key_bytes) != 32:
            raise RuntimeError("signer identity corrupted")
        key = Ed25519PrivateKey.from_private_bytes(key_bytes)
    else:
        key = Ed25519PrivateKey.generate()
        raw = key.private_bytes(Encoding.Raw, PrivateFormat.Raw, NoEncryption())
        fd = os.open(SIGNER, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        try:
            os.write(fd, raw)
            os.fsync(fd)
        finally:
            os.close(fd)
    public = key.public_key().public_bytes(Encoding.Raw, PublicFormat.Raw)
    if PUB.exists():
        if PUB.read_bytes() != public:
            raise RuntimeError("pinned receipt signer changed")
    else:
        fd = os.open(PUB, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        try:
            os.write(fd, public)
            os.fsync(fd)
        finally:
            os.close(fd)
    return key, hashlib.sha256(public).hexdigest()


def stable_identity():
    if not (SITE / "wp-load.php").is_file():
        raise RuntimeError("site identity missing")
    cmd = [
        str(WPCLI), "--path=" + str(SITE),
        "--skip-themes", "--quiet", "eval",
        "echo MAD4B_SCP_Host_Bridge::target_fingerprint_readonly();"
    ]
    result = subprocess.run(cmd, capture_output=True, timeout=23)
    if result.returncode:
        raise RuntimeError("fresh WordPress identity check failed")
    fingerprint = result.stdout.decode("utf-8", "replace").strip()
    if not validator.SHA256.fullmatch(fingerprint):
        raise RuntimeError("WordPress target fingerprint missing")
    return fingerprint


def load_job(path):
    if path.is_symlink() or not path.is_file() or path.stat().st_size > MAX_JSON:
        raise RuntimeError("invalid queued job file")
    fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
    with os.fdopen(fd, "rb") as fp:
        raw = fp.read(MAX_JSON + 1)
    if len(raw) > MAX_JSON:
        raise RuntimeError("queued job oversized")
    data = json.loads(raw)
    if not isinstance(data, dict):
        raise RuntimeError("invalid queued job")
    return data


def safe_partition(subdir):
    folder = SPOOL / subdir
    if SPOOL.is_symlink() or folder.is_symlink() or not folder.is_dir():
        raise RuntimeError("unsafe spool directory")
    return folder


def write_receipt(path, row):
    if path.exists() or path.is_symlink():
        raise RuntimeError("receipt already exists")
    raw = canonical(row) + b"\n"
    if len(raw) > MAX_JSON:
        raise RuntimeError("receipt oversized")
    temp = path.parent / (".receipt-" + row["job_id"] + "." + str(os.getpid()) + ".tmp")
    fd = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        with os.fdopen(fd, "wb") as fp:
            fp.write(raw)
            fp.flush()
            os.fsync(fp.fileno())
        os.replace(temp, path)
    finally:
        if temp.exists():
            temp.unlink()


def handle_readonly_job(path, fingerprint, key, public_sha):
    item = load_job(path)
    reason = validator.check_submission(item, fingerprint, path.name, RUNNER_PROFILE)
    if reason != "verified_read_only_eligible":
        raise RuntimeError("job preflight denied: " + reason)
    job_id = item["job_id"]
    running = safe_partition("running") / path.name
    receipt_path = safe_partition("receipts") / path.name
    if running.exists() or receipt_path.exists():
        raise RuntimeError("job already running/completed; no replay allowed")
    args = item["plan"]["arguments"]
    op = args["diagnostic"]
    argv = ["bash", str(RUNNER), op]
    if op == "php_lint":
        argv.append(args["fixture"])
    if RUNNER.is_symlink() or not RUNNER.is_file() or not os.access(RUNNER, os.X_OK):
        raise RuntimeError("host diagnostic runner missing")
    os.rename(path, running)
    started = utcnow()
    try:
        finished = subprocess.run(argv, capture_output=True, timeout=35,
                                  cwd=str(BASE), text=True, errors="replace",
                                  env={"PATH":"/usr/local/bin:/usr/bin:/bin",
                                       "HOME":str(BASE),"LC_ALL":"C"})
        exit_code = finished.returncode
        stdout = finished.stdout[:MAX_OUTPUT_BYTES]
        stderr = finished.stderr[:MAX_OUTPUT_BYTES]
        result_state = "PASS" if exit_code == 0 else "FAIL"
    except subprocess.TimeoutExpired:
        exit_code, stdout, stderr, result_state = 124, "", "read-only operation timed out", "FAIL"
    receipt = {
        "contract": "mad4b.host-bridge-readonly-receipt.v1",
        "job_id": job_id,
        "plan_sha256": item["plan_sha256"],
        "submission_sha256": item["submission_sha256"],
        "runner_profile_id": item["plan"]["runner_profile_id"],
        "target_fingerprint": fingerprint,
        "environment": "staging",
        "operation_id": "runtime.status.read",
        "diagnostic": op,
        "state": "completed",
        "started_at": started,
        "completed_at": utcnow(),
        "readback_verdict": result_state,
        "exit_code": exit_code,
        "stdout": stdout,
        "stderr": stderr,
        "stdout_sha256": hashlib.sha256(finished.stdout.encode("utf-8") if 'finished' in locals() else b"").hexdigest(),
        "mutation_performed": False,
        "production_authorized": False,
        "signed_by": "staging_readonly_worker_not_general_operator_authority",
        "public_key_sha256": public_sha,
    }
    signature = key.sign(canonical(receipt))
    receipt["attestation"] = {
        "algorithm": "Ed25519",
        "payload_contract": receipt["contract"],
        "signature_b64": base64.b64encode(signature).decode("ascii"),
    }
    write_receipt(receipt_path, receipt)
    running.unlink()
    return {"job_id": job_id, "operation": op, "readback_verdict": result_state,
            "exit_code": exit_code, "receipt_sha256": hashlib.sha256(
                canonical(receipt) + b"\n").hexdigest()}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--inspect-only", action="store_true")
    ap.add_argument("--run-queued-readonly", action="store_true")
    ap.add_argument("--site-root", type=Path, required=True)
    ap.add_argument("--runner-directory", type=Path, required=True)
    ap.add_argument("--wp-cli-binary", type=Path, required=True)
    ap.add_argument("--runner-profile-id", required=True)
    args = ap.parse_args()
    if (not args.site_root.is_absolute() or not args.runner_directory.is_absolute()
            or not args.wp_cli_binary.is_absolute() or
            not validator.PROFILE_ID.fullmatch(args.runner_profile_id)):
        raise RuntimeError("explicit_bound_profile_required")
    if (args.site_root.is_symlink() or args.runner_directory.is_symlink()
            or not args.site_root.is_dir() or not args.runner_directory.is_dir()):
        raise RuntimeError("unsafe_site_or_runner_path")
    global SITE, SPOOL, BASE, RUNNER, SIGNER, PUB, WPCLI, RUNNER_PROFILE
    SITE = args.site_root.resolve(strict=True)
    BASE = args.runner_directory.resolve(strict=True)
    if BASE == SITE or SITE in BASE.parents:
        raise RuntimeError("runner_workspace_must_be_outside_wordpress")
    SPOOL = SITE / "wp-content/mad4b-runner/bridge"
    RUNNER = BASE / "runner.sh"
    SIGNER = BASE / "readonly-receipt-ed25519.key"
    PUB = BASE / "readonly-receipt-ed25519.pub"
    WPCLI = args.wp_cli_binary
    RUNNER_PROFILE = args.runner_profile_id
    if args.inspect_only == args.run_queued_readonly:
        ap.error("choose exactly one mode")
    if os.geteuid() == 0:
        raise RuntimeError("root execution denied")
    queued = safe_partition("queued")
    fp = stable_identity()
    paths = sorted(queued.glob("*.json"))
    if args.inspect_only:
        summary = [
            {"job_id": p.stem,
             "decision": validator.check_submission(load_job(p), fp, p.name)}
            for p in paths
        ]
        print(json.dumps({"mode":"inspect_only","jobs":summary,
                          "mutation_performed":False},sort_keys=True))
        return 0
    if not paths:
        print(json.dumps({"mode":"run_readonly","jobs":[],"nothing_to_run":True}))
        return 0
    # Exclusive local staging consumer lease (never auto retry a running job).
    lock_path = BASE / "readonly-worker.lock"
    with open(lock_path, "a+") as lock:
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        for path in paths:
            item = load_job(path)
            if validator.check_submission(item, fp, path.name) != "verified_read_only_eligible":
                raise RuntimeError("refusing entire queue due to invalid job")
        key, public_sha = load_signing_key()
        results = []
        for path in paths:
            results.append(handle_readonly_job(path, fp, key, public_sha))
    print(json.dumps({
        "contract":"mad4b.host-bridge-readonly-consumption.v1",
        "mode":"run_queued_readonly",
        "receipt_public_key_sha256":public_sha,
        "results":results,
        "shell_available":False,
        "raw_sql_available":False,
        "production_authorized":False,
    },sort_keys=True))
    return 0 if all(r["readback_verdict"]=="PASS" for r in results) else 2


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as e:
        print(json.dumps({"contract":"mad4b.host-bridge-readonly-consumption.v1",
                          "failed":True,"error_type":type(e).__name__,
                          "reason":str(e),"blind_retry_allowed":False},
                          sort_keys=True),file=sys.stderr)
        raise SystemExit(4)
