<?php
/** Original private TEST draft post type lifecycle; no live WordPress writes. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
 private $code;
 function __construct($code,$msg='',$data=[]){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function wp_get_environment_type(){return $GLOBALS['env'];}
function add_action($name,$cb,$priority=10){$GLOBALS['actions'][$name]=[$cb,$priority];}
function add_filter($name,$cb,$priority=10,$args=1){$GLOBALS['filters'][$name]=[$cb,$priority,$args];}
function register_post_type($type,$settings){$GLOBALS['posttypes'][$type]=$settings;}
function get_post($id){return $GLOBALS['stored_posts'][$id]??null;}
function get_posts($query){
 if(isset($GLOBALS['post_search']))return $GLOBALS['post_search'];
 $result=[];
 $key=$query['meta_query'][0]['value']??'';
 foreach($GLOBALS['stored_posts'] as $id=>$post){
  if($post->post_type===($query['post_type']??'')
    &&($GLOBALS['postmeta'][$id]['_mad4b_fixture_key']??'')===$key)$result[]=$id;
 }
 return $result;
}
function wp_insert_post($data,$wp_error=false){
 if(!empty($GLOBALS['fail_insert']))return new WP_Error('write_failed');
 $id=($GLOBALS['next_post_id']??100)+1;$GLOBALS['next_post_id']=$id;
 $GLOBALS['stored_posts'][$id]=(object)array_merge(['ID'=>$id],$data);
 return $id;
}
function add_post_meta($post,$key,$value,$unique=false){
 if(($GLOBALS['fail_meta_key']??'')===$key)return false;
 if($unique&&isset($GLOBALS['postmeta'][$post][$key]))return false;
 $GLOBALS['postmeta'][$post][$key]=$value;
 return true;
}
function wp_delete_post($id,$force=false){
 $GLOBALS['deleted_posts'][]=$id;
 unset($GLOBALS['stored_posts'][$id],$GLOBALS['postmeta'][$id]);
 return true;
}
function get_post_status($id){return $GLOBALS['stored_posts'][$id]->post_status??null;}
function get_post_meta($post,$key,$single=true){return $GLOBALS['postmeta'][$post][$key]??'';}
function qa_check($ok,$name){
 if(!$ok){fwrite(STDERR,'FAIL: '.$name.PHP_EOL);exit(1);}
 $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
}
class MAD4B_SCP_Staging_Context_Fixtures {
 static function catalog(){
  return [
   'site_uuid'=>'11111111-1111-4111-8111-111111111111','brand_id'=>'fixture-brand',
   'sources'=>[
    ['source_key'=>'first','source_id'=>'source-1','label'=>'First'],
    ['source_key'=>'second','source_id'=>'source-2','label'=>'Second']
   ],
   'assets'=>[
    ['asset_key'=>'one','source_id'=>'source-1','title'=>'One','content'=>'sample'],
    ['asset_key'=>'two','source_id'=>'source-1','title'=>'Two','content'=>'sample'],
    ['asset_key'=>'three','source_id'=>'source-2','title'=>'Three','content'=>'sample'],
    ['asset_key'=>'four','source_id'=>'source-2','title'=>'Four','content'=>'sample']
   ]
  ];
 }
}
$GLOBALS['env']='staging';$GLOBALS['actions']=[];$GLOBALS['filters']=[];
$GLOBALS['posttypes']=[];$GLOBALS['stored_posts']=[];$GLOBALS['postmeta']=[];
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-staging-fixture-drafts.php';
$c='MAD4B_SCP_Staging_Fixture_Drafts';
qa_check(isset($GLOBALS['actions']['init'])
 &&isset($GLOBALS['filters']['wp_insert_post_data'])
 &&isset($GLOBALS['filters']['add_post_metadata'])
 &&isset($GLOBALS['filters']['update_post_metadata'])
 &&isset($GLOBALS['filters']['delete_post_metadata']),
 'Original Staging-only source protects CPT and metadata through native WordPress lifecycle');
$c::registerPostTypes();
qa_check(count($GLOBALS['posttypes'])===2,'Only two original private TEST post types are enrolled');
foreach($GLOBALS['posttypes'] as $settings){
 qa_check($settings['public']===false
  &&$settings['show_in_rest']===false
  &&$settings['publicly_queryable']===false
  &&$settings['exclude_from_search']===true,
  'TEST drafts never expose public WordPress REST, search or URLs');
}
$new=$c::guardPostLifecycle(['post_type'=>$c::ASSET,'post_status'=>'publish','comment_status'=>'open','ping_status'=>'open'],[]);
qa_check($new['post_status']==='draft'&&$new['comment_status']==='closed'
 &&$new['ping_status']==='closed'&&$new['post_password']==='',
 'Unverified TEST asset publish request remains draft only');
$GLOBALS['stored_posts'][42]=(object)['ID'=>42,'post_type'=>$c::SOURCE,'post_status'=>'draft'];
$retyped=$c::guardPostLifecycle(['post_type'=>'post','post_status'=>'publish'],['ID'=>42]);
qa_check($retyped['post_type']===$c::SOURCE &&$retyped['post_status']==='draft',
 'Existing restricted TEST source cannot be retyped to public WordPress content');
$trashed=$c::guardPostLifecycle(['post_type'=>$c::SOURCE,'post_status'=>'trash'],[]);
qa_check($trashed['post_status']==='trash','Original isolated TEST content can still be trashed for cleanup');
foreach([$c::META_CLASS,$c::META_TEST,$c::META_SITE,$c::META_BRAND,
 $c::META_KEY,$c::META_SOURCE,$c::META_LOCK] as $key){
 qa_check($c::guardMetadataAdd(null,42,$key,'evil',true)===false
  &&$c::guardMetadataUpdate(null,42,$key,'evil',null)===false
  &&$c::guardMetadataDelete(null,42,$key,null,false)===false,
  'Protected classification and exact site/brand identity cannot be changed from external hooks');
}
qa_check($c::guardMetadataAdd(null,42,'_unrelated_meta','x',true)===null,
 'Original metadata filter does not block unrelated WordPress properties');
$report=$c::report();
qa_check(is_array($report)&&$report['expected_drafts']===6
 &&$report['existing_drafts']===0
 &&$report['external_send_allowed']===false
 &&$report['production_enabled']===false,
 'Native first-party TEST draft report is read only and cannot create booking/mail records');
qa_check(is_wp_error($c::provisionForStagingQa()),
 'Without an exact authorized CLI QA enrollment, no TEST draft is ever created');
unset($GLOBALS['stored_posts'][42]);
define('MAD4B_STAGING_QA_DRAFT_PROVISION',true);
$GLOBALS['fail_meta_key']=$c::META_CLASS;
$failed=$c::provisionForStagingQa();
qa_check(is_wp_error($failed)
 &&count($GLOBALS['stored_posts'])===0
 &&count($GLOBALS['deleted_posts']??[])===1,
 'Original Staging QA failure rolls back just-created private TEST records');
$GLOBALS['fail_meta_key']='';
$created=$c::provisionForStagingQa();
qa_check(is_array($created)&&$created['status']==='PASS'
 &&$created['total_test_drafts']===6
 &&$created['created_draft_posts']===6
 &&$created['email_sent']===false
 &&$created['external_registry_mutation']===false,
 'Exact original CLI QA enrollment creates only six private test drafts');
foreach($GLOBALS['stored_posts'] as $post){
 qa_check($post->post_status==='draft'
  &&in_array($post->post_type,[$c::SOURCE,$c::ASSET],true)
  &&$GLOBALS['postmeta'][$post->ID][$c::META_CLASS]==='test_only'
  &&$GLOBALS['postmeta'][$post->ID][$c::META_LOCK]==='1',
  'Every created record remains permanently test-classified and private');
}
$idempotent=$c::provisionForStagingQa();
qa_check(is_array($idempotent)&&$idempotent['created_draft_posts']===0
 &&count($GLOBALS['stored_posts'])===6,
 'Identical exact QA preflight reuses six existing original test drafts');
echo 'PASS: STAGING_FIXTURE_DRAFTS '.($GLOBALS['checks']??0).
 ' original private CPT and immutable metadata lifecycle checks'.PHP_EOL;
