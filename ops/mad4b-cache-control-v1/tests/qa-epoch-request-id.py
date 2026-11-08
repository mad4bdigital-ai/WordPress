#!/usr/bin/env python3
"""Root-only fixture: exercise request-id host receipt without real Nginx reload."""
import importlib.util, json, tempfile, os, sys, pathlib
src='/usr/local/libexec/mad4b-nginx-public-html-epoch-v1.py'
spec=importlib.util.spec_from_file_location('mad4bepoch',src)
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
with tempfile.TemporaryDirectory(prefix='mad4b-epoch-contract-') as t:
 p=pathlib.Path(t)
 config=p/'nginx.conf'; config.write_bytes(m.conf_with_epoch(b'fastcgi_cache_key "$host|$mad4b_fastcgi_version";\n'))
 signal=p/'signal';rid='0123456789abcdef01234567'
 signal.write_bytes(('MAD4B_SAFE_PUBLIC_HTML_ALL_V2\n'+rid+'\n').encode())
 os.chown(signal,33,33)
 receipt=p/'receipt.json';state=p/'state'
 m.STATE=state
 m.SITES={'fixture':{'conf':config,'signal':signal,'container':'dummy','status':receipt}}
 checks=[]
 m.check_reload=lambda container,verb:checks.append((container,verb))
 sys.argv=[src,'--site','fixture']
 m.main()
 a=json.loads(receipt.read_text())
 b=json.loads((state/'fixture.json').read_text())
 assert a['status']=='ok' and a['request_id']==rid and b['request_id']==rid
 assert a['redis_mutated'] is False and a['cache_keys_rotated_sitewide'] is True
 assert checks==[('dummy','-t'),('dummy','-s')]
 assert a['new_epoch'] in json.dumps(b)
 print('HOST_RECEIPT_REQUEST_ID_FIXTURE_PASS',rid,checks)

