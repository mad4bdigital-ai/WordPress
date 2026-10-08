#!/usr/bin/env python3
"""Manually run Feature 007 G8 disposable SQL CAS matrix; NEVER use a site database."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import sys
import tempfile
import time
from datetime import datetime, timezone

TEST = "wp-content/plugins/mad4b-site-control-plane/tests/g8-mysql-cas-integration.php"
ENGINES = ("mariadb:11.4", "mysql:8.4")
PHP = (("7.4", "php7.4"), ("8.3", "php8.3"))
BASE = "c357bc995b2c831bd9d5a7d0df596d2d39bd3dd2"

def execv(args, env=None, timeout=35, cwd=None):
    try:
        return subprocess.run(args, env=env, cwd=cwd, stdin=subprocess.DEVNULL,
                              capture_output=True, text=True, timeout=timeout)
    except (OSError, subprocess.TimeoutExpired) as exc:
        return type("MissingCommand", (), {"returncode": 99, "stdout": "",
                                             "stderr": type(exc).__name__})()

def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--self-test", action="store_true")
    ap.add_argument("--expected-head")
    ap.add_argument("--php74", default="php7.4")
    ap.add_argument("--php83", default="php8.3")
    ap.add_argument("--allow-disposable-docker", action="store_true")
    ap.add_argument("--report")
    opt = ap.parse_args()
    if opt.self_test:
        assert re.fullmatch(r"[a-f0-9]{40}", "a" * 40)
        assert TEST.endswith("g8-mysql-cas-integration.php")
        print("DISPOSABLE_DB_MATRIX_SELFTEST_PASS; no database tested")
        return 0
    if not opt.allow_disposable_docker:
        ap.error("--allow-disposable-docker is mandatory for any local Docker operation")
    if not opt.report or not re.fullmatch(r"[a-f0-9]{40}", opt.expected_head or ""):
        ap.error("--report and exact --expected-head are required")
    root = Path(__file__).resolve().parents[4]
    report_path = Path(opt.report).resolve()
    if report_path == root or root in report_path.parents:
        ap.error("Report must be outside the checkout")
    report = {"contract": "mad4b.feature007-disposable-db-matrix.v1",
              "observed_at": datetime.now(timezone.utc).isoformat(),
              "head": opt.expected_head, "database_disposable": True,
              "production_authorized": False, "live_site_database_touched": False,
              "host_isolation_certified": False, "release_certified": False,
              "github_ci_certified": False, "results": [], "containers_left": []}
    created = []
    blocked = False
    failed = False
    try:
        head = execv(["git", "-C", str(root), "rev-parse", "HEAD"], cwd=root)
        base = execv(["git", "-C", str(root), "merge-base", "--is-ancestor", BASE, opt.expected_head], cwd=root)
        dirty = execv(["git", "-C", str(root), "status", "--porcelain", "--untracked-files=all"], cwd=root)
        if head.returncode or head.stdout.strip() != opt.expected_head or base.returncode or dirty.returncode or dirty.stdout.strip():
            raise ValueError("EXACT_CLEAN_CHECKOUT_REQUIRED")
        # Never inherit an existing database endpoint (local or external).
        if any(name.startswith(("G8_CAS_", "MYSQL_", "MARIADB_")) for name in os.environ):
            raise ValueError("AMBIENT_DATABASE_VARIABLES_FORBIDDEN")
        if shutil.which("docker") is None:
            blocked = True
            report["results"].append({"case": "docker", "state": "BLOCKED", "reason": "DOCKER_UNAVAILABLE"})
        else:
            with tempfile.TemporaryDirectory(prefix="mad4b-g8-matrix-") as tmp:
                clean_env = {"PATH": os.environ.get("PATH", ""), "HOME": tmp,
                             "TEMP": tmp, "TMP": tmp, "TMPDIR": tmp}
                for key in ("SystemRoot", "WINDIR", "PATHEXT"):
                    if key in os.environ:
                        clean_env[key] = os.environ[key]
                phps = []
                for version, configured in (("7.4", opt.php74), ("8.3", opt.php83)):
                    binary = shutil.which(configured)
                    if binary is None:
                        blocked = True
                        report["results"].append({"case": "php-" + version, "state": "BLOCKED",
                                                  "reason": "NATIVE_PHP_UNAVAILABLE"})
                        continue
                    ver = execv([binary, "-r", "echo PHP_MAJOR_VERSION,'.',PHP_MINOR_VERSION;"],
                                env=clean_env, cwd=root)
                    extension = execv([binary, "-r", "echo extension_loaded('mysqli') ? 'yes' : 'no';"],
                                      env=clean_env, cwd=root)
                    if ver.returncode or ver.stdout.strip() != version or extension.returncode or extension.stdout.strip() != "yes":
                        blocked = True
                        report["results"].append({"case": "php-" + version, "state": "BLOCKED",
                                                  "reason": "EXACT_PHP_OR_MYSQLI_UNAVAILABLE"})
                    else:
                        phps.append((version, binary))
                for engine in ENGINES:
                    image = execv(["docker", "image", "inspect", "--format", "{{.Id}}", engine],
                                  env=clean_env, cwd=root)
                    if image.returncode:
                        blocked = True
                        report["results"].append({"case": engine, "state": "BLOCKED",
                                                  "reason": "IMAGE_NOT_LOCAL_NO_AUTOPULL"})
                        continue
                    name = "mad4b-g8-cas-" + secrets.token_hex(7)
                    password = secrets.token_urlsafe(24)
                    health = ("healthcheck.sh --connect --innodb_initialized" if engine.startswith("mariadb")
                              else "mysqladmin ping --protocol=tcp -h 127.0.0.1 -uroot -p" + password)
                    cmd = ["docker", "run", "--rm", "-d", "--pull=never", "--name", name,
                           "--label", "mad4b.feature007.disposable=true",
                           "-p", "127.0.0.1::3306", "-e", "MYSQL_ROOT_PASSWORD=" + password,
                           "-e", "MYSQL_ROOT_HOST=%", "-e", "MYSQL_DATABASE=g8_ci_contract",
                           "--health-cmd", health, "--health-interval", "5s",
                           "--health-timeout", "4s", "--health-retries", "20", engine]
                    launched = execv(cmd, env=clean_env, cwd=root, timeout=65)
                    if launched.returncode:
                        failed = True
                        report["results"].append({"case": engine, "state": "FAIL",
                                                  "reason": "DOCKER_DISPOSABLE_START_FAILED"})
                        continue
                    created.append(name)
                    # Only loopback host bindings; a non-loopback mapping aborts.
                    mapped = execv(["docker", "port", name, "3306/tcp"], env=clean_env, cwd=root)
                    match = re.fullmatch(r"127\.0\.0\.1:(\d+)", mapped.stdout.strip())
                    if mapped.returncode or not match:
                        failed = True
                        report["results"].append({"case": engine, "state": "FAIL",
                                                  "reason": "NON_LOOPBACK_PORT_MAPPING"})
                        continue
                    healthy = False
                    for _ in range(24):
                        status = execv(["docker", "inspect", "--format", "{{.State.Health.Status}}", name],
                                       env=clean_env, cwd=root)
                        if status.returncode == 0 and status.stdout.strip() == "healthy":
                            healthy = True
                            break
                        if status.returncode or status.stdout.strip() == "unhealthy":
                            break
                        time.sleep(5)
                    if not healthy:
                        failed = True
                        report["results"].append({"case": engine, "state": "FAIL",
                                                  "reason": "DISPOSABLE_DATABASE_NOT_HEALTHY"})
                        continue
                    for version, binary in phps:
                        case = engine + "/PHP" + version
                        scope = dict(clean_env, G8_CAS_DISPOSABLE="1", G8_CAS_HOST="127.0.0.1",
                                     G8_CAS_USER="root", G8_CAS_PASSWORD=password,
                                     G8_CAS_DATABASE="g8_ci_contract", G8_CAS_PORT=match.group(1))
                        refused = execv([binary, TEST], env=dict(scope, G8_CAS_DATABASE="forbidden_database"),
                                        cwd=root, timeout=25)
                        tested = execv([binary, TEST], env=scope, cwd=root, timeout=75)
                        state = "PASS" if refused.returncode == 2 and tested.returncode == 0 else "FAIL"
                        if state == "FAIL":
                            failed = True
                        report["results"].append({"case": case, "state": state,
                                                  "safe_refusal_exit_2": refused.returncode == 2,
                                                  "native_cas_exit_0": tested.returncode == 0,
                                                  "image_id": image.stdout.strip(),
                                                  "stdout_sha256": hashlib.sha256(tested.stdout.encode()).hexdigest()})
    except (ValueError, OSError) as exc:
        failed = True
        report["results"].append({"case": "precondition", "state": "FAIL",
                                  "reason": str(exc)[:100]})
    finally:
        for name in reversed(created):
            removed = execv(["docker", "rm", "-f", name], timeout=20)
            if removed.returncode:
                failed = True
                report["containers_left"].append(name)
        states = [x["state"] for x in report["results"]]
        report["status"] = "FAIL" if failed or "FAIL" in states else "BLOCKED" if blocked or "BLOCKED" in states else "LOCAL_DISPOSABLE_DB_MATRIX_PASS_ONLY"
        report_path.parent.mkdir(parents=True, exist_ok=True)
        report_path.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        print(json.dumps({"status": report["status"], "report": str(report_path),
                          "cases": len(report["results"]), "containers_left": report["containers_left"]}))
    return 1 if report["status"] == "FAIL" else 2 if report["status"] == "BLOCKED" else 0

if __name__ == "__main__":
    sys.exit(main())
