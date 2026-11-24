import sys,json,re,hashlib,time,urllib.parse
from pathlib import Path
import requests
from html.parser import HTMLParser
from lightweight_parity_import import DOM,canon,digest,flatten
class NavDOM(DOM):
    def __init__(self,w=None):
        super().__init__(w);self.nav=[];self.queried=[]
    def handle_starttag(self,tag,attrs):
        a=dict(attrs); super().handle_starttag(tag,attrs)
        scope=self.stack[-1][1] if self.stack else False
        if scope and a.get('data-nav'):
            self.nav.append(json.loads(a['data-nav']))
            self.queried.append(a.get('data-queried-id',''))
def parse(s,w=None):
    d=NavDOM(w);d.feed(s);return d
def check(cfg):
    url=cfg['url'];u=urllib.parse.urlsplit(url)
    assert u.scheme=='https' and u.netloc in ('tours.dev.mad4b.com','wayfatours.com') and not u.query and not u.fragment
    headers={'User-Agent':'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36','Cache-Control':'no-cache, no-store','X-MAD4B-BYPASS-CACHE':'1'}
    started=time.monotonic()
    with requests.Session() as session:
        r=session.get(url+'?nocache=1',headers=headers,timeout=22,allow_redirects=False)
        assert r.status_code==200 and len(r.content)<8_000_000, f'SSR HTTP {r.status_code}'
        ssr=parse(r.text,cfg['widget_id'])
        assert ssr.targets==1 and len(ssr.cards)>0 and not ssr.lazy and len(ssr.nav)==1, f'SSR structure targets={ssr.targets} cards={len(ssr.cards)} nav={len(ssr.nav)} lazy={len(ssr.lazy)}'
        n=ssr.nav[0];q=n['query'];w=n['widget_settings']
        assert int(q['query_id'])==cfg['query_id'] and int(w['lisitng_id'])==cfg['listing_id']
        assert re.fullmatch('[a-f0-9]{64}',q['signature']) and re.fullmatch('[a-f0-9]{64}',w['_jet_engine_settings_signature'])
        assert ssr.queried==[str(cfg['page_id'])+'|WP_Post'], 'queried object differs'
        assert not n.get('enabled'), 'pagination must be handled separately'
        post={'action':'jet_engine_ajax','handler':'get_listing','query':q,'widget_settings':{'_jet_engine_settings_signature':w['_jet_engine_settings_signature']},'page_settings':{'post_id':cfg['document_id'],'queried_id':ssr.queried[0],'element_id':cfg['widget_id'],'page':1},'listing_type':'elementor','isEditMode':'false'}
        ar=session.post(url+'?nocache=1',data=flatten(post),headers={**headers,'Referer':url,'X-Requested-With':'XMLHttpRequest'},timeout=25,allow_redirects=False)
        assert ar.status_code==200 and len(ar.content)<8_000_000, f'AJAX HTTP {ar.status_code}'
        x=ar.json();assert x.get('success') is True and isinstance(x.get('data',{}).get('html'),str), 'native signed request not accepted'
        ajax=parse(x['data']['html'])
        a,b=canon(ssr.cards,url),canon(ajax.cards,url)
        report={'contract':'mad4b.native-listing.public-ssr-http-parity.v1','site':u.netloc,'locale':cfg['locale'],'query':cfg['query_id'],'document':cfg['document_id'],'listing':cfg['listing_id'],'http_ssr':r.status_code,'http_ajax':ar.status_code,'signed_request_accepted':True,'ssr_count':len(a),'ajax_count':len(b),'ssr_ids_sha256':digest([x[0] for x in a]),'ajax_ids_sha256':digest([x[0] for x in b]),'ssr_sha256':digest(a),'ajax_sha256':digest(b),'ordered_semantics_match':a==b,'same_http_session':True,'market_attested':False,'visual_measurement':False,'native_certificate_written':False,'ssr_route':'native_uncached_public_ssr','elapsed_ms':round((time.monotonic()-started)*1000)}
        if a!=b:
            report['first_mismatch_index']=next((i for i,(x,y) in enumerate(zip(a,b)) if x!=y),min(len(a),len(b)))
        print(json.dumps(report))
        return 0 if a==b and len(a)>0 else 2
if __name__=='__main__':
    try:sys.exit(check(json.loads(Path(sys.argv[1]).read_text())))
    except Exception as e:print(json.dumps({'status':'hold','reason':str(e)[:250],'native_certificate_written':False}));sys.exit(2)
