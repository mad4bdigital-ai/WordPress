#!/usr/bin/env python3
"""Execute the existing full Context review harness for AI-only in CI."""
from pathlib import Path
import subprocess
root = Path(__file__).resolve().parents[1]
source = (root / "includes/class-mad4b-scp-context-authority.php").read_text()
ui = (root / "includes/class-mad4b-scp-context-admin-ui.php").read_text()
assert "array( 'human_only', 'human_and_ai', 'ai_only' )" in source
assert "'ai_only_final_review' =>" in source
assert "AI-only Review" in ui
assert "'review_evidence' => array(" in source
assert "'classification_proposal' => array(" in source
assert "mad4b_context_ai_only_evidence_required" in source
fixture = root / "tests/context-ai-agent-review-runtime.php"
for command in (["php", "-l", str(fixture)], ["php", str(fixture)]):
    result = subprocess.run(command, cwd=root, text=True, capture_output=True, timeout=90, check=False)
    if result.returncode or (len(command) == 2 and "context-ai-agent-review.runtime.v1: PASS" not in result.stdout):
        raise SystemExit("AI_ONLY_REVIEW_REGRESSION_FAIL\n" + result.stdout + result.stderr)
print("AI_ONLY_REVIEW_REGRESSION: PASS")
