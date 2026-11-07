<?php
/** Isolated native brand-provider contract; never connects to a live WordPress database. */
define('ABSPATH','/');
define('WP_PLUGIN_DIR','/empty-plugins');
$GLOBALS['hooks']=[];
$GLOBALS['wp_blog']=1;
$GLOBALS['lang']='en';
$GLOBALS['rest']=false;
$GLOBALS['admin']=false;
$GLOBALS['logged']=false;
$GLOBALS['ajax']=false;
$GLOBALS['method']='GET';
$GLOBALS['reads']=[];
$GLOBALS['options']=[
  1=>['home'=>'https://wayfatours.com','active_plugins'=>['mad4b-experience/mad4b-experience.php'],
    'look-and-feel'=>[
      'mad4b_brand_logo_media'=>101,'mad4b_brand_logo_inverse_media'=>102,
      'mad4b_brand_emblem_media'=>103,'mad4b_brand_favicon_media'=>103,
      'mad4b_brand_name'=>'Brand Company One',
    ],'elementor_active_kit'=>55,'mad4b_native_brand_request_memo_v1'=>'on'],
  2=>['home'=>'https://other-company.test','look-and-feel'=>[
      'mad4b_brand_logo_media'=>201,'mad4b_brand_name'=>'Brand Company Two',
    ],'elementor_active_kit'=>66,'mad4b_native_brand_request_memo_v1'=>'on'],
];
$GLOBALS['mime']=[101=>'image/png',102=>'image/png',103=>'image/png',201=>'image/webp',999=>'application/pdf'];
$GLOBALS['kit']=[55=>['system_colors'=>[['_id'=>'primary','color'=>'#123456']]],66=>[]];
$_SERVER['REQUEST_METHOD']='GET';$_GET=[];
class MAD4B_Presentation_Experience {
  public static function enabled(string $feature): bool { return $feature==='company-brand-context'; }
}
function add_action($hook,$cb,$priority=10,$accepted=1){$GLOBALS['hooks'][$hook][$priority][]=$cb;}
function fire($hook,...$params){
 $callbacks=$GLOBALS['hooks'][$hook]??[];
 ksort($callbacks);
 foreach($callbacks as $list)foreach($list as $cb)$cb(...$params);
}
function get_option($name,$fallback=null){
 $GLOBALS['reads'][$name]=($GLOBALS['reads'][$name]??0)+1;
 return $GLOBALS['options'][$GLOBALS['wp_blog']][$name]??$fallback;
}
function get_site_option($key,$default=[]){return $default;}
function get_current_blog_id(){return $GLOBALS['wp_blog'];}
function is_admin(){return $GLOBALS['admin'];}
function is_user_logged_in(){return $GLOBALS['logged'];}
function wp_doing_ajax(){return $GLOBALS['ajax'];}
function get_post_type($id){return in_array((int)$id,[101,102,103,201,999],true)?'attachment':false;}
function get_post_mime_type($id){return $GLOBALS['mime'][$id]??'';}
function wp_get_attachment_url($id){return 'https://media.site.test/uploads/'.(int)$id.'.png';}
function esc_url_raw($v){return $v;}
function get_bloginfo($name){return 'Default Site Name';}
function sanitize_text_field($s){return trim((string)$s);}
function apply_filters($hook,$val,...$args){return $hook==='wpml_current_language'?$GLOBALS['lang']:$val;}
function get_post_meta($id,$key,$single){return $GLOBALS['kit'][$id]??[];}
function do_action($hook,...$a){
 if($hook==='mad4b/native-brand-context/built')$GLOBALS['built']=($GLOBALS['built']??0)+1;
 if($hook==='mad4b/native-brand-context/memo-hit')$GLOBALS['hits']=($GLOBALS['hits']??0)+1;
}
function assert_pass($name,$test){
 if(!$test)throw new RuntimeException('FAIL '.$name);
 echo 'PASS '.$name."\n";
}
$file=getenv('MAD4B_NATIVE_BRAND_SOURCE');
if(!$file||!is_file($file))throw new RuntimeException('Missing provider file');
require $file;
assert_pass('provider does not declare function before plugins_loaded',
 !function_exists('company_brand_context'));
fire('plugins_loaded');
assert_pass('provider registered through native plugin lifecycle',
 function_exists('company_brand_context'));
$first=company_brand_context();
assert_pass('brand name from own site options',($first['name']??'')==='Brand Company One');
assert_pass('inverse logo from own site ID',($first['media_ids']['logo_inverse']??0)===102);
assert_pass('native profile present',($first['profile']??'')==='site-native' && $first['enabled']===true);
assert_pass('Elementor Kit primary color reflected',($first['colors']['primary']??'')==='#123456');
assert_pass('logo URL validated',($first['media']['logo_inverse']??'')==='https://media.site.test/uploads/102.png');
$builds=$GLOBALS['built']??0;
$look_reads=$GLOBALS['reads']['look-and-feel']??0;
$again=company_brand_context();
assert_pass('same context memoized per public GET',$again===$first && ($GLOBALS['built']??0)===$builds);
assert_pass('expensive settings no repeated reads',($GLOBALS['reads']['look-and-feel']??0)===$look_reads);
assert_pass('one memo hit counted',($GLOBALS['hits']??0)===1);
$GLOBALS['lang']='es';
$spanish=company_brand_context();
assert_pass('WPML language isolated',($spanish['language']??'')==='es' && ($GLOBALS['built']??0)===$builds+1);
$GLOBALS['wp_blog']=2;
$second=company_brand_context();
assert_pass('company site identity isolated',($second['name']??'')==='Brand Company Two' && ($second['media_ids']['logo_default']??0)===201);
assert_pass('inverse logo inherits native default',($second['media_ids']['logo_inverse']??0)===201);
$GLOBALS['wp_blog']=1;
$GLOBALS['lang']='en';
$GLOBALS['options'][1]['look-and-feel']['mad4b_brand_name']='Updated Brand';
fire('updated_option','look-and-feel');
$updated=company_brand_context();
assert_pass('brand change invalidates memo',($updated['name']??'')==='Updated Brand');
$GLOBALS['options'][1]['look-and-feel']['mad4b_brand_logo_inverse_media']=999;
fire('updated_option','look-and-feel');
$rejected=company_brand_context();
assert_pass('reject non-image media',$rejected['media_ids']['logo_inverse']===101);
$GLOBALS['admin']=true;
$builds=$GLOBALS['built']??0;
company_brand_context();company_brand_context();
assert_pass('admin bypasses memo',($GLOBALS['built']??0)===$builds+2);
$GLOBALS['admin']=false;
$_SERVER['REQUEST_METHOD']='POST';
$builds=$GLOBALS['built']??0;
company_brand_context();company_brand_context();
assert_pass('write requests bypass memo',($GLOBALS['built']??0)===$builds+2);
$_SERVER['REQUEST_METHOD']='GET';
$_GET['company_brand_token']='unverified';
$builds=$GLOBALS['built']??0;
company_brand_context();company_brand_context();
assert_pass('preview token never uses public memo',($GLOBALS['built']??0)===$builds+2);
echo "PASS_NATIVE_COMPANY_BRAND_CONTEXT_CONTRACT\n";
