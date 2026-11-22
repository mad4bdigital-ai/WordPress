#!/usr/bin/env python3
"""Verify exact native host observer and additive source Nginx patch release."""
import hashlib
import json
from pathlib import Path
P=Path(__file__).resolve().parent
lock=json.loads((P/"bundle-lock.json").read_text())
assert lock.get("contract")=="mad4b.cache-origin-observer-source-locked.v1"
actual={p.relative_to(P).as_posix() for d in ("bin","systemd","patches") for p in (P/d).iterdir() if p.is_file()}
assert len(actual)==6 and actual==set(lock["files"])
for file,digest in lock["files"].items():
 f=P/file
 assert not f.is_symlink() and f.is_file()
 assert hashlib.sha256(f.read_bytes()).hexdigest()==digest, file
assert lock["wayfa_live_classifier_enabled"] is False
assert lock["source_live_classifier_enabled"] is True
assert lock["production_write_authority"] is False
print(json.dumps({"contract":lock["contract"],"sha256_checked":len(actual),
 "wayfa_live_visitor_classifier":False,"source_live_visitor_classifier":True,
 "cache_policy_mutated":False,"owner_write_granted":False}))
