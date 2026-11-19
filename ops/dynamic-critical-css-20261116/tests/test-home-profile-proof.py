"""Client-hint HTML fallback vs an authentic Chrome Critical CSS certificate."""
import importlib.util,json,pathlib,sys,tempfile,hashlib,time,datetime
from pathlib import Path
D=Path(sys.argv[1])
spec=importlib.util.spec_from_file_location('audit_profile',D/'health-audit.py')
a=importlib.util.module_from_spec(spec);spec.loader.exec_module(a)
checks=[]
def check(label,value):
    assert value,label
    checks.append(label)
    print('PASS',label)
with tempfile.TemporaryDirectory() as d:
    root=Path(d);old=a.CHROMIUM_REPORT_ROOT
    a.CHROMIUM_REPORT_ROOT=root/'runs'
    try:
        stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
        run=root/'runs'/'source'/stamp
        (run/'observed').mkdir(parents=True)
        site={'site_host':'tours.dev.mad4b.com','environment':'staging',
              'critical_css':'on','css_epoch':129,'native_breakpoint':767,
              'critical_css_dirty_generation':5,'wpml_languages':['en','it']}
        (run/'site.json').write_text(json.dumps(site))
        (run/'observed/it.html').write_text('<html><body>Chrome certified</body></html>')
        (run/'observed/it.headers').write_text('HTTP/2 200\nx-mad4b-mobile-css-ready: 1\n')
        original=a.assess.classify
        try:
            a.assess.classify=lambda *args,**kwargs: {'status':'certified_active',
                          'css_sha':'a'*64,'critical_cap_px':599}
            now=a.verify_recent_chromium_home('source','it',root,site,5)
            check('fresh certified Chrome profile recorded without changing HTTP response',
                  now and now['critical_cap_px']==599)
            check('different first-paint dependency not trusted',
                  a.verify_recent_chromium_home('source','it',root,site,6) is None)
            check('stale CSS epoch not trusted',
                  a.verify_recent_chromium_home('source','it',root,dict(site,css_epoch=130),5) is None)
            check('changed WPML activated language set invalidates old browser evidence',
                  a.verify_recent_chromium_home('source','it',root,dict(site,wpml_languages=['en','it','fr']),5) is None)
            check('wrong company cannot claim source Chrome proof',
                  a.verify_recent_chromium_home('wayfa','it',root,site,5) is None)
            check('bad source snapshot cannot claim cert',
                  a.verify_recent_chromium_home('source','fr',root,site,5) is None)
            a.assess.classify=lambda *args,**kwargs: {'status':'needs_native_browser_qa'}
            check('failing original certificate never promoted by stale report',
                  a.verify_recent_chromium_home('source','it',root,site,5) is None)
        finally:
            a.assess.classify=original
    finally:a.CHROMIUM_REPORT_ROOT=old
print(f'PASS {len(checks)}/{len(checks)} safe Chrome client-hint variant classification invariants')
