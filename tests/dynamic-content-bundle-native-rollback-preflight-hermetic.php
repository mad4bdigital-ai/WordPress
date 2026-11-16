<?php
/**
 * Execute ORIGINAL Dynamic Content Adapter snapshots, update restore and
 * reversible rollback on synthetic in-memory WP post/meta/tax/media only.
 * No site DB, HTTP, source rights, central grants, publish or file changes.
 */
define('ABSPATH',__DIR__.'/');
$GLOBALS['fixture_posts']=[
 77=>(object)[
  'ID'=>77,'post_type'=>'tours-and-activities','post_title'=>'Original Nile Draft',
  'post_content'=>'Original body','post_excerpt'=>'Original excerpt',
  'post_status'=>'draft','post_parent'=>0,'post_author'=>7
 ]];
$GLOBALS['fixture_meta']=[77=>['tour_price'=>['100'],'_mad4b_dynamic_content_managed_v1'=>['1']]];
$GLOBALS['fixture_tax']=[77=>['destinations'=>[11]]];
$GLOBALS['fixture_media']=[77=>15];
$GLOBALS['fixture_writes']=0;
$GLOBALS['fixture_meta_denied']='';
$GLOBALS['fixture_tax_denied']='';
$GLOBALS['fixture_media_exists']=true;
function add_action(...$x){}
function add_filter(...$x){}
function apply_filters($n,$v){return $v;}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_-]/i','',(string)$s));}
function absint($v){return abs((int)$v);}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function wp_slash($v){return $v;}
function is_wp_error($v){return $v instanceof WP_Error;}
function get_current_user_id(){return 7;}
function current_user_can($cap,...$other){
 if($cap==='edit_post_meta' && ($other[1]??'')===$GLOBALS['fixture_meta_denied'])return false;
 if($cap==='assign_terms' && $GLOBALS['fixture_tax_denied'])return false;
 return true;
}
function get_post($id){return isset($GLOBALS['fixture_posts'][$id])?clone $GLOBALS['fixture_posts'][$id]:null;}
function get_posts($args){
 $found=[];
 foreach($GLOBALS['fixture_posts'] as $id=>$post){
  if($post->post_type!==($args['post_type']??''))continue;
  $values=$GLOBALS['fixture_meta'][$id][$args['meta_key']]??[];
  if(!isset($values[0]) || $values[0]!==($args['meta_value']??''))continue;
  $found[]=$id;
 }
 sort($found,SORT_NUMERIC);return array_slice($found,0,(int)($args['posts_per_page']??2));
}
function wp_delete_post($id,$force=false){
 if(!isset($GLOBALS['fixture_posts'][$id]))return false;
 ++$GLOBALS['fixture_writes'];
 unset($GLOBALS['fixture_posts'][$id],$GLOBALS['fixture_meta'][$id],
  $GLOBALS['fixture_tax'][$id],$GLOBALS['fixture_media'][$id]);
 return true;
}
function get_post_type_object($post_type){
 return (object)['cap'=>(object)['publish_posts'=>'publish_posts',
  'edit_others_posts'=>'edit_others_posts','edit_posts'=>'edit_posts']];
}
function get_post_type($id){return $GLOBALS['fixture_media_exists']&&$id===15?'attachment':'not-attachment';}
function get_post_thumbnail_id($id){return $GLOBALS['fixture_media'][$id]??0;}
function get_post_meta($id,$key,$single=true){
 $values=$GLOBALS['fixture_meta'][$id][$key]??[];
 return $single?($values[0]??''):$values;
}
function metadata_exists($kind,$id,$key){return array_key_exists($key,$GLOBALS['fixture_meta'][$id]??[]);}
function delete_post_meta($id,$key){
 ++$GLOBALS['fixture_writes'];
 $exists=metadata_exists('post',$id,$key);unset($GLOBALS['fixture_meta'][$id][$key]);
 return $exists;
}
function add_post_meta($id,$key,$val,$unique=false){
 ++$GLOBALS['fixture_writes'];
 $GLOBALS['fixture_meta'][$id][$key][]=$val;
 return true;
}
function update_post_meta($id,$key,$val){
 ++$GLOBALS['fixture_writes'];
 $GLOBALS['fixture_meta'][$id][$key]=[$val];
 return true;
}
function get_taxonomy($tax){
 return $tax==='destinations'?(object)['cap'=>(object)['assign_terms'=>'assign_terms']]:null;
}
function wp_get_object_terms($id,$tax,$args=[]){return $GLOBALS['fixture_tax'][$id][$tax]??[];}
function wp_set_object_terms($id,$ids,$tax,$append=false){
 ++$GLOBALS['fixture_writes'];
 $GLOBALS['fixture_tax'][$id][$tax]=array_values($ids);
 return $ids;
}
function wp_update_post($changes,$error=false){
 $id=(int)($changes['ID']??0);
 if(!isset($GLOBALS['fixture_posts'][$id]))return new WP_Error('missing_post');
 ++$GLOBALS['fixture_writes'];
 foreach($changes as $key=>$val){
  if($key!=='ID'&&property_exists($GLOBALS['fixture_posts'][$id],$key))
   $GLOBALS['fixture_posts'][$id]->$key=$val;
 }
 return $id;
}
function set_post_thumbnail($id,$media){++$GLOBALS['fixture_writes'];$GLOBALS['fixture_media'][$id]=$media;return true;}
function delete_post_thumbnail($id){++$GLOBALS['fixture_writes'];$GLOBALS['fixture_media'][$id]=0;return true;}
function clean_post_cache($id){}
class WP_Error{
 private $code;private $data;
 public function __construct($code,$message='',$data=[]){$this->code=$code;$this->data=$data;}
 public function get_error_code(){return $this->code;}
 public function get_error_data($code=''){return $this->data;}
 public function add_data($data,$code=''){$this->data=$data;}
}
class MAD4B_SCP_Provider_Compatibility_Certification{}
class MAD4B_SCP_Market_Growth_Policies{}
class MAD4B_SCP_Market_Content_Exchange{}
class MAD4B_SCP_Policy {public static function can_mutate(){return false;}}
function fixture_check($ok,$reason){if(!$ok)throw new RuntimeException('FAIL DYNAMIC ROLLBACK: '.$reason);}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/adapters/class-mad4b-scp-adapter-base.php';
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/adapters/class-mad4b-scp-dynamic-content-adapter.php';
$a=new MAD4B_SCP_Dynamic_Content_Adapter();
$snapshot=new ReflectionMethod($a,'snapshot');
$restore=new ReflectionMethod($a,'restore_snapshot_state');
if(PHP_VERSION_ID<80100){$snapshot->setAccessible(true);$restore->setAccessible(true);}
$input=['meta'=>['tour_price'=>0],'taxonomies'=>['destinations'=>[]]];
$original=$snapshot->invoke($a,77,$input);
fixture_check($original['post']['post_status']==='draft'
 && $original['post']['post_title']==='Original Nile Draft'
 && $original['meta']['tour_price']['values']===['100']
 && $original['featured_media_id']===15,'Original owned state was not captured exactly');

$mutate=static function(){
 $GLOBALS['fixture_posts'][77]->post_title='Newer drafted title';
 $GLOBALS['fixture_meta'][77]['tour_price']=['200'];
 $GLOBALS['fixture_tax'][77]['destinations']=[12];
 $GLOBALS['fixture_media'][77]=0;
 $GLOBALS['fixture_writes']=0;
};

$mutate();
$GLOBALS['fixture_meta_denied']='tour_price';
$pre=$snapshot->invoke($a,77,$input);
$denied=$restore->invoke($a,77,$original);
fixture_check(is_wp_error($denied)&&$denied->get_error_code()==='mad4b_dynamic_meta_restore_denied'
 && $GLOBALS['fixture_writes']===0
 && $pre===$snapshot->invoke($a,77,$input),
 'Denied metadata access must stop restore before ANY post/meta/term/media mutation');
$GLOBALS['fixture_meta_denied']='';
// Original public Undo entrypoint, not only the private restore helper.
$mutate();
$GLOBALS['fixture_meta_denied']='tour_price';
$denied_public=$a->restore_reversible_state(
 MAD4B_SCP_Dynamic_Content_Adapter::APPLY,
 ['mode'=>'update','post_id'=>77,'scope'=>$original['scope']],$original,[]);
fixture_check(is_wp_error($denied_public)
 && $denied_public->get_error_code()==='mad4b_dynamic_meta_restore_denied'
 && $GLOBALS['fixture_writes']===0
 && $GLOBALS['fixture_posts'][77]->post_title==='Newer drafted title',
 'Public reversible Undo must not change content before a predictable meta permission denial');
$GLOBALS['fixture_meta_denied']='';

// An otherwise valid snapshot is not portable across post IDs or content
// types, even when the caller can edit both resources.
$mutate();
$wrong_id=$original;$wrong_id['post']['ID']=88;
$pre=$snapshot->invoke($a,77,$input);
$denied=$restore->invoke($a,77,$wrong_id);
fixture_check(is_wp_error($denied)&&$denied->get_error_code()==='mad4b_dynamic_restore_identity_mismatch'
 && $GLOBALS['fixture_writes']===0 && $pre===$snapshot->invoke($a,77,$input),
 'Stale foreign post identity must be rejected before any write');
$wrong_type=$original;$wrong_type['post']['post_type']='another-company-post-type';
$denied=$restore->invoke($a,77,$wrong_type);
fixture_check(is_wp_error($denied)&&$denied->get_error_code()==='mad4b_dynamic_restore_identity_mismatch'
 && $GLOBALS['fixture_writes']===0,
 'Foreign post type cannot inherit original reversible ownership');

$mutate();
$GLOBALS['fixture_tax_denied']='destinations';
$pre=$snapshot->invoke($a,77,$input);
$denied=$restore->invoke($a,77,$original);
fixture_check(is_wp_error($denied)&&$denied->get_error_code()==='mad4b_dynamic_taxonomy_restore_denied'
 && $GLOBALS['fixture_writes']===0
 && $pre===$snapshot->invoke($a,77,$input),
 'Missing taxonomy assignment permission must stop BEFORE post/meta restore');
$GLOBALS['fixture_tax_denied']='';

$mutate();
$GLOBALS['fixture_media_exists']=false;
$pre=$snapshot->invoke($a,77,$input);
$denied=$restore->invoke($a,77,$original);
fixture_check(is_wp_error($denied)&&$denied->get_error_code()==='mad4b_dynamic_featured_media_restore_invalid'
 && $GLOBALS['fixture_writes']===0
 && $pre===$snapshot->invoke($a,77,$input),
 'Unavailable recorded featured-media attachment must deny before ANY writes');
$GLOBALS['fixture_media_exists']=true;

$mutate();
$GLOBALS['fixture_meta'][77]['_mad4b_dynamic_content_acceptance_v1']=['fixture-stale-acceptance'];
$target=['mode'=>'update','post_id'=>77,'scope'=>$original['scope']];
$restored=$a->restore_reversible_state(MAD4B_SCP_Dynamic_Content_Adapter::APPLY,
 $target,$original,['fixture_reviewed'=>true]);
fixture_check(is_array($restored)&&$restored['restored']===true
 && $original===$snapshot->invoke($a,77,$input)
 && !metadata_exists('post',77,'_mad4b_dynamic_content_acceptance_v1'),
 'Successful original reversible update must restore full owned post/meta/tax/media scope and invalidate stale acceptance');
// Exact Create Undo must use the original operation-key binding, not an
// unrelated nearby draft, and must never delete an originally existing post.
$key='test-creation-2026';
$binding=hash('sha256',MAD4B_SCP_Dynamic_Content_Adapter::CONTRACT.
 '|tours-and-activities|'.$key);
$GLOBALS['fixture_posts'][88]=(object)[
 'ID'=>88,'post_type'=>'tours-and-activities','post_title'=>'Created fixture draft',
 'post_content'=>'','post_excerpt'=>'','post_status'=>'draft','post_parent'=>0,'post_author'=>7
];
$GLOBALS['fixture_meta'][88]=[MAD4B_SCP_Dynamic_Content_Adapter::BINDING=>[$binding]];
$other=$a->restore_reversible_state(
 MAD4B_SCP_Dynamic_Content_Adapter::APPLY,
 ['mode'=>'create','post_type'=>'tours-and-activities','binding'=>str_repeat('f',64)],
 ['exists'=>false,'post_id'=>0],[]);
fixture_check(is_array($other)&&$other['post_deleted']===false
 && isset($GLOBALS['fixture_posts'][88]),
 'Wrong operation binding must never delete another post');
$exists=$a->restore_reversible_state(
 MAD4B_SCP_Dynamic_Content_Adapter::APPLY,
 ['mode'=>'create','post_type'=>'tours-and-activities','binding'=>$binding],
 ['exists'=>true,'post_id'=>88],[]);
fixture_check(is_wp_error($exists)&&isset($GLOBALS['fixture_posts'][88]),
 'Existing-before-create snapshot cannot authorize deletion');
$undo=$a->restore_reversible_state(
 MAD4B_SCP_Dynamic_Content_Adapter::APPLY,
 ['mode'=>'create','post_type'=>'tours-and-activities','binding'=>$binding],
 ['exists'=>false,'post_id'=>0],[]);
fixture_check(is_array($undo)&&$undo['restored']===true
 && $undo['post_deleted']===true && !isset($GLOBALS['fixture_posts'][88])
 && isset($GLOBALS['fixture_posts'][77])
 && $original===$snapshot->invoke($a,77,$input),
 'Create Undo must delete only the exact bound draft, preserving the other post');
echo "DYNAMIC_CONTENT_ROLLBACK_PREFLIGHT: PASS original Update and Create Undo, preflight, no partial writes\n";
