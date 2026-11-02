#!/usr/bin/env python3
"""Hermetic new GSC authority fixture; no Google, tenant, or WordPress writes."""
import argparse
import os
import pathlib
import re
import subprocess

parser=argparse.ArgumentParser()
parser.add_argument("--plugin-dir",default=None)
parser.add_argument("--php-source-path",default=None)
args=parser.parse_args()
root=pathlib.Path(args.plugin_dir) if args.plugin_dir else pathlib.Path(__file__).resolve().parents[1]
source=root/"includes/class-mad4b-scp-native-search-console.php"
assert source.is_file(),source
body=source.read_text()
required=[
    "MAD4B_SCP_Native_Search_Console",
    "MAD4B_SCP_Native_GSC_G5_Adapter",
    "measurement_broker_grant_for_scope(self::SCOPE)",
    "wp_parse_url($url)",
    "($target['host']??'')!=='www.googleapis.com'",
    "'redirection'=>0",
    "check_admin_referer('mad4b_gsc_action')",
    "'mad4b/search-console-status'",
    "'mad4b/search-console-observations'",
    "MAD4B_SCP_Admin_Route_Registry::schedule_submenu",
    "scope_selected",
    "MAD4B_SCP_G5_External_Providers::descriptor_guard",
]
for value in required[:-1]:
    assert value in body,f"Missing GSC invariant: {value}"
assert "return self::valid_snapshot();" in body
assert "content_modified'=>false" in body
assert "wp_remote_request($url,$args)" in body
assert not re.search(r"(egypttourgates\.com|allroyalegypt\.com)",body,re.I)
php=r'''
define('ABSPATH','/');
define('DAY_IN_SECONDS',86400);
interface MAD4B_SCP_G5_Stored_Observation_Adapter {}
class WP_Error {private $code;public function __construct($c,$message=''){$this->code=$c;}public function get_error_code(){return $this->code;}}
function add_filter($name,$cb,$priority=10){return true;}
function wp_parse_url($url,$component=-1){return parse_url($url,$component);}
function home_url($path='/'){return 'https://foo.example.test/';}
function untrailingslashit($value){return rtrim($value,'/');}
function wp_remote_request($url,$args){throw new RuntimeException('Outbound network called by denied test');}
require $argv[1];
$c='MAD4B_SCP_Native_Search_Console';
$properties=[
 'https://foo.example.test/'=>true,
 'sc-domain:example.test'=>true,
 'https://other.example.test/'=>false,
 'https://foo.example.test.evil.com/'=>false,
 'http://foo.example.test/'=>false,
 'https://foo.example.test/private/' =>false,
 'sc-domain:evil.com'=>false,
];
foreach($properties as $site=>$allowed){
 if($c::property_matches_site($site)!==$allowed)throw new RuntimeException('property mismatch');
}
$rows=['rows'=>[
 ['keys'=>['https://foo.example.test/page/'],'clicks'=>6,'impressions'=>100,'ctr'=>0.06,'position'=>8.2],
 ['keys'=>['https://other.example.test/page/'],'clicks'=>3,'impressions'=>100,'ctr'=>0.03,'position'=>4],
 ['keys'=>['https://foo.example.test/fake/'],'clicks'=>1,'impressions'=>3,'ctr'=>1.3,'position'=>7],
]];
$found=$c::decode_rows($rows,'https://foo.example.test/');
if(!is_array($found)||count($found)!==1||abs((float)current($found)['ctr_percent']-6.0)>0.00001)throw new RuntimeException('Row scope or CTR contract fail');
if(!is_array($c::decode_rows([],'https://foo.example.test/')) || !($c::decode_rows(['rows'=>'wrong'],'https://foo.example.test/') instanceof WP_Error))
 throw new RuntimeException('Empty and bad responses must differ');
$api=new ReflectionMethod($c,'api');
foreach(['http://www.googleapis.com/webmasters/v3/sites','https://www.googleapis.com.evil.com/webmasters/v3/sites','https://www.googleapis.com:444/webmasters/v3/sites','https://evil.example.test/'] as $url){
 $denied=$api->invoke(null,'GET',$url,'fake');
 if(!($denied instanceof WP_Error)||$denied->get_error_code()!=='mad4b_gsc_outbound_url_denied')throw new RuntimeException('Off-host endpoint accepted');
}
echo "NATIVE_GSC_AUTHORITY_HERMETIC_PASS\n";
'''
res=subprocess.run([os.environ.get("MAD4B_TEST_PHP_BIN","php"),"-r",php,args.php_source_path or str(source)],capture_output=True,text=True,timeout=15)
assert res.returncode==0,(res.stdout,res.stderr)
assert "NATIVE_GSC_AUTHORITY_HERMETIC_PASS" in res.stdout,res.stdout
print("NATIVE_GSC_REVIEW_ONLY_REGRESSION_PASS")
