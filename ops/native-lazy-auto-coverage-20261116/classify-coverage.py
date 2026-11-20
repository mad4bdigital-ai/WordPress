"""Reconcile auto-discovered company listing sources with real Chromium SSR.
Classification creates a bounded QA queue; it never creates certificates.
"""
from pathlib import Path
from collections import defaultdict
import hashlib,json,re,sys,datetime
discovery=json.loads(Path(sys.argv[1]).read_text())
sources=Path(sys.argv[2]).read_text().splitlines()
assert discovery['contract']=='mad4b.native-lazy.auto-discovery.v1'
assert len(discovery['candidates'])<=256
threshold=max(18,int(discovery['engine_min_items']))
rows=defaultdict(dict)
# Historical browser fixtures predate per-route keys. Accept them only
# when the locale+widget pair resolves to one unique public source route.
legacy_routes=defaultdict(set)
for c in discovery['candidates']:
 legacy_routes[(c['locale'],c['widget_id'])].add(c['url'])
for l in sources:
 if not l.startswith('SSR_RESULT '):continue
 r=json.loads(l[len('SSR_RESULT '):])
 if r.get('widget') and r.get('locale') and r.get('width') in [390,1920]:
  url=r.get('url')
  if not url:
   choices=legacy_routes[(r['locale'],r['widget'])]
   if len(choices)!=1:continue
   url=next(iter(choices))
  k=(r['locale'],url,r['widget'])
  rows[k][r['width']]=r
queue=[]
matrix=[]
for obj in discovery['candidates']:
 key=(obj['locale'],obj['url'],obj['widget_id'])
 e=rows.get(key,{})
 bands=[e.get(w) for w in [390,1920]]
 status='unknown'
 observed_counts=[v.get('count') if isinstance(v,dict) else None for v in bands]
 if not all(isinstance(v,dict) for v in bands):
  status='needs_ssr_measurement'
 elif all(v.get('reason')=='missing/duplicate widget' for v in bands):
  status='conditional_not_rendered'
 elif any(not v.get('pass',False) for v in bands):
  status='layout_or_render_hold'
 elif any(v['count']<threshold for v in bands):
  status='native_ssr_low_volume'
 elif obj.get('runtime_admitted') is True:
  status='certified_active'
 elif obj.get('status')=='pass':
  status='recertify_changed_query_source' if obj.get('version_match') is False else 'recertify_previous_certificate'
 elif len(set(v['height'] for v in bands))<1:
  status='unmeasured'
 elif not re.fullmatch(r'(?:[a-z]{2}|base)-[a-z]{3}',obj.get('market','')):
  status='market_identity_unverified'
 else:
  status='requires_native_signed_ajax_evidence'
 if status in ('requires_native_signed_ajax_evidence','recertify_changed_query_source','recertify_previous_certificate'):
  row={k:obj[k] for k in ('locale','url','page_id','document_id',
    'query_id','listing_id','widget_id','market','query_generation','listing_sha256',
    'listing_css_sha256','css_epoch','mobile_breakpoint')}
  row['reason']=status
  row['priority']=0 if status.startswith('recertify_') else 1
  queue.append(row)
 matrix.append({'locale':obj['locale'],'query_id':obj['query_id'],
     'listing_id':obj['listing_id'],'widget_id':obj['widget_id'],'market':obj['market'],
     'counts_390_1920':observed_counts,'status':status,'source_revision':obj['query_generation']})
by_status={}
for x in matrix:by_status[x['status']]=by_status.get(x['status'],0)+1
queue.sort(key=lambda x:(x['priority'],x['locale'],x['url'],x['widget_id']))
output={'contract':'mad4b.native-lazy.auto-coverage-queue.v1',
 'site_host':discovery['site_host'],'observed_market':discovery['observed_wcml_market'],
 'configured_currencies':discovery['wcml_configured_currencies'],
 'configured_but_unobserved_currencies':[
   c for c in discovery['wcml_configured_currencies']
   if c!=str(discovery['observed_wcml_market'].get('currency',''))],
 'coverage_rule':'Only first-party browser-reproducible WCML country/currency pairs may receive an AJAX certificate',
 'language_count':len(discovery['wpml_languages']),
 'discovered_count':len(matrix),'statuses':by_status,
 'queue':queue,'matrix':matrix,
 'prepared_at_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
 'has_written_certificates':False}
Path(sys.argv[3]).write_text(json.dumps(output,ensure_ascii=False,indent=2)+'\n')
print('COVERAGE',json.dumps(by_status,ensure_ascii=False),'queued',len(queue))
for item in matrix:print(item['locale'],item['widget_id'],item['counts_390_1920'],item['status'])
