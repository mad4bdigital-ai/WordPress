<?php
if (!defined('ABSPATH')) return;
final class MAD4B_Governed_Cron_Preview {
    const CONTRACT='mad4b.governed-cron-preview.v1';
    public static function ready() {
        return class_exists('MAD4B_Runtime_Admission',false)
          && !empty(MAD4B_Runtime_Admission::environment_status()['verified']);
    }
    // A site/company may register hooks through WP options, not by brand prefix.
    // Every unknown hook remains unreviewed and never gains execution authority.
    public static function classify($hook) {
        if($hook==='action_scheduler_run_queue')return 'action_scheduler_side_effects';
        $registry=get_option('mad4b_scheduler_hook_registry_v1',array());
        if(!is_array($registry) || count($registry)>2000)return 'unreviewed_unregistered';
        $row=$registry[$hook]??null;
        if(!is_array($row)||($row['state']??'')!=='verified')return 'unreviewed_unregistered';
        $category=(string)($row['category']??'');
        return in_array($category,array('company_control_plane','messaging_or_form_side_effects',
            'commerce_or_booking_side_effects','unreviewed_third_party_or_core'),true)
          ? $category : 'unreviewed_unregistered';
    }
    public static function plan($events,$now=null) {
        if(!self::ready())return array('contract'=>self::CONTRACT,'status'=>'blocked','reason'=>'exact_test_environment_required','execute_enabled'=>false);
        $now=$now??time();
        $rows=array();$groups=array();$due=0;
        foreach((array)$events as $ts=>$hooks){
            if(!is_numeric($ts)||$ts>$now)continue;
            foreach((array)$hooks as $hook=>$instances){
                $class=self::classify((string)$hook);
                foreach((array)$instances as $key=>$event){
                    $due++;$groups[$class]=($groups[$class]??0)+1;
                    if(count($rows)>=50)continue;
                    $args=is_array($event['args']??null)?$event['args']:array();
                    $site=class_exists('MAD4B_SCP_Site_Profile',false) ? (string)MAD4B_SCP_Site_Profile::site_uuid() : '';
                    $id=hash('sha256',$site.'|'.get_current_blog_id().'|'.(int)$ts.'|'.$hook.'|'.wp_json_encode($args,JSON_UNESCAPED_SLASHES));
                    $rows[]=array('event_fingerprint'=>$id,'hook'=>$hook,'due_utc'=>gmdate('c',(int)$ts),
                        'classification'=>$class,'side_effects_approved'=>false,'execution_authorized'=>false);
                }
            }
        }
        usort($rows,static function($a,$b){return strcmp($a['due_utc'].$a['hook'],$b['due_utc'].$b['hook']);});
        $existing=class_exists('MAD4B_SCP_Scheduler_Backlog')?MAD4B_SCP_Scheduler_Backlog::status(array()):array();
        return array('contract'=>self::CONTRACT,'status'=>'preview_only','origin'=>home_url(),
            'environment'=>wp_get_environment_type(),'total_due'=>$due,'due_categories'=>$groups,
            'visible_items'=>count($rows),'truncated'=>$due>count($rows),'max_future_batch'=>5,
            'items'=>$rows,'central_backlog_count'=>(int)($existing['count']??0),
            'scheduler_admission_is_non_authorizing'=>true,'owner_approval_present'=>false,
            'execute_enabled'=>false,'dispatch_performed'=>false,'mail_sent'=>false,
            'cron_events_modified'=>false,'review_required'=>true);
    }
}
