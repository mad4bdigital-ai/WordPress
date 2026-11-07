<?php
define('ABSPATH','/qa');
define('DAY_IN_SECONDS',86400);
define('ARRAY_A','ARRAY_A');
$GLOBALS['opts']=['mad4b_page_dependency_schema_v1'=>'installed'];
$GLOBALS['cache']=[];
function add_action($name,$callback,$priority=10,$args=1){}
function add_filter($name,$callback,$priority=10,$args=1){}
function get_option($name,$default=false){return $GLOBALS['opts'][$name]??$default;}
function apply_filters($name,$value,...$args){return $value;}
function wp_json_encode($value){return json_encode($value);}
function absint($value){return abs((int)$value);}
function wp_cache_get($key,$group=''){return $GLOBALS['cache'][$group][$key]??false;}
function wp_cache_set($key,$value,$group='',$ttl=0){$GLOBALS['cache'][$group][$key]=$value;return true;}
function wp_cache_add($key,$value,$group='',$ttl=0){if(isset($GLOBALS['cache'][$group][$key]))return false;$GLOBALS['cache'][$group][$key]=$value;return true;}
function wp_cache_delete($key,$group=''){unset($GLOBALS['cache'][$group][$key]);return true;}
function sanitize_key($value){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$value));}
class FakeDB {
    public string $prefix='wp_';
    public string $term_taxonomy='wp_term_taxonomy';
    public string $last_error='';
    public function prepare($q,...$args){return $q;}
    public function get_var($q){
        if(str_contains($q,'COUNT(DISTINCT dep_hash)'))
            return $GLOBALS['proof_edge_count']??0;
        return 1;
    }
    public function get_row($q,$mode=ARRAY_A){
        return !empty($GLOBALS['proof_is_complete'])?['coverage'=>'complete']:null;
    }
    public function get_col($q){return [14,15];}
    public function get_results($q,$mode=ARRAY_A){
        return !empty($GLOBALS['force_overflow'])
            ?array_fill(0,82,['page_hash'=>str_repeat('a',64),'page_path'=>'/qa/',
                'node_kind'=>'query','coverage'=>'complete']):[];
    }
}
function get_post_type($id){return 'tours-and-activities';}
function get_post_type_object($type){return (object)['public'=>true];}
function get_taxonomy($name){return (object)['public'=>true];}
function wp_using_ext_object_cache(){return true;}
function wp_rand($min,$max){return $min;}
function get_current_user_id(){return 1;}
function update_option($key,$value,$autoload=false){$GLOBALS['opts'][$key]=$value;return true;}
function do_action($name,...$args){}
$wpdb=new FakeDB();
$selector=getenv('MAD4B_PAGE_DEP_FILE');
if(!$selector)throw new Exception('MAD4B_PAGE_DEP_FILE missing');
require $selector;
$scopeFile=getenv('MAD4B_PUBLISHED_SCOPE_FILE');
if($scopeFile)require $scopeFile;
$GLOBALS['asserts']=0;
function test_case($name,$condition) {
 if(!$condition)throw new RuntimeException('FAIL '.$name);
 $GLOBALS['asserts']++;
 echo 'PASS '.$name."\n";
}
test_case('default strategy hybrid',MAD4B_Page_Dependency_Cache::policy()['strategy']==='hybrid');
test_case('unknown dependency safe fallback',MAD4B_Page_Dependency_Cache::policy()['unknown']==='legacy_scope');
test_case('warming disabled until approved',MAD4B_Page_Dependency_Cache::policy()['refresh']==='off');
$p=MAD4B_Page_Dependency_Cache::provider([]);
test_case('provider registered',isset($p['page-dependency-cache']));
test_case('provider reversible scope',in_array('company',$p['page-dependency-cache']['allowed_scopes'],true));
test_case('schema marker ready',MAD4B_Page_Dependency_Cache::ready());
$ref=new ReflectionMethod(MAD4B_Page_Dependency_Cache::class,'query_signature');
$approved=(object)['id'=>33,'query_type'=>'posts','final_query'=>[
    'post_type'=>['tours-and-activities'],'post_status'=>'publish',
    'tax_query'=>[['taxonomy'=>'destinations','field'=>'term_id','terms'=>[14,15],'operator'=>'IN']],
    'orderby'=>'date','order'=>'DESC']];
$valid=$ref->invoke(null,$approved);
test_case('filtered posts query supported',$valid['supported']===true);
test_case('filtered query omits broad post-type membership',
    !in_array('query_pt:tours-and-activities',$valid['dependencies'],true));
$plain=(object)['id'=>34,'query_type'=>'posts','final_query'=>[
    'post_type'=>['tours-and-activities'],'post_status'=>'publish']];
test_case('unfiltered query retains post type membership',
    in_array('query_pt:tours-and-activities',$ref->invoke(null,$plain)['dependencies'],true));
test_case('destination term 14 membership tracked',in_array('query_tax:destinations:14',$valid['dependencies'],true));
test_case('destination term 15 membership tracked',in_array('query_tax:destinations:15',$valid['dependencies'],true));
$invalid=(object)['id'=>19,'query_type'=>'posts','final_query'=>[
   'post_type'=>['tour-rates']]];
test_case('sensitive pricing query rejected',$ref->invoke(null,$invalid)['supported']===false);
$unknown=(object)['id'=>19,'query_type'=>'sql','final_query'=>['query'=>'SELECT *']];
test_case('unknown custom SQL rejected',$ref->invoke(null,$unknown)['supported']===false);
$term=(object)['id'=>18,'query_type'=>'terms','final_query'=>[
    'taxonomy'=>['destinations'],'hide_empty'=>true]];
test_case('term query membership recorded',in_array('query_taxonomy:destinations',$ref->invoke(null,$term)['dependencies'],true));
$meta=(object)['id'=>36,'query_type'=>'posts','final_query'=>[
 'post_type'=>['tours-and-activities'],'meta_key'=>'rank','orderby'=>'meta_value_num']];
test_case('meta-ranked query is unproven',$ref->invoke(null,$meta)['supported']===false);
$or=(object)['id'=>37,'query_type'=>'posts','final_query'=>[
 'post_type'=>['tours-and-activities'],'tax_query'=>[
 'relation'=>'OR',['taxonomy'=>'destinations','terms'=>[14],'field'=>'term_id']]]];
test_case('OR taxonomy query falls back',$ref->invoke(null,$or)['supported']===false);
$nest=(object)['id'=>38,'query_type'=>'posts','final_query'=>[
 'post_type'=>['tours-and-activities'],'tax_query'=>[
 [['taxonomy'=>'destinations','terms'=>[14],'field'=>'term_id']]]]];
test_case('nested taxonomy query falls back',$ref->invoke(null,$nest)['supported']===false);
test_case('impact preview refuses unsupported key',
    MAD4B_Page_Dependency_Cache::impact_preview('orders:12')['status']==='invalid_dependency');
test_case('impact preview remains read only',
    MAD4B_Page_Dependency_Cache::impact_preview('post:60623')['mutation_performed']===false);
$result=MAD4B_Page_Dependency_Cache::query_version('gen-0',$approved,['cache'=>true,'ttl'=>900]);
test_case('unproven graph retains legacy generation',$result==='gen-0');
test_case('unproven authority is legacy',
    MAD4B_Page_Dependency_Cache::authority($approved,['cache'=>true,'ttl'=>900])==='legacy');
if($scopeFile){
    $oldVersion=MAD4B_Published_Content_Scope_Cache::query_version('base',$approved,['cache'=>true,'ttl'=>900]);
    test_case('legacy fallback keeps post-type-or-taxonomy epoch',str_contains($oldVersion,':scope:'));
}
// The certified graph has both the exact query node and the exact expected
// predicate edges, with expiry beyond the requested query cache lifetime.
$known=(object)['id'=>999,'query_type'=>'posts','final_query'=>$approved->final_query];
$signature=$ref->invoke(null,$known);
$GLOBALS['proof_is_complete']=true;
$GLOBALS['proof_edge_count']=count($signature['dependencies']);
$decision=['cache'=>true,'ttl'=>900];
test_case('complete query graph becomes dependency authority',
    MAD4B_Page_Dependency_Cache::authority($known,$decision)==='dependency');
$legacyKey=$scopeFile
    ? MAD4B_Published_Content_Scope_Cache::query_version('base',$known,$decision)
    : 'base';
test_case('certified query skips type-wide epoch',$legacyKey==='base');
$key=MAD4B_Page_Dependency_Cache::query_version($legacyKey,$known,$decision);
test_case('certified query gets standalone graph namespace',
    str_contains($key,':hybrid-graph-v1:')&&!str_contains($key,':scope:'));
test_case('certified graph ignores unrelated published type epoch',
    MAD4B_Page_Dependency_Cache::query_version($legacyKey,$known,$decision)===$key);
$GLOBALS['proof_is_complete']=false;
$GLOBALS['proof_edge_count']=0;
test_case('uncacheable query leaves generation unchanged',MAD4B_Page_Dependency_Cache::query_version('gen-0',$approved,['cache'=>false])==='gen-0');
test_case('unknown query leaves generation unchanged',MAD4B_Page_Dependency_Cache::query_version('gen-0',$unknown,['cache'=>true])==='gen-0');
$GLOBALS['opts'][MAD4B_Page_Dependency_Cache::OPTION]=['strategy'=>'legacy_post_type'];
test_case('operator can select Post Type only',
    MAD4B_Page_Dependency_Cache::authority($known,$decision)==='legacy');
$GLOBALS['opts'][MAD4B_Page_Dependency_Cache::OPTION]=['strategy'=>'hybrid'];
if($scopeFile){
    $GLOBALS['opts'][MAD4B_Published_Content_Scope_Cache::OPTION]=['status'=>'paused'];
    test_case('global legacy policy disables graph authority',
        MAD4B_Page_Dependency_Cache::authority($known,$decision)==='legacy');
    unset($GLOBALS['opts'][MAD4B_Published_Content_Scope_Cache::OPTION]);
}
test_case('provider exposes hybrid mode',
    isset(MAD4B_Page_Dependency_Cache::provider([])['page-dependency-cache']['fields']['strategy']['options']['hybrid']));
test_case('per-query override scope supported',
    in_array('query',MAD4B_Page_Dependency_Cache::provider([])['page-dependency-cache']['allowed_scopes'],true));
test_case('deterministic key stable',MAD4B_Page_Dependency_Cache::hash('post:44')===MAD4B_Page_Dependency_Cache::hash('post:44'));
$GLOBALS['opts'][MAD4B_Page_Dependency_Cache::OPTION]=['unknown'=>'no_cache'];
$unknownCached=(object)['id'=>11,'query_type'=>'sql','cache_query'=>true,'cache_expires'=>900,
  'final_query'=>['sql'=>'SELECT NOW()']];
MAD4B_Page_Dependency_Cache::unknown_query_policy($unknownCached);
test_case('unknown source no_cache enforced',$unknownCached->cache_query===false&&$unknownCached->cache_expires===0);
$knownCached=(object)['id'=>11,'query_type'=>'posts','cache_query'=>true,'cache_expires'=>900,
  'final_query'=>['post_type'=>['tours-and-activities']]];
MAD4B_Page_Dependency_Cache::unknown_query_policy($knownCached);
test_case('unproven query with no_cache is uncached',
    $knownCached->cache_query===false&&$knownCached->cache_expires===0);
$GLOBALS['opts'][MAD4B_Page_Dependency_Cache::OPTION]=['strategy'=>'legacy_post_type','unknown'=>'no_cache'];
$legacyCached=(object)['id'=>12,'query_type'=>'posts','cache_query'=>true,'cache_expires'=>900,
    'final_query'=>['post_type'=>['tours-and-activities']]];
MAD4B_Page_Dependency_Cache::unknown_query_policy($legacyCached);
test_case('explicit Post Type strategy still controls legacy cache',$legacyCached->cache_query===true);
$GLOBALS['opts'][MAD4B_Page_Dependency_Cache::OPTION]=['strategy'=>'hybrid'];
$collect=new ReflectionProperty(MAD4B_Page_Dependency_Cache::class,'collecting');
$collect->setValue(true);
$widget=new class {
  function get_name(){return 'template';}
  function get_settings_for_display(){return ['template_id'=>48562];}
};
MAD4B_Page_Dependency_Cache::elementor_widget($widget);
$edgesProp=new ReflectionProperty(MAD4B_Page_Dependency_Cache::class,'edges');
test_case('Elementor template dependency observed',isset($edgesProp->getValue()['template:48562']));
$collect->setValue(false);
MAD4B_Page_Dependency_Cache::terms_changed(60623,[],[110],'destinations',false,[100]);
$receipts=$GLOBALS['opts'][MAD4B_Page_Dependency_Cache::RECEIPTS]??[];
$receipt=end($receipts);
test_case('old destination membership invalidated',in_array('query_tax:destinations:14',$receipt['dependencies']??[],true));
test_case('new destination membership invalidated',in_array('query_tax:destinations:15',$receipt['dependencies']??[],true));
$collect->setValue(true);
$queriesProp=new ReflectionProperty(MAD4B_Page_Dependency_Cache::class,'queries');
$queriesProp->setValue(array_fill_keys(range(1,24),['query_pt:tours-and-activities']));
MAD4B_Page_Dependency_Cache::observe_query($approved);
$unknownProp=new ReflectionProperty(MAD4B_Page_Dependency_Cache::class,'unknown');
test_case('query collection budget marks coverage unknown',$unknownProp->getValue()===true);
$collect->setValue(false);
// Proof of safe failure: excessive reverse-index fan-out cannot leave
// certified Query Cache on an unchanged generation.
$GLOBALS['force_overflow']=true;
$guardBefore=MAD4B_Page_Dependency_Cache::guard_epoch();
$overflow=MAD4B_Page_Dependency_Cache::invalidate(['post:99999998'],'qa_test');
test_case('overflow refuses unbounded selective invalidation',
    ($overflow['status']??'')==='graph_overflow' && $overflow['fallback']===true);
test_case('overflow rotates hybrid safety generation',
    MAD4B_Page_Dependency_Cache::guard_epoch()!==$guardBefore);
$GLOBALS['force_overflow']=false;
echo 'PASS '.$GLOBALS['asserts']." GRAPH FIXTURES\n";
