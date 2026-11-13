#!/usr/bin/env python3
"""Read exact certified MCP Adapter CI target metadata; never download or install.

The build matrix selects a test version. Neither WordPress environment labels
nor provider metadata can authorize an installation. Archive and critical
file SHA256 values must still match the immutable reviewed profile.
"""
from __future__ import annotations
import argparse
import json
from pathlib import Path
import re
import shlex
from urllib.parse import urlsplit

SHA = re.compile(r"[a-f0-9]{64}\Z")
TARGET = re.compile(r"target-(\d+\.\d+\.\d+)\Z")

class ProfileDenied(ValueError):
    pass

def resolve(config: Path, label: str) -> dict[str, str]:
    match = TARGET.fullmatch(label)
    if not match:
        raise ProfileDenied('ADAPTER_CI_TARGET_LABEL_INVALID')
    version = match.group(1)
    config = Path(config)
    policy = json.loads((config / 'runtime-release-policy.json').read_text())
    if (policy.get('provider') != 'mcp_adapter'
            or policy.get('adapter_release_target_source')
               != 'certified_runtime_release_set.mcp_adapter'):
        raise ProfileDenied('CERTIFIED_ADAPTER_SOURCE_CONTRACT_CHANGED')
    catalog = json.loads((config / 'certified-provider-profiles.json').read_text())
    profiles = catalog.get('providers', {}).get('mcp_adapter', {})
    profile = profiles.get(version)
    if not isinstance(profile, dict) or profile.get('version') != version:
        raise ProfileDenied('EXACT_CERTIFIED_ADAPTER_PROFILE_MISSING')
    archive_sha = profile.get('archive_sha256')
    critical = profile.get('critical_files') or {}
    autoload_sha = critical.get('vendor/autoload_packages.php')
    archive_bytes = profile.get('archive_bytes')
    url = profile.get('package_url')
    if (not isinstance(archive_sha, str) or SHA.fullmatch(archive_sha) is None
            or not isinstance(autoload_sha, str) or SHA.fullmatch(autoload_sha) is None
            or type(archive_bytes) is not int or archive_bytes <= 0
            or not isinstance(url, str) or len(url) > 2048
            or urlsplit(url).scheme != 'https' or not urlsplit(url).hostname
            or urlsplit(url).username or urlsplit(url).password):
        raise ProfileDenied('EXACT_CERTIFIED_ADAPTER_DIGEST_OR_URL_INVALID')
    return {
        'TARGET_VERSION': version,
        'TARGET_SHA': archive_sha,
        'TARGET_BYTES': str(archive_bytes),
        'TARGET_URL': url,
        'TARGET_AUTOLOAD_SHA': autoload_sha,
    }

def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--profile', required=True)
    parser.add_argument('--config-dir', type=Path,
                        default=Path(__file__).resolve().parents[1] /
                          'wp-content/plugins/mad4b-site-control-plane/config')
    args = parser.parse_args()
    values = resolve(args.config_dir, args.profile)
    for key, value in values.items():
        print(f'{key}={shlex.quote(value)}')

if __name__ == '__main__':
    main()
