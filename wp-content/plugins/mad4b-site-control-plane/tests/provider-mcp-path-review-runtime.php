<?php
/** Provider capability discovery distinguishes transport visibility from authorization. */
define( 'ABSPATH', __DIR__ );
function check_mcp($ok,$label){if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);}}
require dirname(__DIR__).'/includes/class-mad4b-scp-provider-mcp-path-review.php';
$klass='MAD4B_SCP_Provider_MCP_Path_Review';
$unobserved=$klass::classify('elementor',array());
check_mcp('NATIVE_TRANSPORT_DISCOVERY_REQUIRED'===$unobserved['state'],'absence cannot prove no native support');
check_mcp(false===$unobserved['write_authorized'] && !$unobserved['authorizing'],'not a mutation authorization');
$adapter=$klass::classify('elementor',array(
    'elementor/get-document'=>array('origin'=>'mad4b-adapter'),
    'elementor/update-widget-settings'=>array('origin'=>'mad4b-adapter')));
check_mcp('MAD4B_ADAPTER_DISCOVERED_NATIVE_UNKNOWN'===$adapter['state'],'adapter is not provider native absence');
check_mcp(count($adapter['mad4b_adapter_abilities'])===2,'registered adapter tools count');
$ambiguous=$klass::classify('jetsmartfilters',array(
    'jetsmartfilters/update-filter-meta'=>array('origin'=>'origin-unverified')));
check_mcp('ABILITY_ORIGIN_UNVERIFIED'===$ambiguous['state'],'unknown publisher cannot be certified');
$native=$klass::classify('jetengine',array(
    'jetengine/create-query'=>array('origin'=>'provider-native-wordpress-ability'),
    'jetengine/native-tools-inventory'=>array('origin'=>'mad4b-adapter')));
check_mcp('NATIVE_WP_ABILITY_DISCOVERED_REVIEW_REQUIRED'===$native['state'],'review native first');
check_mcp(!$native['write_authorized'] && $native['external_native_mcp_state']==='NOT_PROBED','native not permission');
$wpml=$klass::classify('wpml',array());
check_mcp(count($wpml['expected_write_abilities'])===0 && $wpml['external_native_client_required_for_exhaustive_claim'],'unknown WPML writes not treated as unsupported');
$wpml_local=$klass::classify('wpml',array('wpml/relationship-inventory'=>array('origin'=>'origin-unverified')));
check_mcp('ADDITIONAL_LOCAL_ABILITIES_UNVERIFIED'===$wpml_local['state'],'WPML local unknown native path displayed');
check_mcp(count($wpml_local['additional_local_abilities'])===1,'additional provider names retained for review');

check_mcp(!$klass::classify('random-plugin',array())['authorizing'],'unknown provider denied');
function current_user_can($cap){return 'manage_options'===$cap;}
class Fake_Ability {function get_category(){return 'mad4b-read';}}
function wp_get_abilities(){return array('elementor/get-document'=>new Fake_Ability(), 'othervendor/inventory'=>new Fake_Ability());}
$report=$klass::observe_local();
check_mcp($report['external_native_mcp_discovery_performed']===false,'no invented remote discovery');
check_mcp(isset($report['other_discovered_ability_namespaces']['othervendor']),'all vendor namespaces seen');
check_mcp(!$report['write_authorized'] && !$report['mutation_performed'],'discovery no write');
echo "PROVIDER_MCP_PATH_REVIEW: PASS\n";
