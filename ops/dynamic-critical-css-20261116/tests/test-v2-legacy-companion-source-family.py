#!/usr/bin/env python3
"""No writes to WordPress: immutable original V2 paired-source hash proof."""
import importlib.util,hashlib,sys,tempfile,shutil
from pathlib import Path
D=Path(sys.argv[1]);wp=Path(sys.argv[2])
spec=importlib.util.spec_from_file_location('original_v2_family_companion',D/'public-v2-family-canary.py')
v2=importlib.util.module_from_spec(spec);spec.loader.exec_module(v2)
checks=0
def ok(label,pred):
 global checks
 assert pred,label
 checks+=1;print('PASS',label)
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
a=wp/'mu-plugins/421-mad4b-public-critical-css-coverage.php'
b=wp/'mu-plugins/422-mad4b-public-critical-css-v2-consumer.php'
assert sha(a)==v2.ORDERED_421_SHA and sha(b)==v2.ORDERED_422_SHA
with tempfile.TemporaryDirectory() as folder:
 root=Path(folder);mu=root/'mu-plugins';mu.mkdir();shutil.copy2(a,mu/a.name)
 def probe(cert,installed):return v2.original_approved_companion_receipt(root,cert,installed)
 ok('original V2 SHA remains recognized only with exact new 421+422 pair',
    probe(v2.LEGACY_COMPANION_SHA,sha(b)))
 ok('currently installed same SHA accepted on originally qualified routes',
    probe(sha(b),sha(b)))
 ok('unlisted V2 SHA refused',not probe('b'*64,sha(b)))
 ok('null or malformed SHA refused',all(not probe(v,sha(b)) for v in (None,'a'*63,'garbage')))
 ok('unsigned modified installed companion refused',not probe(v2.LEGACY_COMPANION_SHA,'c'*64))
 (mu/a.name).write_bytes(b'non-firstparty-421')
 ok('changed source 421 cannot grandfather legacy cert',not probe(v2.LEGACY_COMPANION_SHA,sha(b)))
 ok('same/current SHA alone is not grandfathered by a false legacy claim',not probe(v2.LEGACY_COMPANION_SHA,'d'*64))
 (mu/a.name).unlink()
 ok('missing source 421 fails closed',not probe(v2.LEGACY_COMPANION_SHA,sha(b)))
 (mu/a.name).symlink_to(b)
 ok('source 421 symlink fails closed',not probe(v2.LEGACY_COMPANION_SHA,sha(b)))
 ok('wrongly interpreted intermediate consumer SHA refused',not probe('a'*64,sha(b)))
 ok('no automatic CSS grant, cert publish or WordPress option edit',
    'def publish(' not in (D/'public-v2-family-canary.py').read_text()
    and 'update_option(' not in (D/'public-v2-family-canary.py').read_text())
print('PASS',checks,'exact backward-compatible V2 source census test')
