#!/usr/bin/env python3
"""Read-only code inventory of possible full-cache purges; never claim causal attribution."""
import argparse, datetime as dt, json, os, re, tempfile
from pathlib import Path

OUTPUT=Path('/opt/mad4b-company-provisioning/reports/company-cache-purge-origin-inventory-current.json')
SITES={
 'tours-dev':Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content'),
 'wayfatours':Path('/opt/wayfa-rebuild-20261102/site-copy/wp-content')
}
PATTERNS={
 'wp_object_flush':re.compile(r'\bwp_cache_flush\s*\(',re.I),
 'litespeed_full_action':re.compile(r'\b(?:do_action|apply_filters)\s*\(\s*[\'"]litespeed_purge_all[\'"]',re.I),
 'litespeed_object_action':re.compile(r'\bdo_action\s*\(\s*[\'"]litespeed_purge_all_object[\'"]',re.I),
 'litespeed_vendor_full':re.compile(r'\b(?:Purge|LiteSpeed\\\\Purge)::purge_all\s*\(',re.I),
 'redis_flushdb':re.compile(r'(?:->|::)\s*flush_?db\s*\(',re.I),
 'redis_flushall':re.compile(r'(?:->|::)\s*flush_?all\s*\(',re.I),
 'shell_redis_flush':re.compile(r'\bredis-cli\b[^\n]{0,160}\bFLUSH(?:DB|ALL)\b',re.I),
 'wp_cli_object_flush':re.compile(r'\bwp\s+cache\s+flush\b',re.I)
}
SUFFIXES={'.php','.py','.sh'}
MAX_BYTES=750_000
MAX_EVIDENCE=300
def inspect(root, label, skip_limit=50000):
    findings=[];counts={};files=0;skipped=0;total=0
    folders=[root/'mu-plugins',root/'plugins'] if label!='host' else [root]
    for folder in folders:
        if not folder.is_dir():continue
        for base,dirs,names in os.walk(folder,followlinks=False):
            dirs[:]=sorted(d for d in dirs if not (Path(base)/d).is_symlink()
                           and d not in ('node_modules','.git','vendor','cache'))
            for name in sorted(names):
                path=Path(base)/name
                if path.suffix not in SUFFIXES or path.is_symlink():continue
                files+=1
                if files>skip_limit:break
                try:
                    if path.stat().st_size>MAX_BYTES:skipped+=1;continue
                    body=path.read_text(errors='replace')
                except (OSError,UnicodeError):skipped+=1;continue
                relative=str(path.relative_to(root))
                owner=('vendor_litespeed' if relative.startswith('plugins/litespeed-cache/')
                    else 'mad4b_owned' if relative.startswith(('mu-plugins/','plugins/mad4b-'))
                    or label=='host' else 'other_plugin')
                for lineno,line in enumerate(body.splitlines(),1):
                    if line.lstrip().startswith(('#','//','*','/*')):continue
                    for kind,regex in PATTERNS.items():
                        if not regex.search(line):continue
                        key=owner+':'+kind
                        counts[key]=counts.get(key,0)+1
                        total+=1
                        if len(findings)<MAX_EVIDENCE:
                            findings.append({'path':relative,'line':lineno,'candidate':kind,'owner':owner})
            if files>skip_limit:break
    return {'files_scanned':files,'files_skipped':skipped,'candidate_count':total,
            'candidate_counts':counts,'evidence_truncated':total>MAX_EVIDENCE,
            'candidate_examples':findings}
def atomic(path,data):
    path.parent.mkdir(parents=True,exist_ok=True)
    fd,tmp=tempfile.mkstemp(prefix='.mad4b-origin-inventory-',dir=path.parent)
    try:
        with os.fdopen(fd,'w') as f:
            json.dump(data,f,indent=2,sort_keys=True);f.write('\n');f.flush();os.fsync(f.fileno())
        os.chmod(tmp,0o600);os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--selftest',action='store_true')
    args=ap.parse_args()
    if args.selftest:
        assert PATTERNS['wp_object_flush'].search('wp_cache_flush();')
        assert PATTERNS['redis_flushdb'].search('$redis->flushDB();')
        assert PATTERNS['litespeed_full_action'].search("do_action('litespeed_purge_all');")
        assert not PATTERNS['litespeed_full_action'].search("do_action('mad4b/public_html/safe_purge_all');")
        print('PURGE_ORIGIN_INVENTORY_SELFTEST_PASS');return
    sites={k:inspect(root,k) for k,root in SITES.items()}
    # Operations scripts are included as candidates, not confirmed callers.
    host=inspect(Path('/opt/mad4b-company-provisioning/bin'),'host')
    result={'contract':'mad4b.cache-purge-origin-inventory.v1',
       'utc':dt.datetime.now(dt.timezone.utc).isoformat(),
       'mode':'readonly_candidate_inventory_not_runtime_attribution',
       'scopes':'site_mu_plugins_all_plugins_and_company_host_bin',
       'captures_source_lines':False,'captures_customer_payloads':False,
       'redis_mutated':False,'cache_purged':False,'sites':sites,'host':host,
       'verdict':'CANDIDATES_REQUIRE_CORRELATION'}
    atomic(OUTPUT,result)
    print(json.dumps({'verdict':result['verdict'],'sites':{k:{
       'files':v['files_scanned'],'candidates':v['candidate_count'],
       'by_type':v['candidate_counts']} for k,v in sites.items()},
       'host_candidates':host['candidate_count']}))
if __name__=='__main__': main()

