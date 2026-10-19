<?php
/** Regression: governed slot persistence, readback and stale-form rejection. */
$state=array();$failWrites=false;$calls=0;
function sanitize_key($value){return preg_replace('/[^a-z0-9_\\-]/','',strtolower((string)$value));}
function sanitize_text_field($value){return trim(strip_tags((string)$value));}
function get_option($key,$default=array()){global $state;return array_key_exists($key,$state)?$state[$key]:$default;}
function update_option($key,$value,$autoload=false){global $state,$failWrites,$calls;$calls++;if($failWrites){return false;}$state[$key]=$value;return true;}
require_once dirname(__DIR__).'/includes/Presentation/ContentSlotRegistry.php';
use ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry;
function check_slot($truth,$message){if(!$truth){throw new RuntimeException($message);}}
$slots=new ContentSlotRegistry();
$old=$slots->revision();
$first=$slots->save(array('id'=>'ci_slot','label'=>'One','template'=>'{{title}}'),$old);
check_slot(!empty($first['saved'])&&'saved'===$first['reason'],'initial save failed');
check_slot(isset($slots->get('ci_slot')['id']),'missing readback');
$new=$slots->revision();check_slot($new!==$old,'revision did not advance');
$stale=$slots->save(array('id'=>'ci_slot','label'=>'Stale'),$old);
check_slot(empty($stale['saved'])&&'stale_revision'===$stale['reason'],'stale write accepted');
$beforeCalls=$calls;$unchanged=$slots->save(array('id'=>'ci_slot','label'=>'One','template'=>'{{title}}'),$new);
check_slot(!empty($unchanged['saved'])&&'unchanged'===$unchanged['reason']&&$calls===$beforeCalls,'no-op triggered write');
$failWrites=true;
$failed=$slots->save(array('id'=>'ci_slot','label'=>'Failed'),$new);
check_slot(empty($failed['saved'])&&'persistence_failed'===$failed['reason'],'failed write reported success');
check_slot(!$slots->delete('ci_slot',$new),'failed delete reported success');
$failWrites=false;
check_slot(!$slots->delete('ci_slot',$old),'stale delete accepted');
check_slot($slots->delete('ci_slot',$new),'current revision delete failed');
check_slot(!$slots->get('ci_slot'),'delete readback failed');
echo "PASS content slot persistence, revision, no-op and failed-write contracts\n";
