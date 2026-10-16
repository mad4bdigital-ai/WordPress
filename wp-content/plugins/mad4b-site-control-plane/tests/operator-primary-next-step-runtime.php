<?php
define( 'ABSPATH', __DIR__ );
require dirname(__DIR__) . '/includes/class-mad4b-scp-operational-remediation.php';
function verify_step( $actual, $state, $ability, $reason ) {
    if ( ! is_array($actual) || ($actual['state'] ?? '') !== $state ||
        ($actual['next_ability'] ?? '') !== $ability ||
        empty($actual['read_only']) || !empty($actual['automatic_execution_allowed']) ||
        !empty($actual['mutation_performed']) || empty($actual['no_blind_retry']) ) {
        fwrite(STDERR, 'FAILED ' . $reason . ':' . json_encode($actual) . "\n"); exit(1);
    }
}
$c = 'MAD4B_SCP_Operational_Remediation';
$path = array( 'action_id'=>'restore_filters', 'readback_registered'=>true,
    'readback_ability'=>'mad4b/browser-acceptance-result',
    'apply_registered'=>true, 'apply_ability'=>'mad4b/unsafe-write' );
$items = array(array('gate_id'=>'ajax_parity', 'paths'=>array($path)));
verify_step($c::primary_next_step(false, array('invalid_site_binding'), $items, true, true, true),
    'DIAGNOSTIC_INTEGRITY_FIRST', 'mad4b/staging-certification-status', 'integrity-precedes-write');
verify_step($c::primary_next_step(true, array(), $items, false, false, false),
    'VERIFY_CURRENT_GATE_BEFORE_APPROVAL', 'mad4b/browser-acceptance-result', 'readback-before-retry');
$x=$c::primary_next_step(true,array(),$items,false,false,false);
if (($x['focus_gate']??'')!=='ajax_parity' || ($x['focus_action_id']??'')!=='restore_filters')
    {fwrite(STDERR,'FAILED dynamic primary route identity'."\n");exit(1);}
$path['readback_registered']=false; $items[0]['paths']=array($path);
verify_step($c::primary_next_step(true,array(),$items,true,false,false),
    'PREPARE_EXACT_GOVERNED_REVIEW','mad4b/operational-remediation-prepare','only prepare never apply');
$path['apply_registered']=false; $items[0]['paths']=array($path);
verify_step($c::primary_next_step(true,array(),$items,false,false,false),
    'DISCOVER_EXISTING_NATIVE_CAPABILITY','mad4b/operation-route-discover','dynamic discovery before new plugin');
$items[0]['paths']=array();
verify_step($c::primary_next_step(true,array(),$items,false,false,false),
    'CAPABILITY_GAP','mad4b/operation-route-discover','missing route clear');
verify_step($c::primary_next_step(true,array(),array(),false,false,false),
    'VERIFY_NATIVE_STAGING_READINESS','mad4b/staging-certification-status','no invented readiness');
verify_step($c::primary_next_step(true,array(),array(),true,true,false),
    'EXTERNAL_BROWSER_ACCEPTANCE_REQUIRED','mad4b/browser-acceptance-plan','browser not claimed');
verify_step($c::primary_next_step(true,array(),array(),true,true,true),
    'GATES_OBSERVED_READY_NOT_RELEASE_CERTIFIED','mad4b/operational-acceptance-evidence','release independent');
echo "OPERATOR_PRIMARY_NEXT_STEP_RUNTIME_PASS\n";
