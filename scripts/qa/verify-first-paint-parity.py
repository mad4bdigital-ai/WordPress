#!/usr/bin/env python3
"""Check the two enrolled first-party tenants retain the same native first-paint engine.
Uses only explicit file paths passed by the operator; no WordPress database writes.
"""
from pathlib import Path
import argparse,hashlib,sys
parser=argparse.ArgumentParser()
parser.add_argument('--source',type=Path,required=True)
parser.add_argument('--company',type=Path,required=True)
parser.add_argument('--expected-sha256',required=True)
args=parser.parse_args()
assert len(args.expected_sha256)==64 and all(c in '0123456789abcdef' for c in args.expected_sha256)
out={}
for name,p in (('source',args.source),('company',args.company)):
    assert p.is_file() and not p.is_symlink()
    digest=hashlib.sha256(p.read_bytes()).hexdigest()
    assert digest==args.expected_sha256,(name,'SHA_MISMATCH',digest)
    out[name]=digest
code=args.source.read_text()
assert "get_option('mad4b_perf_mobile_city_preload_v1','auto')" in code
assert "MAD4B_Delayed_Hero_YT::responsive_lcp_enabled()" in code
assert "MAD4B_Delayed_Hero_YT::mobile_hero_priority()" in code
assert "data-mad4b-first-city=" in code
print('SHARED_FIRST_PAINT_ENGINE_PARITY',out,'native_hero_adaptation=yes')
