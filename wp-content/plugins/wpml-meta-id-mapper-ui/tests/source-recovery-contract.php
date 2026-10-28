<?php
/** Executable standalone policy contract: no real WordPress or network. */
define('ABSPATH','/test/');
define('ICL_SITEPRESS_VERSION','mock');
final class WP_Error {
    private $code;
    public function __construct($code){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
class WP_Post {public function __construct(public int $ID,public string $post_type){}}
function is_wp_error($v){return $v instanceof WP_Error;}
function absint($v){return abs((int)$v);}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function home_url($p){return $GLOBALS['origin'].$p;}
function get_current_user_id(){return 1;}
function current_user_can($permission){return $permission==='manage_options';}
function current_time($type,$gmt=false){return 1800000000;}
function wp_json_encode($v){return json_encode($v);}
function wp_cache_delete($id,$group){return true;}
function get_option($key,$default=[]){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;return true;}
function get_post($id){return isset($GLOBALS['posts'][$id])?new WP_Post($id,$GLOBALS['posts'][$id]['type']):null;}
function get_post_type($id){return $GLOBALS['posts'][$id]['type']??null;}
function get_post_meta($id,$key,$single=true){return $GLOBALS['posts'][$id]['meta'][$key]??'';}
function update_post_meta($id,$key,$value){$GLOBALS['posts'][$id]['meta'][$key]=$value;return true;}
function apply_filters($hook,$value,...$args) {
    if($hook==='wpml_default_language')return $GLOBALS['default_lang'];
    if($hook==='wpml_post_language_details')return ['language_code'=>$GLOBALS['posts'][$args[0]]['lang']??''];
    if($hook==='wpml_element_trid')return null;
    if($hook==='wpml_sync_custom_field_copied_value')return 20;
    return $value;
}
$GLOBALS['wpdb']=new class {public function query($sql){return true;}};
$GLOBALS['default_lang']='en';
$GLOBALS['origin']='https://company-one.example';
$GLOBALS['options']=[
  'wpml_meta_id_mapper_rules'=>[
    ['post_type'=>'service-rates','meta_key'=>'linked_service_id','type'=>'post','object_type'=>'services']
  ],
  'wpml_meta_id_mapper_recovery_policy_v1'=>[
    'service-rates'=>['linked_service_id'=>[
      'match_fields'=>['period_start','period_end'],
      'mirror_meta_key'=>'_mapped_service_owner','expiry_meta_key'=>'period_end',
      'stop_sale_meta_key'=>'sale_blocked']]
  ]
];
$GLOBALS['posts']=[
  1=>['type'=>'service-rates','lang'=>'en','meta'=>[
    'linked_service_id'=>'10','period_start'=>'1700000000','period_end'=>'1710000000']],
  2=>['type'=>'service-rates','lang'=>'fr','meta'=>[
    'linked_service_id'=>'','period_start'=>'1700000000','period_end'=>'1710000000']],
  3=>['type'=>'service-rates','lang'=>'fr','meta'=>[
    'linked_service_id'=>'10','period_start'=>'1700000000','period_end'=>'1710000000']],
  10=>['type'=>'services','lang'=>'en','meta'=>[]],
  20=>['type'=>'services','lang'=>'fr','meta'=>[]],
];
require dirname(__DIR__).'/includes/class-wpml-meta-id-mapper-source-recovery.php';
function check($ok,$name){if(!$ok){fwrite(STDERR,'FAIL '.$name.PHP_EOL);exit(1);}echo 'PASS '.$name.PHP_EOL;}
$class='WPML_Meta_ID_Mapper_Source_Recovery';
$before=serialize($GLOBALS['posts']);
$missing=$class::assess(2,'linked_service_id');
check(is_wp_error($missing)&&$missing->get_error_code()==='wpml_mapper_recovery_source_lineage_missing','no invented missing source');
$correct=$class::assess(2,'linked_service_id',1);
check(is_array($correct)&&$correct['mapped_id']===20&&$correct['expired'],'configured source fields and native locale mapping');
check(serialize($GLOBALS['posts'])===$before,'preflight performs no mutations');
$wrong=$class::assess(2,'linked_service_id',3);
check(is_wp_error($wrong)&&$wrong->get_error_code()==='wpml_mapper_recovery_explicit_source_must_be_default_language','noncanonical source rejected');
$GLOBALS['posts'][2]['meta']['period_start']='bad';
check(is_wp_error($class::assess(2,'linked_service_id',1)),'commercial source field mismatch denied');
$GLOBALS['posts'][2]['meta']['period_start']='1700000000';
check(is_wp_error($class::assess(2,'unregistered_relation',1)),'arbitrary meta key denied');
$applied=$class::repair(2,'linked_service_id',1);
check(is_array($applied)&&$applied['state']==='repaired'&&get_post_meta(2,'sale_blocked')==='1','mapped relation and expired stop sale applied');
check((int)get_post_meta(2,'_mapped_service_owner')===20,'per-site mirror policy honored');
$again=$class::repair(2,'linked_service_id');
check(is_array($again)&&$again['state']==='already_reconciled','idempotent replay using stored source pointer');
$GLOBALS['origin']='https://company-two.example';
$switched=$class::assess(2,'linked_service_id');
check(is_array($switched)&&$switched['site_origin']==='https://company-two.example/','no origin/domain hardcoding');
echo 'ALL SOURCE RECOVERY CONTRACTS PASSED'.PHP_EOL;
