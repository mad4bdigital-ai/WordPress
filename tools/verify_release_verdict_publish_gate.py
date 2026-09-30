#!/usr/bin/env python3
from pathlib import Path
import re

workflow = Path(".github/workflows/mad4b-release-verdict.yml")
text = workflow.read_text(encoding="utf-8")
errors = []

def require(condition: bool, message: str) -> None:
    if not condition:
        errors.append(message)

require(
    "artifact_required: ${{ steps.emit_verdict.outputs.artifact_required }}" in text,
    "verdict job must expose artifact_required from emit_verdict",
)
require(
    re.search(
        r"(?ms)^      - name: Emit machine-readable release verdict\n"
        r"        id: emit_verdict\n",
        text,
    ) is not None,
    "machine-readable verdict step must have id=emit_verdict",
)
require(
    "fh.write('artifact_required=' + ('true' if artifact_required else 'false') + '\\n')" in text,
    "verdict step must publish artifact_required to GITHUB_OUTPUT",
)

marker = "  publish-control-plane-update-channel:\n"
require(text.count(marker) == 1, "publish-control-plane-update-channel job must exist exactly once")
publish = text.split(marker, 1)[1] if marker in text else ""

for required in (
    "needs:\n      - verdict",
    "github.event_name == 'push'",
    "github.ref == 'refs/heads/master'",
    "needs.verdict.result == 'success'",
    "needs.verdict.outputs.artifact_required == 'true'",
):
    require(required in publish, f"publish job missing gate: {required}")

require(
    "Resolve exact successful canonical package run" in publish,
    "artifact-required publish path must retain exact package discovery",
)

if errors:
    raise SystemExit("\n".join(f"release verdict publish-gate contract: FAIL: {e}" for e in errors))

print("release verdict publish-gate contract: PASS")
