#!/usr/bin/env python3
"""Regression gate: MAD4B core is site-neutral, while fixtures stay per-site.

Only enforce on general runtime PHP and the new shared remote execution tools.
Historical ARE/ETG acceptance documents, legacy fixture datasets and the
separately specialized ETG plugin are intentionally outside this code gate.
"""
from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "wp-content/plugins/mad4b-site-control-plane/includes"
SHARED_TOOLS = ROOT / "tools/mad4b-remote-execution"
SHARED_TESTS = ROOT / "tests/remote-execution"
SHARED_WORKFLOWS = sorted((ROOT / ".github/workflows").glob("mad4b-operator-*.yml"))

DENIED = (
    ("customer_domain", re.compile(r"\b(?:staging\.)?(?:allroyalegypt|egypttourgates)\.com\b", re.I)),
    ("hosting_account_path", re.compile(r"/home/u[0-9]{6,}/")),
    ("site_specific_runner", re.compile(r"are-staging-(?:unified|ssh)-v1")),
    ("old_shared_path", re.compile(r"tools/are-staging-unified-remote-plane")),
)
errors = []
checked = 0
for path in sorted(PLUGIN.glob("*.php")) + sorted(SHARED_TOOLS.glob("*")) + sorted(SHARED_TESTS.glob("*")) + SHARED_WORKFLOWS:
    if not path.is_file() or path.suffix not in (".php", ".py", ".yml"):
        continue
    checked += 1
    try:
        lines = path.read_text(encoding="utf-8").splitlines()
    except UnicodeDecodeError:
        errors.append(f"{path.relative_to(ROOT)}:invalid_utf8")
        continue
    for n, line in enumerate(lines, 1):
        for code, pattern in DENIED:
            if pattern.search(line):
                errors.append(f"{path.relative_to(ROOT)}:{n}:{code}")
if not SHARED_WORKFLOWS or not (SHARED_TOOLS / "operator-seal-verifier.py").exists():
    errors.append("missing_shared_remote_tooling")
if errors:
    print("FAIL shared MAD4B runtime/remote portability")
    print("\n".join(errors[:80]))
    sys.exit(1)
print(f"PASS {checked} shared runtime, tools, tests and workflow files contain no site-specific host/path/profile bindings")
