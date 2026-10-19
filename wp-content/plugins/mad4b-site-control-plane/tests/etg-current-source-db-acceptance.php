<?php
/** Real disposable WordPress + MySQL acceptance of the *current PR source*, not the pinned legacy ETG fixture. */
if (!defined('ABSPATH') || !defined('ETG_DFSB_DIR') || 'production'===wp_get_environment_type())
    throw new RuntimeException('Non-production installed ETG WordPress runtime required');
require_once ETG_DFSB_DIR . 'includes/Runtime/OptionMutationGate.php';
require_once ETG_DFSB_DIR . 'includes/Presentation/ContentSlotRegistry.php';
use ETG\DynamicFilterSEOBridge\Runtime\OptionMutationGate as Gate;
use ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry as Slots;
$option=Slots::OPTION_NAME;
$slots=new Slots();
$id='ci_governed_candidate';
$before=$slots->get($id);
if($before)throw new RuntimeException('Disposable fixture already exists; refusing overwrite');
$lease=Gate::acquire($option);
if(is_wp_error($lease))throw new RuntimeException('Candidate option lock acquisition failed');
try {
    $contender=Gate::acquire($option);
    if(!is_wp_error($contender)||'mad4b_etg_target_locked'!==$contender->get_error_code())
        throw new RuntimeException('Second local contender was incorrectly admitted');
    $revision=$slots->revision();
    $saved=$slots->save(array('id'=>$id,'label'=>'CI current source',
        'enabled'=>true,'type'=>'text','template'=>'candidate-first'),$revision);
    if(empty($saved['saved'])||!$slots->get($id))
        throw new RuntimeException('Native candidate slot save/readback failed');
    $stale=$slots->save(array('id'=>$id,'label'=>'CI stale','template'=>'stale'),$revision);
    if(!empty($stale['saved'])||'stale_revision'!==($stale['reason']??''))
        throw new RuntimeException('Stale candidate option revision was accepted');
    if(!$slots->delete($id,$slots->revision())||$slots->get($id))
        throw new RuntimeException('Native candidate slot restore/readback failed');
} finally {
    if(!Gate::release($lease))
        throw new RuntimeException('Candidate owner-token atomic SQL lock release failed');
}
echo "ETG CURRENT SOURCE REAL DB SLOT CAS + MUTEX + RESTORE: PASS (disposable WP only)\n";
