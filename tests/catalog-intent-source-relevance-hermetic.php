<?php
/**
 * Original first-party Catalog Intent source planning — no crawling or
 * content tasks, no WordPress DB. Exercises actual class and read schema.
 */
define('ABSPATH',__DIR__.'/');
$GLOBALS['catalog_read_allowed']=false;
$GLOBALS['catalog_abilities']=array();
function add_action($name,$method,$priority=10){}
function wp_register_ability($name,$cfg){$GLOBALS['catalog_abilities'][$name]=$cfg;}
function wp_has_ability($name){return isset($GLOBALS['catalog_abilities'][$name]);}
class WP_Error {
 private $code;public function __construct($code,$msg='',$data=array()){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
class MAD4B_SCP_Policy {public static function can_read(){return $GLOBALS['catalog_read_allowed'];}}
function catalog_assert($ok,$label){if(!$ok)throw new RuntimeException('FAIL CATALOG INTENT: '.$label);}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-catalog-intent-policy.php';
$c='MAD4B_SCP_Catalog_Intent_Policy';
$c::register();
catalog_assert(count($GLOBALS['catalog_abilities'])===1
 && isset($GLOBALS['catalog_abilities'][$c::ABILITY]),'One exact original read-only planner only');
$config=$GLOBALS['catalog_abilities'][$c::ABILITY];
catalog_assert($config['permission_callback']===array('MAD4B_SCP_Policy','can_read')
 && $config['meta']['mcp']['surface']==='read'
 && $config['meta']['annotations']['readonly']===true
 && $config['input_schema']['additionalProperties']===false
 && $config['input_schema']['properties']['links']['maxItems']===$c::MAX_LINKS,
 'Native schema is bounded, closed and original WordPress-policy protected');
$root='https://examples.invalid/tours/';
$links=array(
 array('url'=>'https://examples.invalid/tours/private-nile-journey/?utm_campaign=promo&nights=4',
  'title'=>'Four night journey','entity_type'=>'individual_tour',
  'discovery_context'=>'listing_card','intent_relation'=>'direct','entity_key'=>'nile4'),
 array('url'=>'https://examples.invalid/tours/private-nile-journey/?nights=4',
  'title'=>'Duplicate footer entry','entity_type'=>'individual_tour',
  'discovery_context'=>'footer','intent_relation'=>'unknown','entity_key'=>'nile4'),
 array('url'=>'https://examples.invalid/tours/destinations/luxor',
  'title'=>'Luxor destination guide','entity_type'=>'destination',
  'discovery_context'=>'inline_reference','intent_relation'=>'supporting',
  'related_primary_urls'=>array('https://examples.invalid/tours/private-nile-journey/?nights=4')),
 array('url'=>'https://examples.invalid/tours/?destination=luxor&page=2',
  'title'=>'More destinations','entity_type'=>'filter',
  'discovery_context'=>'filter_control','intent_relation'=>'direct'),
 array('url'=>'https://examples.invalid/tours/unrelated',
  'title'=>'Unrelated page','entity_type'=>'unknown',
  'discovery_context'=>'footer','intent_relation'=>'unrelated'),
);
$input=array('catalog_url'=>$root,'intent'=>'tour_catalog','links'=>$links);
$denied=$c::plan($input);
catalog_assert($denied instanceof WP_Error
 && $denied->get_error_code()==='mad4b_catalog_intent_permission_denied',
 'Original source must deny without exact WordPress can_read admission');
$GLOBALS['catalog_read_allowed']=true;
$result=$c::plan($input);
catalog_assert(is_array($result)
 && $result['counts']['primary']===1
 && $result['counts']['complementary']===1
 && $result['counts']['discovery_expansion']===1
 && $result['counts']['review']===1
 && $result['duplicate_count']===1
 && $result['unresolved_relationships']===0
 && $result['network_requests_performed']===0
 && $result['wordpress_mutation_performed']===false
 && $result['publication_authorized']===false
 && $result['task_creation_performed']===false
 && $result['third_party_content_copied']===false,
 'Original planner must retain primary, complementary, filter navigation and unproven review separately');
$primary=$result['tasks']['primary'][0];
catalog_assert($primary['source_url']==='https://examples.invalid/tours/private-nile-journey?nights=4'
 && $result['tasks']['complementary'][0]['related_primary_task_ids']===array($primary['task_id']),
 'Ad tracking is removed, functional date filter preserved and explicit relation mapped');
catalog_assert($result['next_ability']==='mad4b/content-orchestration-plan'
 && $result['required_write_lanes']===array('approved_content_job','governed_mcp_write'),
 'ContentOrchestration is only a separately governed next step, not a newly registered planner');
foreach(array(
 'http://127.0.0.1/admin',
 'https://user:pass@examples.invalid/catalog/',
 'https://localhost/admin',
 'https://examples.invalid:8443/tours',
 "https://examples.invalid/\nsecret",
) as $unsafe)catalog_assert($c::canonical_url($unsafe)==='',
 'Unsafe URL must be rejected: '.str_replace("\n",'_',$unsafe));
catalog_assert($c::plan(array_replace($input,array(
 'links'=>array(array_replace($links[0],array('intent_relation'=>'unrelated',
  'other_data'=>'untrusted')))))) instanceof WP_Error,
 'Unknown schema keys cannot become ungrounded catalog assertions');
$existing=$c::plan(array_replace($input,array(
 'existing_urls'=>array('https://examples.invalid/tours/private-nile-journey?nights=4'))));
catalog_assert($existing['tasks']['primary'][0]['existing_local_url_match']===true
 && $existing['tasks']['primary'][0]['required_next_steps']===array('review_existing_local_entity','assess_update_not_create'),
 'Existing WP content must prefer review/update and not duplicate published tour');
echo "CATALOG_INTENT_EXACT_SOURCE: PASS intent lanes, duplicate/URL safety, no task creation\n";
echo "CATALOG_INTENT_EXACT_SOURCE: PASS\n";
