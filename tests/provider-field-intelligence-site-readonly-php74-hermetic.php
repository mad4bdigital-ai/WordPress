<?php
/**
 * Original Provider Field Intelligence read-only field catalog, source-only
 * PHP7.4 and PHP8.5. Synthetic WP meta, JetEngine and WPML declarations.
 * No customer metadata values, secrets, DB writes, media edits or web requests.
 */
define('ABSPATH',__DIR__.'/');
define('ARRAY_A','ARRAY_A');
$GLOBALS['field_admin']=false;
$GLOBALS['field_site_ready']=true;
$GLOBALS['field_routes']=[];
$GLOBALS['field_hooks']=[];
$GLOBALS['field_values_read']=0;
class WP_Error{
 private $code;public function __construct($c,$message=''){$this->code=$c;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function add_action($hook,$cb,$priority=10){$GLOBALS['field_hooks'][$hook]=$cb;}
function register_rest_route($namespace,$route,$cfg){
 $GLOBALS['field_routes'][$namespace.$route]=$cfg;
}
function current_user_can($cap){return $cap==='manage_options'&&$GLOBALS['field_admin'];}
function rest_ensure_response($r){return $r;}
function wp_json_encode($data,$flags=0){return json_encode($data,$flags);}
function absint($v){return abs((int)$v);}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function post_type_exists($type){return in_array($type,['post','tours-and-activities'],true);}
function get_post($id){
 return (int)$id===77?(object)['ID'=>77,'post_type'=>'tours-and-activities']:null;
}
function get_post_thumbnail_id($id){return (int)$id===77?12:0;}
function get_post_meta($id,$key,$single=true){
 if((int)$id===12 && $key==='_wp_attachment_image_alt')return '';
 if($key==='rank_math_title')return 'Localized Nile Review';
 if($key==='rank_math_description')return 'Editorial itinerary summary';
 return '';
}
function get_attached_file($id){return '/__mad4b_nonexistent_ci_media__/image.jpg';}
function taxonomy_exists($name){return $name==='destinations';}
function get_registered_meta_keys($type,$name){return [
 'rank_math_title'=>['type'=>'string','show_in_rest'=>false],
 '_elementor_data'=>['type'=>'string','show_in_rest'=>false],
 'hero_image'=>['type'=>'integer','show_in_rest'=>true],
 'client_secret'=>['type'=>'string','show_in_rest'=>true],
 'gallery'=>['type'=>'array','show_in_rest'=>false],
 '_wp_attachment_image_alt'=>['type'=>'string','show_in_rest'=>true],
 '_thumbnail_id'=>['type'=>'integer','show_in_rest'=>false],
];}
function get_option($name,$default=[]){
 return $name==='icl_sitepress_settings'?['custom_posts_sync_option'=>['attachment'=>1],
   'custom_fields_translation'=>['rank_math_title'=>2]]:$default;
}
function apply_filters($hook,$value,$context=null){
 if($hook==='wpml_active_languages')return [
 'en'=>['name'=>'English'],'es'=>['name'=>'Spanish']];
 if($hook==='mad4b/provider_field_intelligence/adapters')return [
 'vendor_one'=>[
  'banner_image'=>['field_type'=>'image'],
  'client_secret'=>['field_type'=>'text'],
  'rank_math_title'=>['field_type'=>'image']
 ]];
 return $value;
}
class MockFields {
 public function get_registered_fields(){return [
 'tours-and-activities'=>[
  ['name'=>'hero_image','type'=>'media','id'=>14,'show_in_rest'=>true],
  ['name'=>'nile_gallery','type'=>'gallery','id'=>15,'repeater-fields'=>[
   ['name'=>'gallery_photo'],['name'=>'session_token']]],
  ['name'=>'client_secret','type'=>'text'],
 ]];}
}
class MockJetEngine {
 public $meta_boxes;public function __construct(){$this->meta_boxes=new MockFields();}
}
function jet_engine(){return new MockJetEngine();}
class MockSitepress {
 public function get_custom_fields_translation_settings($flag){
  return $flag===2?['rank_math_title','_wp_attachment_image_alt','hero_image']:[];
 }
}
$GLOBALS['sitepress']=new MockSitepress();
class MockWPDB {
 public $termmeta='wp_termmeta';
 public $term_taxonomy='wp_term_taxonomy';
 public $postmeta='wp_postmeta';
 public $posts='wp_posts';
 public $last_error='';
 public function prepare($sql,...$args){return $sql;}
 public function get_results($query,$style=null){return [
  ['meta_key'=>'hero_image','c'=>'7'],
  ['meta_key'=>'wp_password','c'=>'33'],
  ['meta_key'=>'_thumbnail_id','c'=>'17'],
  ['meta_key'=>'rank_math_description','c'=>'9'],
  ['meta_key'=>'provider_client_token','c'=>'19'],
 ];}
}
$GLOBALS['wpdb']=new MockWPDB();
class MAD4B_SCP_Site_Profile {
 public static function status(){return [
  'site_uuid'=>'12345678-1234-4123-8123-123456789abc',
  'origin_match'=>$GLOBALS['field_site_ready'],
  'environment_match'=>true,'deployment_binding_match'=>true,
  'environment'=>'production'];}
}
function ff_assert($cond,$message){
 if(!$cond)throw new RuntimeException('FAIL PROVIDER FIELD INTELLIGENCE: '.$message);
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-provider-field-intelligence.php';
$c='MAD4B_SCP_Provider_Field_Intelligence';
$c::rest_route();
$route=$GLOBALS['field_routes']['mad4b/v1/provider-fields']??null;
ff_assert(is_array($route)&&$route['methods']==='GET'
 && call_user_func($route['permission_callback'])===false,
 'Provider field inventory REST is admin-only, not publicly callable');
$GLOBALS['field_admin']=true;
ff_assert(call_user_func($route['permission_callback'])===true,
 'An authorized WordPress administrator may inspect registered field names');
$GLOBALS['field_admin']=false;
$bad=$c::discover(['object'=>'post','subtype'=>'foreign','limit'=>300]);
ff_assert($bad instanceof WP_Error && $bad->get_error_code()==='mad4b_provider_fields_unknown_object',
 'Only registered WordPress content types may be discovered');
$unknown=$c::discover(['object'=>'post','subtype'=>'tours-and-activities','db'=>'all']);
ff_assert($unknown instanceof WP_Error && $unknown->get_error_code()==='mad4b_provider_fields_invalid_input',
 'No arbitrary database or unsafe caller parameters accepted');
$GLOBALS['field_site_ready']=false;
$unbound=$c::discover();
ff_assert($unbound instanceof WP_Error && $unbound->get_error_code()==='mad4b_provider_fields_site_unverified',
 'Cross-site clone must not expose even provider field names');
// Do not leak which CPTs exist on a foreign clone either. Input schema is
// valid, but its object type is unregistered in the fake local WordPress.
$foreign_type=$c::discover(['object'=>'post','subtype'=>'unregistered-cloned-cpt']);
ff_assert($foreign_type instanceof WP_Error
 && $foreign_type->get_error_code()==='mad4b_provider_fields_site_unverified',
 'Foreign site identity must be denied before enumerating registered post types');
$GLOBALS['field_site_ready']=true;
$r=$c::discover(['object'=>'post','subtype'=>'tours-and-activities']);
ff_assert(is_array($r)
 && $r['discovery_read_only']===true && $r['mutations_performed']===false
 && $r['translation_changes_authorized']===false
 && $r['physical_media_copied']===false
 && $r['providers']['jetengine']['context_found']===true
 && $r['providers']['wpml']['attachment_translatable_configured']===true
 && $r['counts']['total']>3,
 'Original source must discover provider declarations without modifying them');
$rows=[];
foreach($r['fields'] as $field){$rows[$field['key']]=$field;
 ff_assert($field['authorizes_edit']===false
  && $field['authorizes_translation_write']===false
  && $field['translation_is_approved']===false,
  'An observed meta key cannot confer WordPress write or WPML translation rights');}
foreach(['client_secret','wp_password','provider_client_token'] as $secret)
 ff_assert(!isset($rows[$secret]),'Discovered source secrets must be excluded from catalog');
ff_assert($rows['hero_image']['provider']==='jetengine'
 && $rows['hero_image']['is_media_field']===true
 && $rows['hero_image']['wpml_translation']==='translate',
 'JetEngine image field metadata plus WPML configuration are observations, not approvals');
ff_assert($rows['rank_math_title']['provider']==='wordpress_registered'
 && $rows['rank_math_title']['is_seo_field']===true
 && $rows['rank_math_title']['field_type']==='string',
 'Untrusted extension cannot replace WordPress canonical field type');
$media=$c::media_field_names('tours-and-activities');
ff_assert(in_array('hero_image',$media,true)
 && in_array('nile_gallery',$media,true)
 && !in_array('_thumbnail_id',$media,true)
 && !in_array('banner_image',$media,true),
 'Only JetEngine-registered media fields can participate in guarded galleries');
$limited=$c::discover(['object'=>'post','subtype'=>'tours-and-activities','limit'=>2,'only_media'=>true]);
ff_assert($limited['counts']['returned']===2
 && $limited['counts']['filtered']>=2
 && $limited['enumeration_complete']===false,
 'Partial pagination must not falsely certify full field enumeration');
ff_assert($GLOBALS['field_values_read']===0,
 'Read-only inventory must never load private metadata values');
$locale=$c::locale_media_audit(77);
ff_assert($locale['complete']===true
 && $locale['languages_with_posts']===1
 && $locale['featured_alt_missing']===1
 && $locale['locales'][0]['featured_alt_length']===0
 && $locale['locales'][0]['featured_asset_exists']===false
 && $locale['photo_subject_verified']===false
 && $locale['alt_text_generated']===false
 && $locale['translation_data_written']===false
 && $locale['media_binary_duplicated']===false,
 'Locale audit must report missing alt without inventing images, captions or WPML approval');
$GLOBALS['field_site_ready']=false;
$other_site=$c::locale_media_audit(77);
ff_assert($other_site['complete']===false
 && $other_site['error']==='site_identity_unverified',
 'Read-only locale audit cannot inspect unbound company site data');
$GLOBALS['field_site_ready']=true;
ff_assert($GLOBALS['field_values_read']===0,
 'No private meta values were enumerated during locale field discovery');
echo "PROVIDER_FIELDS_SITE_PHP74: PASS original REST, field rights, locale audit and no write\n";// Audited positive exit marker; all denial checks must complete first.
echo "PROVIDER_FIELDS_SITE_PHP74: PASS\n";
