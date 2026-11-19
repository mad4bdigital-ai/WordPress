#!/usr/bin/env python3
"""Verify or rebuild the immutable native-navigation asset and signed catalog.

This is a source build step, never called inside WordPress or visitor requests.
The original JavaScript remains authoritative; minification is intentionally
not invented here. The historical 2026-11-13 minifier manifest is not changed.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / 'wp-content/plugins/mad4b-platform/legacy-mu-tree/assets/mad4b-navigation-lazy-listings.js'
DIR = ROOT / 'wp-content/mu-plugins/mad4b-navigation-lazy-listings'
LOADER = ROOT / 'wp-content/mu-plugins/mad4b-navigation-lazy-listings.php'
CATALOG = ROOT / 'ops/company-code-release-v1/catalog/mad4b-release-components.json'
COMPONENT = 'mad4b-navigation-lazy-immutable-v1'
LOADER_PATH = 'wp-content/mu-plugins/mad4b-navigation-lazy-listings.php'


def validate(write: bool = False) -> dict:
    assert SOURCE.is_file() and LOADER.is_file()
    data = SOURCE.read_bytes()
    digest = hashlib.sha256(data).hexdigest()
    target = DIR / f'navigation.{digest[:12]}.js'
    expected = 'wp-content/mu-plugins/mad4b-navigation-lazy-listings/' + target.name
    loader_source=LOADER.read_text()
    pattern=r"private const ASSET_SHA256 = '[a-f0-9]{64}';"
    found=re.findall(pattern,loader_source)
    if len(found)!=1: raise ValueError('PINNED_NAV_ASSET_SHA_MISSING_OR_DUPLICATED')
    desired="private const ASSET_SHA256 = '"+digest+"';"
    if write and found[0]!=desired:
        LOADER.write_text(loader_source.replace(found[0],desired))
    elif found[0]!=desired:
        raise ValueError('NAV_LOADER_SHA_DOES_NOT_MATCH_CANONICAL_JS')
    if write:
        DIR.mkdir(parents=True, exist_ok=True)
        # Remove only recognized prior hashed artifacts, never arbitrary files.
        for old in DIR.glob('navigation.*.js'):
            if old.name != target.name and len(old.name) == len('navigation.' + digest[:12] + '.js'):
                if old.is_symlink(): raise ValueError('symlink asset must not be removed blindly')
                old.unlink()
        if target.is_symlink(): raise ValueError('symlink asset denied')
        if not target.exists() or target.read_bytes() != data:
            target.write_bytes(data)
    if not target.is_file() or target.is_symlink() or target.read_bytes() != data:
        raise ValueError('IMMUTABLE_NAV_ASSET_ABSENT_OR_STALE:' + expected)
    catalog = json.loads(CATALOG.read_text())
    items = [x for x in catalog['components'] if x.get('id') == COMPONENT]
    if len(items) != 1: raise ValueError('NAV_COMPONENT_MISSING_OR_DUPLICATED')
    paths = [LOADER_PATH, expected]
    if write:
        items[0]['files'] = paths
        CATALOG.write_text(json.dumps(catalog, indent=2, ensure_ascii=False) + '\n')
    if items[0]['files'] != paths:
        raise ValueError('NAV_RELEASE_COMPONENT_NOT_PINNED_TO_LATEST_SHA')
    src = LOADER.read_text()
    if 'self::ASSET_SHA256' not in src or 'hash_equals($digest' not in src:
        raise ValueError('IMMUTABLE_LOADER_HASH_GUARD_ABSENT')
    minifier = ROOT / 'wp-content/plugins/mad4b-platform/legacy-mu-tree/assets/mad4b-static-js'
    stale = json.loads((minifier / 'manifest.json').read_text())
    if 'mad4b-navigation-lazy-listings' in stale['assets']:
        raise ValueError('STALE_NAV_MINIFIER_SOURCE_BINDING')
    for other in minifier.glob('navigation-listings.*.min.js'):
        raise ValueError('STALE_NAV_MINIFIED_ASSET:' + other.name)
    return {'code_sha256': digest, 'asset': expected, 'component': COMPONENT, 'release_paths': paths}


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--check', action='store_true', help='read-only verification (default)')
    parser.add_argument('--write', action='store_true', help='rebuild only owned immutable source artifacts')
    args = parser.parse_args()
    if args.check and args.write: parser.error('--check and --write are mutually exclusive')
    print(json.dumps(validate(write=args.write), ensure_ascii=False))
