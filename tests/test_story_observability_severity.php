<?php
/**
 * Read-only, no-post-mutation regression for Story Observability's
 * high-severity browser popup signal.
 */
if (!defined('ABSPATH') || !class_exists('MAD4B_Story_Observability_V2',false)) {
    fwrite(STDERR,"Story Observability provider unavailable\n");
    exit(2);
}
$provider='MAD4B_Story_Observability_V2';
$state=(array)get_option($provider::STATE,array());
$ids=array_map('intval',array_keys((array)($state['records']??array())));
$post=$ids?min($ids):1;
$fail=array();
try {
    $method=new ReflectionMethod($provider,'issue');
    $high=$method->invoke(null,$post,'story_browser_popup_failure','high');
    if(($high['severity']??'')!=='high')$fail[]='declared_high_severity_drift';
    $input=$high;
    $input['source']='mad4b-story-observability';
    // A forged ID must be rejected, not cause an InvalidArgumentException.
    $input['id']=str_repeat('0',64);
    if($provider::accepts_issue($input)!==false)$fail[]='bogus_story_issue_accepted';
    $input['severity']='medium';
    if($provider::accepts_issue($input)!==false)$fail[]='downgraded_story_issue_accepted';
} catch(Throwable $error) {
    $fail[]='exception_'.get_class($error);
}
echo wp_json_encode(array('contract'=>'mad4b.story-severity-qa.v1',
    'passed'=>3-count($fail),'total'=>3,'failures'=>$fail));
if($fail)exit(1);
