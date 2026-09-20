#!/usr/bin/env python3
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
scan=(ROOT/'tests/scan-packaged-providers.py').read_text('utf-8')
baseline=(ROOT/'config/certified-providers.json').read_text('utf-8')

for marker in [
    'def scan_composite_provider(',
    'def _component_header(',
    'def _find_component_file(',
    'archive_sha256_mismatch',
    'plugin_version_mismatch',
    'critical_file_manifest_missing',
    'critical_file_hash_mismatch',
    'expected.get("components")',
]:
    assert marker in scan, marker

for marker in [
    '"wp-import-export"',
    '"wp-all-import-pro.zip"',
    '"wp-all-export-pro.zip"',
    '"critical_files"',
]:
    assert marker in baseline, marker

# Composite support must strengthen, not bypass, the baseline.
assert 'required_contracts_pass' in scan
assert 'failures.append(provider)' in scan
assert 'compare_baseline(report, baseline_path)' in scan

print('mad4b.composite-packaged-provider-scan.contract.v1: PASS')
