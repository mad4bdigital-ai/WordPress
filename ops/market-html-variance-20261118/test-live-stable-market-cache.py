#!/usr/bin/env python3
"""Live HMAC migration + exact signed FastCGI country reuse; no raw token logs."""
import json,hashlib,subprocess,time,re
from urllib.request import Request,urlopen
CONTAINER='php-guxhp9y6vgh7oouuw8plxrlr'
UA='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 Chrome/157.0.0.0 Mobile Safari/537.36'
def legacy_token(market,nonce):
    php=f'''$r=["contract"=>"mad4b.country-switcher.v1",
      "site_uuid"=>MAD4B_SCP_Market_Profiles::site_uuid(),
      "country"=>"{market}","time"=>{nonce}];
      $payload=rtrim(strtr(base64_encode(wp_json_encode($r)),"+/","-_"),"=");
      echo $payload.".".hash_hmac("sha256",$payload,wp_salt("auth"));'''
    x=subprocess.run(['docker','exec',CONTAINER,'wp','--allow-root','eval',php],
       capture_output=True,text=True,timeout=80,check=True)
    token=x.stdout.strip().splitlines()[-1]
    assert re.fullmatch(r'[A-Za-z0-9_-]{25,1400}\.[a-f0-9]{64}',token)
    return token
def migrate(old):
    endpoint='https://wayfatours.com/wp-admin/admin-ajax.php?action=mad4b_visitor_preference_state'
    with urlopen(Request(endpoint,headers={'User-Agent':UA,'Cookie':'mad4b_country_selected_v1='+old,
                         'Accept':'application/json'}),timeout=30) as r:
        payload=json.loads(r.read())
        assert r.status==200 and payload['success'] and payload['data']['country']['explicit']
        headers=r.headers.get_all('Set-Cookie') or []
        cookies=[]
        for value in headers:
            if value.startswith('mad4b_country_selected_v1='):
                cookies.append(value.split(';',1)[0].partition('=')[2])
        assert len(cookies)==1,'expected one signed migration cookie'
        assert any('no-store' in x for x in (r.headers.get_all('Cache-Control') or []))
        return cookies[0],payload['data']['country']['country']
now=int(time.time())
old1=legacy_token('US',now)
old2=legacy_token('US',now-86400)
legacy_DE=legacy_token('DE',now)
new1,code1=migrate(old1)
new2,code2=migrate(old2)
new_de,coded=migrate(legacy_DE)
assert code1==code2=='US' and coded=='DE'
assert new1==new2 and new1!=old1 and new2!=old2 and new_de!=new1
print('CANONICAL_MARKET_MIGRATION',{'US_two_old_tokens':True,'same_new_token':True,
       'DE_separate':True,'private_batch_no_store':True},flush=True)
def get(country,token):
    url='https://wayfatours.com/'
    req=Request(url,headers={'User-Agent':UA,'Accept':'text/html','Accept-Encoding':'identity',
                             'Cookie':'mad4b_country_selected_v1='+token})
    t=time.monotonic()
    with urlopen(req,timeout=33) as res:
        content=res.read(2700000)
        flags=[x for x in (res.headers.get_all('Cache-Control') or []) if 'no-store' in x]
        row={'country':country,'cache':res.headers.get('X-Wayfa-Origin-Cache',''),
             'http':res.status,'css_ready':res.headers.get('X-MAD4B-Private-Mobile-CSS-Ready',''),
             'no_store':bool(flags),'body_sha':hashlib.sha256(content).hexdigest()[:12],
             'elapsed_ms':round((time.monotonic()-t)*1000)}
    print('STABLE_MARKET_ORIGIN',row,flush=True)
    return row
one=get('US',new1)
two=get('US',new2)
three=get('DE',new_de)
four=get('DE',new_de)
assert two['cache']=='HIT' and four['cache']=='HIT'
assert all(x['http']==200 and x['no_store'] and x['css_ready']=='1' for x in [one,two,three,four])
assert one['body_sha']==two['body_sha'] and three['body_sha']==four['body_sha']
print('CANONICAL_SIGNED_MARKET_FASTCGI_LIVE_PASS')
