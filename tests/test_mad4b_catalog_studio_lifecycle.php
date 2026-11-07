<?php
/** Isolated engine lifecycle. No WordPress DB, user impersonation, API calls or feed uploads. */
define('ABSPATH','/isolated');
$GLOBALS['options']=[];$GLOBALS['transients']=[];$GLOBALS['authorized']=true;
class WP_Error {public function __construct(private string $code,private string $msg=''){}public function get_error_code(){return $this->code;}public function get_error_message(){return $this->msg;}}
function is_wp_error($x){return $x instanceof WP_Error;}
function current_user_can($c){return $GLOBALS['authorized'];}
function get_option($n,$d=null){return $GLOBALS['options'][$n]??$d;}
function add_option($n,$v,$deprecated='',$autoload=false){if(array_key_exists($n,$GLOBALS['options']))return false;$GLOBALS['options'][$n]=$v;return true;}
function delete_option($n){if(!array_key_exists($n,$GLOBALS['options']))return false;unset($GLOBALS['options'][$n]);return true;}

function update_option($n,$v,$autoload=false){$GLOBALS['options'][$n]=$v;return true;}
function set_transient($n,$v,$t){$GLOBALS['transients'][$n]=$v;return true;}
function get_transient($n){return $GLOBALS['transients'][$n]??false;}
function delete_transient($n){unset($GLOBALS['transients'][$n]);return true;}
function get_current_user_id(){return 77;}
$GLOBALS['db_lock_held']=false;$GLOBALS['db_lock_denied']=false;$GLOBALS['db_lock_acquired']=0;
function mcfm_feed_editor_lock(){if($GLOBALS['db_lock_denied']||$GLOBALS['db_lock_held'])return new WP_Error('feed_edit_busy');$GLOBALS['db_lock_held']=true;$GLOBALS['db_lock_acquired']++;return true;}
function mcfm_feed_editor_unlock(){$GLOBALS['db_lock_held']=false;}

function add_action(...$args){return true;}
function sanitize_key($x){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$x));}
function sanitize_title($x){return trim(preg_replace('/[^a-z0-9-]/','-',strtolower((string)$x)),'-');}
function sanitize_text_field($x){return strip_tags((string)$x);}
function wp_json_encode($data,$opts=0){return json_encode($data,$opts);}
function wp_generate_password($n,$special=false,$extra=false){return str_repeat('t', $n);}
function wp_reset_postdata(){}
function mcfm_iterate_feed_rows($f,$lang,$cb){return null;}
function mcfm_get_feeds(){return get_option('mcfm_feeds_v2',[]);}
function mcfm_update_feeds($rows){update_option('mcfm_feeds_v2',array_values($rows));}
function mcfm_default_feed(){return ['label'=>'','slug'=>'','object_type'=>'post_type','object_key'=>'post','default_format'=>'csv','xml_variant'=>'rss','language_mode'=>'all','status'=>'publish','hide_empty_terms'=>0,'delimiter'=>',','mapping'=>[]];}
function mcfm_normalize_feed($f){return array_merge(mcfm_default_feed(),$f);}
function mcfm_sanitize_mapping_row($x){return [
 'column'=>sanitize_text_field($x['column']??''),'source_type'=>sanitize_text_field($x['source_type']??'meta'),
 'source'=>sanitize_text_field($x['source']??''),'extra'=>sanitize_text_field($x['extra']??''),
 'required_non_empty'=>empty($x['required_non_empty'])?0:1];}
function post_type_exists($x){return in_array($x,['post','tours-and-activities'],true);}
function taxonomy_exists($x){return in_array($x,['destinations'],true);}
function mcfm_resolve_requested_language($feed,$lang){return '';}
function mcfm_is_wpml_active(){return false;}
function apply_filters($filter,$value,...$rest){return $value;}
function mcfm_resolve_value($feed,$object,$map,$lang){return match($map['source_type']){
  'static'=>$map['source'], 'object_field'=>$object->{$map['source']}??'', default=>'value-'.$map['source']};}
function mcfm_is_empty_feed_value($v){return trim((string)$v)==='';}
function mcfm_row_passes_required_mapping($feed,$row){foreach($feed['mapping'] as $m)if(!empty($m['required_non_empty'])&&mcfm_is_empty_feed_value($row[$m['column']]??null))return false;return true;}
class WP_Query {public array $posts;public function __construct($args){$this->posts=[(object)['id'=>101,'title'=>'Cairo Tour'],(object)['id'=>102,'title'=>'Luxor Tour']];}}
require_once __DIR__.'/../wp-content/plugins/mad4b-distribution-connectors/includes/class-mad4b-catalog-studio.php';
$s='MAD4B_Catalog_Studio_V1';$checks=0;
function ok($result,$why){global $checks;$checks++;if(!$result)throw new RuntimeException('FAILED_'.$why);}
$created=$s::create(['label'=>'Test Tour Export','slug'=>'tour-export','object_type'=>'post_type','object_key'=>'tours-and-activities']);
ok($created['state']==='PRIVATE_DRAFT_CREATED','CREATE_DRAFT');
$slug=$created['slug'];ok($slug==='mad4b-managed-tour-export','PRIVATE_PREFIX');
ok(count(mcfm_get_feeds())===1,'ONE_FEED');
ok($s::get_state($s::get($slug)['feed'])['status']==='DRAFT','DRAFT_STATUS');
ok($s::publish_internal($slug) instanceof WP_Error,'UNVALIDATED_PUBLISH_DENIED');
ok($s::create(['label'=>'Duplicate','slug'=>'tour-export','object_type'=>'post_type','object_key'=>'post']) instanceof WP_Error,'DUPLICATE_SLUG_DENIED');
ok($s::create(['label'=>'Bad','slug'=>'missing','object_type'=>'taxonomy','object_key'=>'unregistered']) instanceof WP_Error,'UNKNOWN_SOURCE_DENIED');
$bad=$s::save_mapping($slug,['column'=>['bad[0]','bad[0]'],'source_type'=>['meta','meta'],'source'=>['foo','bar']]);
ok($bad instanceof WP_Error,'DUPLICATE_COLUMN_DENIED');
$map=['column'=>['id','title','price'],'source_type'=>['object_field','object_field','static'],
      'source'=>['id','title','49.00'],'extra'=>['','',''],'required'=>[0=>1,1=>1,2=>0]];
$saved=$s::save_mapping($slug,$map);
ok($saved['state']==='MAPPING_SAVED_REVALIDATION_REQUIRED','SAVE_MAPPING');
ok(count($s::get($slug)['feed']['mapping'])===3,'THREE_MAPPED_FIELDS');
ok($s::get_state($s::get($slug)['feed'])['status']==='DRAFT','STILL_DRAFT');
$checked=$s::validate_and_record($slug);
ok($checked['status']==='VALIDATED_INTERNAL','VALIDATED');
ok($checked['sample_count']===2,'BOUNDED_PREVIEW_TWO_ROWS');
ok($s::get_state($s::get($slug)['feed'])['status']==='VALIDATED_INTERNAL','VALIDATION_RECEIPT_STORED');
$preview=$s::preview_and_record($slug);
ok($preview['sample_count']===2,'PREVIEW_READY');
ok(count($s::get_preview($slug))===2,'PREVIEW_TRANSIENT_READBACK');
ok(($s::get_preview($slug)[0]['title']??'')==='Cairo Tour','REAL_FIELD_RESOLVER');
$ready=$s::publish_internal($slug);
ok($ready['state']==='READY_INTERNAL','MARK_READY_INTERNAL');
ok($ready['external_delivery_enabled']===false,'NO_EXTERNAL_UPLOAD');
ok($s::get_state($s::get($slug)['feed'])['status']==='READY_INTERNAL','READY_RECEIPT');
$profile=$s::save_profile($slug,['format'=>'rss','language_mode'=>'default']);
ok($profile['state']==='PROFILE_SAVED_REVALIDATION_REQUIRED','SAVE_PROFILE');
ok($s::get_state($s::get($slug)['feed'])['status']==='DRAFT','STALE_READY_INVALIDATED');
ok($s::publish_internal($slug) instanceof WP_Error,'STALE_PUBLISH_DENIED');
ok(count($s::get_preview($slug))===0,'STALE_PREVIEW_INVALIDATED');
$copy=$s::duplicate($slug);
ok($copy['state']==='PRIVATE_DRAFT_DUPLICATED','DUPLICATE_DRAFT');
ok(count(mcfm_get_feeds())===2,'TWO_FEEDS');
ok($s::get_state($s::get($copy['slug'])['feed'])['status']==='DRAFT','COPY_NOT_PUBLISHED');
ok($s::get($copy['slug'])['feed']['mapping']===$s::get($slug)['feed']['mapping'],'MAPPING_COPIED');
// Two-step import review: staged transient must match original fingerprint.
$name='mad4b_studio_import_77_'.$slug;$reviewMap=$s::get($slug)['feed']['mapping'];
$reviewMap[]=['column'=>'new_property','source_type'=>'static','source'=>'abc','extra'=>'','required_non_empty'=>0];
$token='token_for_isolated_test';set_transient($name,['token_hash'=>hash('sha256',$token),'review_token'=>$token,
 'mapping'=>$reviewMap,'feed_hash'=>$s::fingerprint($s::get($slug)['feed'])],600);
ok($s::apply_import($slug,'wrong') instanceof WP_Error,'IMPORT_TOKEN_REQUIRED');
ok($s::get_import_review($slug)['token']===$token,'IMPORT_REVIEW_VISIBLE_TO_OWNER');
$applied=$s::apply_import($slug,$token);
ok($applied['state']==='MAPPING_IMPORTED_REVALIDATION_REQUIRED','IMPORT_APPLIED');
ok(count($s::get($slug)['feed']['mapping'])===4,'IMPORT_READBACK');
ok($s::get_import_review($slug)===[],'IMPORT_TOKEN_SINGLE_USE');
$current=$s::fingerprint($s::get($slug)['feed']);
$locked=$s::guarded_action('save_mapping',$slug,$current,static fn()=>['state'=>'MUTEX_GRANTED']);
ok($locked['state']==='MUTEX_GRANTED','REVISION_LOCK_ACCEPTS_FRESH_VERSION');
ok(!array_key_exists('mad4b_catalog_studio_write_lock_v1',$GLOBALS['options']),'MUTEX_RELEASED');
$stale=$s::guarded_action('save_mapping',$slug,'invalid_or_old_fingerprint',static fn()=>['state'=>'BAD']);
ok($stale instanceof WP_Error && $stale->get_error_code()==='feed_revision_conflict','STALE_EDITOR_REFUSED');
$GLOBALS['options']['mad4b_catalog_studio_write_lock_v1']=['token'=>'another-editor','created'=>time()];
$busy=$s::guarded_action('save_mapping',$slug,$current,static fn()=>['state'=>'BAD']);
ok($busy instanceof WP_Error && $busy->get_error_code()==='catalog_edit_in_progress','CONCURRENT_EDITOR_REFUSED');
$GLOBALS['options']['mad4b_catalog_studio_write_lock_v1']['created']=time()-180;
$reclaimed=$s::guarded_action('save_mapping',$slug,$current,static fn()=>['state'=>'STALE_LOCK_RECOVERED']);
ok($reclaimed['state']==='STALE_LOCK_RECOVERED','EXPIRED_LOCK_RECOVERED');
ok(!array_key_exists('mad4b_catalog_studio_write_lock_v1',$GLOBALS['options']),'STALE_LOCK_CLEANED');
$GLOBALS['db_lock_denied']=true;
$held=$s::guarded_action('save_mapping',$slug,$current,static fn()=>['state'=>'UNEXPECTED']);
ok($held instanceof WP_Error && $held->get_error_code()==='feed_edit_busy','ADVANCED_EDITOR_SHARED_LOCK_DENIES_STUDIO');
$GLOBALS['db_lock_denied']=false;
ok($GLOBALS['db_lock_acquired']>=4,'STUDIO_ACQUIRES_DB_LOCK_FOR_WRITES');
$GLOBALS['authorized']=false;
ok($s::create(['label'=>'Denied','slug'=>'blocked','object_type'=>'post_type','object_key'=>'post']) instanceof WP_Error,'NO_ANONYMOUS_CREATE');
ok($s::save_mapping($slug,$map) instanceof WP_Error,'NO_ANONYMOUS_SAVE');
ok($s::validate_and_record($slug) instanceof WP_Error,'NO_ANONYMOUS_VALIDATE');
ok($s::publish_internal($slug) instanceof WP_Error,'NO_ANONYMOUS_PUBLISH');
ok($s::preview_and_record($slug) instanceof WP_Error,'NO_ANONYMOUS_PREVIEW');
ok($s::duplicate($slug) instanceof WP_Error,'NO_ANONYMOUS_DUPLICATE');
echo json_encode(['suite'=>'mad4b.catalog-studio.lifecycle','checks_passed'=>$checks,
 'created_feed'=>$slug,'mapping_columns'=>4,'import_review'=>'two_step',
 'preview'=>'bounded','readiness'=>'fingerprint_scoped','external_calls'=>0,
 'provider_uploads'=>0,'network_mutations'=>false],JSON_UNESCAPED_SLASHES).PHP_EOL;
