<?php
/** Bounded, network-free regression for MAD4B Destination Term Save Preflight. */
define('ABSPATH','/fixture/');
define('ARRAY_A','ARRAY_A');
class WP_Error {
    private $code,$msg,$data;
    public function __construct($code,$message='',$data=null) {
        $this->code=$code;$this->msg=$message;$this->data=$data;
    }
    public function get_error_code(){return $this->code;}
    public function get_error_message(){return $this->msg;}
    public function get_error_data(){return $this->data;}
}
class MAD4B_SCP_Site_Profile {
    public static $id='fixture-site-uuid';
    public static function site_uuid(){return self::$id;}
}
class MAD4B_SCP_Brand_Experience_Quality {
    public static function brand_context(){return array('scope'=>array(
        'site_uuid'=>'fixture-site-uuid','brand_name'=>'First Party Tours'));}
}
class Fixture_DB {
    public $termmeta='wp_termmeta',$last_error='',$rows=array();
    public function prepare($sql,...$params){
        foreach($params as $p)$sql=preg_replace('/%[sd]/',is_numeric($p)?(string)$p:"'".str_replace("'","''",$p)."'", $sql,1);
        return $sql;
    }
    public function get_col($sql){
        if($this->last_error!=='')return null;
        if(!preg_match("/term_id=([0-9]+) AND meta_key='([^']+)'/",$sql,$m))return null;
        return $this->rows[(int)$m[1]][$m[2]]??array();
    }
}
$GLOBALS['wpdb']=new Fixture_DB;
$GLOBALS['guard_hooks']=array();$GLOBALS['guard_denials']=array();
function add_filter($tag,$call,$priority=10,$arity=1){$GLOBALS['guard_hooks'][$tag][]=$call;}
function add_action($tag,$call,$priority=10,$arity=1){$GLOBALS['guard_hooks'][$tag][]=$call;}
function do_action($tag,$value){$GLOBALS['guard_denials'][]=array($tag,$value);}
function apply_filters($tag,$value,...$params){foreach($GLOBALS['guard_hooks'][$tag]??array() as $call)$value=call_user_func_array($call,array_merge(array($value),$params));return $value;}
function is_wp_error($value){return $value instanceof WP_Error;}
function home_url($s='/'){return 'https://first-party.example'.$s;}
function wp_parse_url($s,$component=-1){return parse_url($s,$component);}
function get_term($id,$taxonomy){return $id===665 && $taxonomy==='destinations'?(object)array('term_id'=>665,'taxonomy'=>$taxonomy):null;}
function maybe_unserialize($raw){if(is_string($raw) && substr($raw,0,2)==='a:')return unserialize($raw);return $raw;}
function wp_unslash($v){return is_array($v)?array_map('wp_unslash',$v):stripslashes((string)$v);}
function wp_slash($v){return addslashes((string)$v);}
function absint($v){return abs((int)$v);}
function current_user_can($c,$id=null){return true;}
function is_admin(){return true;}
function check_admin_referer($s){$GLOBALS['guard_nonce_calls'][]=$s;return 1;}
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
class Fixture_Die extends Exception{}
function wp_die($message,$title,$args){throw new Fixture_Die((string)$message,(int)$args['response']);}
function pass($condition,$message){
    if(!$condition){fwrite(STDERR,'FAIL: '.$message."\n");exit(1);}
}
$path=$argv[1]??dirname(__DIR__,3).'/mu-plugins/mad4b-destination-term-save-preflight.php';
pass(is_file($path),'guard present');
require $path;
$g='MAD4B_Destination_Term_Save_Preflight_V1';
pass(!empty($GLOBALS['guard_hooks']['update_term_metadata']),'global update metadata guard installed');
pass(!empty($GLOBALS['guard_hooks']['add_term_metadata']),'global add metadata guard installed');
pass(!empty($GLOBALS['guard_hooks']['admin_init']),'admin edit guard installed');
$old='https://legacy-other.example/tours/';
$first='<a href="https://legacy-other.example/tours/">Historical unchanged</a>';
$GLOBALS['wpdb']->rows[665]['internal_links']=array($first);
$check=$g::preflight(665,'internal_links',$first);
pass(!is_wp_error($check),'historical unchanged link allowed');
$check=$g::preflight(665,'internal_links',$first.' <a href="https://other-company.example/tours/">New</a>');
pass(is_wp_error($check) && $check->get_error_code()==='mad4b_destination_external_link_blocked','new external anchor denied');
$check=$g::preflight(665,'internal_links',$first.' <a href="https://www.first-party.example/destination/cairo/">Local</a>');
pass(!is_wp_error($check),'verified www site alias allowed');
$check=$g::preflight(665,'internal_links',$first.' <a href="//external.example/book/">Bad</a>');
pass(is_wp_error($check),'protocol-relative foreign blocked');
$check=$g::preflight(665,'internal_links',$first.' <a href="/jsf/jet-engine:tours_query_archive/">Broken</a>');
pass(is_wp_error($check),'KSES-unstable relative URL denied');
$check=$g::preflight(665,'why_visit',array('section'=>array('html'=>'<a href="https://other.example/reserve">Book</a>')));
pass(is_wp_error($check),'nested JetEngine public visitor text covered');
$check=$g::preflight(665,'mad4b_geo_city_source_v1','https://editorial.example/reference');
pass(!is_wp_error($check),'editorial source metadata excluded');
$check=$g::preflight(665,'featured_image','https://old-media.example/wp-content/uploads/pic.jpg');
pass(!is_wp_error($check),'image origin remains separate from content link policy');
$check=$g::preflight(665,'internal_links',str_repeat('x',$g::MAX_FIELD_BYTES+1));
pass(is_wp_error($check),'oversized unscannable field fails closed');
$check=$g::preflight(665,'internal_links','https://another-company.example/tours');
pass(is_wp_error($check),'bare internal-link URL denied');
$check=$g::preflight(665,'internal_links','https://first-party.example/destination/cairo/');
pass(!is_wp_error($check),'bare first-party URL allowed');
$g::preflight(665,'internal_links',$first);
$check=apply_filters('update_term_metadata',null,665,'internal_links',$first.' <a href="https://foreign.example/tours">A</a>',null);
pass($check===false,'direct WordPress term-meta update explicitly rejected');
$check=apply_filters('add_term_metadata',null,665,'internal_links',$first.' <a href="https://foreign.example/tours">A</a>',null);
pass($check===false,'direct WordPress term-meta insert explicitly rejected');
pass(count($GLOBALS['guard_denials'])===2,'structured rejection emitted without external URLs');
$check=$g::before_insert_term('New term','destinations',array('meta_input'=>array('internal_links'=>'https://foreign.example/booking')));
pass(is_wp_error($check),'new term meta_input denied before term creation');
$check=$g::before_insert_term('New term','destinations',array('meta_input'=>array('internal_links'=>'/destination/cairo/')));
pass($check==='New term','new first-party link allowed before term creation');
$check=$g::before_insert_term('Other','other_taxonomy',array('meta_input'=>array('internal_links'=>'https://foreign.example/')));
pass($check==='Other','unrelated taxonomy unaffected');
$GLOBALS['pagenow']='edit-tags.php';
$_SERVER['REQUEST_METHOD']='POST';
$_POST=array('action'=>'editedtag','taxonomy'=>'destinations','tag_ID'=>665,
    'internal_links'=>wp_slash($first.' <a href="https://foreign.example/book">Bad</a>'));
try{$g::admin_before_save();pass(false,'admin form should reject before any database write');}
catch(Fixture_Die $e){pass($e->getCode()===422,'admin form returns HTTP 422');}
pass($GLOBALS['guard_nonce_calls'][0]==='update-tag_665','admin form checks exact nonce action');
$GLOBALS['wpdb']->last_error='SELECT_FAILURE';
$check=$g::preflight(665,'internal_links',$first);
pass(is_wp_error($check),'unreadable SQL baseline fails closed');
$GLOBALS['wpdb']->last_error='';
MAD4B_SCP_Site_Profile::$id='different-site';
$check=$g::preflight(665,'internal_links','https://first-party.example/destination/');
pass(is_wp_error($check),'unbound site UUID fails closed');
echo "PASS: DESTINATION_TERM_SAVE_PREFLIGHT\n";
