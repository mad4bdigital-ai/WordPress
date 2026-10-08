#!/usr/bin/env python3
"""Parse-check only the passive consent refresh inline JS, never execute it."""
from __future__ import annotations
import pathlib
import shutil
import subprocess
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
source = (ROOT / "includes/class-mad4b-scp-local-oauth-server.php").read_text(encoding="utf-8")
prefix = ";const BASE=15000,MAX=60000;"
end_marker = "})();</script>"
if source.count(prefix) != 1 or source.count(end_marker) != 1:
    raise SystemExit("CONSENT_SCRIPT_BOUNDARY_INVALID")
start = source.index(prefix)
end = source.index(end_marker, start) + len("})();")
js = "const u='https://example.invalid',nonce='fixture'" + source[start:end]
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
