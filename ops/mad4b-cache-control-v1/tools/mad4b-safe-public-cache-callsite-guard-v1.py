#!/usr/bin/env python3
"""Read-only alert for reintroduction of native LiteSpeed full/Redis purge in MAD4B modules."""
import datetime,json,re,sys
from pathlib import Path
BASE=Path('/opt/mad4b-company-provisioning/reports')
ROOTS={
 'tours-dev':Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content'),
 'wayfatours':Path('/opt/wayfa-rebuild-20261102/site-copy/wp-content')}
FULL=re.compile(r'\bdo_action\s*\(\s*[\'"]litespeed_purge_all[\'"]\s*[,)]')
FULL_METHOD=re.compile(r'\\?LiteSpeed\\Purge::purge_all\s*\(')
WP_FULL=re.compile(r'\bwp_cache_flush\s*\(')
REDIS_FULL=re.compile(r'(?:->|::)\s*flush_?(?:db|all)\s*\(',re.I)
DIRECT_OBJECT=re.compile(r'\bdo_action\s*\(\s*[\'"]litespeed_purge_all_object[\'"]')
def scan(site:Path):
    locations=[site/'mu-plugins']
    all_plugins=site/'plugins'
    if all_plugins.is_dir():
        # Enumerate every MAD4B plugin family, not only Platform/Control Plane.
        locations.extend(sorted(p for p in all_plugins.iterdir()
                                if p.is_dir() and not p.is_symlink()
                                and p.name.startswith('mad4b-')))
    hits=[]
    for root in locations:
        if not root.exists():continue
        for path in sorted(root.rglob('*.php')):
            if path.is_symlink() or not path.is_file() or path.stat().st_size>500000:
                continue
            text=path.read_text(errors='replace')
            for no,line in enumerate(text.splitlines(),1):
                if (FULL.search(line) or FULL_METHOD.search(line) or DIRECT_OBJECT.search(line) or WP_FULL.search(line) or REDIS_FULL.search(line)) and not line.strip().startswith(('*','//','#')):
                    hits.append({'module':str(path.relative_to(site)), 'line':no,
                                 'reason':'native_full_or_object_purge_bypasses_public_html_coherence'})
    return hits
def main():
    if '--selftest' in sys.argv:
        assert FULL.search("do_action('litespeed_purge_all');")
        assert FULL.search('do_action( "litespeed_purge_all", $reason );')
        assert FULL_METHOD.search(r'\LiteSpeed\Purge::purge_all();')
        assert DIRECT_OBJECT.search('do_action("litespeed_purge_all_object");')
        assert WP_FULL.search('wp_cache_flush();')
        assert REDIS_FULL.search('$redis->flushDB();')
        assert not FULL.search("do_action('mad4b/public_html/safe_purge_all','menu');")
        print('NATIVE_FULL_PURGE_CALLSITE_GUARD_SELFTEST_PASS');return
    sites={}
    for name,path in ROOTS.items():
        hits=scan(path)
        sites[name]={'status':'PASS' if not hits else 'UNSAFE_NATIVE_FULL_PURGE_CALLSITE',
                     'hits':hits,'hit_count':len(hits)}
    doc={'contract':'mad4b.public-cache.unsafe-callsite-guard.v1',
         'utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
         'scope':'MAD4B_mu_and_all_mad4b_plugin_families;no_vendor_files',
         'no_cache_or_wp_mutations':True,
         'sites':sites,'verdict':'PASS' if all(x['status']=='PASS' for x in sites.values()) else 'ALERT'}
    BASE.mkdir(parents=True,exist_ok=True)
    out=BASE/'company-cache-native-full-purge-guard-current.json'
    out.write_text(json.dumps(doc,indent=2,sort_keys=True)+'\n')
    print(json.dumps({'verdict':doc['verdict'],'counts':{k:v['hit_count'] for k,v in sites.items()}}))
    if '--strict' in sys.argv and doc['verdict']!='PASS':sys.exit(1)
if __name__=='__main__':main()

