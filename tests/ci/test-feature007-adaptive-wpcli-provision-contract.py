#!/usr/bin/env python3
"""Prove PHP 7.4 WP-CLI provisioning remains fail-closed and full-site."""
from pathlib import Path
import subprocess
import textwrap

repo = Path(__file__).resolve().parents[2]
workflow = (repo / ".github/workflows/feature-007-pre-staging-hybrid-audit.yml").read_text()
start = workflow.index("  adaptive-search-conformance:")
end = workflow.index("  disposable-database-cas:", start)
job = workflow[start:end]
setup = job.index("      - name: Set up PHP and WP-CLI")
ensure = job.index("      - name: Ensure WP-CLI executable for the disposable WordPress runtime")
provision = job.index("      - name: Provision disposable WordPress")
real = job.index("      - name: Verify real WordPress CAS discovery catalog UI and breaker")
final = job.index("      - name: Bind Adaptive Search gates to exact head and disposable evidence")
evidence = job.index("      - name: Upload exact-head Adaptive Search acceptance evidence")
assert setup < ensure < provision < real < final < evidence
assert "php: ['7.4', '8.3', '8.4', '8.5']" in job
assert "tools: wp-cli" in job
section = job[ensure:job.index("      - name:", ensure + 14)]
assert "if command -v wp" in section and "wp --info" in section
assert "curl --fail --location --retry 3" in section
assert "phar/wp-cli.phar" in section
assert 'exec php "$(dirname "$0")/wp-cli.phar" "$@"' in section
assert '"$GITHUB_PATH"' in section
assert "set -euo pipefail" in section
assert "|| true" not in section
shell_lines = section.split("        run: |\n", 1)[1].splitlines(keepends=True)
script = textwrap.dedent("".join(shell_lines))
run = subprocess.run(["bash", "-n"], input=script, text=True, capture_output=True)
assert run.returncode == 0, run.stderr
assert "wp core download --path=" in job
assert job.count("wp eval-file ") == 3
assert "failed=0" in job and 'exit "$failed"' in job
assert "if-no-files-found: error" in job
assert "continue-on-error:" not in job
print("FEATURE007_ADAPTIVE_WPCLI_PHP74_CONTRACT: PASS")
