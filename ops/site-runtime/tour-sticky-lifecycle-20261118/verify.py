from pathlib import Path
import hashlib,json,re,shutil,subprocess
r=Path(__file__).resolve().parent
manifest=json.loads((r/'manifest.json').read_text())
assert manifest['contract']=='mad4b.tour.sticky.lifecycle.v1'
assert manifest['deployment_authority'] is False
assert len(manifest['paths'])==8
for rel,sha in manifest['paths'].items():
 assert rel.startswith('payload/') and '..' not in Path(rel).parts
 assert (r/rel).is_file() and hashlib.sha256((r/rel).read_bytes()).hexdigest()==sha,rel
js=(r/'payload/mu-plugins/assets'/manifest['asset']).read_text()
php=(r/'payload/mu-plugins/407-mad4b-elementor-sticky-lifecycle-guard.php').read_text()
for marker in ('var saved = $elementCSSBackup.data(', "if ( saved && 'object' === typeof saved ) return saved;", 'oldInstance.destroy();','window.MAD4BStickyLifecycleGuard.recoveries++','window.MAD4BStickyLifecycleGuard.reinitializations++'):
 assert marker in js,marker
for marker in ('is_singular(', 'hash_file(', 'hash_equals(', 'add_assets(', 'wp_scripts()', 'script_loader_src', "'e-sticky'"):
 assert marker in php,marker
assert re.fullmatch(r'mad4b-elementor-sticky-lifecycle-[a-f0-9]{16}\.js',manifest['asset'])
rate=(r/'payload/mu-plugins/410-mad4b-tour-rate-lazy-footprint.php').read_text()
watch=(r/'payload/host/bin/company-form6-asset-cache-reconciler-v1.py').read_text()
for marker in ('mad4b-rate-lazy-footprint-v1','jet-engine-query-count','count-type-total','rateRowHeight=138.5','MutationObserver'):
 assert marker in rate,marker
menu=(r/'payload/mu-plugins/411-mad4b-direct-menu-link-contract.php').read_text()
for marker in ('mad4b.n-menu.direct-link.v1','link-only','e-n-menu-dropdown-icon',
               'MutationObserver','pageshow'):
 assert marker in menu,marker
bottom=(r/'payload/mu-plugins/428-mad4b-mobile-bottom-action-nav-visibility.php').read_text()
for marker in ('mad4b-mobile-bottom-nav-policy-v1','mad4b-mobile-dock-host',
               'mad4b-tour-mobile-action-dock','mad4b-archive-price-value',
               '[aria-expanded="true"]','[data-settings*=' ):
 assert marker in bottom,marker
for marker in ('RATE_FOOTPRINT','STICKY_GUARD','STICKY_ASSET','DIRECT_MENU',
               'BOTTOM_NAV','code=[verified_regular(f)'):
 assert marker in watch,marker
for site in ('source','wayfa'):
 unit=(r/f'payload/host/systemd/mad4b-form6-permanent-assets-{site}.path').read_text()
 for marker in ('410-mad4b-tour-rate-lazy-footprint.php','407-mad4b-elementor-sticky-lifecycle-guard.php','mad4b-elementor-sticky-lifecycle-c77a2b2fbdc39daa.js','411-mad4b-direct-menu-link-contract.php','428-mad4b-mobile-bottom-action-nav-visibility.php'):
  assert marker in unit,marker
 assert unit.count('PathChanged=')==8

if shutil.which('node'):subprocess.run(['node','--check',str(r/'payload/mu-plugins/assets'/manifest['asset'])],check=True)
if shutil.which('php'):
 for name in ('407-mad4b-elementor-sticky-lifecycle-guard.php','410-mad4b-tour-rate-lazy-footprint.php','411-mad4b-direct-menu-link-contract.php','428-mad4b-mobile-bottom-action-nav-visibility.php'):
  subprocess.run(['php','-l',str(r/'payload/mu-plugins'/name)],check=True,stdout=subprocess.DEVNULL)
for file in ('direct-menu-link-contract.test.js','mobile-saved-market-return.test.js','mobile-interaction-resume.test.js','mobile-bottom-action-menu.test.js','archive-preportal-price.test.js'):
 if shutil.which('node'):subprocess.run(['node','--check',str(r/'tests'/file)],check=True)
print('STICKY_SOURCE_VERIFIED',len(manifest['paths']))
