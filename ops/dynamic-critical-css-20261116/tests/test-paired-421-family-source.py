#!/usr/bin/env python3
"""Original WPML 421 CSS-v2 paired source snapshot, no real WordPress."""
from pathlib import Path
import importlib.util,os,json,hashlib,tempfile,sys
here=Path(sys.argv[1])
def load(name,file):
 spec=importlib.util.spec_from_file_location(name,here/file)
 mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod);return mod
fam=load('staged_family_original_source','refresh-source-families.py')
planner=load('staged_original_bulk_paired_plan','bulk-release-plan.py')
m=load('staged_original_source_queue','public-route-queue.py')
n=0
def ok(label,passed):
 global n
 assert passed,label
 n+=1;print('PASS',label)
catalog={'contract':'mad4b.public-critical-css.routes.v1',
 'host':'wayfatours.com','wpml_languages':['en','es'],'default_language':'en',
 'generation':120,'global_generation':77,'listing_generation':93,
 'routes':[{'path':'/tours-and-activities/safe-test/','type':'tours-and-activities',
  'id':404,'language':'en','source_profile':'tour:en',
  'source_graph':'d'*64,'source_graph_status':'configured','source_document_ids':[400],
  'source_listing_dependency':True,'revision':4,'route_key':'a'*64}]}
state={'site_host':'wayfatours.com','critical_css':'on','css_epoch':258,
 'global_generation':77,'listing_generation':93,
 'runtime_sha':'a'*64,'consumer_v2_sha':'b'*64,
 'wcml_multi_currency':False,'enabled_currencies':['USD']}
report={'host':'wayfatours.com','css_epoch':258,'global_generation':77,'listing_generation':93}
with tempfile.TemporaryDirectory() as temp:
 root=Path(temp)
 site=root/'wayfa';site.mkdir(mode=0o700)
 original_report={'host':'wayfatours.com','css_epoch':258,'global_generation':77,'listing_generation':93}
 old=site/'20261122T111111Z';old.mkdir(mode=0o700)
 m.atom(old/'catalog.json',{**catalog,'listing_generation':92})
 m.atom(old/'site.json',{**state,'css_epoch':257})
 m.atom(site/'source-families-latest.json',original_report)
 planner.ROOT=root
 try:
  planner.clean_site('wayfa')
 except ValueError as e:
  ok('original stale catalog is refused rather than reused for V2 publication',
     'report-css-epoch-changed' in str(e))
 else:raise AssertionError('stale original first-party source admitted')
 pair=fam.paired_original_family_source_snapshot('wayfa',catalog,state,report,site,m.atom)
 ok('original WPML catalog+runtime pair is a new root-private source snapshot',
    pair.is_dir() and pair.stat().st_uid==0 and pair.stat().st_mode&0o077==0
    and (pair/'catalog.json').stat().st_mode&0o077==0)
 ok('source report carries independent exact immutable site and catalog hashes',
    hashlib.sha256((pair/'catalog.json').read_bytes()).hexdigest()==report['paired_catalog_sha256']
    and hashlib.sha256((pair/'site.json').read_bytes()).hexdigest()==report['paired_state_sha256'])
 m.atom(site/'source-families-latest.json',report)
 # Latest stat-mtime must not silently select old CSS epoch.
 os.utime(old,None)
 plan=planner.clean_site('wayfa')
 ok('paired snapshot heals source catalog/report epoch drift without synthetic grant',
    plan['source_snapshot']==str(pair) and plan['css_epoch']==258
    and plan['listing_generation']==93)
 ok('same original 421 route and Elementor graph retained',
    plan['eligible_for_source_preflight']==1
    and plan['waves'][0]['routes'][0]['route_key']=='a'*64)
 ok('snapshot represents READ ONLY plan with zero public CSS grants',
    plan['ready_for_public_release']==0 and plan['policy']['mutations'].startswith('NONE:'))
 (pair/'catalog.json').write_text('{}')
 try:planner.clean_site('wayfa')
 except ValueError as e:
  ok('tampered catalog SHA fails closed before any CSS selection',
     'family-paired-source-content-hash-drift' in str(e))
 else:raise AssertionError('forged source planning admitted')
 m.atom(pair/'catalog.json',catalog)
 newreport={**report,'paired_source_snapshot':str(site/'../other/family-source-20261122T130000Z-111')}
 m.atom(site/'source-families-latest.json',newreport)
 try:planner.clean_site('wayfa')
 except ValueError:
  ok('foreign/multi-level path in family paired source cannot enter planning',True)
 else:raise AssertionError('foreign snapshot route admitted')
 for key,bad in [('css_epoch',999),('global_generation',76),('listing_generation',94)]:
  prior=report.copy();prior[key]=bad
  try:fam.paired_original_family_source_snapshot('wayfa',catalog,state,prior,site,m.atom)
  except ValueError:
   ok('first-party source pair rejects mismatched '+key,True)
  else:raise AssertionError('different original generation accepted')
 try:fam.paired_original_family_source_snapshot('source',catalog,state,report,site,m.atom)
 except ValueError:ok('source cannot reuse Wayfa original WPML site snapshot',True)
 else:raise AssertionError('wrong company pair accepted')
print('PASS',n,'paired original 421/422 snapshot versus stale job source tests')
