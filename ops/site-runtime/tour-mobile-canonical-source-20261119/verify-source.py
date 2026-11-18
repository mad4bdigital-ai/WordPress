#!/usr/bin/env python3
"""Read-only, isolated source integrity and PHP/JS behavior contract."""
import hashlib
import base64
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
m = json.loads((HERE / 'manifest.json').read_text())
assert m['source_only'] is True and m['auto_deploy'] is False
assert m['production_mutated'] is False and m['upstream_evidence_pr'] == 895
assert len(m['files']) == 5
files = {}
for name, expected in m['files'].items():
    path = ROOT / name
    assert path.is_file() and not path.is_symlink(), name
    raw = path.read_bytes()
    assert hashlib.sha256(raw).hexdigest() == expected, name
    assert len(raw) < 30000, name
    text = raw.decode('utf-8')
    for bad in ('tours.dev.mad4b.com', 'wayfatours.com',
                '/opt/hostinger-migrations', '/opt/wayfa-rebuild',
                'shell_access', 'wp-config.php'):
        assert bad not in text, (name, bad)
    files[path.name] = text

js_name = next(x for x in files if x.endswith('.js'))
assert re.search(r'[a-f0-9]{16}\.js$', js_name)
assert hashlib.sha256(files[js_name].encode()).hexdigest()[:16] in js_name
assert "hash_equals($verifiedVendor" in files['407-mad4b-elementor-sticky-lifecycle-guard.php']
assert "count($files) !== 1" in files['407-mad4b-elementor-sticky-lifecycle-guard.php']
assert "jet-engine-query-count" in files['410-mad4b-tour-rate-lazy-footprint.php']
assert "mad4b.n-menu.direct-link.v1" in files['411-mad4b-direct-menu-link-contract.php']
assert "link-only" in files['411-mad4b-direct-menu-link-contract.php']
assert "mad4b-archive-price-value" in files['428-mad4b-mobile-bottom-action-nav-visibility.php']
assert "max-width:1024px" in files['428-mad4b-mobile-bottom-action-nav-visibility.php']
assert '[aria-expanded="true"]' in files['428-mad4b-mobile-bottom-action-nav-visibility.php']

php = shutil.which('php')
container = os.environ.get('MOBILE_QA_PHP_CONTAINER')
node = shutil.which('node')
assert (php or container) and node, 'PHP interpreter/container and Node.js are required'

def check(args):
    subprocess.run(args, check=True, stdout=subprocess.DEVNULL)

def php_lint(raw):
    if php:
        subprocess.run([php,'-l'],input=raw,check=True,stdout=subprocess.DEVNULL)
    else:
        subprocess.run(['docker','exec','-i',container,'php','-l'],input=raw,
                       check=True,stdout=subprocess.DEVNULL)

for name in m['files']:
    if name.endswith('.php'):
        php_lint((ROOT/name).read_bytes())
check([node,'--check',str(ROOT/'wp-content/mu-plugins/assets'/js_name)])

# Validate exactly the JavaScript emitted through the WordPress hooks.
with tempfile.TemporaryDirectory(prefix='mad4b-mobile-source-') as temp:
    for filename in ('410-mad4b-tour-rate-lazy-footprint.php',
                     '411-mad4b-direct-menu-link-contract.php'):
        src = files[filename]
        match = re.search(r'<script\s[^>]*>(.*?)</script>', src, re.S)
        assert match, filename
        temp_js = Path(temp)/(filename + '.js')
        temp_js.write_text(match.group(1))
        check([node,'--check',str(temp_js)])

# A WordPress-free fixture ensures public-only scripts/styles render, but
# no admin-side markup is added; isolated CLI, no site or DB access.
fixture = r'''
define('ABSPATH', '/');
$GLOBALS['hooks']=[];
function add_action($n,$cb,$p=10){$GLOBALS['hooks'][$n][]=$cb;}
function add_filter($n,$cb,$p=10,$a=1){}
function is_admin(){return getenv('MOBILE_QA_ADMIN')==='1';}
function wp_doing_ajax(){return false;}
function is_singular($type){return $type==='tours-and-activities';}
function is_preview(){return false;}
require $argv[1];
ob_start();
foreach(($GLOBALS['hooks']['wp_head']??[]) as $fn){$fn();}
echo ob_get_clean();
'''
for name, marker in (
    ('410-mad4b-tour-rate-lazy-footprint.php','mad4b-rate-lazy-footprint-v1'),
    ('411-mad4b-direct-menu-link-contract.php','mad4b-direct-menu-link-contract-v1'),
    ('428-mad4b-mobile-bottom-action-nav-visibility.php','mad4b-mobile-bottom-nav-policy-v1')):
    path=str(ROOT/'wp-content/mu-plugins'/name)
    if php:
        public_args=[php,'-r',fixture,path]
        admin_args=[php,'-r',fixture,path]
        pubenv={**os.environ,'MOBILE_QA_ADMIN':'0'}
        admenv={**os.environ,'MOBILE_QA_ADMIN':'1'}
    else:
        # Feed the exact source as base64 into an isolated PHP eval. No site
        # filesystem, WordPress bootstrap, database, or container file writes.
        code=base64.b64encode((ROOT/'wp-content/mu-plugins'/name).read_bytes()).decode()
        runfixture=fixture.replace('require $argv[1];',
                        'eval("?>" . base64_decode("'+code+'"));')
        public_args=['docker','exec','-i','-e','MOBILE_QA_ADMIN=0',container,'php','-r',runfixture]
        admin_args=['docker','exec','-i','-e','MOBILE_QA_ADMIN=1',container,'php','-r',runfixture]
        pubenv=os.environ.copy()
        admenv=os.environ.copy()
    public=subprocess.run(public_args,capture_output=True,text=True,check=True,env=pubenv)
    admin=subprocess.run(admin_args,capture_output=True,text=True,check=True,env=admenv)
    assert public.stdout.count(marker)>=1, name
    assert not admin.stdout, name
print('CANONICAL_MOBILE_SOURCE_PASS: 5 source SHA, 4 PHP, 3 JS, 3 public/admin hook pairs')
