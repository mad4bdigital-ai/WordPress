#!/usr/bin/env python3
"""Pinned Source Runtime must preserve WPML Arabic normalization and V2."""
from pathlib import Path
import hashlib,sys,importlib.util
D=Path(sys.argv[1]);root=D.parents[1]
php=(root/'wp-content/mu-plugins/422-mad4b-public-critical-css-runtime.php').read_bytes()
consumer=(root/'wp-content/mu-plugins/422-mad4b-public-critical-css-v2-consumer.php').read_bytes()
h=lambda b:hashlib.sha256(b).hexdigest()
runner=(D/'run-public-route.py').read_text()
text="'source':('"+h(php)+"',\n                  '"+h(consumer)+"')"
assert runner.count(text)==2,('source source pair mismatch',runner.count(text))
assert 'normalized_escaped_path' in php.decode('utf8')
assert 'MAD4B_Public_Critical_CSS_Consumer_V2::transform' in php.decode('utf8')
assert runner.count("'wayfa':('597a31f0ea962d2700ead68f9856596727a4e07055650705f52ba57bbaf11119'")==2
assert runner.count("approved_stage_422='254c382bb96faadda2510d75501107ac73ae80673093dd3c0a47a5f63f7f2ff6'")==1
print('PASS 5/5 Source exact pinned PHP V2, Arabic WordPress continuity and Wayfa isolation')
