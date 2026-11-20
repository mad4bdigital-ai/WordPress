<?php
/** Networkless WordPress Ability contract fixture; no live WordPress boot. */
define('ABSPATH', '/');
define('WP_CONTENT_DIR', '/fixture/wp-content');
$GLOBALS['actions'] = [];
$GLOBALS['abilities'] = [];
function add_action($hook,$callable,$priority=10) {
  $GLOBALS['actions'][$hook][] = $callable;
}
function wp_has_ability($name) { return isset($GLOBALS['abilities'][$name]); }
function wp_register_ability($name,$args) {
  $GLOBALS['abilities'][$name] = $args;
  return true;
}
function home_url() { return 'https://tours.dev.mad4b.com'; }
function current_user_can($name) { return $name==='manage_options'; }
class MAD4B_SCP_Site_Profile {
  public static function site_uuid() { return 'e5e64bca-ed62-48d2-b2b3-dff693cd702a'; }
}
class WP_Error {
  public $code;
  public function __construct($code,$message='') { $this->code=$code; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
require '/work/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-native-source-release-review.php';
$owner = 'MAD4B_SCP_Native_Source_Release_Review';
$owner::boot();
$owner::boot();
foreach (($GLOBALS['actions']['wp_abilities_api_init']??[]) as $fn) $fn();
$reads = 0;
foreach ($GLOBALS['abilities'] as $name=>$args) {
  if (($args['meta']['annotations']['readonly']??false) === true
    && ($args['meta']['mcp']['surface']??'') === 'read'
    && ($args['meta']['show_in_rest']??true) === false) $reads++;
}
$file='wp-content/plugins/mad4b-commerce/modules/presentation/assets/unified-date-v0.4.3.js';
$target=WP_CONTENT_DIR.'/'.substr($file,strlen('wp-content/'));
$plan=[
  'operation_id'=>'source.code.release',
  'ability_name'=>'mad4b/native-source-release',
  'provider'=>'mad4b-source-release',
  'site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),
  'origin'=>home_url(),'company'=>'tours-central','environment'=>'staging',
  'plan_sha256'=>str_repeat('a',64),
  'source_manifest_sha256'=>str_repeat('b',64),
  'operation_binding_sha256'=>str_repeat('c',64),
  'git_commit'=>str_repeat('d',40),
  'files'=>[['target'=>$file,
    'expected_before_sha256'=>hash_file('sha256',$target),
    'source_sha256'=>str_repeat('e',64)]],
];
$valid=$owner::request_input($plan);
$preview=$owner::preflight($plan);
$traversal=$plan;
$traversal['files'][0]['target']='wp-content/plugins/mad4b-commerce/modules/../assets/unified-date-v0.4.3.js';
$backslash=$plan;
$backslash['files'][0]['target']=str_replace('/assets/','/assets'.chr(92),$file);
$wrong=$plan;
$wrong['site_uuid']='12345678-1234-4123-8123-123456789abc';
$status=$owner::status([]);
echo json_encode([
  'read_abilities_registered'=>$reads,
  'source_write_ability_registered'=>wp_has_ability('mad4b/native-source-release'),
  'valid_request'=>is_array($valid)?'PASS':$valid->code,
  'valid_preflight'=>is_array($preview)?$preview['state']:$preview->code,
  'traversal_rejected'=>is_wp_error($owner::request_input($traversal)),
  'backslash_rejected'=>is_wp_error($owner::request_input($backslash)),
  'site_mismatch_rejected'=>is_wp_error($owner::request_input($wrong)),
  'approval_issued'=>$status['approval_created'],
  'mutation_performed'=>$status['mutation_performed'],
],JSON_THROW_ON_ERROR);
