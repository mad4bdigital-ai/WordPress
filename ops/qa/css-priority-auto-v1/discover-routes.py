#!/usr/bin/env python3
"""Read-only WPML/published-page discovery. Private checkout/booking paths are excluded."""
import subprocess,json,re,sys
from urllib.parse import urlparse
from pathlib import Path
Q=Path(__file__).resolve().parent
code='''$s=get_option("icl_sitepress_settings",[]);$langs=$s["active_languages"]??["en","ar","fr","de","es","it"];$posts=get_posts(["post_type"=>"page","post_status"=>"publish","posts_per_page"=>20,"orderby"=>"modified","order"=>"DESC","suppress_filters"=>false]);$urls=[];foreach($posts as $p){$urls[]=get_permalink($p->ID);}echo wp_json_encode(["langs"=>$langs,"urls"=>$urls]);'''
cmd=['docker','exec','-u','www-data','etg-commerce-staging-php','sh','-lc',"cd /var/www/html; wp eval '"+code+"' --quiet"]
try:r=subprocess.run(cmd,capture_output=True,text=True,timeout=28,check=True); obj=json.loads(r.stdout)
except Exception as e:print('DISCOVER_FAIL: '+str(e)[:180],file=sys.stderr);sys.exit(2)
langs=set(x for x in obj.get('langs',[]) if x in ('en','ar','fr','de','es','it'))
if not langs:raise SystemExit('No verified WPML locales')
base=['/' if lang=='en' else '/'+lang+'/' for lang in sorted(langs)]
base+=['/tours-and-activities/' if lang=='en' else '/'+lang+'/tours-and-activities/' for lang in sorted(langs)]
for url in obj.get('urls',[]):
 try:u=urlparse(url)
 except:continue
 if u.hostname!='tours.dev.mad4b.com' or u.query or u.fragment:continue
 path=u.path
 if not path.endswith('/') or path in base:continue
 if re.search(r'(?:checkout|cart|my-account|payment|booking|book-now|order|thank-you|login|register|wp-admin|wp-json|privacy-policy)',path,re.I):continue
 if re.fullmatch(r'/[a-zA-Z0-9/_~.-]{1,250}',path) and '..' not in path:base.append(path)
base=list(dict.fromkeys(base))[:48]
(Q/'routes.json').write_text(json.dumps({'site':'tours.dev.mad4b.com','paths':base},indent=2)+'\n')
print(json.dumps({'count':len(base),'languages':sorted(langs),'sample':base[:10]}))
