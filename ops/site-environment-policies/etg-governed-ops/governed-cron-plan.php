<?php
if (!defined('ABSPATH')) return;
final class MAD4B_ETG_Governed_Cron_Runner {
    const CONTRACT='mad4b.etg-governed-cron-preview.v1';
    public static function ready() {
        $raw=wp_get_environment_type();
        $host=defined('WP_ENVIRONMENT_TYPE')?(string)WP_ENVIRONMENT_TYPE:'';
        $effective=class_exists('MAD4B_SCP_Environment')?MAD4B_SCP_Environment::effective():'';
        $res=class_exists('MAD4B_SCP_Site_Profile')
            ? MAD4B_SCP_Site_Profile::environment_resolution():array();
        return $raw==='staging' && $host==='staging' && $effective==='staging'
            && is_array($res) && !empty($res['profile_environment_authoritative'])
            && empty($res['wordpress_profile_mismatch']);
    }
    public static function classify($hook) {
        if($hook==='action_scheduler_run_queue')return 'action_scheduler_side_effects';
        if(preg_match('/fluentcrm|fluentform|mail|newsletter|notification|email/i',$hook))return 'messaging_or_form_side_effects';
        if(preg_match('/woocommerce|^wc_|order|payment|booking|inventory|stock/i',$hook))return 'commerce_or_booking_side_effects';
        if(preg_match('/^mad4b_|^etg_/i',$hook))return 'company_control_plane';
        return 'unreviewed_third_party_or_core';
    }
    public static function plan($events,$now=null) {
        if(!self::ready())return array('contract'=>self::CONTRACT,'status'=>'blocked','reason'=>'exact_staging_environment_required','execute_enabled'=>false);
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
                    $id=hash('sha256',get_current_blog_id().'|'.(int)$ts.'|'.$hook.'|'.wp_json_encode($args,JSON_UNESCAPED_SLASHES));
                    $rows[]=array('event_fingerprint'=>$id,'hook'=>$hook,'due_utc'=>gmdate('c',(int)$ts),
                        'classification'=>$class,'side_effects_approved'=>false,'execution_authorized'=>false);
                }
            }
        }
        usort($rows,static function($a,$b){return strcmp($a['due_utc'].$a['hook'],$b['due_utc'].$b['hook']);});
        $existing=class_exists('MAD4B_SCP_Scheduler_Backlog')?MAD4B_SCP_Scheduler_Backlog::status(array()):array();
        return array('contract'=>self::CONTRACT,'status'=>'preview_only','origin'=>home_url(),
            'environment'=>'staging','total_due'=>$due,'due_categories'=>$groups,
            'visible_items'=>count($rows),'truncated'=>$due>count($rows),'max_future_batch'=>5,
            'items'=>$rows,'central_backlog_count'=>(int)($existing['count']??0),
            'scheduler_admission_is_non_authorizing'=>true,'owner_approval_present'=>false,
            'execute_enabled'=>false,'dispatch_performed'=>false,'mail_sent'=>false,
            'cron_events_modified'=>false,'review_required'=>true);
    }
}
