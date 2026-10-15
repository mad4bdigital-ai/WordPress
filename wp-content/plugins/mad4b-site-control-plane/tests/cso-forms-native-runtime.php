<?php
/** Native PHP fake CSO forms: no real WordPress, no site writes. */
define('ABSPATH','/');
class WP_Error {private $c;public function __construct($c,$m='',$d=array()){$this->c=$c;}public function get_error_code(){return $this->c;}}
function is_wp_error($v){return $v instanceof WP_Error;}
class MAD4B_SCP_CSO_Scope {
 public static function enabled($component){return 'forms'===$component;}
 public static function safe_data($x){return !array_key_exists('api_key',$x);}
 public static function error($r){return new WP_Error($r);}
}
class MAD4B_SCP_CSO01_Read_Foundation {
 public static $revision='first';
 public static function can_read(){return true;}
 public static function form_schema($a){
  if(!isset($a['ability_name'])||$a['ability_name']!=='mad4b/test-read')return new WP_Error('unknown');
  return array('ability_name'=>$a['ability_name'],'descriptor_sha256'=>hash('sha256',self::$revision),
   'fields'=>array(array('key'=>'query','type'=>'string','required'=>true,'max_length'=>40,'enum'=>array('Cairo','Giza','Luxor'))));
 }
 public static function form_validate($input){
  $current=self::form_schema(array('ability_name'=>$input['ability_name']));
  if(is_wp_error($current)||$current['descriptor_sha256']!==$input['expected_descriptor_sha256'])
    return new WP_Error('stale_descriptor');
  return array('valid'=>isset($input['values']['query'])&&is_string($input['values']['query']),
   'saved'=>false,'authorizing'=>false);
 }
 public static function form_explain($a){return array('field'=>array('key'=>$a['field']),'authorizing'=>false);}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-cso-forms.php';
function ck($ok,$msg){if(!$ok){fwrite(STDERR,'FAIL '.$msg."\n");exit(1);}}
$form=MAD4B_SCP_CSO_Forms::schema('mad4b/test-read');
ck(!is_wp_error($form)&&$form['write_supported']===false&&$form['sealed_form']['ability_name']==='mad4b/test-read','typed descriptor from verified source');
$valid=MAD4B_SCP_CSO_Forms::validate($form['sealed_form'],array('query'=>'hello'));
ck(!is_wp_error($valid)&&$valid['status']==='VALIDATED'&&!$valid['saved'],'typed form validation without effect');
$forged=$form['sealed_form'];$forged['trusted_write']=true;
ck(is_wp_error(MAD4B_SCP_CSO_Forms::validate($forged,array('query'=>'test'))),'unexpected claims rejected');
ck(is_wp_error(MAD4B_SCP_CSO_Forms::validate($form['sealed_form'],array('api_key'=>'secret'))),'secrets in conversation denied');
$suggest=MAD4B_SCP_CSO_Forms::suggest($form['sealed_form'],'query','Gi',0);
ck(!is_wp_error($suggest)&&count($suggest['items'])===1&&$suggest['items'][0]['value']==='Giza','literal enum suggestion is scoped to sealed descriptor');
ck(is_wp_error(MAD4B_SCP_CSO_Forms::suggest($form['sealed_form'],'unlisted','',0)),'unknown field suggestion denied');
MAD4B_SCP_CSO01_Read_Foundation::$revision='new';
ck(is_wp_error(MAD4B_SCP_CSO_Forms::validate($form['sealed_form'],array('query'=>'test'))),'stale descriptor denied');
ck(is_wp_error(MAD4B_SCP_CSO_Forms::schema('mad4b/test-read',array('post_id'=>12))),'unbound target form rejected');
echo "PASS CSO native descriptors/secret rejection/revision fence\n";
