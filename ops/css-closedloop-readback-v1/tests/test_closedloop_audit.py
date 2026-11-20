#!/usr/bin/env python3
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile

HERE=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('css_audit',HERE/'closedloop_audit.py')
audit=importlib.util.module_from_spec(spec)
spec.loader.exec_module(audit)
tests=0

def check(name,expression):
    global tests
    assert expression,name
    tests+=1
    print('PASS',name)

def deny(name,func):
    try:func()
    except (ValueError,KeyError):
        check(name,True)
    else:raise AssertionError(name)

h='HTTP/2 200\r\nx-wayfa-origin-cache: HIT\r\nCache-Control: public, max-age=1800\r\n'
s=audit.html_delivery(h,'<html><head><link rel="stylesheet" href="a.css"></head></html>')
check('HIT alone never declares mobile CSS optimized',s['cache_hit_without_ccss_markup'] and not s['css_delivery_markup_visible'])
check('HIT alone never asserts certificate context',not s['certificate_context_cryptographically_revalidated'])
home='<style id="mad4b-critical-css-v1">x</style><link rel="stylesheet" id="mad4b-css-rest-v1" href="rest.css">'
check('home dual-asset proof requires header',not audit.html_delivery(h,home)['css_delivery_markup_visible'])
check('home dual-asset proof with readiness header',audit.html_delivery(h+'X-MAD4B-Mobile-CSS-Ready: 1\r\n',home)['css_html_readback']=='home_mobile_css_markup')
check('home corrupted missing css-rest fails closed',not audit.html_delivery(h+'X-MAD4B-Mobile-CSS-Ready: 1\r\n',home.replace('mad4b-css-rest-v1','other'))['css_delivery_markup_visible'])
route='<style id="mad4b-public-critical-css-v1"></style><link id="mad4b-public-css-rest-v1" rel="stylesheet">'
check('v1 route readback requires correct header',not audit.html_delivery(h,route)['css_delivery_markup_visible'])
check('v1 route header and markup observed',audit.html_delivery(h+'X-MAD4B-Public-CCSS-Ready: 1\r\n',route)['css_html_readback']=='public_route_v1_markup')
v2=('<style media="(max-width:767px)" data-mad4b-ccss-v2-critical="1"></style>'
    '<link rel="stylesheet" data-mad4b-ccss-v2-rest="1">'
    '<link rel="stylesheet" data-mad4b-ccss-v2-rest="1">'
    '<script id="mad4b-public-ccss-v2-recovery"></script>')
check('v2 multi-source markup present but not certificate proof',
      audit.html_delivery(h+'X-MAD4B-Public-CCSS-Ready: 1\r\n',v2)['css_html_readback']=='public_route_v2_markup'
      and not audit.html_delivery(h+'X-MAD4B-Public-CCSS-Ready: 1\r\n',v2)['certificate_context_cryptographically_revalidated'])
check('v2 missing recovery holds',not audit.html_delivery(h+'X-MAD4B-Public-CCSS-Ready: 1\r\n',v2.replace('mad4b-public-ccss-v2-recovery','missing'))['css_delivery_markup_visible'])
check('browser private no-store is independent of origin cache HIT',
      audit.html_delivery(h+'Cache-Control: private, no-store\r\n',home)['browser_cache_private_or_no_store'])
check('redirect first-hop HIT is not counted for final bypass',
      audit.html_delivery(h+'HTTP/2 200\r\nX-Wayfa-Origin-Cache: BYPASS\r\n',route)['cache_state']=='BYPASS')

with tempfile.TemporaryDirectory(prefix='css-closedloop-test-') as td:
 root=Path(td)
 wp=root/'site/wp-content';(wp/'mu-plugins').mkdir(parents=True)
 (wp/'mu-plugins/422-mad4b-public-critical-css-runtime.php').write_text('<?php // exact fixture')
 runtime=hashlib.sha256((wp/'mu-plugins/422-mad4b-public-critical-css-runtime.php').read_bytes()).hexdigest()
 certdir=wp/'mad4b-public-critical-css-v1/certs';certdir.mkdir(parents=True)
 (wp/'mad4b-public-critical-css-v1/assets').mkdir(parents=True)
 (wp/'litespeed/css').mkdir(parents=True)
 css_name='a'*32+'.css';critical_name=hashlib.sha256(b'b{}').hexdigest()+'.css'
 family={'contract':'mad4b.public-css.source-family-registry.v1','site':'source','host':'example.test',
 'css_epoch':152,'routes_discovered':3,'source_groups_total':2,
 'routes_with_exact_current_certificate':0,'source_canary_qualified_groups':0,
 'source_families':[{'status':'source_canary_pending','attempted_canary_count':0},
                    {'status':'source_native_optimizer_market_partial','attempted_canary_count':1}]}
 fam=root/'families.json';fam.write_text(json.dumps(family))
 cert={'contract':'mad4b.public-critical-css.route-certificate.v1','status':'certified',
       'css_epoch':129,'runtime_sha':runtime,'css_file':css_name,'critical_file':critical_name,
       'css_sha':hashlib.sha256(b'a{}').hexdigest(),
       'critical_sha':hashlib.sha256(b'b{}').hexdigest()}
 (certdir/'1.json').write_text(json.dumps(cert))
 (wp/'litespeed/css'/css_name).write_text('a{}')
 (wp/'mad4b-public-critical-css-v1/assets'/critical_name).write_text('b{}')
 report=audit.build('source',wp,fam)
 check('source routes/families remain independent from stored certificates',
       report['routes_discovered']==3 and report['template_families']==2
       and report['fully_qualified_source_families']==0)
 check('stale epoch invalidates old certificate despite real CSS files',
       report['saved_public_certificates']['stored']==1
       and report['saved_public_certificates']['epoch_mismatch']==1
       and report['saved_public_certificates']['minimum_epoch_runtime_assets_eligible']==0)
 check('source family pending and market partial do not authorize reuse',
       report['family_statuses']=={'source_canary_pending':1,'source_native_optimizer_market_partial':1}
       and not report['template_family_reuse_authorized_by_this_report'])
 cert['css_epoch']=152;cert['runtime_sha']='deadbeef';(certdir/'1.json').write_text(json.dumps(cert))
 check('wrong runtime SHA prevents eligibility',
       audit.build('source',wp,fam)['saved_public_certificates']['runtime_mismatch']==1
       and audit.build('source',wp,fam)['saved_public_certificates']['minimum_epoch_runtime_assets_eligible']==0)
 cert['runtime_sha']=runtime;(certdir/'1.json').write_text(json.dumps(cert))
 (wp/'litespeed/css'/css_name).unlink()
 check('missing original combined stylesheet blocks minimum eligibility',
       audit.build('source',wp,fam)['saved_public_certificates']['combined_css_missing']==1
       and audit.build('source',wp,fam)['saved_public_certificates']['minimum_epoch_runtime_assets_eligible']==0)
 (wp/'litespeed/css'/css_name).write_text('a{}')
 check('matching epoch/runtime/files is only minimum eligibility not delivery certificate',
       audit.build('source',wp,fam)['saved_public_certificates']['minimum_epoch_runtime_assets_eligible']==1
       and audit.build('source',wp,fam)['saved_public_certificates']['route_qualified_after_full_context_validation']=='not_asserted')
 (wp/'litespeed/css'/css_name).write_text('a{color:black}')
 check('same name but changed CSS bytes invalidates stored certificate',
       audit.build('source',wp,fam)['saved_public_certificates']['combined_css_sha_mismatch']==1
       and audit.build('source',wp,fam)['saved_public_certificates']['minimum_epoch_runtime_assets_eligible']==0)
 (wp/'litespeed/css'/css_name).write_text('a{}')
 (wp/'mad4b-public-critical-css-v1/assets'/critical_name).write_text('b{color:red}')
 check('modified critical asset SHA cannot count toward eligibility',
       audit.build('source',wp,fam)['saved_public_certificates']['critical_asset_sha_mismatch']==1)
 (wp/'mad4b-public-critical-css-v1/assets'/critical_name).write_text('b{}')
 samples=[{'name':'cached_tour','headers':str(root/'tour.headers'),'html':str(root/'tour.html')}]
 (root/'tour.headers').write_text(h)
 (root/'tour.html').write_text('<link rel="stylesheet" href="a.css">')
 (root/'samples.json').write_text(json.dumps(samples))
 check('independent public cache sample is not counted as certified',
       audit.build('source',wp,fam,root/'samples.json')['html_samples'][0]['cache_hit_without_ccss_markup'])
 profile=root/'profile.json'
 profile.write_text(json.dumps({'contract':'mad4b.css.site-owned-cron.profile.v1',
      'site_id':'source','site_host':'example.test','tasks':['public','first_fold']}))
 cron=root/'cron';cron.mkdir()
 report_with_profile=audit.build('source',wp,fam,root/'samples.json',cron,profile)
 check('profile confirms missing families visual audit are not enrolled',
       all(report_with_profile['cron_receipts'][job]['receipt']=='not_enrolled_in_site_owned_profile'
           for job in ['families','visual','audit']))
 check('enrolled job without receipt stays unknown, never treated as completed',
       report_with_profile['cron_receipts']['public']['receipt']=='missing_receipt_not_proof_of_runtime_schedule')
 deny('site identity mismatch refused',lambda:audit.build('wayfa',wp,fam))
 (root/'tour.html').unlink();(root/'tour.html').symlink_to(root/'tour.headers')
 deny('symlink evidence refused',lambda:audit.build('source',wp,fam,root/'samples.json'))
print(f'PASS {tests}/{tests} closedloop source-only readback regression tests')
