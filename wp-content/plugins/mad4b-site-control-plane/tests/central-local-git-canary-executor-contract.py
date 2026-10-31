#!/usr/bin/env python3
"""Hermetic source contract: the bounded local Git executor cannot dispatch Production."""
from pathlib import Path
import ast
import sys

ROOT = Path(__file__).resolve().parents[4]
SOURCE = ROOT / "ops" / "central-rollout" / "local-git-staging-canary-executor.py"
assert SOURCE.is_file(), SOURCE
source = SOURCE.read_text()
ast.parse(source)
required = [
    'CONTAINER = "etg-commerce-staging-php"',
    'PLUGIN = "mad4b-deploy-canary"',
    'REPO = Path("/opt/staging/mad4b-local-git-canary-20261031/source")',
    '"rollout_scope": "staging_canary_only"',
    '"source_type": "local_git"',
    'def assert_site()',
    'def perform_native_cycle(',
    'def independent_readback(',
    'def sign_verified_receipt(',
    'wp("plugin", "delete", PLUGIN)',
    'independent_readback(expected_sha)',
    'if plugin_installed():',
    'if REPO.stat().st_uid != 0',
    'if command(["git", "-C", str(REPO), "status", "--porcelain"]) != ""',
    'if dest.exists():',
    'if sys.argv[1:] != ["run-registered-staging-canary"]:',
]
for line in required:
    assert line in source, "missing bound safety rule " + line
for forbidden in ["--allow-root", "wayfatours-production-php", '"company_production"', "shell=True", "eval(", "test_attestation"]:
    assert forbidden not in source, "unreviewed runtime expansion " + forbidden
print("MAD4B_LOCAL_GIT_STAGING_CANARY_EXECUTOR_CONTRACT: PASS")
