#!/usr/bin/env python3
"""Fresh original WordPress HTML pre-release source graph stability proof.

This is a mandatory READ-ONLY source preflight for Wayfatours ordered
Multi-CSS certification. It cannot issue CSS, modify certificates or ACK a
WordPress job. It rejects source drift BEFORE release-first issuance, leaving
the current native CSS intact and a retryable first-party queue HOLD.

The source is the already captured original CSS bundle/HTML from the
authenticated WordPress catalog, NEVER FastCGI's cached public HTML.
"""
from pathlib import Path
import hashlib,importlib.util,json,os,re,sys

HERE=Path(__file__).resolve().parent
def load(name,file):
 spec=importlib.util.spec_from_file_location(name,HERE/file)
 m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m

observer=load('mad4b_original_source_stability_observer',
              'public-css-manifest-observer.py')
queue=load('mad4b_original_source_stability_ledger','public-route-queue.py')
CONTRACT='mad4b.public-css.original-html-stable-before-release.v1'
HOST='wayfatours.com'
ROOT=Path('/opt/mad4b-company-provisioning/reports/public-critical-css-v1/wayfa')
WP=Path('/opt/wayfa-rebuild-20261102/site-copy/wp-content')
SHA=re.compile(r'^[0-9a-f]{64}$')

def check(ok,reason):
 if not ok:raise ValueError(reason)

def doc_fingerprint(page):
 pos=page.lower().find('<body')
 check(pos>=0,'missing-original-body-source-graph')
 docs=sorted({int(d) for d in re.findall(
     r'(?<![a-z0-9_-])elementor-(\d+)\b',page[pos:],re.I)})
 check(bool(docs),'empty-original-elementor-graph')
 return hashlib.sha256(','.join(map(str,docs)).encode()).hexdigest()

def verify(catalog,route,wp,job,*,fetch=observer.public_fetch):
 wp=Path(wp);job=Path(job)
 check(isinstance(catalog,dict)
       and catalog.get('contract')=='mad4b.public-critical-css.routes.v1'
       and catalog.get('host')==HOST
       and route in catalog.get('routes',[]),
       'source-stability-route-not-firstparty-enrolled')
 check(isinstance(route,dict)
       and route.get('type') in {
          'page','post','tours-and-activities','properties',
          'destinations','tour-types','property-types','tour-categories',
          'category','archive:tours-and-activities','archive:properties'}
       and route.get('language') in ('en','es')
       and isinstance(route.get('path'),str)
       and isinstance(route.get('route_key'),str)
       and SHA.fullmatch(route['route_key']) is not None,
       'source-stability-route-identity-untrusted')
 check(wp.is_dir() and wp.name=='wp-content' and not wp.is_symlink()
       and job.is_dir() and not job.is_symlink(),
       'source-stability-unsafe-company-filesystem')
 srcfile=job/'source.html'
 reportfile=job/'multi-css-source-report.json'
 manifestfile=job/'css-dependencies.json'
 for f in (srcfile,reportfile,manifestfile):
  check(f.is_file() and not f.is_symlink()
        and 0<f.stat().st_size<observer.ordered.MAX_HTML+1,
        'source-stability-private-evidence-missing')
 original=srcfile.read_text(encoding='utf-8')
 report=json.loads(reportfile.read_text())
 persisted=json.loads(manifestfile.read_text())
 check(report.get('contract')==observer.CONTRACT
       and report.get('host')==HOST
       and report.get('path')==route['path']
       and report.get('route_key')==route['route_key']
       and report.get('language')==route['language']
       and report.get('css_source_proven') is True,
       'source-stability-capture-receipt-untrusted')
 check(report.get('original_html_sha256')
       ==observer.ordered.sha(original.encode()),
       'source-stability-stored-html-tampered')
 before=observer.ordered.inspect(original,HOST,wp)
 check(before==persisted
       and before.get('manifest_sha256')==report.get('manifest_sha256'),
       'source-stability-recorded-css-source-mismatch')
 # A conditional Elementor Jet Dropbar can render a previously omitted
 # template without changing 421 listing generation, CSS epoch or CSS
 # manifest. Two independent ORIGINAL WordPress/BYPASS observations must
 # agree with the first-party captured source; one lucky view is not proof.
 # This is only a read-only PRE-ISSUE gate; every original 5-width browser
 # and postrelease qualified readback is still required.
 initial_doc=doc_fingerprint(original)
 for observation in (1,2):
  current,head=fetch(HOST,route['path'])
  check(isinstance(current,str) and isinstance(head,dict),
        'source-stability-fresh-origin-malformed')
  check(head.get('X-Wayfa-Origin-Cache')=='BYPASS'
        and 'no-store' in head.get('Cache-Control','').lower(),
        'source-stability-fresh-wordpress-bypass-missing')
  check(head.get('X-MAD4B-Public-CCSS-Ready')!='1',
        'source-stability-original-already-optimized')
  check(head.get('X-MAD4B-Public-CCSS-Market')==report.get('market_country')
        and head.get('X-MAD4B-Public-CCSS-Currency')==report.get('currency'),
        'source-stability-wcml-market-context-changed')
  after=observer.ordered.inspect(current,HOST,wp)
  check(after['manifest_sha256']==before['manifest_sha256'],
        'source-stability-ordered-css-manifest-changed')
  check(initial_doc==doc_fingerprint(current),
        'source-stability-elementor-document-graph-changed')
 return {'contract':CONTRACT,'decision':'same-original-source-before-release',
   'site':'wayfa','route_key':route['route_key'],
   'path':route['path'],'language':route['language'],
   'market_country':report['market_country'],'currency':report['currency'],
   'ordered_css_sha':before['manifest_sha256'],
   'elementor_doc_sha':initial_doc,
   'verified_fresh_origin':'BYPASS',
   'independent_original_wordpress_observations':2,
   'public_certification_issued':False,
   'wordpress_ack':False}

def main():
 check(len(sys.argv)==5,'USAGE catalog.json route.json wp-content private-job')
 _,cat,route,wp,job=sys.argv
 root=ROOT
 output=Path(job)
 check(Path(wp)==WP and output.parent==root
       and re.fullmatch(r'20[0-9]{6}T[0-9]{6}Z',output.name) is not None,
       'source-stability-path-not-enrolled')
 data=verify(queue.read(cat),queue.read(route),Path(wp),output)
 receipt=output/'original-source-stability-before-release.json'
 check(not receipt.is_symlink(),'source-stability-output-symlink')
 queue.atom(receipt,data)
 print('PUBLIC_SOURCE_STABLE_BEFORE_RELEASE',
       json.dumps(data,sort_keys=True),flush=True)

if __name__=='__main__':
 try:main()
 except (OSError,ValueError,TypeError,KeyError,UnicodeError,json.JSONDecodeError) as e:
  print('HOLD_PUBLIC_SOURCE_STABILITY',str(e)[:180],file=sys.stderr)
  sys.exit(4)
