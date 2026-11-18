#!/usr/bin/env python3
"""Exactly distinct source CSS bundles must never inherit another certificate."""
import importlib.util
import copy
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
ck('missing browser provenance never treated as complete',zz['http_profiles_pending_visual_qa']==['en'])
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
print('CSS_SOURCE_DUAL_PROFILE_TESTS 8')
