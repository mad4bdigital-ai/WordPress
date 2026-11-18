#!/usr/bin/env python3
"""Root-only live/Git drift observer; never publishes, approves, or repairs."""
import fcntl
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import time

CONFIG = Path("/etc/mad4b/live-git-parity.v1.json")
POLICY = Path("/etc/mad4b/company-code-release-policy.json")
OUT = Path("/opt/mad4b-company-provisioning/reports/live-git-parity-v1")
SHA = re.compile(r"^[0-9a-f]{40}$")

def digest(blob):
    return hashlib.sha256(blob).hexdigest()

def root_json(path):
    st = path.lstat()
    if not stat.S_ISREG(st.st_mode) or st.st_uid != 0 or st.st_nlink != 1 or st.st_mode & 0o077:
        raise ValueError("UNTRUSTED_ROOT_POLICY")
    if path.is_symlink() or st.st_size > 200000:
        raise ValueError("POLICY_FILE_INVALID")
    return json.loads(path.read_bytes())

def save_once(path, data):
    path.parent.mkdir(parents=True, mode=0o700, exist_ok=True)
    if path.exists():
        if path.is_symlink() or path.read_bytes() != data:
            raise ValueError("SNAPSHOT_CONTENT_COLLISION")
        return
    fd = os.open(path, os.O_CREAT | os.O_EXCL | os.O_WRONLY | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, "wb") as stream:
        stream.write(data)
        stream.flush()
        os.fsync(stream.fileno())

def inspect_live(root, relative):
    rel = PurePosixPath(relative)
    if (relative != rel.as_posix() or len(rel.parts) < 3 or
        rel.parts[0] != "wp-content" or ".." in rel.parts):
        raise ValueError("INVALID_POLICY_RELATIVE_PATH")
    p = root / relative
    for item in (p, *p.parents):
        if item == root.parent:
            break
        if item.is_symlink():
            raise ValueError("LIVE_PATH_SYMLINK")
    try:
        st = p.stat()
    except FileNotFoundError:
        return None, None
    if not stat.S_ISREG(st.st_mode) or st.st_size > 4000000 or st.st_nlink != 1:
        raise ValueError("INVALID_LIVE_TARGET")
    blob = p.read_bytes()
    after = p.stat()
    if (after.st_ino, after.st_mtime_ns, after.st_size) != (st.st_ino, st.st_mtime_ns, st.st_size):
        raise ValueError("LIVE_CHANGED_DURING_READ")
    return blob, st

def git_blob(repo, commit, relative):
    proc = subprocess.run(["git", "-C", str(repo), "show", commit + ":" + relative],
                          capture_output=True, timeout=20, check=False)
    if proc.returncode:
        return None
    if len(proc.stdout) > 4000000:
        raise ValueError("GIT_BLOB_TOO_LARGE")
    return proc.stdout

def run():
    cfg = root_json(CONFIG)
    pol = root_json(POLICY)
    commit = cfg["comparison_commit"]
    repo = Path(cfg["canonical_git_root"])
    if (cfg.get("contract") != "mad4b.live-git-parity-policy.v1" or
        not SHA.fullmatch(commit) or not repo.is_dir() or repo.is_symlink() or
        cfg.get("monitored_scopes") != sorted(pol["targets"])):
        raise ValueError("SOURCE_OR_SCOPE_NOT_PINNED")
    valid = subprocess.run(["git", "-C", str(repo), "cat-file", "-e", commit + "^{commit}"],
                           capture_output=True, timeout=15, check=False)
    if valid.returncode:
        raise ValueError("GIT_COMMIT_NOT_AVAILABLE")
    OUT.mkdir(parents=True, exist_ok=True, mode=0o700)
    if OUT.stat().st_uid != 0 or stat.S_IMODE(OUT.stat().st_mode) != 0o700 or OUT.is_symlink():
        raise ValueError("UNTRUSTED_REPORT_DIRECTORY")
    rows = []
    for scope in cfg["monitored_scopes"]:
        target = pol["targets"][scope]
        root = Path(target["root"])
        if not root.is_dir() or root.is_symlink():
            raise ValueError("LIVE_SCOPE_ROOT_INVALID")
        for relative in target["allowed_targets"]:
            live, st = inspect_live(root, relative)
            source = git_blob(repo, commit, relative)
            entry = {"scope": scope, "path": relative, "release_authorized": False}
            if live is not None:
                entry.update(live_sha256=digest(live), live_size=len(live),
                             live_mtime_ns=st.st_mtime_ns)
                save_once(OUT / "snapshots" / scope / (digest(live) + ".php-or-asset"), live)
            if source is not None:
                entry["source_sha256"] = digest(source)
            entry["state"] = ("LIVE_MISSING" if live is None else
                              "SOURCE_MISSING" if source is None else
                              "MATCH" if live == source else "DIVERGED_HOLD")
            rows.append(entry)
    summary = {s: sum(row["state"] == s for row in rows)
               for s in ("MATCH", "DIVERGED_HOLD", "SOURCE_MISSING", "LIVE_MISSING")}
    report = {"contract": "mad4b.live-git-drift-observation.v1",
              "created_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
              "source_ref": commit, "source_ref_is_diagnostic_only": True,
              "canonical_git_root": str(repo), "policy_sha256": digest(POLICY.read_bytes()),
              "summary": summary, "rows": rows, "word_press_mutated": False,
              "release_authorized": False, "original313_receipt_issued": False}
    data = (json.dumps(report, ensure_ascii=False, sort_keys=True, indent=2) + "\n").encode()
    tmp = OUT / (".latest." + str(os.getpid()) + ".tmp")
    fd = os.open(tmp, os.O_CREAT | os.O_EXCL | os.O_WRONLY | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, "wb") as stream:
        stream.write(data)
        stream.flush()
        os.fsync(stream.fileno())
    os.replace(tmp, OUT / "latest.json")
    dirfd = os.open(OUT, os.O_RDONLY | os.O_DIRECTORY)
    os.fsync(dirfd)
    os.close(dirfd)
    print(json.dumps({"state": "DRIFT_OBSERVED_NO_RELEASE", "counts": summary,
                      "report": str(OUT / "latest.json"), "report_sha256": digest(data)}))

if __name__ == "__main__":
    OUT.mkdir(parents=True, exist_ok=True, mode=0o700)
    lock = OUT / ".observer.lock"
    fd = os.open(lock, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        run()
    finally:
        os.close(fd)

