<?php
declare(strict_types=1);

function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
function sanitize_text_field($value){return trim(strip_tags((string)$value));}
function absint($value){return abs((int)$value);}
function etg_ability_expect($condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
function etg_ability_same($expected,$actual,string $message):void{if($expected!==$actual){fwrite(STDERR,"FAIL: $message\nEXPECTED ".var_export($expected,true)."\nACTUAL ".var_export($actual,true)."\n");exit(1);}}

$GLOBALS['etg_ability_actions']=array();
$GLOBALS['etg_registered_categories']=array();
$GLOBALS['etg_registered_abilities']=array();
$GLOBALS['etg_can_manage_options']=true;
$GLOBALS['etg_category_register_calls']=0;
$GLOBALS['etg_ability_register_calls']=0;
$GLOBALS['etg_forbidden_get_lookup_calls']=0;

function add_action($hook,$callback,$priority=10,$acceptedArgs=1){unset($priority,$acceptedArgs);$GLOBALS['etg_ability_actions'][$hook][]=$callback;return true;}
function wp_has_ability_category($slug){return isset($GLOBALS['etg_registered_categories'][$slug]);}
function wp_register_ability_category($slug,$args){$GLOBALS['etg_category_register_calls']++;$GLOBALS['etg_registered_categories'][$slug]=$args;return (object)$args;}
function wp_has_ability($name){return isset($GLOBALS['etg_registered_abilities'][$name]);}
function wp_register_ability($name,$args){$GLOBALS['etg_ability_register_calls']++;$GLOBALS['etg_registered_abilities'][$name]=$args;return (object)$args;}
function wp_get_ability_category($slug){unset($slug);$GLOBALS['etg_forbidden_get_lookup_calls']++;throw new RuntimeException('wp_get_ability_category must not be used for existence checks');}
function wp_get_ability($name){unset($name);$GLOBALS['etg_forbidden_get_lookup_calls']++;throw new RuntimeException('wp_get_ability must not be used for existence checks');}
function current_user_can($cap){return 'manage_options'===$cap && !empty($GLOBALS['etg_can_manage_options']);}

$root=dirname(__DIR__);
require_once $root.'/includes/Diagnostics/EvidenceProvider.php';
require_once $root.'/includes/Integration/EvidenceAbilities.php';

use ETG\DynamicFilterSEOBridge\Diagnostics\EvidenceProvider;
use ETG\DynamicFilterSEOBridge\Integration\EvidenceAbilities;

$snapshot=array(
    'contract'=>'etg.dfsb.runtime-inventory.v2',
    'evidence_complete'=>true,
    'availability_errors'=>array(),
    'snapshot_fingerprint'=>'ability-snapshot',
    'collected_at_gmt'=>'2026-09-13T00:00:00+00:00',
    'inventory'=>array(
        'availability'=>array(),
        'completeness'=>array(),
        'jet_smart_filters'=>array(
            'contract'=>'etg.dfsb.jet-smart-filters-definition-inspection.v1',
            'diagnostic_contract'=>'etg.dfsb.jet-smart-filters-diagnostic.v2',
            'authorizing'=>false,
            'read_only'=>true,
            'profile_mutation'=>false,
            'surface_count'=>0,
            'resolved_surface_count'=>0,
            'unresolved_surface_count'=>0,
            'surfaces'=>array(),
            'definition_unavailable'=>array(),
            'drift'=>array(),
        ),
        'elementor_topology'=>array(
            'contract'=>'etg.dfsb.runtime-topology.v1',
            'authorizing'=>false,
            'read_only'=>true,
            'profile_mutation'=>false,
            'bindings'=>array(),
            'provider_group_drift'=>array(),
        ),
    ),
);

$provider=new EvidenceProvider(
    static function()use($snapshot):array{return $snapshot;},
    static function(array $current,array $profiles):array{unset($current,$profiles);return array('contract'=>'etg.dfsb.inventory-reconciliation.v3','findings'=>array());},
    static function():array{return array();}
);
$abilities=new EvidenceAbilities($provider);
$abilities->register();

etg_ability_expect(isset($GLOBALS['etg_ability_actions']['wp_abilities_api_categories_init']),'ability category hook registered');
etg_ability_expect(isset($GLOBALS['etg_ability_actions']['wp_abilities_api_init']),'ability registration hook registered');
call_user_func($GLOBALS['etg_ability_actions']['wp_abilities_api_categories_init'][0]);
call_user_func($GLOBALS['etg_ability_actions']['wp_abilities_api_init'][0]);

etg_ability_expect(isset($GLOBALS['etg_registered_categories']['etg-dfsb-diagnostics']),'ETG diagnostic category registered');
etg_ability_expect(isset($GLOBALS['etg_registered_abilities']['etg-dfsb/evidence-provider']),'descriptor ability registered');
etg_ability_expect(isset($GLOBALS['etg_registered_abilities']['etg-dfsb/evidence-query']),'bounded query ability registered');
etg_ability_same(0,$GLOBALS['etg_forbidden_get_lookup_calls'],'registration never probes missing abilities/categories through warning-producing getters');
etg_ability_same(1,$GLOBALS['etg_category_register_calls'],'diagnostic category registered exactly once');
etg_ability_same(2,$GLOBALS['etg_ability_register_calls'],'both ETG evidence abilities registered exactly once');

call_user_func($GLOBALS['etg_ability_actions']['wp_abilities_api_categories_init'][0]);
call_user_func($GLOBALS['etg_ability_actions']['wp_abilities_api_init'][0]);
etg_ability_same(1,$GLOBALS['etg_category_register_calls'],'category registration is idempotent through wp_has_ability_category');
etg_ability_same(2,$GLOBALS['etg_ability_register_calls'],'ability registration is idempotent through wp_has_ability');
etg_ability_same(0,$GLOBALS['etg_forbidden_get_lookup_calls'],'repeat registration still avoids warning-producing getters');

$descriptorAbility=$GLOBALS['etg_registered_abilities']['etg-dfsb/evidence-provider'];
$queryAbility=$GLOBALS['etg_registered_abilities']['etg-dfsb/evidence-query'];
foreach(array($descriptorAbility,$queryAbility) as $ability){
    etg_ability_same(true,$ability['meta']['public'],'ability is public/discoverable');
    etg_ability_same(true,$ability['meta']['show_in_rest'],'ability is exposed through the core governed REST ability surface');
    etg_ability_same(true,$ability['meta']['annotations']['readonly'],'ability is annotated read-only');
    etg_ability_same(false,$ability['meta']['annotations']['destructive'],'ability is explicitly non-destructive');
    etg_ability_same(true,$ability['meta']['annotations']['idempotent'],'read ability is idempotent');
    etg_ability_same(false,$ability['meta']['authorizing'],'ability does not authorize');
    etg_ability_same(false,$ability['meta']['profile_mutation'],'ability cannot mutate profiles');
    etg_ability_expect(false===strpos(json_encode($ability),'_mad4b_approval_ticket_id'),'read ability has no approval-ticket input');
}

$descriptor=call_user_func($descriptorAbility['execute_callback']);
etg_ability_same(EvidenceProvider::CONTRACT,$descriptor['contract'],'descriptor delegates to canonical provider');
etg_ability_same(array('summary','unresolved_surfaces','filters','profile_reconciliation','provider_group_drift'),$descriptor['sections'],'all five bounded sections are discoverable');

$query=call_user_func($queryAbility['execute_callback'],array('section'=>'summary'));
etg_ability_same('ok',$query['state'],'ability query delegates to canonical provider');
etg_ability_same('ability-snapshot',$query['snapshot_fingerprint'],'ability preserves provider provenance');
etg_ability_same('etg.dfsb.jet-smart-filters-diagnostic.v2',$query['payload']['jet_smart_filters']['diagnostic_contract'],'diagnostic v2 is visible through bounded ability');

$invalid=call_user_func($queryAbility['execute_callback'],array('section'=>'not-a-section'));
etg_ability_same('invalid_request',$invalid['state'],'provider validation remains authoritative through ability bridge');

etg_ability_same(true,call_user_func($queryAbility['permission_callback'],array('section'=>'summary')),'administrator may read evidence');
$GLOBALS['etg_can_manage_options']=false;
etg_ability_same(false,call_user_func($queryAbility['permission_callback'],array('section'=>'summary')),'non-administrator is denied');

echo "Alpha13 evidence abilities smoke tests passed.\n";
