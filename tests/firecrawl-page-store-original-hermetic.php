<?php
/** Original Firecrawl storage admission only: cannot touch tenant DB or remote crawl. */
define('ABSPATH',__DIR__.'/');
class WP_Error {private $code;function __construct($code,$msg='',$data=[]){$this->code=$code;}function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function doing_action($name){return false;}
function wp_is_uuid($value){return is_string($value)&&preg_match('/^[a-f0-9-]{36}$/D',$value);}
function get_option($name,$default=[]){$GLOBALS['option_reads']++;return [];}
function current_user_can($cap){return $GLOBALS['cap'];}
function fire_check($ok,$reason){if(!$ok){fwrite(STDERR,'FAIL '.$reason.PHP_EOL);exit(1);} $GLOBALS['cases']=($GLOBALS['cases']??0)+1;}
class MAD4B_SCP_Site_Profile {
 static $status=[];
 static function status(){return self::$status;}
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-firecrawl-page-store.php';
$c='MAD4B_SCP_Firecrawl_Page_Store';
$ready=['configured'=>true,'origin_match'=>true,'deployment_binding_match'=>true,'environment_match'=>true,'write_enabled'=>true];
$GLOBALS['cap']=true;
foreach(array_keys($ready) as $flag){
 foreach([new WP_Error('origin_unverified'),1,'true',null,false] as $bad){
  MAD4B_SCP_Site_Profile::$status=$ready;MAD4B_SCP_Site_Profile::$status[$flag]=$bad;
  $GLOBALS['option_reads']=0;
  $r=$c::save_pages('crawl','https://source.example.com',[],false);
  fire_check(is_wp_error($r)&&$r->get_error_code()==='mad4b_crawl_pages_admin_required'
    &&$GLOBALS['option_reads']===0,
   'Unknown Site Profile proof may not access owned crawl run inventory');
 }
}
MAD4B_SCP_Site_Profile::$status=$ready;
foreach([new WP_Error('admin_unknown'),1,'true',null,false] as $bad){
 $GLOBALS['cap']=$bad;
 foreach(['save','list','read','summary'] as $op){
  $GLOBALS['option_reads']=0;
  if($op==='save')$r=$c::save_pages('crawl','https://source.example.com',[],false);
  elseif($op==='list')$r=$c::list_by_job('11111111-1111-4111-8111-111111111111');
  elseif($op==='read')$r=$c::read_by_job('11111111-1111-4111-8111-111111111111',1);
  else $r=$c::summary('crawl',false);
  fire_check(is_wp_error($r) &&$GLOBALS['option_reads']===0,
    'Unknown admin capability must not read run state, schema or page records');
 }
}
// Existing first-party read methods must never run dbDelta on a missing table.
// Explicit original writers alone may request ensure_schema().
class Firecrawl_Read_Only_WPDB {
 public $prefix='wp_';
 public $probes=0;
 public function prepare($sql,...$args){return $sql;}
 public function get_var($sql){$this->probes++;return null;}
 public function get_charset_collate(){throw new RuntimeException('Unexpected DDL metadata request on read');}
}
$GLOBALS['wpdb']=new Firecrawl_Read_Only_WPDB();
$GLOBALS['cap']=true;
foreach(['list','read','summary'] as $op){
 $GLOBALS['wpdb']->probes=0;
 if($op==='list')$r=$c::list_by_job('11111111-1111-4111-8111-111111111111');
 elseif($op==='read')$r=$c::read_by_job('11111111-1111-4111-8111-111111111111',1);
 else $r=$c::summary('crawl',false);
 fire_check(is_wp_error($r)&&$GLOBALS['wpdb']->probes===1,
  'Read-only Firecrawl pages may query existing schema, never create it');
}
$GLOBALS['option_reads']=0;
$r=$c::save_pages('crawl','https://source.example.com',[],false);
fire_check(is_wp_error($r)&&$r->get_error_code()==='mad4b_crawl_pages_unowned'
 &&$GLOBALS['option_reads']===1,'Exact authorized admin still cannot store unowned crawl');
echo 'PASS: FIRECRAWL_PAGE_STORE_ORIGINAL '.($GLOBALS['cases']??0).' native site/operator denial checks'.PHP_EOL;
