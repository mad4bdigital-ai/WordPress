<?php
/** Run: php test_public_term_tax_gate.php path/to/governor.php */
declare(strict_types=1);
define('ABSPATH','/');
define('DAY_IN_SECONDS',86400);
define('WPMU_PLUGIN_DIR',sys_get_temp_dir().'/mad4b-term-tax-fixture-'.getmypid());
mkdir(WPMU_PLUGIN_DIR,0700,true);
register_shutdown_function(static function() {
 @unlink(WPMU_PLUGIN_DIR.'/mad4b-selective-render-policy.json');
 @rmdir(WPMU_PLUGIN_DIR);
});
class WP_Post {
 public int $ID;
 public string $post_type;
 public string $post_status;
 public function __construct(int $id,string $type,string $status) {
  $this->ID=$id;$this->post_type=$type;$this->post_status=$status;
 }
}
$GLOBALS['fixture_opts']=[
 'mad4b_tax_safe_public_terms_v2'=>'off',
 'mad4b_render_public_fragments_tour_scope'=>'active'
];
$GLOBALS['fixture_raw']='[{"elType":"container","widgetType":"jet-listing-dynamic-field","id":"public-title"}]';
$GLOBALS['fixture_is_tour']=true;
function add_filter(...$args): bool{return true;}
function add_action(...$args): bool{return true;}
function get_option($name,$default=null){return $GLOBALS['fixture_opts'][$name]??$default;}
function wp_get_environment_type(){return $GLOBALS['fixture_env']??'staging';}
function home_url($p=''){return 'https://example.test'.$p;}
function is_singular($post_type=''){return $GLOBALS['fixture_is_tour'] && $post_type==='tours-and-activities';}
function get_queried_object_id(){return 60623;}
function absint($n){return abs((int)$n);}
function get_post($id){return new WP_Post((int)$id,$id===45929?'jet-engine':'tours-and-activities','publish');}
function is_post_publicly_viewable($p){return true;}
function post_password_required($p){return false;}
function get_permalink($p){return 'https://example.test/tours-and-activities/test-tour/';}
function wp_parse_url($v,$part){return parse_url($v,$part);}
function get_post_meta($id,$key,$single=true){
 if($key==='_elementor_data')return $GLOBALS['fixture_raw'];
 if($key==='_listing_data')return ['source'=>'terms','tax'=>'destinations'];
 return '';
}
function fail_if(bool $condition,string $label): void {
 if(!$condition)throw new RuntimeException('FAIL: '.$label);
 echo "PASS $label\n";
}
$_SERVER['REQUEST_METHOD']='GET';
$_SERVER['REQUEST_URI']='/tours-and-activities/test-tour/';
$_SERVER['HTTP_HOST']='example.test';
require $argv[1]??'';
file_put_contents(WPMU_PLUGIN_DIR.'/mad4b-selective-render-policy.json',json_encode([
 'contract'=>'mad4b.render-query.public-fragments.v1','status'=>'active',
 'environment'=>'staging','site_host'=>'example.test','ttl_seconds'=>300,
 'templates'=>['45929'=>['tour_public_fragment'=>true,
  'tour_css_identity'=>'jet_term_fallback','taxonomies'=>['destinations'],
  'elementor_sha256'=>hash('sha256',$GLOBALS['fixture_raw'])]]
]));
$method=new ReflectionMethod('MAD4B_Render_Query_Public_Fragments','tax_safe_tour_term_policy');
fail_if(!$method->invoke(null),'disabled option cannot cache public terms');
$GLOBALS['fixture_opts']['mad4b_tax_safe_public_terms_v2']='canary';
fail_if(!$method->invoke(null),'canary is not a public default');
$_SERVER['HTTP_X_MAD4B_TAX_SAFE_TERMS_PROOF']='strict-tax-safe-20261124';
fail_if($method->invoke(null),'staging exact-header canary with audited term manifest admitted');
$GLOBALS['fixture_raw']='tour_rates unsafe dynamic quote';
fail_if(!$method->invoke(null),'sensitive rates document fails closed even when pinned hash differs');
$GLOBALS['fixture_raw']='[{"elType":"container","widgetType":"jet-listing-dynamic-field","id":"public-title"}]';
$GLOBALS['fixture_is_tour']=false;
fail_if(!$method->invoke(null),'non-Tour route cannot reuse the term exemption');
$GLOBALS['fixture_is_tour']=true;
$GLOBALS['fixture_opts']['mad4b_tax_safe_public_terms_v2']='on';
unset($_SERVER['HTTP_X_MAD4B_TAX_SAFE_TERMS_PROOF']);
fail_if($method->invoke(null),'only deliberately activated, audited public Tour term listing admitted');
echo "ALL_PUBLIC_TERM_TAX_GATE_TESTS_PASS\n";
