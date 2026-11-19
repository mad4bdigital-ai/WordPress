<?php
/** Read-only outer permission callback plus original broker authority split. */
require __DIR__ . '/governed-enrollment-dispatch-smoke.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-abilities.php';
$parent=new MAD4B_SCP_Abilities();
$GLOBALS['env']='production';
$GLOBALS['production_enabled']=true;
$GLOBALS['parity_calls']=0;
$candidates=MAD4B_SCP_Governed_Ability_Census::eligible();
$operator=$candidates['mad4b/operator-grants-converge']??null;
if(!is_array($operator) || empty($operator['policy_governed']))exit(2);
$input=array('operation_id'=>$operator['operation_id']);
$allowed=$parent->can_enrollment_dispatch($input);
if($allowed!==true || ($GLOBALS['parity_calls']??-1)!==0){
    fwrite(STDERR,'FAIL governed parent incorrectly delegates to legacy Staging-only parity'.PHP_EOL);exit(3);
}
echo 'PASS outer WordPress Ability accepts governed Production preflight without legacy Staging checker'.PHP_EOL;
$GLOBALS['production_enabled']=false;
$blocked=$parent->can_enrollment_dispatch($input);
if(!is_wp_error($blocked) || $blocked->get_error_code()!=='mad4b_registered_write_runtime_policy_required'
    || $GLOBALS['parity_calls']!==0)exit(4);
echo 'PASS parent denies revoked effective Production policy without legacy delegation'.PHP_EOL;
$GLOBALS['production_enabled']=true;
$GLOBALS['env']='staging';
$legacyAbility=wp_get_ability('mad4b/operator-grants-converge');
$meta=$legacyAbility->get_meta();
$meta['mcp']['broker_dispatch_policy']='owner_attestation_v1';
$meta['mcp']['production_mutation_allowed']=false;
$meta['mcp']['production_allowed']=false;
unset($meta['mcp']['governed_operation_policy']);
$GLOBALS['abilities']['mad4b/operator-grants-converge']=new WP_Ability(
    'mad4b/operator-grants-converge',array('meta'=>$meta,
        'input_schema'=>$legacyAbility->get_input_schema(),
        'execute_callback'=>array('MAD4B_SCP_Operator_Grant_Convergence','execute'),
        'permission_callback'=>array('MAD4B_SCP_Operator_Grant_Convergence','can_access')));
$legacy=MAD4B_SCP_Governed_Ability_Census::eligible()['mad4b/operator-grants-converge']??null;
if(!is_array($legacy) || !empty($legacy['policy_governed']))exit(5);
$legacyResult=$parent->can_enrollment_dispatch(array('operation_id'=>$legacy['operation_id']));
if($legacyResult!==true || $GLOBALS['parity_calls']!==1)exit(6);
echo 'PASS legacy Staging operation still passes independent Remote Operation Parity'.PHP_EOL;
$GLOBALS['env']='production';
$legacyProd=$parent->can_enrollment_dispatch(array('operation_id'=>$legacy['operation_id']));
if(!is_wp_error($legacyProd)||$legacyProd->get_error_code()!=='mad4b_enrollment_dispatch_operation_unknown'
    || $GLOBALS['parity_calls']!==1)exit(7);
echo 'PASS legacy Staging operation cannot inherit Production policy by metadata alias'.PHP_EOL;
$GLOBALS['abilities']['mad4b/operator-grants-converge']=$legacyAbility;
if(!empty($GLOBALS['execute_calls']))exit(8);
echo 'PASS zero grants and zero callback executions'.PHP_EOL;
