#!/usr/bin/env python3
"""Enforce exact source-backed packaging in disposable Local OAuth CI.

This is a source/CI diagnostic only, not provider certification, owner
attestation, Staging deployment or Production install authority.
"""
from pathlib import Path
import sys

root = Path(__file__).resolve().parents[4]
workflow = root / ".github/workflows/mad4b-local-oauth-standalone.yml"
if not workflow.is_file() or workflow.is_symlink():
    raise SystemExit("LOCAL_OAUTH_PACKAGED_SOURCE_WORKFLOW_MISSING")
source = workflow.read_text(encoding="utf-8")
tokens = (
    'SOURCE_SHA="$(git rev-parse HEAD)"',
    'test -z "$(git status --porcelain --untracked-files=all)"',
    'ADAPTER_SHA="$(sha256sum "$ADAPTER_ARCHIVE"',
    'build-deterministic-control-plane-package.py"',
    '--source-sha "$SOURCE_SHA"',
    '--adapter-version "$EXPECTED_ADAPTER_VERSION" --adapter-sha "$ADAPTER_SHA"',
    'from mad4b_standalone_build import verify_zip',
    'verify_zip(folder / "control-plane.zip", receipt, source)',
    'materialize-packaged-plugin.py"',
    '--archive "$PACKAGE_DIR/control-plane.zip"',
    '--plugins-dir "$WP_PATH/wp-content/plugins" --slug mad4b-site-control-plane',
    'test -s "$WP_PATH/wp-content/plugins/mad4b-site-control-plane/MAD4B-BUILD-PROVENANCE.json"',
)
for token in tokens:
    # The same audited materializer handles both the certified MCP Adapter
    # and the exact Control Plane package. All other bindings are unique.
    required = 2 if token == 'materialize-packaged-plugin.py"' else 1
    if source.count(token) != required:
        raise SystemExit("LOCAL_OAUTH_MANAGED_PACKAGE_SOURCE_DRIFT: " + token)
build = source.index('build-deterministic-control-plane-package.py"')
verify = source.index('verify_zip(folder / "control-plane.zip", receipt, source)')
materialize = source.index('--slug mad4b-site-control-plane')
activate = source.index('php /tmp/wp-cli.phar plugin activate mad4b-site-control-plane')
if not (build < verify < materialize < activate):
    raise SystemExit("LOCAL_OAUTH_PACKAGED_SOURCE_ORDER_INVALID")
if 'cp -R wp-content/plugins/mad4b-site-control-plane "$WP_PATH/wp-content/plugins/mad4b-site-control-plane"' in source:
    raise SystemExit("LOCAL_OAUTH_RAW_SOURCE_INSTALL_UNVERIFIED")
if 'mad4b_runtime_cache_convergence_pending' in source:
    raise SystemExit("LOCAL_OAUTH_CACHE_PROVENANCE_DENIAL_OVERRIDE_FORBIDDEN")
for expected in ('--root "$PACKAGE_DIR/source/mad4b-site-control-plane"',
                 'test "$INSTALLED_ADAPTER_VERSION" = "$EXPECTED_ADAPTER_VERSION"'):
    if expected not in source:
        raise SystemExit("LOCAL_OAUTH_SOURCE_BINDING_MISSING: " + expected)
print("LOCAL_OAUTH_MANAGED_SOURCE_PACKAGE_CONTRACT: PASS")
