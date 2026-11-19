#!/usr/bin/env python3
import importlib.util,json,pathlib,sys,tempfile
D=pathlib.Path(sys.argv[1])
spec=importlib.util.spec_from_file_location('delivery',D/'source-home-delivery-admission.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
rows={locale:{'route':'/' if locale=='en' else '/'+locale+'/',
              'sha256':'a'*64,'elementor_doc_fingerprint':'b'*64}
      for locale in m.LANGS}
base={'contract':m.CONTRACT,'host':m.HOST,
 'scope':'trigger_exact_locale_browser_QA_not_a_certificate',
 'checked_at':10000,'fingerprint':'c'*64,'state':'source_matched',
 'sources':rows,'languages_without_exact_source_certificate':[]}
checks=[]
with tempfile.TemporaryDirectory() as root:
 path=pathlib.Path(root)/'source.json'
 def test(name,data,passed,why=None,now=10012):
  path.write_text(json.dumps(data))
  valid,reason=m.evaluate(path,now)
  assert valid is passed and (why is None or reason==why),(name,valid,reason)
  checks.append(name);print('PASS',name)
 test('all verified first-party language/market contexts can report stable',base,True)
 test('changed EN/ES/IT CSS keeps native fallback and blocks false READY',
   dict(base,state='drift',languages_without_exact_source_certificate=['en','es','it']),False,
   'unmatched_source_css_or_elementor_graph')
 test('stale observation cannot approve a new generated CSS identity',base,False,
   'source_receipt_contract_or_age_invalid',now=20000)
 test('missing locale prevents a six-locale certificate claim',
   dict(base,sources=dict(list(rows.items())[:5])),False,'incomplete_wpml_source_coverage')
 test('unexpected source host rejected',dict(base,host='wayfatours.com'),False)
 test('fake incomplete hash rejected',dict(base,fingerprint='not-a-real-sha'),False)
 test('source report may not lie about drift list',
   dict(base,languages_without_exact_source_certificate=['ru']),False)
print('SOURCE_HOME_DELIVERY_ADMISSION_CHECKS',len(checks))
