import hashlib,importlib.util,json,sys,tempfile
from pathlib import Path
spec=importlib.util.spec_from_file_location('source_recheck',Path(sys.argv[1])/'verify-source-revision.py')
mod=importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
with tempfile.TemporaryDirectory() as td:
 p=Path(td);public=p/'observed';public.mkdir()
 css=p/'css';css.mkdir()
 file='a'*32+'.css';blob=b'body{padding:5px}'*3000
 (css/file).write_bytes(blob)
 document='<body class="page-id-90 elementor-9 elementor-90"><h1>Test</h1></body>'
 html='<html><head><link rel="stylesheet" href="https://example.test/wp-content/litespeed/css/'+file+'"></head>'+document+'</html>'
 (public/'en.html').write_text(html);(public/'en.headers').write_text('HTTP/2 200\r\n')
 body_sha=hashlib.sha256('9,90'.encode()).hexdigest()
 row={'lang':'en','css_file':file,'source_sha':hashlib.sha256(blob).hexdigest(),'dom_sha':body_sha,'page_id':90}
 item={'contract':'mad4b.mobile-critical.candidates.v1','candidates':[row],'errors':[]}
 def check(label,valid):
  failed=False
  try:mod.verify(item,public,css)
  except ValueError:failed=True
  assert failed is not valid,label
  print('PASS',label)
 check('exact source unchanged after QA',True)
 (public/'en.html').write_text(html.replace('elementor-9','elementor-10'))
 check('Elementor graph changed after QA',False)
 (public/'en.html').write_text(html)
 (css/file).write_bytes(blob+b'changed')
 check('CSS bytes changed while QA ran',False)
 (css/file).write_bytes(blob)
 (public/'en.headers').write_text('HTTP/2 502\n')
 check('HTTP error must not be published',False)
 (public/'en.headers').write_text('HTTP/2 200\n')
 check('recovered exact source eligible',True)
print('PASS 5/5 final page-source revocation invariants')
