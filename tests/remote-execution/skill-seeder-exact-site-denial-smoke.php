<?php
/** Negative-only: seed worker must fail before any file I/O or options write. */
define('ABSPATH',dirname(__DIR__,2).'/');
define('MAD4B_SCP_DIR',ABSPATH.'wp-content/plugins/mad4b-site-control-plane/');
class WP_Error {
 private $code;
 public function __construct($c,$message=''){$this->code=$c;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function sanitize_key($x){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$x));}
function add_action(...$args){}
function get_option(...$args){$GLOBALS['read_option_calls']=($GLOBALS['read_option_calls']??0)+1;return array();}
function update_option(...$args){$GLOBALS['write_option_calls']=($GLOBALS['write_option_calls']??0)+1;return true;}
class MAD4B_SCP_Site_Profile {
 public static function origin_enrolled(){return empty($GLOBALS['origin_revoked']);}
 public static function site_urls_match_enrollment(){return empty($GLOBALS['url_mismatch']);}
}
class MAD4B_SCP_Skill_Registry {
 public static function catalog_enabled(){return true;}
}
require MAD4B_SCP_DIR.'includes/class-mad4b-scp-skill-seeder.php';
function check_denied($name){
 $s=MAD4B_SCP_Skill_Seeder::reconcile();
 if(!is_array($s)||($s['state']??'')!=='site_identity_not_enrolled'){
  fwrite(STDERR,'FAIL '.$name.' bootstrap admitted unenrolled site'.PHP_EOL);exit(2);
 }
 $batch=MAD4B_SCP_Skill_Seeder::reconcile_batch();
 if(!is_wp_error($batch)||$batch->get_error_code()!=='mad4b_skill_seed_site_identity_not_enrolled')exit(3);
 $scheduled=MAD4B_SCP_Skill_Seeder::auto_reconcile_enrolled();
 if(!is_array($scheduled)||($scheduled['state']??'')!=='site_identity_not_enrolled')exit(4);
 if(!empty($GLOBALS['write_option_calls'])||!empty($GLOBALS['read_option_calls']))exit(5);
 echo 'PASS '.$name.' all three entrypoints refused with zero options I/O'.PHP_EOL;
}
$GLOBALS['origin_revoked']=true;
check_denied('revoked Site Profile');
unset($GLOBALS['origin_revoked']);$GLOBALS['url_mismatch']=true;
check_denied('Site Profile URL mismatch');
