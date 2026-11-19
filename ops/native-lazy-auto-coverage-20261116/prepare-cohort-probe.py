"""Generate a short-lived hashed-token, staging-only JetEngine browser probe.
Reads only server-discovered source metadata and a fresh SSR measurement.
Never edits WordPress, publishes a certificate or outputs the token to stdout.
"""
import json,secrets,hashlib,sys,time,base64,re
from pathlib import Path
manifest=json.loads(Path(sys.argv[1]).read_text())
baseline=Path(sys.argv[2]).read_text().splitlines()
locale=sys.argv[3];widget=sys.argv[4]
url=sys.argv[5]
out=Path(sys.argv[6])
assert manifest['contract']=='mad4b.native-lazy.auto-discovery.v1'
assert manifest['site_environment']=='staging'
assert re.fullmatch('[a-z]{2}(?:-[a-z]{2})?',locale)
matches=[x for x in manifest['candidates'] if x['locale']==locale and x['widget_id']==widget and x['url']==url]
assert len(matches)==1
item=matches[0]
assert re.fullmatch('[a-f0-9]{64}',item['listing_sha256'])
assert re.fullmatch('[a-f0-9]{64}',item['listing_css_sha256'])
assert re.fullmatch(r'scoped:v1:[a-f0-9]{64}',item['query_generation'])
market=item['market']
assert re.fullmatch(r'(?:[a-z]{2}|base)-[a-z]{3}',market)
v={}
for line in baseline:
 if not line.startswith('SSR_RESULT '):continue
 row=json.loads(line[len('SSR_RESULT '):])
 if row.get('locale')==locale and row.get('widget')==widget and row.get('url',url)==url and row.get('width') in [390,1920]:
  assert row.get('pass') and row.get('count',0)>=18
  v[row['width']]=row
assert set(v)=={390,1920}
assert 100<=v[390]['height']<=1600 and 100<=v[1920]['height']<=1600
assert v[390]['height']<v[1920]['height']*2
token=secrets.token_hex(32)
public={k:item[k] for k in ['locale','url','page_id','document_id','widget_id',
    'query_id','listing_id','query_generation','document_sha256','listing_sha256','listing_css_sha256','market']}
public.update({'mobile_height':v[390]['height'],'wide_height':v[1920]['height'],
   'mobile_breakpoint':manifest['mobile_breakpoint'],'css_epoch':manifest['css_epoch'],
   'host':manifest['site_host'],'expires_at':int(time.time())+900,
   'token_sha256':hashlib.sha256(token.encode()).hexdigest()})
(encoded,secret)=(base64.b64encode(json.dumps(public,separators=(',',':')).encode()).decode(),{'token':token})
php=Path(__file__).with_name('ephemeral-qa-probe-template.php').read_text()
assert php.count('__MAD4B_PROBE_B64__')==1
out.mkdir(parents=True,exist_ok=True)
(out/'qa-plugin.php').write_text(php.replace('__MAD4B_PROBE_B64__',encoded))
(out/'qa-config.json').write_text(json.dumps({k:v for k,v in public.items() if k!='token_sha256'},sort_keys=True))
(out/'qa-secret.json').write_text(json.dumps(secret))
for name in ('qa-plugin.php','qa-config.json','qa-secret.json'):
 (out/name).chmod(0o600)
print('PREPARED',locale,widget,'market',market,'firstCount',v[390]['count'],
 'heights',v[390]['height'],v[1920]['height'],
 'expires',public['expires_at'])
