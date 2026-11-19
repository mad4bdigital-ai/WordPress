#!/usr/bin/env python3
"""Real WordPress HMAC canonicalization across two historical independent cookies."""
import json,hashlib,subprocess,time,re
from urllib.request import urlopen,Request
C='php-guxhp9y6vgh7oouuw8plxrlr'
UA='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 Chrome/157.0.0.0 Mobile Safari/537.36'
def legacy(market,passport,ts):
    php=f'''$a=["contract"=>"mad4b.country-switcher.v1",
       "site_uuid"=>MAD4B_SCP_Market_Profiles::site_uuid(),
       "country"=>"{market}","time"=>{ts}];
       $b=["contract"=>"travel_booking.traveller-nationality.v1",
       "site_uuid"=>hash("sha256",rtrim((string)get_option("home",""),"/")."/"),
       "country"=>"{passport}","saved_at"=>{ts}];
       foreach([$a,$b] as $v){{
        $e=rtrim(strtr(base64_encode(wp_json_encode($v)),"+/","-_"),"=");
        echo $e.".".hash_hmac("sha256",$e,wp_salt("auth"))."\\n";
       }}'''
    out=subprocess.run(['docker','exec',C,'wp','--allow-root','eval',php],
       capture_output=True,text=True,timeout=75,check=True).stdout.strip().splitlines()
    tok=out[-2:]
    assert len(tok)==2 and all(re.fullmatch(r'[A-Za-z0-9_-]{25,1400}\.[a-f0-9]{64}',x) for x in tok)
    return tok
def ajax(tokens):
    header='mad4b_country_selected_v1='+tokens[0]+'; travel_booking_nationality_preference_v1='+tokens[1]
    req=Request('https://wayfatours.com/wp-admin/admin-ajax.php?action=mad4b_visitor_preference_state',
                headers={'User-Agent':UA,'Cookie':header,'Accept':'application/json'})
    with urlopen(req,timeout=38) as r:
        payload=json.loads(r.read())
        assert payload['success'],payload.get('data',{}).get('code','')
        data=payload['data'];assert data['country']['country']=='US' and data['nationality']['country']=='EG'
        out={}
        for item in r.headers.get_all('Set-Cookie') or []:
            key,sep,val=item.partition('=')
            if sep and key in ('mad4b_country_selected_v1','travel_booking_nationality_preference_v1'):
                out[key]=val.split(';',1)[0]
        assert len(out)==2,('expected both cookies',list(out))
        assert any('no-store' in v for v in (r.headers.get_all('Cache-Control') or []))
        return out
now=int(time.time())
old1=legacy('US','EG',now); old2=legacy('US','EG',now-17000)
new1=ajax(old1);new2=ajax(old2)
assert new1==new2 and old1[0]!=new1['mad4b_country_selected_v1'] and old2[1]!=new2['travel_booking_nationality_preference_v1']
print('TWO_SIGNED_PREFERENCES_MIGRATED',{'country':'stable','nationality':'stable','private_state':'verified'},flush=True)
def visit(cookies):
    header='; '.join(k+'='+v for k,v in cookies.items())
    started=time.monotonic()
    with urlopen(Request('https://wayfatours.com/',headers={
       'User-Agent':UA,'Cookie':header,'Accept-Encoding':'identity'}),timeout=32) as r:
        html=r.read(2500000)
        no_store=any('no-store' in x for x in (r.headers.get_all('Cache-Control') or []))
        result={'cache':r.headers.get('X-Wayfa-Origin-Cache'),
          'ready':r.headers.get('X-MAD4B-Private-Mobile-CSS-Ready'),
          'status':r.status,'no_store':no_store,
          'sha':hashlib.sha256(html).hexdigest()[:12],
          'elapsed_ms':round((time.monotonic()-started)*1000)}
    print('SHARED_SAME_COHORT',result,flush=True);return result
a=visit(new1);b=visit(new2)
assert b['cache']=='HIT' and a['status']==b['status']==200
assert b['ready']=='1' and a['no_store'] and b['no_store']
assert a['sha']==b['sha']
print('WAYFA_CANONICAL_COUNTRY_AND_PASSPORT_FASTCGI_PASS')
