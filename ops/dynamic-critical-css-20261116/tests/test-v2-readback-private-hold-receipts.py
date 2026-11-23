#!/usr/bin/env python3
"""No fabricated certificate or ACK from fail-closed delivery diagnostics."""
import pathlib,tempfile,sys,importlib.util,json
d=pathlib.Path(sys.argv[1])
spec=importlib.util.spec_from_file_location('v2_private_hold_receipt',d/'public-v2-existing-readback.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
checks=0
def ck(cond,label):
 global checks
 assert cond,label
 checks+=1;print('PASS',label)
with tempfile.TemporaryDirectory() as tmp:
 root=pathlib.Path(tmp)/'wayfa';root.mkdir()
 p=root/'20261121T054510Z';p.mkdir()
 output=p/'v2-postqa-origin-readback.json'
 ck(m.record_bounded_hold('wayfa',output,ValueError('Elementor-source-graph-changed'),root),
   'exact known private post-release output has a hold receipt')
 hold=output.with_name('v2-postqa-origin-readback.hold.json')
 j=json.loads(hold.read_text())
 ck(j['reason']=='Elementor-source-graph-changed','exact failure cause is recorded')
 ck(j['status']=='hold_no_qualification' and not j['wordpress_ack_issued_by_this_step']
    and not j['critical_css_certificate_issued_by_this_step'],
    'receipt never grants certification or WordPress ACK')
 ck(not output.exists(),'failure diagnostic does not forge a success receipt')
 bad=root/'20261121T054510Z'/'elsewhere.json'
 ck(not m.record_bounded_hold('wayfa',bad,ValueError('bad'),root),
    'arbitrary output filename refused')
 ck(not m.record_bounded_hold('source',output,ValueError('bad'),root),
    'cross-company output refused')
 ck(not m.record_bounded_hold('wayfa',root/'file',ValueError('bad'),root),
    'untrusted parent path refused')
 hold.unlink()
 hold.symlink_to(root/'unsafe')
 ck(not m.record_bounded_hold('wayfa',output,ValueError('bad'),root),
    'symlink to private receipt refused')
 ck(hold.is_symlink() and not (root/'unsafe').exists(),'no symlink follow performed')
print(f'PASS {checks}/{checks} bounded post-release source hold diagnostics')
