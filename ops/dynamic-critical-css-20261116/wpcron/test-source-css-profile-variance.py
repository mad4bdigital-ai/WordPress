#!/usr/bin/env python3
"""Exactly distinct source CSS bundles must never inherit another certificate."""
import importlib.util
import copy
import tempfile,os,time
from pathlib import Path
p=Path(__file__).with_name('source-css-profile-variance.py')
sp=importlib.util.spec_from_file_location('css_cohort',p)
m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)
LANGS=m.LANGS
def row(lang):
 route='/' if lang=='en' else '/'+lang+'/'
 return {'route':route,'file':'a'*32+'.css','sha256':'b'*64,'elementor_doc_fingerprint':'c'*64}
http={'contract':'mad4b.source-home-css-change-observation.v1','host':'tours.dev.mad4b.com',
      'fingerprint':'f'*64,'sources':{lang:row(lang) for lang in LANGS}}
browser={lang:{'css_file':'a'*32+'.css','css_sha':'b'*64,
   'doc_sha':'c'*64,'ready':'1'} for lang in LANGS}
def entry(cssfile,sha,docs):
 return {sha:{'bundle_file':cssfile,'variants':{
   (('/' if lang=='en' else '/'+lang+'/')+'|'+lang+'|mobile'):{
     'gate_status':'pass','css_epoch':129,'elementor_doc_fingerprint':docs}
   for lang in LANGS}}}
manifest={'entries':entry('a'*32+'.css','b'*64,'c'*64)}
def ck(name,good):
 assert good,name
 print('PASS',name)
got=m.classify(http,manifest,browser,129)
ck('six exact matching original QA proofs',not got['http_profiles_pending_visual_qa'])
ck('report cannot issue its own certificate',got['certificate_issued'] is False and got['wp_ticket_acknowledged'] is False)
changed={'contract':http['contract'],'host':http['host'],'fingerprint':http['fingerprint'],
         'sources':{lang:dict(v) for lang,v in http['sources'].items()}}
changed['sources']['en']={'route':'/','file':'d'*32+'.css','sha256':'e'*64,'elementor_doc_fingerprint':'f'*64}
z=m.classify(changed,manifest,browser,129)
ck('new EN CSS and Elementor HTML graph cannot reuse Chromium certificate',
   z['http_profiles_pending_visual_qa']==['en']
   and z['per_locale']['en']['chromium_previous_certificate_matched'] is True
   and z['per_locale']['en']['http_previous_independent_certificate_matched'] is False)
manifest2={'entries':dict(manifest['entries'])}
manifest2['entries']['e'*64]={'bundle_file':'d'*32+'.css',
    'variants':{'/|en|mobile':{'gate_status':'pass','css_epoch':129,
               'elementor_doc_fingerprint':'f'*64}}}
zz=m.classify(changed,manifest2,browser,129)
ck('separate profile accepted only with exact independent real QA certificate',
   zz['http_profiles_pending_visual_qa']==[]
   and zz['per_locale']['en']['status']=='both_profiles_independently_certified')
manifest3={'entries':copy.deepcopy(manifest2['entries'])}
manifest3['entries']['e'*64]['variants']['/|en|mobile']['css_epoch']=128
zz=m.classify(changed,manifest3,browser,129)
ck('stale CSS epoch never accepted',zz['http_profiles_pending_visual_qa']==['en'])
missing=dict(browser);missing.pop('en')
zz=m.classify(http,manifest,missing,129)
ck('expired browser readback cannot fabricate a fresh Chromium result',
   zz['browser_profiles_missing_or_expired']==['en']
   and zz['http_profiles_pending_visual_qa']==[]
   and zz['per_locale']['en']['http_previous_independent_certificate_matched'] is True
   and zz['per_locale']['en']['chromium_previous_certificate_matched'] is False
   and zz['certificate_issued'] is False)
zz_unqualified=m.classify(changed,manifest,missing,129)
ck('missing Chromium plus missing exact native CSS certificate stays pending',
   zz_unqualified['http_profiles_pending_visual_qa']==['en']
   and zz_unqualified['browser_profiles_missing_or_expired']==['en']
   and zz_unqualified['per_locale']['en']['http_previous_independent_certificate_matched'] is False)
# A newer uncertified host-egress browser snapshot must not erase the
# older, independently valid Docker-bridge Chrome certificate.
two={lang:[dict(row)] for lang,row in browser.items()}
two['en'].insert(0,{'css_file':'d'*32+'.css','css_sha':'e'*64,
                    'doc_sha':'f'*64,'ready':''})
result=m.classify(changed,manifest,two,129)
ck('new host source remains pending even when old bridge proof survives',
   result['http_profiles_pending_visual_qa']==['en']
   and result['per_locale']['en']['chromium_previous_certificate_matched'] is True
   and result['per_locale']['en']['browser_profiles_seen']==2
   and result['per_locale']['en']['http_current_profile_seen_in_browser'] is True)
two['en'][0]['ready']='1'
result=m.classify(changed,manifest2,two,129)
ck('two different exact CSS proofs may coexist without borrowing QA',
   result['http_profiles_pending_visual_qa']==[]
   and len(result['per_locale']['en']['chromium_qualified_source_shas'])==2
   and result['per_locale']['en']['status']=='same_exact_profile')
# A native job directory can have a recent mtime even when the independent
# Chromium observed/ directory is outside the 90-minute readback window.
# This stale fact must not abort new valid browser cohort checks.
with tempfile.TemporaryDirectory() as d:
    root=Path(d)
    good=root/'20261118T152815Z';good.mkdir()
    (good/'observed').mkdir()
    stale=root/'20261118T151455Z';stale.mkdir()
    (stale/'observed').mkdir()
    now=time.time()
    os.utime(good,(now,now))
    os.utime(good/'observed',(now,now))
    os.utime(stale,(now,now))
    os.utime(stale/'observed',(now-5500,now-5500))
    seen=[]
    original=m.native_browser_rows
    try:
        def mock_native(p,**kw):
            seen.append(p)
            return {'en':dict(browser['en'])}
        m.native_browser_rows=mock_native
        rows=m.browser_provenance([good,stale])
        ck('stale observed dir cannot suppress newer real Chrome cohort',
           seen==[good/'observed'] and len(rows.get('en',[]))==1)
        seen.clear()
        rows=m.browser_provenance([stale])
        ck('only stale receipts never grant a browser source certificate',
           rows=={} and m.classify(http,manifest,rows,129)
           ['browser_profiles_missing_or_expired']==list(LANGS)
           and m.classify(http,manifest,rows,129)
           ['http_profiles_pending_visual_qa']==[])
        wrong=root/'20261118T164000Z';wrong.mkdir()
        (wrong/'observed').symlink_to(good/'observed',target_is_directory=True)
        try:m.browser_provenance([wrong,good])
        except ValueError as e:
            ck('symlinked newest browser readback still fails closed',
               str(e)=='untrusted_browser_report')
        else:raise AssertionError('untrusted observed symlink admitted')
    finally:m.native_browser_rows=original
print('CSS_SOURCE_DUAL_PROFILE_TESTS 12')
