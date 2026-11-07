#!/usr/bin/env python3
"""Read-only regression: WPML must initialize its native query hooks and render translations.

Usage: python3 script.py --case 'name|php_container|https://page|en,es|footer_required_0_or_1'
"""
import argparse
import json
import re
import subprocess
import sys
import urllib.request

p = argparse.ArgumentParser()
p.add_argument('--case', action='append', required=True, help='name|container|url|langs_csv|footer_required')
args = p.parse_args()
failed = 0
seen = set()
for spec in args.case:
    parts = spec.split('|')
    if len(parts) != 5:
        p.error('case requires 5 pipe-delimited fields')
    name, container, url, lang_csv, footer_required = parts
    langs = lang_csv.split(',')
    if not url.startswith('https://') or not all(re.fullmatch(r'[a-z]{2}', x) for x in langs):
        p.error('valid https URL and 2-letter language codes required')
    try:
        if container not in seen:
            seen.add(container)
            expr = '''$s=(array)get_option("icl_sitepress_settings",[]);$sp=$GLOBALS["sitepress"]??null;echo wp_json_encode(["verified"=>(int)($s["existing_content_language_verified"]??0),"setup"=>(int)($s["setup_complete"]??0),"native_wp_hook"=>is_object($sp)?has_action("wp",[$sp,"set_wp_query"]):false,"language_codes"=>is_object($sp)?array_keys((array)$sp->get_active_languages()):[]]);'''
            cmd = ['docker', 'exec', '-u', 'www-data', '-w', '/var/www/html', container, 'wp', 'eval', expr, '--skip-themes']
            proc = subprocess.run(cmd, text=True, capture_output=True, timeout=65)
            if proc.returncode: raise RuntimeError('wp-cli readback failed: '+proc.stderr[-240:])
            state = json.loads(proc.stdout.strip().splitlines()[-1])
            if state['verified'] != 1 or state['setup'] != 1 or state['native_wp_hook'] is False:
                raise AssertionError('WPML native bootstrap incomplete: '+str(state))
            if any(x not in state['language_codes'] for x in langs):
                raise AssertionError('configured language missing: '+str(state['language_codes']))
        request = urllib.request.Request(url, headers={'User-Agent':'MAD4B-WPML-ReadOnly-Regression/1.0'})
        with urllib.request.urlopen(request, timeout=43) as res:
            status = res.status
            html = res.read().decode('utf-8', 'replace')
        if status != 200: raise AssertionError('HTTP '+str(status))
        absent = [lang for lang in langs if 'wpml-ls-item-'+lang not in html]
        if absent: raise AssertionError('WPML widget languages missing: '+','.join(absent))
        no_head = [lang for lang in langs if 'hreflang="'+lang+'"' not in html]
        if no_head: raise AssertionError('SEO hreflang missing: '+','.join(no_head))
        if footer_required == '1' and 'wpml-ls-statics-footer' not in html:
            raise AssertionError('Footer language widget missing')
        print(json.dumps({'case':name,'passed':True,'status':status,'languages':langs,'footer_required':footer_required=='1','html_bytes':len(html)},ensure_ascii=False))
    except Exception as exc:
        failed += 1
        print(json.dumps({'case':name,'passed':False,'error':str(exc)},ensure_ascii=False),file=sys.stderr)
print(json.dumps({'cases':len(args.case),'failures':failed,'result':'PASS' if failed==0 else 'FAIL'}))
sys.exit(1 if failed else 0)
