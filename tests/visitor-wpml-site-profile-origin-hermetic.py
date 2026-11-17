#!/usr/bin/env python3
"""Exercise actual Site Profile origin logic with isolated PHP/WordPress stubs.

No live site, network, database, cookies, owner grants or Production mutation.
"""
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-site-profile.php"
PHP = r'''<?php
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
define('ICL_SITEPRESS_VERSION', '4.7');
define('REST_REQUEST', ($argv[2] ?? '') === 'rest');
class SitePress {
    public function get_this_lang() { return $GLOBALS['case']['sitepress_code']; }
}
$GLOBALS['sitepress'] = new SitePress();
function home_url($path = '/') { return $GLOBALS['case']['observed']; }
function site_url($path = '/') { return $GLOBALS['case']['siteurl']; }
function get_option($key, $fallback = null) {
    if ($key === 'home') return 'https://tenant.example.test';
    if ($key === 'icl_sitepress_settings') return array(
        'active_languages' => $GLOBALS['case']['active'],
        'hidden_languages' => $GLOBALS['case']['hidden']
    );
    return $fallback;
}
function apply_filters($name, $fallback = '') { return $GLOBALS['case']['wpml_code']; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_doing_ajax() { return $GLOBALS['case']['ajax']; }
function absint($value) { return abs((int) $value); }
require $argv[1];
$base = 'https://tenant.example.test';
$defaults = array('observed'=>$base, 'siteurl'=>$base, 'wpml_code'=>'en',
  'sitepress_code'=>'en', 'active'=>array('en','es'), 'hidden'=>array(),
  'ajax'=>false, 'host'=>'tenant.example.test');
$cases = REST_REQUEST ? array(
  array('rest-localized-language', array('observed'=>$base.'/es/'), $base),
  array('rest-hidden-locale-denied', array('observed'=>$base.'/es/', 'hidden'=>array('es')), $base.'/es'),
  array('rest-foreign-host-denied', array('observed'=>$base.'/es/', 'host'=>'other.example.test'), $base.'/es'),
) : array(
  array('canonical-home', array(), $base),
  array('synchronous-native-language', array('observed'=>$base.'/en/'), $base),
  array('synchronous-mismatched-language-denied', array('observed'=>$base.'/es/'), $base.'/es'),
  array('ajax-localized-language', array('observed'=>$base.'/es/', 'ajax'=>true), $base),
  array('ajax-hidden-language-denied', array('observed'=>$base.'/es/', 'ajax'=>true, 'hidden'=>array('es')), $base.'/es'),
  array('ajax-unknown-language-denied', array('observed'=>$base.'/fr/', 'ajax'=>true), $base.'/fr'),
  array('ajax-deep-path-denied', array('observed'=>$base.'/es/tour/', 'ajax'=>true), $base.'/es/tour'),
  array('ajax-mismatched-host-denied', array('observed'=>$base.'/es/', 'ajax'=>true, 'host'=>'other.example.test'), $base.'/es'),
  array('ajax-foreign-origin-denied', array('observed'=>'https://foreign.example.test/es/', 'ajax'=>true), 'https://foreign.example.test/es'),
  array('ajax-siteurl-mismatch-denied', array('observed'=>$base.'/es/', 'ajax'=>true, 'siteurl'=>$base.'/other'), $base.'/es'),
  array('ajax-invalid-wpml-code-denied', array('observed'=>$base.'/es/', 'ajax'=>true, 'wpml_code'=>'../../'), $base.'/es'),
);
foreach ($cases as $item) {
    $GLOBALS['case'] = array_replace($defaults, $item[1]);
    $_SERVER['HTTP_HOST'] = $GLOBALS['case']['host'];
    $actual = MAD4B_SCP_Site_Profile::current_origin();
    if ($actual !== $item[2]) {
        fwrite(STDERR, 'FAIL: '.$item[0].' got='. $actual .' expected='. $item[2]."\n");
        exit(1);
    }
}
echo 'PASS: ' . count($cases) . ' Site Profile origin PHP scenarios ('.(REST_REQUEST?'REST':'normal/AJAX').")\n";
'''

def main():
    if not SOURCE.is_file():
        raise SystemExit("FAIL: original Site Profile PHP source unavailable")
    if not shutil.which("php"):
        raise SystemExit("FAIL: PHP CLI unavailable; source-only substitute forbidden")
    with tempfile.TemporaryDirectory(prefix="mad4b-wpml-origin-") as directory:
        script = Path(directory) / "fixture.php"
        script.write_text(PHP, encoding="utf-8")
        for mode in ("normal", "rest"):
            result = subprocess.run(
                ["php", str(script), str(SOURCE), mode], cwd=ROOT,
                capture_output=True, text=True, timeout=20,
            )
            if result.returncode:
                raise SystemExit(
                    "FAIL: " + mode + " Site Profile behavior: "
                    + (result.stderr + result.stdout)[-2000:]
                )
            if "PASS: " not in result.stdout:
                raise SystemExit("FAIL: " + mode + " PHP assertions lack PASS")
            print(result.stdout.strip())
    print("PASS: 14 original Site Profile normal/AJAX/REST positive and denial assertions")

if __name__ == "__main__":
    main()
