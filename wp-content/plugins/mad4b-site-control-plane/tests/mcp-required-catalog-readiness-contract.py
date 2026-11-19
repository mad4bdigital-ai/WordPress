#!/usr/bin/env python3
"""Run the actual bundled MCP Adapter negative regressions as CI-only proof.

This is deliberately a disposable, offline source test: never claim to have
validated a live WordPress catalog, Host grant or production release.
"""
import json
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[4]
PLUGIN = ROOT / 'wp-content/plugins/mad4b-site-control-plane'
MATERIALIZER = PLUGIN / 'tests/materialize-packaged-plugin.py'
SOURCE = PLUGIN / 'tests/mcp-official-required-preflight-runtime.php'
ARCHIVE = ROOT / 'wp-content/plugins/mcp-adapter.zip'
MARKERS = (
    'PASS bundled Adapter:',
    'PASS governed Operator Grants direct step-up official DTO',
    'PASS real DTO faults: empty input, invalid name filter, identity collision and size budgets',
)

with tempfile.TemporaryDirectory(prefix='mad4b-required-mcp-offline-') as temp:
    plugin_folder = Path(temp) / 'plugins'
    prepared = subprocess.run(
        [sys.executable, str(MATERIALIZER), '--archive', str(ARCHIVE),
         '--plugins-dir', str(plugin_folder), '--slug', 'mcp-adapter'],
        cwd=str(ROOT), capture_output=True, text=True, timeout=45, check=True,
    )
    provenance = json.loads(prepared.stdout)
    if provenance.get('status') != 'passed' or provenance.get('slug') != 'mcp-adapter':
        raise RuntimeError('PACKAGED_MCP_ADAPTER_PROVENANCE_UNVERIFIED')
    result = subprocess.run(
        ['php', str(SOURCE), str(plugin_folder / 'mcp-adapter')],
        cwd=str(ROOT), capture_output=True, text=True, timeout=55, check=False,
    )
    if result.returncode != 0 or not all(marker in result.stdout for marker in MARKERS):
        # Bounded exception: never serialize unknown provider error bodies or secrets.
        raise RuntimeError('REQUIRED_MCP_CATALOG_REGRESSION_FAILED:exit=' + str(result.returncode))
    print('MCP_REQUIRED_CATALOG_FAIL_CLOSED_PASS; packaged official Adapter, source-only, no live authority')
