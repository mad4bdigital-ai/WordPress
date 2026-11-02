import hashlib
import importlib.util
import json
import tempfile
from pathlib import Path
from urllib.parse import urlparse
from datetime import datetime,timezone,timedelta

name=str(Path(__file__).resolve().parents[1]/'ops'/'live-frontend-probe.py')
spec=importlib.util.spec_from_file_location('wpml_frontend_lab',name)
module=importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
passed=0
def check(cond,msg):
 global passed
 if not cond:raise AssertionError(msg)
 passed+=1

with tempfile.TemporaryDirectory(prefix='mad4b-priority-lab-') as directory:
 module.OUT=Path(directory)/'frontend-visibility.json'
 module.CURSOR=Path(directory)/'frontend-visibility-cursor.json'
 langs=('ar','fr','de','es','it')
 domain=module.BASE
 link_headers=''.join(f'<link rel="alternate" hreflang="{l}" href="https://tours.dev.mad4b.com/{l}/">' for l in langs)
 links={}
 for lang in langs:
  linklist=[f'/{lang}/tours-and-activities/']+[f'/{lang}/tours-and-activities/test-trip-{j}/' for j in range(12)]
  links[lang]=linklist
  for path in linklist:
   check(path.startswith('/'+lang+'/'),'valid fixture locale path')
 def html_for(url):
  path=urlparse(url).path
  if path=='/':return '<html><head>'+link_headers+'</head><body>Home</body></html>'
  lang=path.split('/')[1]
  if lang not in langs:return None
  post_num=abs(hash(path))%99999+1
  ids=f'<article data-post-id="{post_num}"></article><div data-elementor-id="{post_num+1}"></div>'
  if path=='/'+lang+'/':
   return ids+''.join('<a href="'+p+'">Visit</a>' for p in links[lang])+'<a href="https://other.example/bad/">OFFHOST</a>'
  if path.endswith('/tours-and-activities/'):
   return ids+''.join('<a href="'+p+'">Visit</a>' for p in links[lang][1:])
  return ids
 module.fetch=html_for
 first=module.observe()
 check(len(first['pages'])==5,'first crawl dynamically discovers five locales')
 check(sum(len(v) for v in first['pages'].values())>=20,'homepage/archive/details across languages')
 for lang in langs:
  check(any(r['kind']=='archive' for r in first['pages'][lang]),lang+' archive present')
 check(not any('other.example' in p['path'] for x in first['pages'].values() for p in x),'off-host is excluded')
 current_hash=hashlib.sha256(module.OUT.read_bytes()).hexdigest()
 first_paths={lang:{x['path'] for x in first['pages'][lang]} for lang in langs}
 second=module.observe()
 for lang in langs:
  changed={x['path'] for x in second['pages'][lang]}-first_paths[lang]
  check(bool(changed),lang+' rotates previously-unseen public pages')
  check(len(second['pages'][lang])>len(first['pages'][lang]),lang+' retains last verified pages')
  check(any(x['observed_at'] for x in second['pages'][lang]),lang+' per-page observation time')
 check(module.OUT.stat().st_size<240000,'snapshot bounded')
 # Old evidence drops after TTL; current pages still refresh.
 old=json.loads(module.OUT.read_text())
 old['pages']['ar'].append({'path':'/ar/expired-old/','kind':'linked_page',
    'http_status':200,'observed_at':(datetime.now(timezone.utc)-timedelta(hours=40)).isoformat(),
    'elementor_ids':['99998'],'listing_ids':[],'post_ids':[]})
 module.OUT.write_text(json.dumps(old))
 third=module.observe()
 check(not any(p['path']=='/ar/expired-old/' for p in third['pages']['ar']),'40h evidence expires')
 # Partial outage must not overwrite accepted snapshot.
 good=hashlib.sha256(module.OUT.read_bytes()).hexdigest()
 def outage(url):
  path=urlparse(url).path
  if path.startswith(('/ar/','/fr/','/de/')):return None
  return html_for(url)
 module.fetch=outage
 try:module.observe()
 except RuntimeError as exc:check('coverage_incomplete' in str(exc),'partial outage rejects replacement')
 else:raise AssertionError('partial outage unexpectedly accepted')
 check(hashlib.sha256(module.OUT.read_bytes()).hexdigest()==good,'failed crawl keeps last snapshot bytes')
 check(module.links_in('<a href="https://attacker.example/payload">x</a>', 'ar',domain+'/ar/')==[],
       'off-host malicious links ignored')
 print('PASS',passed,'fixture-only tests: rotation, TTL, bounded scan, fail-safe evidence, locales, host restrictions')
