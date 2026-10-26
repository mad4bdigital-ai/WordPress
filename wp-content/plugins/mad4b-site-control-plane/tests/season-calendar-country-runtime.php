<?php
/** ETG local acceptance, zero permanent market/calendar/term mutations. */
require '/var/www/html/wp-load.php';
if(wp_get_environment_type()!=='local')exit(3);
$c='MAD4B_SCP_Season_Calendar';$m='MAD4B_SCP_Market_Profiles';$uuid=$m::site_uuid();
wp_set_current_user(1);
$id='qa_country_holiday_241024';$calendar='ar.eg#holiday@group.v.calendar.google.com';
$p=['contract'=>$m::CONTRACT,'profile_id'=>'qaegcalendar','site_uuid'=>$uuid,'environment'=>'local','label'=>'Egypt Arabic','country'=>'EG','language'=>'ar','status'=>'approved','revision'=>1,'approved_revision'=>1,'approved_at'=>'2026-10-24 12:00:00'];
$filter=static fn()=>['qaegcalendar'=>$p];add_filter('pre_option_'.$m::OPTION,$filter);
$event=['id'=>$id,'summary'=>'يوم وطني – اختبار محلي','status'=>'confirmed','start'=>['date'=>'2027-01-25'],'end'=>['date'=>'2027-01-26']];
$tentative=$event;$tentative['id']=$id.'tentative';$tentative['summary']='اختبار مبدئي';$tentative['description']='هذا التاريخ مبدئي وقد يتغيّر.';
$sources=[$calendar=>['calendar_id'=>$calendar,'country'=>'EG','language'=>'ar']];
$created=[];$key='';$proposalOption=$c::OPTION;$prior=get_option($proposalOption,[]);
$check=function($label,$truth){echo $label.'='.($truth?'PASS':'FAIL').PHP_EOL;if(!$truth)throw new RuntimeException($label);};
try{
 $plan=$c::plan('EG','ar',$calendar,[$event,$tentative],$sources);
 $check('PLAN_CREATED',is_array($plan)&&$plan['count']===2&&$plan['term_created']===0);
 $badCountry=$c::plan('US','ar',$calendar,[$event],$sources);
 $check('UNAPPROVED_US_REJECTED',is_wp_error($badCountry));
 $badSource=$c::plan('EG','ar','en.us#holiday@group.v.calendar.google.com',[$event],$sources);
 $check('UNSUBSCRIBED_SOURCE_REJECTED',is_wp_error($badSource));
 $saved=$c::store_plan($plan);$check('PENDING_STORED',is_array($saved)&&$saved['current_pending']>=2);
 $proposals=$c::proposals();foreach($plan['records'] as $k=>$v)if($v['event_id']===$id)$key=$k;
 $check('EVENT_KEY_AVAILABLE',(bool)$key);
 $old=$c::approve_schedule($key,0);$check('STALE_REVIEW_REJECTED',is_wp_error($old));
 $tentKey='';foreach($plan['records'] as $k=>$v)if($v['event_id']===$tentative['id'])$tentKey=$k;
 $blocked=$c::approve_schedule($tentKey,1);$check('TENTATIVE_REJECTED',is_wp_error($blocked));
 $accepted=$c::approve_schedule($key,1);$check('REVIEW_CREATES_SEASON',is_array($accepted)&&($accepted['season_term_id']??0)>0);
 $termId=(int)$accepted['season_term_id'];$created[]=$termId;
 $check('TERM_INACTIVE',get_term_meta($termId,'season_enabled',true)==='0');
 $check('TERM_DOMESTIC',get_term_meta($termId,'season_mode',true)==='domestic');
 $check('TERM_COUNTRY',get_term_meta($termId,'mad4b_market_country',true)==='EG');
 $check('TERM_WINDOW',(get_term_meta($termId,'season_date_windows',true)[0]['start']??'')==='2027-01-25');
 $replay=$c::store_plan($plan);$check('SAME_EVENT_IDEMPOTENT',is_array($replay)&&($c::proposals()[$key]['state']??'')==='reviewed_schedule_inactive');
 $changed=$event;$changed['start']['date']='2027-01-26';$changed['end']['date']='2027-01-27';
 $newPlan=$c::plan('EG','ar',$calendar,[$changed],$sources);$change=$c::store_plan($newPlan);
 $check('SOURCE_CHANGE_REVOKES_REVIEW',is_array($change)&&($c::proposals()[$key]['state']??'')==='pending_review');
 $check('SOURCE_CHANGE_TERM_DISABLED',get_term_meta($termId,'season_enabled',true)==='0');
 $check('SOURCE_CHANGE_TERM_FLAGGED',get_term_meta($termId,'mad4b_calendar_review_status',true)==='source_changed_review_required');
 $empty=$c::plan('EG','ar',$calendar,[],$sources,'2027-01-01','2027-02-01');
 $removed=$c::store_plan($empty);
 $check('REMOVED_EVENT_NO_LONGER_ELIGIBLE',is_array($removed)&&($c::proposals()[$key]['state']??'')==='source_missing_review');
 $check('REMOVED_EVENT_TERM_STAYS_OFF',get_term_meta($termId,'season_enabled',true)==='0');
}finally{
 foreach($created as $term_id)if(get_term_meta($term_id,'mad4b_calendar_source_identity',true)===$key)wp_delete_term($term_id,'season');
 update_option($proposalOption,$prior,false);
 remove_filter('pre_option_'.$m::OPTION,$filter);
}
echo 'AFTER_RECORDS='.count($c::proposals()).' MARKETS='.count($m::records()).PHP_EOL;
