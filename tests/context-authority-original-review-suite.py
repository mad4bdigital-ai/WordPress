#!/usr/bin/env python3
"""Exact original Source Control Context Authority behavior, nonauthorizing.

Runs only reviewed, hermetic, unchanged PHP fixtures that require the actual
Context Authority source, with explicit positive assertions. The test suite
does not invoke WordPress, external providers, or any enrolled Production DB.
"""
from pathlib import Path
import re
import shutil
import subprocess

ROOT = Path(__file__).resolve().parents[1]
TEST_ROOT = ROOT / "wp-content/plugins/mad4b-site-control-plane/tests"
FIXTURES = {
    "context-registry-atomicity-runtime.php": (
        "PASS: CONTEXT_RECYCLE_VAULT_CAPACITY",
        "mad4b.site-control-plane.context-registry-atomicity.runtime.v3: PASS",
    ),
    "brand-asset-source-binding-hermetic.php": (
        "BRAND_ASSET_SOURCE_BINDING: PASS",
    ),
    "context-ai-agent-review-runtime.php": (
        "PASS: CONTEXT_AI_CROSS_ENV_REVIEW",
    ),
    "context-unbound-census-runtime.php": (
        "mad4b.site-control-plane.context-unbound-census.runtime.v1: PASS",
    ),
    "context-scan-completeness-runtime.php": (
        "mad4b.site-control-plane.context-scan-completeness.runtime.v4: PASS",
    ),
    "context-source-write-policy-runtime.php": (
        "mad4b.site-control-plane.context-source-write-policy.runtime.v7: PASS",
    ),
    "context-site-binding-runtime.php": (
        "mad4b.site-control-plane.context-site-binding.runtime.v1: PASS",
    ),
}


def check():
    php = shutil.which("php")
    if php is None:
        raise AssertionError("Native PHP interpreter required")
    results = []
    for filename, markers in FIXTURES.items():
        source = TEST_ROOT / filename
        assert source.is_file() and not source.is_symlink(), "No unreviewed PHP path"
        script = source.read_text(encoding="utf-8", errors="replace")
        assert "class-mad4b-scp-context-authority.php" in script, (
            f"Original source not included in fixture {filename}"
        )
        result = subprocess.run(
            [php, str(source)], cwd=TEST_ROOT,
            capture_output=True, text=True, timeout=45, check=False
        )
        good = result.returncode == 0 and all(
            marker in result.stdout for marker in markers
        ) and not re.search(
            r"(?m)^(?:FAIL|Fatal error|Parse error|Warning):?",
            result.stdout + "\n" + result.stderr
        )
        if not good:
            raise AssertionError(
                f"{filename}: missing assertion or failure (code {result.returncode})\n"
                + result.stdout[-1500:] + result.stderr[-900:]
            )
        results.append(filename)
    assert len(results) == 7
    print("PASS: CONTEXT_AUTHORITY_ORIGINAL_REVIEW 7 source bound PHP regression fixtures")


if __name__ == "__main__":
    check()
