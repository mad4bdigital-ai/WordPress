"""Read-only qualification evidence, not an authority/receipt issuer."""
import argparse, collections, hashlib, json, re
from html.parser import HTMLParser
from pathlib import Path

SHA=lambda v:hashlib.sha256(v if isinstance(v,bytes) else v.encode()).hexdigest()
VOID=set('area base br col embed hr img input link meta param source track wbr'.split())
UNSAFE=re.compile(r'<\s*(?:script|form|input|iframe)\b|\bnonce\b|aria-current|\be-current\b|data-(?:price|rate|amount|session|user|cart)\b|elementor-dcss-[0-9]{10,22}(?![0-9])',re.I)
LISTINGS={'67124':'tour-types','67125':'destinations'}

class Scan(HTMLParser):
    def __init__(self, source):
        super().__init__(convert_charrefs=False)
        self.source=source
        self.starts=[0]+[m.end() for m in re.finditer('\n',source)]
        self.stack=[];self.rows=[];self.invalid=[];self.menu=0;self.lang='';self.assets=set()
        self.feed(source)
        self.close()
        if any(x['menu'] for x in self.stack):self.invalid.append('unclosed_menu')
    def pos(self):
        l,c=self.getpos();return self.starts[l-1]+c
    def handle_starttag(self,tag,attrs):
        d=dict(attrs);cl=set((d.get('class') or '').split())
        root=d.get('data-id')=='6072b3f';scope=root or bool(self.stack and self.stack[-1]['menu'])
        if root:self.menu+=1
        if tag=='html':self.lang=d.get('lang','')
        if tag=='link' and 'stylesheet' in (d.get('rel') or '').split() and d.get('href'):
            self.assets.add(('css',d['href']))
        if tag=='script' and d.get('src'):self.assets.add(('js',d['src']))
        if tag in VOID:return
        self.stack.append(dict(tag=tag,attrs=d,classes=cl,menu=scope,start=self.pos()))
    def handle_startendtag(self,tag,attrs):
        self.handle_starttag(tag,attrs)
        if tag not in VOID:self.handle_endtag(tag)
    def handle_endtag(self,tag):
        i=next((i for i in range(len(self.stack)-1,-1,-1) if self.stack[i]['tag']==tag),None)
        if i is None:return
        closing=self.stack[i]
        if i!=len(self.stack)-1 and any(x['menu'] for x in self.stack[i+1:]):
            self.invalid.append('unbalanced_menu_markup')
        del self.stack[i:]
        if not closing['menu']:return
        doc=closing['attrs'].get('data-elementor-id')
        if doc not in LISTINGS:return
        outer=next((x for x in reversed(self.stack) if 'jet-listing-grid__item' in x['classes']),None)
        term=outer['attrs'].get('data-post-id','') if outer else ''
        if not term.isdecimal() or int(term)<1:
            self.invalid.append('missing_term_wrapper');return
        end=self.source.find('>',self.pos())+1
        raw=self.source[closing['start']:end]
        stable=[x for x in outer['classes'] if re.fullmatch('elementor-dcss-mad4b-[a-f0-9]{16}',x)]
        self.rows.append({'listing':int(doc),'term':int(term),'document_sha256':SHA(raw),'bytes':len(raw.encode()),'safe_candidate':not UNSAFE.search(raw),'stable_outer_css':len(stable)==1,'css_identity':stable[0] if len(stable)==1 else None})
    def facts(self):
        return {'language':self.lang,'menus':self.menu,'errors':sorted(set(self.invalid)),'counts':dict(collections.Counter(str(x['listing']) for x in self.rows)),'documents':self.rows,'external_asset_count':len(self.assets)}

def evaluate(a,b,source_before,source_after,receipts):
    snapshot_match=source_before==source_after
    native_observed=all(isinstance(r,list) and any(x.get('status')=='V2_SCOPE_REJECTED' for x in r) for r in receipts)
    cohorts={}
    for listing in LISTINGS:
        x=[r for r in a.rows if str(r['listing'])==listing];y=[r for r in b.rows if str(r['listing'])==listing]
        match=x==y and bool(x)
        safe=all(r['safe_candidate'] and r['stable_outer_css'] for r in x+y)
        template=source_before.get('templates',{}).get(listing,{})
        source_ok=template.get('published') is True and template.get('listing',{}).get('source')=='terms' and template.get('listing',{}).get('tax')==LISTINGS[listing]
        ok=snapshot_match and native_observed and source_ok and a.menu==b.menu==1 and not a.invalid and not b.invalid and a.lang==b.lang and match and safe
        cohorts[listing]={'documents_a':len(x),'documents_b':len(y),'exact_ordered_document_match':match,'safe_html_candidate':safe and bool(x+y),'source_template_verified':source_ok,'http_document_evidence_pass':ok,'state':'HTTP_EVIDENCE_ONLY' if ok else ('NOT_OBSERVED' if not x and not y else 'HOLD'),'matched_document_hashes':x if ok else []}
    return {'contract':'mad4b.navigation-card.http-qualification.v1','source_snapshot_match':snapshot_match,'native_menu_canary_rejected':native_observed,'source_snapshot_sha256':SHA(json.dumps(source_before,sort_keys=True,separators=(',',':'))),'language':a.lang,'cohorts':cohorts,'common_external_asset_urls':len(a.assets&b.assets),'page_specific_external_asset_counts':[len(a.assets-b.assets),len(b.assets-a.assets)],'full_renderer_bytes_verified':False,'asset_parity_verified':False,'browser_parity_verified':False,'market_attested':False,'authority_verified':False,'replay_authorized':False}

def main():
    p=argparse.ArgumentParser();p.add_argument('folder',type=Path);p.add_argument('--output',type=Path,required=True);args=p.parse_args()
    folder=args.folder
    result=evaluate(*[Scan((folder/(n+'.html')).read_text()) for n in ('a','b')],*[json.loads((folder/(n+'.json')).read_text()) for n in ('before','after')],[json.loads((folder/(n+'.receipt.json')).read_text()) for n in ('a','b')])
    args.output.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps({**result,'cohorts':{k:{m:n for m,n in v.items() if m!='matched_document_hashes'} for k,v in result['cohorts'].items()}}))
if __name__=='__main__':main()
