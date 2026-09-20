<?php
declare(strict_types=1);

namespace {
function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
function sanitize_title($value){return trim(preg_replace('/[^a-z0-9\-_]+/','-',strtolower((string)$value)),'-');}
function sanitize_text_field($value){return trim(strip_tags((string)$value));}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
$GLOBALS['etg_profile_filter_mode']='identity';
function apply_filters($tag,$value,...$args){
    if('wpml_active_languages'===$tag){return array('en'=>array(),'it'=>array(),'ar'=>array());}
    if('etg_filter_seo_surface_profiles'===$tag && 'overflow'===$GLOBALS['etg_profile_filter_mode']){
        $out=(array)$value;
        for($i=0;$i<55;$i++){$out['filtered-'.$i]=array('id'=>'filtered-'.$i,'enabled'=>false);}
        return $out;
    }
    return $value;
}
}

namespace ETG\DynamicFilterSEOBridge\Config {
final class Configuration {
    private $data;
    public function __construct(array $data){$this->data=$data;}
    public function get(string $key,$default=null){return array_key_exists($key,$this->data)?$this->data[$key]:$default;}
    public function revision():string{return 'hardening-test';}
}
}

namespace {
$base=dirname(__DIR__);
require_once $base.'/includes/Config/ProfileRegistry.php';
require_once $base.'/includes/Diagnostics/RuntimeInventory.php';

use ETG\DynamicFilterSEOBridge\Config\Configuration;
use ETG\DynamicFilterSEOBridge\Config\ProfileRegistry;
use ETG\DynamicFilterSEOBridge\Diagnostics\RuntimeInventory;

function check($condition,$message){if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}}

$inherited=array(
    'id'=>'inherited-surface','enabled'=>true,'inherit_global_defaults'=>true,
    'post_types'=>array('book'),'require_post_type_binding'=>true,'post_type_authority'=>'query_builder',
    'archive_slugs'=>array('books'),'archive_paths'=>array('/books/'),
    'providers'=>array('jet-engine'),'query_ids'=>array('books_a','books_b'),'routes'=>array(),
    'taxonomy_rules'=>array('genre'=>array('role'=>'genre','index_single'=>true,'min_results'=>2)),
    'allowed_taxonomy_sets'=>array('genre'),'indexable_combinations'=>array(),
);
$registry=new ProfileRegistry(new Configuration(array(
    'profiles_json'=>json_encode(array($inherited)),
    'providers'=>array('jet-engine'),'query_ids'=>array('books_a','books_b'),
    'allowed_taxonomies'=>array('genre','injected_taxonomy'),
)));
$errors=$registry->validationErrors();
check(in_array('profile:inherited-surface:exact_routes_required',$errors,true),'inheritance cannot waive exact route pairs');
check(array_keys($registry->get('inherited-surface')['taxonomy_rules'])===array('genre'),'global inheritance cannot synthesize taxonomy identities');
$resolution=$registry->resolve(array('archive'=>'books','archive_path'=>'/books/','provider'=>'jet-engine','query_id'=>'books_a','filters'=>array('genre'=>'seo')));
check($resolution['scope_valid']===false && $resolution['reason']==='provider_not_profiled','provider/query arrays cannot authorize an exact route');

$tooMany=$inherited;
$tooMany['id']='too-many-routes';
$tooMany['inherit_global_defaults']=false;
$tooMany['routes']=array();
for($i=0;$i<21;$i++){$tooMany['routes'][]=array('provider'=>'jet-engine','query_id'=>'q'.$i);}
$boundedRegistry=new ProfileRegistry(new Configuration(array('profiles_json'=>json_encode(array($tooMany)))));
$firstErrors=$boundedRegistry->validationErrors();
check(in_array('profile:too-many-routes:route_limit_exceeded',$firstErrors,true),'normalization errors are visible on the first validation call');
$blocked=$boundedRegistry->resolve(array('archive'=>'books','archive_path'=>'/books/','provider'=>'jet-engine','query_id'=>'q0','filters'=>array('genre'=>'seo')));
check($blocked['scope_valid']===false && $blocked['reason']==='profile_registry_invalid','truncated authority input cannot authorize even an included route');

$GLOBALS['etg_profile_filter_mode']='overflow';
$safe=$inherited;
$safe['id']='safe-disabled';
$safe['enabled']=false;
$safe['inherit_global_defaults']=false;
$safe['routes']=array(array('provider'=>'jet-engine','query_id'=>'books_a'));
$filteredRegistry=new ProfileRegistry(new Configuration(array('profiles_json'=>json_encode(array($safe)))));
$filteredErrors=$filteredRegistry->validationErrors();
check(in_array('filtered_profile_count_limit_exceeded',$filteredErrors,true),'post-filter profile overflow fails visibly');
$filteredDecision=$filteredRegistry->resolve(array('archive'=>'books','archive_path'=>'/books/','provider'=>'jet-engine','query_id'=>'books_a','filters'=>array('genre'=>'seo')));
check($filteredDecision['scope_valid']===false && $filteredDecision['reason']==='profile_registry_invalid','post-filter truncation fails closed at scope resolution');
$GLOBALS['etg_profile_filter_mode']='identity';

class HardeningQueryMock {
    public $id;
    public $query_id;
    private $postType;
    public function __construct($id,$queryId,$postType){$this->id=$id;$this->query_id=$queryId;$this->postType=$postType;}
    public function get_query_type(){return 'posts';}
    public function get_query_args(){return array('post_type'=>$this->postType);}
}
$languages=function(){return array('en'=>array('code'=>'en','active'=>1,'url'=>'https://example.test/'));};
$forward=function(){return array(new HardeningQueryMock(10,'same_identity','tour'),new HardeningQueryMock(10,'same_identity','product'));};
$reverse=function() use ($forward){return array_reverse($forward());};
$a=(new RuntimeInventory($forward,$languages))->collect();
$b=(new RuntimeInventory($reverse,$languages))->collect();
check($a['snapshot_fingerprint']===$b['snapshot_fingerprint'],'equal identity/id/type collisions remain deterministic across provider order');
check($a['inventory']['query_builder']['identity_conflict_count']===1,'equal identity collision is detected');
$records=$a['inventory']['query_builder']['identity_conflicts'][0]['records'];
$postTypeSets=array_map(function($record){return implode(',',(array)($record['post_types']??array()));},$records);
sort($postTypeSets,SORT_STRING);
check($postTypeSets===array('product','tour'),'collision evidence includes structural Post Type identity');

echo "Pre-staging authority hardening smoke tests passed.\n";
}
