<?php
declare(strict_types=1);
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function sanitize_title($v){return trim(preg_replace('/[^a-z0-9\-_]+/','-',strtolower((string)$v)),'-');}
function absint($v){return abs((int)$v);}function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}function wp_strip_all_tags($v){return strip_tags((string)$v);}function wp_kses_post($v){return(string)$v;}function _n($s,$p,$n,$d=null){return 1===$n?$s:$p;}function get_bloginfo($k){return'ETG';}function apply_filters($tag,$value){return$value;}function home_url($path=''){return'https://example.test'.('/'===substr((string)$path,0,1)?$path:'/'.$path);}function esc_url_raw($v){return(string)$v;}function wp_get_attachment_image_url($id,$size){return'https://example.test/media/'.$id.'.jpg';}
$GLOBALS['etg_options']=array();function get_option($key,$default=array()){return$GLOBALS['etg_options'][$key]??$default;}function update_option($key,$value,$autoload=false){$GLOBALS['etg_options'][$key]=$value;return true;}
function expect_true($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}}function expect_same($e,$a,$m){if($e!==$a){fwrite(STDERR,"FAIL: $m\nEXPECTED ".var_export($e,true)."\nACTUAL ".var_export($a,true)."\n");exit(1);}}

require_once dirname(__DIR__).'/includes/JetEngine/QueryIdentityResolver.php';
require_once dirname(__DIR__).'/includes/Runtime/RuntimeTopologyDiscoverer.php';
require_once dirname(__DIR__).'/includes/Runtime/RuntimeQueryBindingResolver.php';
require_once dirname(__DIR__).'/includes/Diagnostics/RuntimeInventory.php';
require_once dirname(__DIR__).'/includes/Diagnostics/InventoryReconciler.php';
require_once dirname(__DIR__).'/includes/Content/GalleryComposer.php';
require_once dirname(__DIR__).'/includes/Content/ContentComposer.php';
require_once dirname(__DIR__).'/includes/Presentation/ContentSlotRegistry.php';
require_once dirname(__DIR__).'/includes/Presentation/InventoryContentCatalog.php';
require_once dirname(__DIR__).'/includes/Presentation/PresentationResolver.php';

use ETG\DynamicFilterSEOBridge\JetEngine\QueryIdentityResolver;
use ETG\DynamicFilterSEOBridge\Runtime\RuntimeTopologyDiscoverer;
use ETG\DynamicFilterSEOBridge\Runtime\RuntimeQueryBindingResolver;
use ETG\DynamicFilterSEOBridge\Diagnostics\RuntimeInventory;
use ETG\DynamicFilterSEOBridge\Diagnostics\InventoryReconciler;
use ETG\DynamicFilterSEOBridge\Content\GalleryComposer;
use ETG\DynamicFilterSEOBridge\Content\ContentComposer;
use ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry;
use ETG\DynamicFilterSEOBridge\Presentation\InventoryContentCatalog;
use ETG\DynamicFilterSEOBridge\Presentation\PresentationResolver;

class Alpha11Query{public $id;public $query_id;private $type;private $args;public function __construct($id,$qid,$type='posts',$postType='tours-and-activities'){$this->id=$id;$this->query_id=$qid;$this->type=$type;$this->args=array('post_type'=>$postType);}public function get_query_type(){return$this->type;}public function get_query_args(){return$this->args;}}
$queries=array();for($i=0;$i<130;$i++){$queries[]=new Alpha11Query(1000+$i,'q'.str_pad((string)$i,3,'0',STR_PAD_LEFT),'posts','post');}
$target=new Alpha11Query(5,'tours_archive_qb','posts','tours-and-activities');$queries[]=$target;
$queries[]=new Alpha11Query(2001,'unrelated_collision','posts','post');$queries[]=new Alpha11Query(2002,'unrelated_collision','posts','page');
$queryProvider=function()use(&$queries){return$queries;};
$templateProvider=function(){return array(array('id'=>30843,'data'=>array(array('id'=>'listing','elType'=>'widget','widgetType'=>'jet-listing-grid','settings'=>array('_element_id'=>'tours_query_archive','custom_query'=>'yes','custom_query_id'=>'5')),array('id'=>'filter','elType'=>'widget','settings'=>array('query_id'=>'tours_query_archive')))));};
$topology=new RuntimeTopologyDiscoverer($templateProvider,$queryProvider);$top=$topology->discover(true);
expect_same(true,$top['available'],'topology sources available');expect_same(1,$top['binding_count'],'one correlated binding');$binding=$top['bindings'][0];expect_same('verified',$binding['status'],'binding verified');expect_same('tours_query_archive',$binding['provider_query_id'],'provider identity preserved');expect_same('5',$binding['query_builder_internal_id'],'internal ID evidence captured');expect_same('tours_archive_qb',$binding['query_builder_custom_query_id'],'stable Query Builder custom ID resolved');expect_same(array('tours-and-activities'),$binding['post_types'],'post type discovered from Query Builder');
$identity=new QueryIdentityResolver($queryProvider);$runtimeBinding=new RuntimeQueryBindingResolver($topology,$identity);$resolved=$runtimeBinding->resolve('jet-engine','tours_query_archive');expect_same(true,$resolved['resolved'],'provider ID auto-resolves through topology');expect_same('tours_archive_qb',$resolved['query_builder_custom_query_id'],'runtime consumer receives Query Builder custom ID');expect_same('5',$resolved['query_builder_internal_id'],'numeric internal ID remains evidence');expect_same('elementor_runtime_topology',$resolved['source'],'topology source declared');

$inventory=new RuntimeInventory($queryProvider,function(){return array('en'=>array('code'=>'en','default_locale'=>'en_US','native_name'=>'English','translated_name'=>'English','active'=>1,'url'=>'https://example.test/'));},function()use($topology){return$topology->discover();});
$method=new ReflectionMethod($inventory,'queries');$method->setAccessible(true);$qr=$method->invoke($inventory);expect_same(RuntimeInventory::MAX_QUERIES,count($qr['data']['queries']),'detail list bounded');expect_same(count($queries),count($qr['data']['identity_index']),'full identity index retained beyond first 100');expect_same(true,$qr['data']['identity_index_complete'],'identity index declared complete');expect_same(true,$qr['completeness']['truncated'],'detail truncation explicit');expect_same(false,$qr['identity_completeness']['truncated'],'identity evaluation is complete');

$invCore=array('post_types'=>array('tours-and-activities'=>array('label'=>'Tours & Activities','publicly_queryable'=>true,'has_archive'=>true,'taxonomies'=>array('location_jet','tour-types_jet','tour-styles_jet'),'archive_paths'=>array('current'=>'/tours-and-activities/'))),'taxonomies'=>array('location_jet'=>array('object_type'=>array('tours-and-activities')),'tour-types_jet'=>array('object_type'=>array('tours-and-activities')),'tour-styles_jet'=>array('object_type'=>array('tours-and-activities'))),'languages'=>array(array('code'=>'en','url_path'=>'/')),'query_builder'=>$qr['data'],'elementor_topology'=>$top,'completeness'=>array('post_types'=>array('observed_count'=>1,'included_count'=>1,'limit'=>RuntimeInventory::MAX_POST_TYPES,'truncated'=>false),'taxonomies'=>array('observed_count'=>3,'included_count'=>3,'limit'=>RuntimeInventory::MAX_TAXONOMIES,'truncated'=>false),'languages'=>array('observed_count'=>1,'included_count'=>1,'limit'=>RuntimeInventory::MAX_LANGUAGES,'truncated'=>false),'query_builder'=>$qr['completeness'],'query_identity_index'=>$qr['identity_completeness'],'archive_path_translations'=>array('observed_count'=>0,'included_count'=>0,'limit'=>RuntimeInventory::MAX_ARCHIVE_PATH_TRANSLATIONS,'truncated'=>false)));
$snapshot=array('contract'=>RuntimeInventory::CONTRACT,'authorizing'=>false,'read_only'=>true,'profile_mutation'=>false,'snapshot_fingerprint'=>hash('sha256',json_encode($invCore,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),'inventory'=>$invCore);
$profile=array('id'=>'tours','enabled'=>true,'post_types'=>array('tours-and-activities'),'taxonomy_rules'=>array('location_jet'=>array('role'=>'location'),'tour-types_jet'=>array('role'=>'tour_type'),'tour-styles_jet'=>array('role'=>'style')),'archive_paths'=>array('/tours-and-activities/'),'routes'=>array(array('provider'=>'jet-engine','query_id'=>'tours_query_archive')));
$recon=(new InventoryReconciler())->analyze($snapshot,array('tours'=>$profile));$codes=array_column($recon['findings'],'code');expect_same(0,$recon['summary']['blocking'],'unrelated Query Builder collision does not block uniquely resolved Tours profile');expect_true(in_array('query_builder_identity_collision',$codes,true),'unrelated collision stays visible as warning evidence');expect_true(in_array('profile_query_binding_verified',$codes,true),'provider-to-Query Builder binding verified automatically');expect_true(!in_array('profile_query_missing',$codes,true),'provider ID is not misread as Query Builder custom ID');

$slots=new ContentSlotRegistry();$save=$slots->save(array('id'=>'archive_heading','label'=>'Archive Heading','enabled'=>true,'type'=>'text','template'=>'Explore {{term:location:name}} — {{result_count}} tours','source_inventory_fingerprint'=>$snapshot['snapshot_fingerprint']));expect_same(true,$save['saved'],'slot saved');expect_same(false,$save['authorizing'],'slot save is non-authorizing');
$gallery=new GalleryComposer();$content=new ContentComposer($gallery);$context=array('active'=>true,'in_scope'=>true,'scope_valid'=>true,'runtime_ready'=>true,'filters'=>array('location_jet'=>'cairo'),'provider_observation_matches_url'=>true,'terms'=>array('location'=>array('term_id'=>10,'name'=>'Cairo','slug'=>'cairo','description'=>'Cairo tours','short_description'=>'','image_id'=>0,'gallery_ids'=>array())),'profile'=>array('require_post_type_binding'=>false,'taxonomy_rules'=>array('location_jet'=>array('role'=>'location','priority'=>10))),'post_type_binding'=>array(),'result_count'=>29,'language'=>'en','profile_id'=>'tours','provider'=>'jet-engine','query_id'=>'tours_query_archive','archive_path'=>'/tours-and-activities/','unknown_filters'=>array(),'malformed'=>false,'missing_terms'=>array(),'translation_fallback'=>false);
$resolver=new PresentationResolver(function()use($context){return$context;},$content,$gallery,$slots);expect_same('Explore Cairo — 29 tours',$resolver->slot('archive_heading'),'slot composes Inventory-compatible runtime tokens');
$catalog=(new InventoryContentCatalog())->build($snapshot,array('tours'=>$profile));expect_true(isset($catalog['tokens']['term:location:name']),'taxonomy role produces term token');expect_true(isset($catalog['tokens']['topology:tours_query_archive:query_builder_query_id']),'verified topology becomes a non-authorizing inventory token');expect_same(false,$catalog['authorizing'],'catalog cannot authorize indexing');

echo "Alpha11 runtime topology and dynamic content smoke tests passed.\n";
