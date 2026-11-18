#!/usr/bin/env python3
"""Root-only read-only synthetic market comparison; never store cookie/HTML.

All signed visitor-country cookies are minted by the exact first-party WP
owner via wp eval, and used only in this process's memory. No Woo data,
order, quote, real visitor session or user account is touched.
"""
import hashlib,json,re,subprocess,sys,time
from html.parser import HTMLParser
from pathlib import Path
from urllib.request import Request,urlopen
from urllib.parse import urljoin
import importlib.util

CONTAINER='php-guxhp9y6vgh7oouuw8plxrlr'
DOMAIN='wayfatours.com'
UA='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 Chrome/157.0.0.0 Mobile Safari/537.36'
COUNTRIES=('US','DE','EG','GB')

class PublicPage(HTMLParser):
    def __init__(self):
        super().__init__();self.prices=[];self.items=[];self.depth=0;self.buff=[]
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=='div' and 'jet-listing-grid__item' in a.get('class',''):
            self.items.append((a.get('data-post-id',''),a.get('class','')))
        if self.depth:
            self.depth+=1
        elif tag in ('span','div','bdi') and 'woocommerce-Price-amount' in a.get('class',''):
            self.depth=1;self.buff=[]
    def handle_data(self,data):
        if self.depth:self.buff.append(data)
    def handle_endtag(self,tag):
        if self.depth:
            self.depth-=1
            if not self.depth:self.prices.append(' '.join(' '.join(self.buff).split()))

def mint_country_tokens():
    php="""foreach(['US','DE','EG','GB'] as $country){
      $r=['contract'=>'mad4b.country-switcher.v1',
          'site_uuid'=>MAD4B_SCP_Market_Profiles::site_uuid(),
          'country'=>$country,'time'=>time()];
      $v=rtrim(strtr(base64_encode(wp_json_encode($r)),'+/','-_'),'=');
      echo $country.' '.$v.'.'.hash_hmac('sha256',$v,wp_salt('auth'))."\\n";
    }"""
    p=subprocess.run(['docker','exec',CONTAINER,'wp','--allow-root','eval',php],
      stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,text=True,timeout=80,check=True)
    tokens={}
    for line in p.stdout.splitlines():
        code,sep,value=line.partition(' ')
        if sep and code in COUNTRIES and 30<len(value)<=1500:
            tokens[code]=value
    if set(tokens)!=set(COUNTRIES):raise RuntimeError('country_authority_unavailable')
    return tokens

def capture(route,tokens):
    target='https://'+DOMAIN+route
    lang='es' if route=='/es/' else 'en'
    measurements=[]
    for country in COUNTRIES:
        cookie='mad4b_country_selected_v1='+tokens[country]
        if lang=='es':cookie+='; wp-wpml_current_language=es'
        req=Request(target,headers={'User-Agent':UA,'Cookie':cookie,
              'Accept':'text/html,application/xhtml+xml','Accept-Encoding':'identity'})
        with urlopen(req,timeout=28) as res:
            if res.status!=200 or res.url!=target:
                raise RuntimeError('http_origin_or_redirect_invalid')
            blob=res.read(2800001)
            if len(blob)>2800000 or len(blob)<20000:raise RuntimeError('untrusted_public_html_size')
            body=blob.decode('utf-8','replace')
            parser=PublicPage();parser.feed(body)
            if len(parser.prices)!=9 or len(parser.items)<10:
                raise RuntimeError('public_price_or_listing_unexpected:prices='+str(len(parser.prices))+',listing='+str(len(parser.items))+',bytes='+str(len(blob)))
            if any(not re.fullmatch(r'\$\s*[0-9][0-9,.]*',p) for p in parser.prices):
                raise RuntimeError('unknown_price_currency')
            r={
                'host':DOMAIN,'path':route,'language':lang,
                'market':country,'currency':'USD',
                'sampled_epoch':int(time.time()),
                'signed_market_verified':True,'trusted_origin':True,
                'displayed_prices':parser.prices,
                'public_listing_sha':hashlib.sha256(json.dumps(parser.items,sort_keys=True).encode()).hexdigest(),
                '_full_html_sha':hashlib.sha256(blob).hexdigest(),
                '_cache_control':res.headers.get('Cache-Control',''),
                '_origin_cache':res.headers.get('X-Wayfa-Origin-Cache',''),
            }
            measurements.append(r)
    return measurements

def run(route):
    if route not in ('/','/es/'):raise ValueError('route_not_admitted')
    path=Path('/opt/wayfa-rebuild-20261102/stack/nginx.conf')
    wp=Path('/opt/wayfa-rebuild-20261102/site-copy/wp-content/mu-plugins/429-mad4b-signed-home-destination-card-cache-v1.php')
    origin_generation=hashlib.sha256((path.read_bytes()+wp.read_bytes())).hexdigest()
    module=Path(__file__).with_name('route-market-variant.py')
    spec=importlib.util.spec_from_file_location('route_market_policy',module)
    policy=importlib.util.module_from_spec(spec);spec.loader.exec_module(policy)
    snapshots=capture(route,mint_country_tokens())
    for s in snapshots:s['source_revision']=origin_generation
    decisions=policy.classify(snapshots,list(COUNTRIES),now=int(time.time()))
    if decisions.get('state')=='hold':
        raise RuntimeError('market_render_comparison_held:'+decisions.get('reason',''))
    report={
      'contract':'mad4b.public-route-market-output-observed.v1',
      'host':DOMAIN,'path':route,'checked_at':int(time.time()),
      'sampled_countries':list(COUNTRIES),'source_revision':origin_generation,
      'price_count_each':len(snapshots[0]['displayed_prices']),
      'displayed_prices_sha':{s['market']:hashlib.sha256(json.dumps(s['displayed_prices']).encode()).hexdigest() for s in snapshots},
      'public_listing_sha':{s['market']:s['public_listing_sha'] for s in snapshots},
      'whole_html_sha':{s['market']:s['_full_html_sha'] for s in snapshots},
      'cache_status':{s['market']:s['_origin_cache'] for s in snapshots},
      'price_and_listing_decision':decisions,
      'full_html_share_authorized':False,
      'why_not_full_html':'signed country SWATCHER and private context not yet neutralized'
    }
    # Volatile country cookies, raw body, personal prices, origin logs, token
    # and WordPress salts are NEVER persisted in this receipt.
    dest=Path('/opt/mad4b-company-provisioning/reports/market-html-variance-20261118')
    dest.mkdir(parents=True,exist_ok=True,mode=0o700)
    final=dest/('wayfa-home-es-market-output.json' if route=='/es/' else 'wayfa-home-en-market-output.json')
    tmp=final.with_suffix('.pending')
    tmp.write_text(json.dumps(report,indent=2,sort_keys=True)+'\n')
    tmp.chmod(0o600);tmp.replace(final)
    print('MARKET_VARIANCE_WITNESS',route,
          'comparison',decisions['state'],
          'price_fingerprints',len(set(report['displayed_prices_sha'].values())),
          'listing_fingerprints',len(set(report['public_listing_sha'].values())),
          'raw_html_fingerprints',len(set(report['whole_html_sha'].values())),
          'full_html_share_authorized',report['full_html_share_authorized'])

if __name__=='__main__':
    if len(sys.argv)!=2:raise SystemExit(2)
    run(sys.argv[1])
