#!/usr/bin/env python3
import tempfile,pathlib,importlib.util,hashlib,sys
modpath=pathlib.Path(sys.argv[1])/'mad4b-mobile-cache-prime-v2.py';spec=importlib.util.spec_from_file_location('prime',modpath);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
total=0
def check(desc,works):
 global total
 assert works,desc
 total+=1
 print('PASS',desc)
def reject(desc,body,headers,manifest,origin):
 try:m.admit_css(body,headers,manifest,origin)
 except RuntimeError:check(desc,True)
 else:raise AssertionError(desc)
check('native-only proof remains pending CCSS, not READY',m.css_certification_state({'/|anonymous':'native_full_verified'})=='awaiting_ccss')
check('mixed native/critical remains pending CCSS',m.css_certification_state({'/|anonymous':'critical_verified','/es/|anonymous':'native_full_verified'})=='awaiting_ccss')
check('all independently certified critical paths mark READY',m.css_certification_state({'/|anonymous':'critical_verified','/es/|anonymous':'critical_verified'})=='ready')
for bad in ({}, {'/':'not_verified'}):
 try:m.css_certification_state(bad)
 except ValueError:check('missing/untrusted CSS mode rejected',True)
 else:raise AssertionError('false CSS ready')
with tempfile.TemporaryDirectory() as td:
 root=pathlib.Path(td)/'wp-content';manifest=root/'mad4b-critical-css-v1/manifest.json';manifest.parent.mkdir(parents=True)
 content=b'/* original full LiteSpeed CSS */'+b'a'*102000
 cssname=hashlib.md5(content).hexdigest()+'.css'
 css=root/'litespeed/css'/cssname;css.parent.mkdir(parents=True);css.write_bytes(content)
 origin='https://wayfatours.com'
 link=f'<link data-optimized="2" rel="stylesheet" href="{origin}/wp-content/litespeed/css/{cssname}?ver=123" />'
 body='<html><head>'+link+('</head><body>')+('hello '*6000)+'</body></html>'
 native={m.READY_HEADER:'0',m.NATIVE_HEADER:'1'}
 check('trusted native proof and exact MD5-bound full bundle',m.admit_css(body,native,manifest,origin)=='native_full_verified')
 criticalbody='<html><head><style id="mad4b-critical-css-v1">' + ('body{color:red;}'*2000) + '</style>'+'<link id="mad4b-css-rest-v1" href="'+origin+'/wp-content/litespeed/css/'+cssname+'" />'+'<script data-mad4b-css-async-lib="inline-v1"></script></head><body>'+('a'*26000)+'</body></html>'
 check('verified exact critical proof remains recognized independently',m.admit_css(criticalbody.replace('id="mad4b-css-rest-v1" href=', 'id="mad4b-css-rest-v1" rel="stylesheet" media="print" onload="this.media=all" href='),{m.READY_HEADER:'1',m.NATIVE_HEADER:'0'},manifest,origin)=='critical_verified')
 capbody=criticalbody.replace('<style id="mad4b-critical-css-v1">','<style id="mad4b-critical-css-v1" media="(max-width:767px)">')
 capbody=capbody.replace('id="mad4b-css-rest-v1" href=','id="mad4b-css-rest-v1" rel="stylesheet" media="(min-width:768px)" onload="this.media=all" href=')
 check('measured phone-only 767px contract accepted',m.admit_css(capbody,{m.READY_HEADER:'1',m.NATIVE_HEADER:'0'},manifest,origin)=='critical_verified')
 reject('phone certificate cannot use all-width async CSS',capbody.replace('media="(min-width:768px)"','media="print"'),{m.READY_HEADER:'1',m.NATIVE_HEADER:'0'},manifest,origin)
 reject('all-width critical cannot claim tablet-only full CSS',capbody.replace('media="(max-width:767px)"',''),{m.READY_HEADER:'1',m.NATIVE_HEADER:'0'},manifest,origin)
 reject('critical async CSS link must include onload',capbody.replace('onload="this.media=all"',''),{m.READY_HEADER:'1',m.NATIVE_HEADER:'0'},manifest,origin)
 reject('foreign critical CSS URL rejected',capbody.replace('wayfatours.com','attacker.invalid'),{m.READY_HEADER:'1',m.NATIVE_HEADER:'0'},manifest,origin)
 reject('critical source media drift rejected',capbody.replace('min-width:768px','min-width:800px'),{m.READY_HEADER:'1',m.NATIVE_HEADER:'0'},manifest,origin)
 reject('native proof requires WordPress final native header',body,{m.READY_HEADER:'0',m.NATIVE_HEADER:'0'},manifest,origin)
 reject('native cannot masquerade as critical readiness',body,{m.READY_HEADER:'1',m.NATIVE_HEADER:'0'},manifest,origin)
 reject('native proof missing is refused',body,{},manifest,origin)
 reject('contradictory ready and native proofs refused',body,{m.READY_HEADER:'1',m.NATIVE_HEADER:'1'},manifest,origin)
 reject('critical markers without critical proof refused',criticalbody,native,manifest,origin)
 reject('critical claims without minimum verified style cannot qualify',criticalbody.replace('body{color:red;}'*2000,'body{color:red;}'),{m.READY_HEADER:'1',m.NATIVE_HEADER:'0'},manifest,origin)
 reject('foreign source URL refused',body.replace('wayfatours.com','attacker.example'),native,manifest,origin)
 reject('multiple native optimizer bundles rejected',body.replace('</head>',link+'</head>'),native,manifest,origin)
 reject('nonrenderblocking native CSS rejected',body.replace('rel="stylesheet"','rel="stylesheet" media="print"'),native,manifest,origin)
 reject('native async onload not verified',body.replace('rel="stylesheet"','rel="stylesheet" onload="bad()"'),native,manifest,origin)
 reject('unmatched asset hash rejected',body,native,manifest,origin.replace('wayfatours.com','foo.invalid'))
 css.write_bytes(content+b' changed')
 reject('stale source asset with changed MD5 cannot pass',body,native,manifest,origin)
 css.unlink()
 reject('missing native CSS asset cannot pass',body,native,manifest,origin)
 css.symlink_to('/etc/passwd')
 reject('symlinked native CSS asset denied',body,native,manifest,origin)
 check('zero false critical certification from native mode',m.admit_css(capbody,{m.READY_HEADER:'1',m.NATIVE_HEADER:'0'},manifest,origin)=='critical_verified')
print(f'PASS {total}/{total} dual-mode CSS fail-closed invariants')
