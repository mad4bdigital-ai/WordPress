#!/usr/bin/env python3
"""Verify the stricter rc.129 WP-CLI recovery without weakening real WP acceptance.

The older #882 test pinned a different fallback that downloaded an unverified
rolling PHAR. The newer workflow uses a release-scoped PHAR plus SHA-256 check.
Keep the full real WordPress tests and validate the strengthened fallback.
"""
from pathlib import Path
import subprocess
import textwrap

repo = Path(__file__).resolve().parents[2]
workflow = (repo / ".github/workflows/feature-007-pre-staging-hybrid-audit.yml").read_text()
start = workflow.index("  adaptive-search-conformance:")
end = workflow.index("  disposable-database-cas:", start)
job = workflow[start:end]
setup = job.index("      - name: Set up PHP and WP-CLI")
recovery = job.index("      - name: Restore verified WP-CLI when setup-php tool installation fails")
provision = job.index("      - name: Provision disposable WordPress")
real = job.index("      - name: Verify real WordPress CAS discovery catalog UI and breaker")
final = job.index("      - name: Bind Adaptive Search gates to exact head and disposable evidence")
evidence = job.index("      - name: Upload exact-head Adaptive Search acceptance evidence")
assert setup < recovery < provision < real < final < evidence
assert "php: ['7.4', '8.3', '8.4', '8.5']" in job
assert "tools: wp-cli" in job
section = job[recovery:job.index("      - name:", recovery + 14)]
conditions = (
    "set -euo pipefail",
    "if command -v wp",
    "wp --info",
    "https://github.com/wp-cli/wp-cli/releases/download/v",
    ".phar.sha256",
    'curl --fail --location --retry 3',
    "sha256sum -c -",
    "INVALID_WPCLI_CHECKSUM",
    'php \"$phar\" --info',
    '>> \"$GITHUB_PATH\"',
    "ADAPTIVE_WPCLI_VERIFIED_FALLBACK: PASS",
)
for marker in conditions:
    assert marker in section, f"Missing a verified WP-CLI recovery requirement: {marker}"
assert "|| true" not in section
assert "continue-on-error:" not in job
assert "wp core download --path=" in job
assert job.count("wp eval-file ") == 3
assert "failed=0" in job and 'exit "$failed"' in job
assert "if-no-files-found: error" in job
shell_lines = section.split("        run: |\n", 1)[1].splitlines(keepends=True)
script = textwrap.dedent("".join(shell_lines))
run = subprocess.run(["bash", "-n"], input=script, text=True, capture_output=True)
assert run.returncode == 0, run.stderr
# The test must fail if source verification is removed, without editing the workflow.
for tampered in (
    section.replace("sha256sum -c -", "echo skipped checksum", 1),
    section.replace('>> \"$GITHUB_PATH\"', "# missing PATH export", 1),
):
    assert any(marker not in tampered for marker in ("sha256sum -c -", '>> \"$GITHUB_PATH\"'))
print("FEATURE007_ADAPTIVE_WPCLI_PHP74_VERIFIED_SUCCESSOR: PASS")
