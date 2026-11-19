#!/usr/bin/env python3
"""Verify overlay bytes match live Wayfatours before any future release."""
from pathlib import Path
import hashlib,sys
base=Path(sys.argv[1]).resolve()
over=base/'ops/market-html-variance-20261118/wayfa-live-overlay/wp-content'
site=Path('/opt/wayfa-rebuild-20261102/site-copy/wp-content')
source=base/'ops/market-html-variance-20261118/wayfa-nginx-private-signed-home.conf'
live=Path('/opt/wayfa-rebuild-20261102/stack/nginx.conf')
verified=0
for entry in sorted(over.rglob('*')):
    if entry.is_dir():continue
    assert entry.is_file() and not entry.is_symlink(),str(entry)
    target=site/entry.relative_to(over)
    assert target.is_file() and not target.is_symlink(),str(target)
    assert hashlib.sha256(entry.read_bytes()).digest()==hashlib.sha256(target.read_bytes()).digest(),str(target)
    verified+=1
assert source.read_bytes()==live.read_bytes(),'Nginx config drift'
print('LIVE_WAYFA_EXACT_SOURCE_PARITY',verified,'WordPress files + live Nginx')
