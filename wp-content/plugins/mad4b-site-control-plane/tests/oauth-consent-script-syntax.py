#!/usr/bin/env python3
"""Parse-check only the passive consent refresh inline JS, never execute it."""
from __future__ import annotations
import pathlib
import re
import shutil
import subprocess
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
source = (ROOT / "includes/class-mad4b-scp-local-oauth-server.php").read_text(encoding="utf-8")
# Exactly one passive-poll closure, with a bounded interval and backoff.
# The static parser must not depend on one retired constant pair.
intervals = list(re.finditer(r";const BASE=(\d+),MAX=(\d+);", source))
end_marker = "})();</script>"
if len(intervals) != 1 or source.count(end_marker) != 1:
    raise SystemExit("CONSENT_SCRIPT_BOUNDARY_INVALID")
baseline, maximum = (int(x) for x in intervals[0].groups())
if not (15000 <= baseline <= 60000 and baseline <= maximum <= 180000):
    raise SystemExit("CONSENT_POLLING_BOUNDS_INVALID")
start = intervals[0].start()
end = source.index(end_marker, start) + len("})();")
js = "(function(){const u='https://example.invalid',nonce='fixture'" + source[start:end]
required = (
    "developer_execution_blockers", "developer-host-state", "Readback unavailable",
    "lastFingerprint=\"\"", "No displayable grant names", "textContent",
)
for token in required:
    if token not in js:
        raise SystemExit("CONSENT_JS_EXPECTED_GUARD_MISSING:" + token)
if "innerHTML" in js or "eval(" in js:
    raise SystemExit("CONSENT_JS_UNSAFE_RENDERER")
node = shutil.which("node")
if node is None:
    raise SystemExit("CONSENT_JS_NODE_REQUIRED")
with tempfile.TemporaryDirectory(prefix="mad4b-consent-syntax-") as directory:
    target = pathlib.Path(directory) / "consent.js"
    target.write_text(js + "\n", encoding="utf-8")
    result = subprocess.run([node, "--check", str(target)], capture_output=True, text=True)
    if result.returncode:
        raise SystemExit("CONSENT_JS_SYNTAX_FAILED:" + (result.stderr or "").strip()[:500])
print("OAUTH_CONSENT_JS_SYNTAX: PASS")
