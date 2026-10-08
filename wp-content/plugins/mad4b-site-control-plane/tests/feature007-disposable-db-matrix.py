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
OWNERSHIP_LABEL = "mad4b.feature007.disposable.run"
SOURCE_PATHS = (TEST, TEST.replace("tests/g8-mysql-cas-integration.php",
                                  "includes/class-mad4b-scp-g8-record.php"),
                TEST.replace("tests/g8-mysql-cas-integration.php",
                             "includes/class-mad4b-scp-operation-journal.php"),
                TEST.replace("g8-mysql-cas-integration.php", "feature007-disposable-db-matrix.py"))

def execv(args, env=None, timeout=35, cwd=None):
    try:
        return subprocess.run(args, env=env, cwd=cwd, stdin=subprocess.DEVNULL,
                              capture_output=True, text=True, timeout=timeout)
    except (OSError, subprocess.TimeoutExpired) as exc:
        return type("MissingCommand", (), {"returncode": 99, "stdout": "",
                                             "stderr": type(exc).__name__})()

def source_snapshot(root, expected_head):
    def git(*arguments):
        result = execv(["git", "-C", str(root), *arguments], cwd=root)
        if result.returncode:
            raise ValueError("GIT_CHECK_FAILED")
        return result.stdout.strip()
    def exact_clean():
        if git("rev-parse", "HEAD") != expected_head:
            raise ValueError("HEAD_MISMATCH")
        if git("status", "--porcelain", "--untracked-files=all"):
            raise ValueError("WORKTREE_DIRTY")
    exact_clean()
    base = execv(["git", "-C", str(root), "merge-base", "--is-ancestor", BASE, expected_head], cwd=root)
    if base.returncode:
        raise ValueError("BASE_NOT_ANCESTOR")
    snapshot = {"head": expected_head, "source_tree_sha": git("rev-parse", "HEAD^{tree}"),
                "runtime_source_sha256": {path: hashlib.sha256((root / path).read_bytes()).hexdigest()
                                          for path in SOURCE_PATHS}}
    exact_clean()
    return snapshot

def cleanup_owned(created, env, root):
    """Unknown starts are inspected; only a matching immutable container ID is removed."""
    unresolved = []
    for entry in reversed(created):
        name, token = entry["name"], entry["token"]
        observed = execv(["docker", "inspect", "--format", "{{.Id}} {{json .Config.Labels}}", name],
                         env=env, cwd=root, timeout=20)
        if observed.returncode:
            # A rejected start may have created nothing; distinguish that from a
            # daemon/transport failure instead of reporting cleanup as verified.
            listed = execv(["docker", "container", "ls", "-a", "--filter", "name=^/" + name + "$",
                            "--format", "{{.Names}}"], env=env, cwd=root, timeout=20)
            if listed.returncode == 0 and not listed.stdout.strip():
                continue
            unresolved.append(name)
            continue
        identifier, _, raw_labels = observed.stdout.strip().partition(" ")
        try:
            labels = json.loads(raw_labels) if len(raw_labels) <= 8192 else None
        except (ValueError, TypeError):
            labels = None
        if (not re.fullmatch(r"[a-f0-9]{64}", identifier) or not isinstance(labels, dict)
                or labels.get("mad4b.feature007.disposable") != "true"
                or labels.get(OWNERSHIP_LABEL) != token):
            unresolved.append(name)
            continue
        # Using the ID prevents a same-name replacement race from deleting an
        # unrelated container after the ownership observation.
        removed = execv(["docker", "rm", "-f", identifier], env=env, cwd=root, timeout=20)
        if removed.returncode:
            unresolved.append(name)
    return unresolved

def final_integrity(report, root, expected_head):
    report["source_immutable_verified"] = False
    if "integrity" not in report:
        return False
    try:
        final = source_snapshot(root, expected_head)
        if final != report["integrity"]:
            raise ValueError("SOURCE_SNAPSHOT_CHANGED")
        report["final_integrity"] = final
        report["source_immutable_verified"] = True
        return True
    except (ValueError, OSError) as exc:
        report["results"].append({"case": "final-integrity", "state": "FAIL", "reason": str(exc)[:100]})
        return False

def run_matrix(root, opt, report, created, clean_env):
    blocked = failed = False
    phps = []
    for version, configured in (("7.4", opt.php74), ("8.3", opt.php83)):
        binary = shutil.which(configured, path=clean_env["PATH"])
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
        if len(phps) != len(PHP):
            # Refuse to start databases for a partial PHP matrix.
            blocked = True
            break
        image = execv(["docker", "image", "inspect", "--format", "{{.Id}}", engine],
                      env=clean_env, cwd=root)
        if image.returncode:
            blocked = True
            report["results"].append({"case": engine, "state": "BLOCKED",
                                      "reason": "IMAGE_NOT_LOCAL_NO_AUTOPULL"})
            continue
        name = "mad4b-g8-cas-" + secrets.token_hex(7)
        token = secrets.token_hex(16)
        password = "g8_ci_only_" + secrets.token_urlsafe(24)
        health = ("healthcheck.sh --connect --innodb_initialized" if engine.startswith("mariadb")
                  else "mysqladmin ping --protocol=tcp -h 127.0.0.1 -uroot -p" + password)
        cmd = ["docker", "run", "--rm", "-d", "--pull=never", "--name", name,
               "--label", "mad4b.feature007.disposable=true", "--label", OWNERSHIP_LABEL + "=" + token,
               "-p", "127.0.0.1::3306", "-e", "MYSQL_ROOT_PASSWORD=" + password,
               "-e", "MYSQL_ROOT_HOST=%", "-e", "MYSQL_DATABASE=g8_ci_contract",
               "--health-cmd", health, "--health-interval", "5s",
               "--health-timeout", "4s", "--health-retries", "20", engine]
        # A timeout/nonzero CLI result can still leave a created container.
        created.append({"name": name, "token": token})
        launched = execv(cmd, env=clean_env, cwd=root, timeout=65)
        if launched.returncode:
            failed = True
            report["results"].append({"case": engine, "state": "FAIL",
                                      "reason": "DOCKER_DISPOSABLE_START_FAILED"})
            continue
        # Only loopback host bindings; a non-loopback mapping aborts.
        mapped = execv(["docker", "port", name, "3306/tcp"], env=clean_env, cwd=root)
        match = re.fullmatch(r"127\.0\.0\.1:([1-9][0-9]{3,4})", mapped.stdout.strip())
        if mapped.returncode or not match or not 1024 <= int(match.group(1)) <= 65535:
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
            state = "PASS" if (refused.returncode == 2 and tested.returncode == 0
                               and "G8_JOURNAL_TRANSACTION: PASS" in tested.stdout) else "FAIL"
            if state == "FAIL":
                failed = True
            report["results"].append({"case": case, "state": state,
                                      "safe_refusal_exit_2": refused.returncode == 2,
                                      "native_cas_exit_0": tested.returncode == 0,
                                      "journal_genesis_native_sql_marker": "G8_JOURNAL_TRANSACTION: PASS" in tested.stdout,
                                      "image_id": image.stdout.strip(),
                                      "stdout_sha256": hashlib.sha256(tested.stdout.encode()).hexdigest()})
    return blocked, failed

def self_test():
    """Fault-injection only: no Docker, native PHP, or database is executed."""
    import contextlib
    import io
    from types import SimpleNamespace
    from unittest.mock import patch
    head = "a" * 40
    initial = {"head": head, "source_tree_sha": "b" * 40,
               "runtime_source_sha256": {TEST: "c" * 64}}
    scenarios = ("success", "timeout_after_create", "rejected_without_create", "wrong_owner",
                 "remove_failed", "missing_docker", "missing_php", "head_changed",
                 "dirty_at_end", "source_changed", "missing_journal_marker")
    for scenario in scenarios:
        live, calls = {}, []
        snapshots = 0
        def snapshot(*arguments):
            nonlocal snapshots
            snapshots += 1
            if snapshots > 1:
                if scenario in ("head_changed", "dirty_at_end"):
                    raise ValueError("HEAD_MISMATCH" if scenario == "head_changed" else "WORKTREE_DIRTY")
                if scenario == "source_changed":
                    return dict(initial, runtime_source_sha256={TEST: "d" * 64})
            return dict(initial)
        def fake_exec(args, env=None, timeout=35, cwd=None):
            calls.append(args)
            assert env is not None and cwd is not None
            assert "DOCKER_HOST" not in env and "UNRELATED_SECRET" not in env
            assert Path(env["HOME"]).is_dir(), "Temporary HOME must survive cleanup"
            code, output = 0, ""
            if args[0] == "docker":
                if args[1:3] == ["image", "inspect"]:
                    output = "sha256:mock-image"
                elif args[1] == "run":
                    name = args[args.index("--name") + 1]
                    token = next(value.split("=", 1)[1] for value in args if value.startswith(OWNERSHIP_LABEL + "="))
                    password = next(value.split("=", 1)[1] for value in args if value.startswith("MYSQL_ROOT_PASSWORD="))
                    assert re.fullmatch(r"g8_ci_only_[A-Za-z0-9_-]{32}", password)
                    if scenario != "rejected_without_create":
                        live[name] = (format(len(live) + 1, "064x"), token)
                    code = 99 if scenario in ("timeout_after_create", "rejected_without_create") else 0
                elif args[1] == "port":
                    output = "127.0.0.1:49170"
                elif args[1] == "inspect":
                    if args[args.index("--format") + 1] == "{{.State.Health.Status}}":
                        output = "healthy"
                    elif args[-1] not in live:
                        code = 1
                    else:
                        identifier, token = live[args[-1]]
                        labels = {"mad4b.feature007.disposable": "true",
                                  OWNERSHIP_LABEL: "unrelated" if scenario == "wrong_owner" else token}
                        output = identifier + " " + json.dumps(labels)
                elif args[1:3] == ["container", "ls"]:
                    output = ""
                elif args[1] == "rm":
                    assert re.fullmatch(r"[a-f0-9]{64}", args[-1]), "Remove by observed owned ID only"
                    if scenario == "remove_failed":
                        code = 1
                    else:
                        name = next(name for name, row in live.items() if row[0] == args[-1])
                        del live[name]
            elif "-r" in args:
                output = "yes" if "extension_loaded" in args[-1] else "7.4" if "7.4" in args[0] else "8.3"
            else:
                code = 2 if env.get("G8_CAS_DATABASE") == "forbidden_database" else 0
                if code == 0 and args[-1] == TEST and scenario != "missing_journal_marker":
                    output = "G8_JOURNAL_TRANSACTION: PASS (mocked fixture marker only)"
            return SimpleNamespace(returncode=code, stdout=output, stderr="")
        def which(binary, **kwargs):
            if scenario == "missing_docker" and binary == "docker" or scenario == "missing_php" and binary == "php7.4":
                return None
            return "/fake/" + binary
        with tempfile.TemporaryDirectory(prefix="mad4b-db-selftest-") as tmp:
            report_path = Path(tmp) / "receipt.json"
            argv = ["matrix", "--allow-disposable-docker", "--expected-head", head, "--report", str(report_path)]
            with patch.dict(globals(), {"execv": fake_exec, "source_snapshot": snapshot}), \
                    patch.object(shutil, "which", side_effect=which), patch.object(sys, "argv", argv), \
                    patch.dict(os.environ, {"PATH": "/fake", "DOCKER_HOST": "untrusted", "UNRELATED_SECRET": "private"}, clear=True), \
                    contextlib.redirect_stdout(io.StringIO()):
                code = main()
            report = json.loads(report_path.read_text(encoding="utf-8"))
        expected = 0 if scenario == "success" else 2 if scenario in ("missing_docker", "missing_php") else 1
        assert code == expected, (scenario, report)
        assert report["source_immutable_verified"] == (scenario not in ("head_changed", "dirty_at_end", "source_changed"))
        if scenario == "wrong_owner":
            assert not any(args[0:2] == ["docker", "rm"] for args in calls)
        if scenario not in ("wrong_owner", "remove_failed"):
            assert not live and not report["containers_left"], (scenario, "Owned container leaked")
        else:
            assert len(report["containers_left"]) == len(live) == 2
        if scenario in ("missing_docker", "missing_php"):
            assert not any(args[0:2] == ["docker", "run"] for args in calls)
    print("DISPOSABLE_DB_MATRIX_SELFTEST_PASS; 10 mocked faults/scenarios; no Docker, PHP, or database tested")
    return 0

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
        return self_test()
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
    temporary = None
    clean_env = None
    blocked = False
    failed = False
    try:
        report["integrity"] = source_snapshot(root, opt.expected_head)
        # Never inherit an existing database endpoint (local or external).
        if any(name.startswith(("G8_CAS_", "MYSQL_", "MARIADB_")) for name in os.environ):
            raise ValueError("AMBIENT_DATABASE_VARIABLES_FORBIDDEN")
        if shutil.which("docker") is None:
            blocked = True
            report["results"].append({"case": "docker", "state": "BLOCKED", "reason": "DOCKER_UNAVAILABLE"})
        else:
            temporary = tempfile.TemporaryDirectory(prefix="mad4b-g8-matrix-")
            tmp = temporary.name
            clean_env = {"PATH": os.environ.get("PATH", ""), "HOME": tmp,
                         "TEMP": tmp, "TMP": tmp, "TMPDIR": tmp}
            for key in ("SystemRoot", "WINDIR", "PATHEXT"):
                if key in os.environ:
                    clean_env[key] = os.environ[key]
            blocked, failed = run_matrix(root, opt, report, created, clean_env)
    except (ValueError, OSError) as exc:
        failed = True
        report["results"].append({"case": "precondition", "state": "FAIL",
                                  "reason": str(exc)[:100]})
    finally:
        if created:
            report["containers_left"] = cleanup_owned(created, clean_env, root)
            if report["containers_left"]:
                failed = True
                report["results"].append({"case": "cleanup", "state": "FAIL",
                                          "reason": "OWNERSHIP_OR_REMOVAL_UNVERIFIED"})
        if temporary is not None:
            temporary.cleanup()
        if not final_integrity(report, root, opt.expected_head):
            failed = True
        states = [x["state"] for x in report["results"]]
        report["status"] = "FAIL" if failed or "FAIL" in states else "BLOCKED" if blocked or "BLOCKED" in states else "LOCAL_DISPOSABLE_DB_MATRIX_PASS_ONLY"
        report_path.parent.mkdir(parents=True, exist_ok=True)
        report_path.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        print(json.dumps({"status": report["status"], "report": str(report_path),
                          "cases": len(report["results"]), "containers_left": report["containers_left"]}))
    return 1 if report["status"] == "FAIL" else 2 if report["status"] == "BLOCKED" else 0

if __name__ == "__main__":
    sys.exit(main())
