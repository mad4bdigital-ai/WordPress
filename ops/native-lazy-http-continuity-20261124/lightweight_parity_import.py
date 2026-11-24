import hashlib,json,re,sys,urllib.parse,requests
from html.parser import HTMLParser
from pathlib import Path
from collections import Counter
VOID=set('area base br col embed hr img input link meta param source track wbr'.split())
class DOM(HTMLParser):
    def __init__(self,widget=None):
        super().__init__(convert_charrefs=True); self.stack=[];self.widget=widget;self.targets=0;self.cards=[];self.lazy=[];self.settings=[]
    def handle_starttag(self,tag,attrs):
        a=dict(attrs); c=(a.get('class') or '').split()
        parent=self.stack[-1] if self.stack else (None,self.widget is None,None,False)
        scope=parent[1] or ('elementor-element-'+str(self.widget) in c)
        if self.widget is not None and 'elementor-element-'+str(self.widget) in c:
            self.targets+=1
            if a.get('data-widget-settings'):self.settings.append(json.loads(a['data-widget-settings']))
        ignored=parent[3] or tag in ('script','style','noscript','template')
        card=parent[2]
        if scope and 'jet-listing-grid__item' in c:
            if card is not None:raise ValueError('nested listing cannot qualify')
            card={'id':a.get('data-post-id'),'text':[],'links':[]};self.cards.append(card)
        if scope and a.get('data-lazy-load'):self.lazy.append(json.loads(a['data-lazy-load']))
        if scope and card is not None and not ignored and tag=='a' and a.get('href'):card['links'].append(a['href'])
        if tag not in VOID:self.stack.append((tag,scope,card,ignored))
    def handle_startendtag(self,tag,attrs):
        self.handle_starttag(tag,attrs)
        if tag not in VOID:self.handle_endtag(tag)
    def handle_endtag(self,tag):
        for i in range(len(self.stack)-1,-1,-1):
            if self.stack[i][0]==tag:del self.stack[i:];break
    def handle_data(self,s):
        if self.stack:
            _,scope,card,ignored=self.stack[-1]
            if scope and card is not None and not ignored:
                t=' '.join(s.split())
                if t:card['text'].append(t)
def parse(s,w=None):
    d=DOM(w);d.feed(s);return d
def canon(cards,base):
    return [[c['id'],' '.join(c['text']),[urllib.parse.urljoin(base,x) for x in c['links']]] for c in cards]
def digest(x):return hashlib.sha256(json.dumps(x,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()
def flatten(o,k=''):
    if isinstance(o,dict):return [(a,b) for x,y in o.items() for a,b in flatten(y,f'{k}[{x}]' if k else x)]
    if isinstance(o,list):return [(a,b) for i,y in enumerate(o) for a,b in flatten(y,f'{k}[{i}]')]
    return [(k,'true' if o is True else 'false' if o is False else str(o))]
def run(cfg,token):
    url=cfg['url'];u=urllib.parse.urlsplit(url)
    assert u.scheme=='https' and u.netloc==cfg['host']=='tours.dev.mad4b.com' and not u.query and not u.fragment
    assert re.fullmatch('[a-f0-9]{64}',token)
    headers={'User-Agent':'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36','Cache-Control':'no-cache, no-store','X-MAD4B-BYPASS-CACHE':'1','X-MAD4B-Widget-Probe':'native-grid-20261116','X-MAD4B-Coverage-Probe':token}
    with requests.Session() as session:
        def get(mode):
            r=session.get(url,headers={**headers,'X-MAD4B-Coverage-Mode':mode},timeout=22,allow_redirects=False)
            assert r.status_code==200 and r.headers.get('X-MAD4B-Native-Grid-QA')=='1' and r.headers.get('X-MAD4B-Native-Grid-QA-Market')==cfg['market'], 'route/market probe not verified'
            assert len(r.content)<8_000_000
            return parse(r.text,cfg['widget_id'])
        ssr=get('ssr');lazy=get('lazy')
        assert ssr.targets==lazy.targets==1 and len(ssr.cards)>0 and not ssr.lazy and len(lazy.lazy)==1 and not lazy.cards,'native SSR/lazy structure not equivalent'
        src=lazy.lazy[0]
        assert int(src['post_id'])==cfg['document_id'] and int(src['query']['query_id'])==cfg['query_id']
        assert re.fullmatch('[a-f0-9]{64}',src['query']['signature']) and len(src['settings_signature'])>=8
        settings=dict(lazy.settings[0]) if len(lazy.settings)==1 else {}
        settings['_jet_engine_settings_signature']=src['settings_signature']
        data={'action':'jet_engine_ajax','handler':'get_listing','query':src['query'],'widget_settings':settings,'page_settings':{'post_id':src['post_id'],'queried_id':src['queried_id'],'element_id':cfg['widget_id'],'page':1},'listing_type':'elementor','isEditMode':'false'}
        r=session.post(url+'?nocache=1',data=flatten(data),headers={'User-Agent':headers['User-Agent'],'Referer':url,'Cache-Control':'no-cache, no-store'},timeout=25,allow_redirects=False)
        assert r.status_code==200 and len(r.content)<8_000_000
        x=r.json();assert x.get('success') is True and isinstance(x.get('data',{}).get('html'),str),'native signed handler rejected'
        ajax=parse(x['data']['html']); a,b=canon(ssr.cards,url),canon(ajax.cards,url)
        result={'contract':'mad4b.native-listing.lightweight-http-parity.v1','site':cfg['host'],'locale':cfg['locale'],'market':cfg['market'],'query':cfg['query_id'],'document':cfg['document_id'],'listing':cfg['listing_id'],'query_generation':cfg['query_generation'],'ssr_count':len(a),'ajax_count':len(b),'ssr_sha256':digest(a),'ajax_sha256':digest(b),'ordered_semantics_match':a==b,'visual_measurement':False,'native_certificate_written':False}
        print(json.dumps(result,ensure_ascii=False))
        return 0 if a==b and len(a)>0 else 2
if __name__=='__main__':
    try:sys.exit(run(json.loads(Path(sys.argv[1]).read_text()),json.loads(Path(sys.argv[2]).read_text())['token']))
    except Exception as exc:
        print(json.dumps({'contract':'mad4b.native-listing.lightweight-http-parity.v1','status':'hold','reason':str(exc)[:200],'native_certificate_written':False}));sys.exit(2)
