#!/usr/bin/env python3
"""Exact rc129 early-loader and cache-convergence safety review.

This check produces no release authorization. Its SHA binding is separately
verified by the pinned QA31 reviewed-successor ledger and Git object identity.
"""
from pathlib import Path
import hashlib
import subprocess

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / 'wp-content/plugins/mad4b-site-control-plane'
MCP = PLUGIN / 'bootstrap/mad4b-mcp-adapter-mu-bootstrap.php'
CACHE = PLUGIN / 'bootstrap/mad4b-runtime-cache-convergence-mu.php'


def check(source, markers, rejected):
    assert source.is_file() and not source.is_symlink(), source
    contents = source.read_text()
    for marker in markers:
        assert marker in contents, (source.name, 'missing', marker)
    for marker in rejected:
        assert marker not in contents, (source.name, 'forbidden', marker)
    return hashlib.sha256(source.read_bytes()).hexdigest()


def main():
    mcp = check(MCP, (
        "'eligible' => false", "'request_requires_mcp_runtime' => false",
        "'plugin_identity_ambiguous' => false",
        "count( $mad4b_mcp_mu_control_plane_matches ) > 1",
        "count( $mad4b_mcp_mu_adapter_matches ) > 1",
        "'GET' === ($_SERVER['REQUEST_METHOD'] ?? '')",
        "'profile_work_deferred_public_get'",
        "request_scope_bypassed",
        "array( 'eligible' => false )",
    ), (
        "opcache_reset(", "shell_exec(", "eval(", "create_user(",
    ))
    cache = check(CACHE, (
        "'mutation_authorized' => false",
        "'global_reset_used' => false",
        "'production' !== $environment",
        "const LOCAL_OVERLAY_LIMIT = 65536;",
        "const LOCAL_OVERLAY_FILES_LIMIT = 128;",
        "fileowner( $overlay_file )",
        "hash_equals( $binding",
        "base_manifest_sha256",
        "base_source_commit_sha",
        "is_link( $overlay_file )",
        "opcache_invalidate(",
    ), (
        "opcache_reset(", "shell_exec(", "wp_remote_post(",
    ))
    # The review is grounded in real PHP source, not a generated stub.
    assert len(MCP.read_bytes()) > 9000 and len(CACHE.read_bytes()) > 9000
    print('QA31_RC129_BOOTSTRAP_BOUNDARY: PASS',
          'MCP', mcp[:12], 'Cache', cache[:12],
          'review_only=True', 'production_authorized=False')


if __name__ == '__main__':
    main()
