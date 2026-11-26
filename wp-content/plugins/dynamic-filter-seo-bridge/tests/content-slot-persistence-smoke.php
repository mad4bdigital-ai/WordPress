<?php
define('ABSPATH', __DIR__.'/');
require_once dirname(__DIR__).'/includes/Runtime/LegacyOptionMirror.php';
/** Regression: governed slot persistence, readback and stale-form rejection. */
$state=array();$failWrites=false;$calls=0;
function sanitize_key($value){return preg_replace('/[^a-z0-9_\\-]/','',strtolower((string)$value));}
function sanitize_text_field($value){return trim(strip_tags((string)$value));}
function get_option($key,$default=array()){global $state;return array_key_exists($key,$state)?$state[$key]:$default;}
function update_option($key,$value,$autoload=false){global $state,$failWrites,$calls;$calls++;if($failWrites){return false;}$state[$key]=$value;return true;}
require_once dirname(__DIR__).'/includes/Presentation/ContentSlotRegistry.php';
use DynamicFilterSEOBridge\Presentation\ContentSlotRegistry;
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
$beforeCalls=$calls;$revision=$slots->revision();$native=$slots->get('editorial_cards');
check_slot($native['origin']==='built_in'&&$native['template']==='{{editorial_cards}}'&&$native['type']==='html','native editorial definition');
check_slot($calls===$beforeCalls&&$slots->revision()===$revision,'new default does not persist settings');
$custom=$slots->save(['id'=>'editorial_cards','label'=>'Company custom','type'=>'html','template'=>'{{title}}','enabled'=>false],$revision);
check_slot($custom['saved']&&$slots->get('editorial_cards')['label']==='Company custom'&&!$slots->get('editorial_cards')['enabled'],'existing override stays authoritative');
check_slot($slots->delete('editorial_cards',$slots->revision()),'delete explicit override');
check_slot($slots->get('editorial_cards')['template']==='{{editorial_cards}}','restore built-in only after explicit override deletion');
$legacy=new ContentSlotRegistry(true);$legacyDefaults=$legacy->defaults();unset($legacyDefaults['editorial_cards']);
$stored=[];for($i=0;$i<ContentSlotRegistry::MAX_SLOTS-count($legacyDefaults);$i++){$id=sprintf('z_custom_%03d',$i);$stored[$id]=['id'=>$id,'label'=>$id,'template'=>'{{title}}'];}
$state[ContentSlotRegistry::OPTION_NAME]=$stored;$beforeCalls=$calls;$full=$legacy->all();
check_slot(count($full)===ContentSlotRegistry::MAX_SLOTS&&!isset($full['editorial_cards']),'new slot does not evict at capacity');
foreach(array_keys($stored) as $id){check_slot(isset($full[$id]),'existing custom retained: '.$id);}
foreach(array_keys($legacyDefaults) as $id){check_slot(isset($full[$id]),'existing built-in retained: '.$id);}
check_slot($calls===$beforeCalls,'capacity check has no writes');
echo "PASS content slot persistence, revision, no-op and failed-write contracts\n";
