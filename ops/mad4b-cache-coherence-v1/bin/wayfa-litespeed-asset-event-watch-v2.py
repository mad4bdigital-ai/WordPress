#!/usr/bin/env python3
"""Event-driven Wayfatours CSS/JS and FastCGI coherence.

Linux inotify (libc, no package/Chromium). Never parses visitors' cookies,
rewrites WordPress data, grants authorities or runs an untrusted shell command.
The existing scoped SHA-aware guard owns all edits; this daemon only schedules it.
"""
from __future__ import annotations

import argparse
import ctypes
import errno
import json
import os
from pathlib import Path
import re
import select
import signal
import struct
import subprocess
import sys
import tempfile
import time

ASSETS = Path("/opt/wayfa-rebuild-20261102/site-copy/wp-content/litespeed")
CACHE_CONTAINER = "nginx-guxhp9y6vgh7oouuw8plxrlr"
GUARD = "/opt/mad4b-company-provisioning/bin/wayfa-litespeed-fastcgi-asset-coherence-v1.py"
LOCK = "/run/lock/mad4b-wayfa-tour-asset-coherence.lock"
REPORT = Path("/opt/mad4b-company-provisioning/reports/wayfa-tour-blank-20261121/event-watch-v2-current.json")

IN_CLOSE_WRITE = 0x00000008
IN_MOVED_FROM = 0x00000040
IN_MOVED_TO = 0x00000080
IN_CREATE = 0x00000100
IN_DELETE = 0x00000200
IN_DELETE_SELF = 0x00000400
IN_MOVE_SELF = 0x00000800
IN_Q_OVERFLOW = 0x00004000
IN_IGNORED = 0x00008000
IN_ISDIR = 0x40000000
MASK = (IN_CLOSE_WRITE | IN_MOVED_FROM | IN_MOVED_TO | IN_CREATE |
        IN_DELETE | IN_DELETE_SELF | IN_MOVE_SELF)
EVENT = struct.Struct("iIII")
HASHED = re.compile(r"^[a-f0-9]{32}\.(?:css|js)(?:\.gz)?$")
CACHEFILE = re.compile(r"^[0-9a-f]{32}$")
_HEALTH_INTERVAL = 10.0
_FAST_REPAIR_DEBOUNCE = 0.25
_NEW_ASSET_STABLE_DELAY = 5.2
_MIN_RUN_INTERVAL = 1.0


class Inotify:
    def __init__(self):
        self.libc = ctypes.CDLL(None, use_errno=True)
        self.libc.inotify_init1.argtypes = [ctypes.c_int]
        self.libc.inotify_init1.restype = ctypes.c_int
        self.libc.inotify_add_watch.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_uint32]
        self.libc.inotify_add_watch.restype = ctypes.c_int
        self.fd = self.libc.inotify_init1(os.O_NONBLOCK | os.O_CLOEXEC)
        if self.fd < 0:
            raise OSError(ctypes.get_errno(), "inotify_init1")
        self.wds = {}

    def add(self, path: Path, kind: str):
        if not path.is_dir() or path.is_symlink():
            raise RuntimeError("UNTRUSTED_WATCH_DIRECTORY")
        if not any(p == path for p, _ in self.wds.values()):
            wd = self.libc.inotify_add_watch(self.fd, os.fsencode(str(path)), MASK)
            if wd < 0:
                raise OSError(ctypes.get_errno(), "inotify_add_watch")
            self.wds[wd] = (path, kind)

    def get_events(self, timeout_ms: int):
        if timeout_ms < 0 or timeout_ms > 1000:
            raise ValueError("INVALID_POLL_TIMEOUT")
        poll = select.poll()
        poll.register(self.fd, select.POLLIN)
        if not poll.poll(timeout_ms):
            return []
        data = os.read(self.fd, 1024 * 128)
        events = []
        offset = 0
        while offset + EVENT.size <= len(data):
            wd, flags, cookie, length = EVENT.unpack_from(data, offset)
            offset += EVENT.size
            if offset + length > len(data):
                raise RuntimeError("TRUNCATED_INOTIFY_EVENT")
            name = data[offset:offset + length].split(b"\0", 1)[0].decode("utf-8", "replace")
            offset += length
            events.append((wd, flags, name))
            if flags & IN_IGNORED:
                self.wds.pop(wd, None)
        return events

    def close(self):
        os.close(self.fd)


def cache_dir() -> tuple[Path, int]:
    q = subprocess.run(
        ["/usr/bin/docker", "inspect", "--format", "{{.State.Pid}}", CACHE_CONTAINER],
        timeout=7, capture_output=True, text=True, check=True,
    )
    pid = int(q.stdout.strip())
    if pid < 2:
        raise RuntimeError("NGINX_NOT_RUNNING")
    root = Path(f"/proc/{pid}/root/var/cache/nginx/wayfa")
    if not root.is_dir() or root.is_symlink() or root.stat().st_uid != 33:
        raise RuntimeError("NGINX_CACHE_SCOPE_UNTRUSTED")
    return root, pid


def register(watch: Inotify, cache_root: Path):
    for folder in (ASSETS, ASSETS / "css", ASSETS / "js"):
        watch.add(folder, "asset")
    watch.add(cache_root, "cache")
    for level1 in cache_root.iterdir():
        if level1.is_dir() and not level1.is_symlink() and re.fullmatch(r"[0-9a-f]", level1.name):
            watch.add(level1, "cache")
            for level2 in level1.iterdir():
                if (level2.is_dir() and not level2.is_symlink() and
                        re.fullmatch(r"[0-9a-f]{2}", level2.name)):
                    watch.add(level2, "cache")


def execute_guard() -> dict:
    result = subprocess.run(
        ["/usr/bin/flock", "-n", LOCK, "/usr/bin/python3", GUARD, "--repair"],
        timeout=35, capture_output=True, text=True,
    )
    if result.returncode:
        return {"status": "LOCK_BUSY" if result.returncode == 1 else "FAILED",
                "code": result.returncode, "error": result.stderr[-200:]}
    try:
        d = json.loads(result.stdout.strip().splitlines()[-1])
    except (ValueError, IndexError):
        return {"status": "INVALID_RECEIPT"}
    return {"status": "PASS",
            "broken": d.get("fastcgi_invalid_objects_found", -1),
            "evicted": d.get("fastcgi_invalid_objects_evicted", -1),
            "restored": d.get("restored_missing_public_bundles", -1),
            "archived": d.get("archived_or_updated", -1),
            "errors": d.get("errors", [])[:6]}


def write_receipt(counters: dict, reason: str, result: dict):
    data = {
        "contract": "mad4b.wayfa-event-driven-asset-coherence.v2",
        "site": "wayfatours.com",
        "observed_utc": __import__("datetime").datetime.now(
            __import__("datetime").timezone.utc).isoformat(),
        "trigger": reason,
        "counters": counters,
        "repair": result,
        "external_browser_tests": False,
        "production_grants_changed": False,
        "source_code_synchronized": False,
    }
    REPORT.parent.mkdir(parents=True, exist_ok=True)
    fd, p = tempfile.mkstemp(prefix=".event-watch-v2-", dir=str(REPORT.parent))
    try:
        with os.fdopen(fd, "w") as stream:
            json.dump(data, stream, sort_keys=True)
            stream.write("\n")
        os.chmod(p, 0o600)
        os.replace(p, REPORT)
    finally:
        Path(p).unlink(missing_ok=True)


def selftest():
    with tempfile.TemporaryDirectory(prefix="mad4b-inotify-test-") as tmp:
        base = Path(tmp)
        watch = Inotify()
        try:
            watch.add(base, "test")
            name = "0123456789abcdef0123456789abcdef.js"
            f = base / name
            f.write_bytes(b"hashed-bundle-test")
            events = watch.get_events(500)
            assert any(n == name and m & IN_CLOSE_WRITE for _, m, n in events), events
            f.unlink()
            events = watch.get_events(500)
            assert any(n == name and m & IN_DELETE for _, m, n in events), events
            print(json.dumps({"contract": "mad4b.wayfa-inotify-hermetic-regression.v2",
                              "status": "PASS", "asset_create_close_write": True,
                              "asset_delete": True, "external_browser": False}))
        finally:
            watch.close()


def daemon():
    counters = {"inotify_events": 0, "runs": 0, "restarts": 0, "lock_busy": 0, "failures": 0,
                "asset_events": 0, "cache_events": 0, "overflow": 0}
    force = [False]
    signal.signal(signal.SIGUSR1, lambda *_: force.__setitem__(0, True))
    signal.signal(signal.SIGTERM, lambda *_: sys.exit(0))
    signal.signal(signal.SIGINT, lambda *_: sys.exit(0))
    watch = None
    pid = None
    next_health = 0
    due_fast = None
    due_delayed = None
    last_run = 0
    reason = "startup"
    while True:
        now = time.monotonic()
        if now >= next_health:
            new_root, new_pid = cache_dir()
            if watch is None or new_pid != pid:
                if watch:
                    watch.close()
                    counters["restarts"] += 1
                watch = Inotify()
                pid = new_pid
                due_fast = now + 0.25
                reason = "startup_or_nginx_rebind"
            register(watch, new_root)
            next_health = now + _HEALTH_INTERVAL
        if force[0]:
            force[0] = False
            due_fast = now + 0.05
            reason = "explicit_internal_signal"
        events = watch.get_events(500)
        now = time.monotonic()
        has_new_dirs = False
        for wd, flags, name in events:
            counters["inotify_events"] += 1
            if flags & IN_Q_OVERFLOW:
                counters["overflow"] += 1
                due_fast = now + 0.15
                reason = "kernel_queue_overflow"
                has_new_dirs = True
                continue
            entry = watch.wds.get(wd)
            if not entry:
                has_new_dirs = True
                continue
            path, kind = entry
            if (flags & IN_ISDIR) and (flags & (IN_CREATE | IN_MOVED_TO | IN_DELETE_SELF | IN_MOVE_SELF)):
                has_new_dirs = True
            if kind == "asset":
                if (path == ASSETS and name not in ("css", "js") and
                        not (flags & (IN_DELETE_SELF | IN_MOVE_SELF))):
                    continue
                if path != ASSETS and name and not HASHED.fullmatch(name):
                    continue
                counters["asset_events"] += 1
                if flags & (IN_DELETE | IN_MOVED_FROM | IN_DELETE_SELF | IN_MOVE_SELF):
                    due_fast = now + _FAST_REPAIR_DEBOUNCE if due_fast is None else min(due_fast, now + _FAST_REPAIR_DEBOUNCE)
                    reason = "hashed_asset_removed"
                elif flags & (IN_CLOSE_WRITE | IN_MOVED_TO | IN_CREATE):
                    due_delayed = now + _NEW_ASSET_STABLE_DELAY if due_delayed is None else max(due_delayed, now + _NEW_ASSET_STABLE_DELAY)
                    reason = "hashed_asset_generated"
            elif kind == "cache":
                if not (flags & (IN_CLOSE_WRITE | IN_MOVED_TO | IN_DELETE_SELF | IN_MOVE_SELF)):
                    continue
                if name and not (CACHEFILE.fullmatch(name) or flags & IN_ISDIR):
                    continue
                counters["cache_events"] += 1
                due_fast = now + _FAST_REPAIR_DEBOUNCE if due_fast is None else min(due_fast, now + _FAST_REPAIR_DEBOUNCE)
                reason = "fastcgi_object_written"
        if has_new_dirs:
            next_health = min(next_health, now + 0.5)
        should_run = ((due_fast is not None and now >= due_fast) or
                      (due_delayed is not None and now >= due_delayed))
        if should_run and now - last_run >= _MIN_RUN_INTERVAL:
            started = time.monotonic()
            try:
                result = execute_guard()
            except (OSError, subprocess.TimeoutExpired) as exc:
                result = {"status": "FAILED", "error": str(exc)[:160]}
            last_run = time.monotonic()
            counters["runs"] += 1
            if result["status"] == "LOCK_BUSY":
                counters["lock_busy"] += 1
                due_fast = last_run + 1.0
            elif result["status"] != "PASS":
                counters["failures"] += 1
                due_fast = last_run + 3.0
            else:
                due_fast = None
                if due_delayed is not None and started >= due_delayed:
                    due_delayed = None
            write_receipt(counters, reason, result)
            print(json.dumps({"trigger": reason, "result": result,
                              "duration_ms": int(1000*(last_run-started)),
                              "runs": counters["runs"]}), flush=True)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    if args.self_test:
        selftest()
    else:
        daemon()
