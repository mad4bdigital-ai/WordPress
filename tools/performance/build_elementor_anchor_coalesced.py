#!/usr/bin/env python3
"""Build, do not vendor-copy, the exact pinned Elementor observer coalescing.

Original frontend.min.js remains byte-for-byte untouched. This script only
accepts a known SHA and a single reviewed observer snippet, then generates a
content-addressed first-party WP mu-plugin asset. Default is dry-run.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import os
from pathlib import Path
import tempfile

ORIGINAL_SHA = 'bbfe6403b4ddba29284c72d20f000154d04588d60850fc39a6fd65861c6d83e6'
PATCH_SHA = 'a2ff13fb4b2d624179d1304fe7fc02771e512c157f624351d5f2d4dbed7a578c'
ASSET_NAME = 'mad4b-elementor-anchor-coalesced-bbfe6403.js'
OLD = 'observeStickyElements(e){new MutationObserver(t=>{for(let n of t)(n.type===`childList`||n.type===`attributes`&&n.target.classList.contains(`elementor-sticky`))&&e()}).observe(document.body,{childList:!0,subtree:!0,attributes:!0,attributeFilter:[`class`,`style`]})}'
NEW = 'observeStickyElements(e){let r=0;new MutationObserver(t=>{for(let n of t)if(n.type===`childList`||n.type===`attributes`&&n.target.classList.contains(`elementor-sticky`)){r||(r=requestAnimationFrame(()=>{r=0;e()}));break}}).observe(document.body,{childList:!0,subtree:!0,attributes:!0,attributeFilter:[`class`,`style`]})}'

def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()

def transform(data: bytes, *, enforce_sha: bool = True) -> bytes:
    if enforce_sha and sha(data) != ORIGINAL_SHA:
        raise ValueError('ELEMENTOR_VENDOR_SHA_DRIFT: refusing a different plugin release')
    text = data.decode('utf-8')
    if text.count(OLD) != 1 or NEW in text:
        raise ValueError('ELEMENTOR_OBSERVER_CONTRACT_DRIFT: exact old callback missing')
    result = text.replace(OLD, NEW, 1).encode('utf-8')
    if enforce_sha and sha(result) != PATCH_SHA:
        raise ValueError('ELEMENTOR_PATCH_HASH_MISMATCH: abort')
    return result

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--wp-content', required=True, type=Path)
    ap.add_argument('--apply', action='store_true')
    args = ap.parse_args()
    base = args.wp_content.resolve(strict=True)
    vendor = base / 'plugins/elementor/assets/js/frontend.min.js'
    target = base / 'mu-plugins/assets' / ASSET_NAME
    if vendor.is_symlink() or target.is_symlink():
        raise ValueError('SYMLINK_REFUSED')
    data = vendor.read_bytes()
    product = transform(data)
    receipt = {'contract': 'mad4b.elementor-anchor-observer.pinned.v1',
        'original_sha256': sha(data), 'patch_sha256': sha(product),
        'vendor_unchanged': True, 'target': str(target),
        'applied': False, 'preexisting': target.is_file()}
    if target.exists():
        if sha(target.read_bytes()) != PATCH_SHA:
            raise ValueError('EXISTING_ASSET_CONFLICT')
        receipt['already_correct'] = True
    elif args.apply:
        if not target.parent.is_dir():
            raise ValueError('MISSING_FIRST_PARTY_ASSETS_DIR')
        fd, temp = tempfile.mkstemp(prefix='.mad4b-elementor-', dir=target.parent)
        try:
            with os.fdopen(fd, 'wb') as f:
                f.write(product)
                f.flush()
                os.fsync(f.fileno())
            os.chmod(temp, 0o644)
            if target.exists():
                raise ValueError('TARGET_CREATED_CONCURRENTLY')
            os.replace(temp, target)
        finally:
            if os.path.exists(temp):
                os.unlink(temp)
        receipt['applied'] = True
    print(json.dumps(receipt, sort_keys=True))

if __name__ == '__main__':
    main()
