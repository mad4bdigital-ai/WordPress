#!/usr/bin/env python3
"""Fail-closed checks against live Nginx, with synthetic tokens kept in RAM."""
import importlib.util,json,subprocess,time
from pathlib import Path
from urllib.request import Request,urlopen
from importlib.machinery import SourceFileLoader
p=Path(__file__).with_name('capture-live-route-variance.py')
spec=importlib.util.spec_from_loader('signed_negative_capture',SourceFileLoader('signed_negative_capture',str(p)))
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
country=m.mint_country_tokens()['US']
def national_token():
    php='''$r=["contract"=>"travel_booking.traveller-nationality.v1",
       "site_uuid"=>hash("sha256",rtrim((string)get_option("home",""),"/")."/"),
       "country"=>"EG","time"=>time()];
       $b=rtrim(strtr(base64_encode(wp_json_encode($r)),"+/","-_"),"=");
       echo $b.".".hash_hmac("sha256",$b,wp_salt("auth"));'''
    call=subprocess.run(['docker','exec',m.CONTAINER,'wp','--allow-root','eval',php],
        capture_output=True,text=True,timeout=75,check=True)
    return call.stdout.strip().splitlines()[-1]
national=national_token()
assert len(national)>50 and '.' in national
def probe(label,cookie,path='/'):
    r=Request('https://wayfatours.com'+path,headers={'User-Agent':m.UA,'Cookie':cookie,
     'Accept':'text/html','Accept-Encoding':'identity'})
    started=time.monotonic()
    with urlopen(r,timeout=30) as res:
        res.read(16000)
        out={'case':label,'http':res.status,'cache':res.headers.get('X-Wayfa-Origin-Cache',''),
             'flags':res.headers.get('X-MAD4B-Signed-Cache-Diag',''),
             'source_admit':res.headers.get('X-MAD4B-Signed-Home-Cache-Ready',''),
             'private_css':res.headers.get('X-MAD4B-Private-Mobile-CSS-Ready',''),
             'browser_no_store':any('no-store' in v for v in (res.headers.get_all('Cache-Control') or [])),
             'elapsed_ms':round((time.monotonic()-started)*1000)}
    print('SIGNED_EDGE_NEGATIVE',json.dumps(out),flush=True)
    return out
base='mad4b_country_selected_v1='+country
a=probe('signed_country_plus_verified_passport_first',base+'; travel_booking_nationality_preference_v1='+national)
b=probe('signed_country_plus_verified_passport_second',base+'; travel_booking_nationality_preference_v1='+national)
assert a['source_admit']=='1' and b['cache']=='HIT' and b['browser_no_store']
fake=country[:-1]+('0' if country[-1]!='0' else '1')
c=probe('invalid_signature_first','mad4b_country_selected_v1='+fake)
d=probe('invalid_signature_second','mad4b_country_selected_v1='+fake)
assert c['cache']!='HIT' and d['cache']!='HIT' and not c['source_admit'] and not d['source_admit']
e=probe('woocommerce_unknown_cookie_bypass',base+'; wp_woocommerce_session_fake=xyz')
assert e['cache']=='BYPASS'
f=probe('duplicate_country_cookie_bypass',base+'; '+base)
assert f['cache']=='BYPASS'
g=probe('locale_mismatch_bypass',base+'; wp-wpml_current_language=en','/es/')
assert g['cache']=='BYPASS'
h=probe('query_string_bypass',base,'/?nocache=1')
assert h['cache']=='BYPASS'
print('PRIVATE_SIGNED_EDGE_FAILURE_CONTRACT_CHECKS 8')
