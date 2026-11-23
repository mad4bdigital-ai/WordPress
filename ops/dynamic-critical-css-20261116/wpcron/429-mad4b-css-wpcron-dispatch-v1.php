<?php
/**
 * Plugin Name: MAD4B Critical CSS Native WP-Cron Dispatch
 * Description: Exact-company CSS work scheduling in WordPress; never runs Chromium or a shell process inside PHP.
 * Version: 1.1.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_CCSS_WPCron_Dispatch_V1', false)) {
final class MAD4B_CCSS_WPCron_Dispatch_V1 {
    const CONTRACT='mad4b.css.wpcron.dispatch.v1';
    const OPTION='mad4b_css_wpcron_dispatch_v1';
    const TICK='mad4b_css_scheduler_tick_v1';
    const DIRTY='mad4b_css_scheduler_dirty_v1';
    const INTERVAL='mad4b_css_every_5_minutes_v1';
    // A WordPress site may enroll its OWN scheduled CSS task profile.
    // This is NOT a host-execution grant: the original VPS worker still
    // accepts only separately registered first-party executor adapters.
    const PROFILE='mad4b_css_wpcron_site_profile_v1';
    const TASK_ALLOWLIST=['public','bulk_publish','bulk_qa','first_fold','families','visual','audit','retention'];

    public static function profile(): ?array {
        $profile=get_option(self::PROFILE,[]);
        if (!is_array($profile) || count($profile)!==4
            || !isset($profile['site_id'],$profile['site_host'],
                       $profile['environment'],$profile['tasks'])
            || !is_string($profile['site_id'])
            || !preg_match('/^[a-z][a-z0-9_-]{1,63}$/D',$profile['site_id'])
            || !is_string($profile['site_host'])
            || !preg_match('/^[a-z0-9.-]{3,253}$/D',$profile['site_host'])
            || !is_string($profile['environment'])
            || !in_array($profile['environment'],['staging','production'],true)
            || $profile['environment']!==wp_get_environment_type()
            || strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST))!==$profile['site_host']
            || !is_array($profile['tasks'])
            || !$profile['tasks'] || count($profile['tasks'])>count(self::TASK_ALLOWLIST))
            return null;
        foreach ($profile['tasks'] as $name=>$interval) {
            if (!is_string($name) || !in_array($name,self::TASK_ALLOWLIST,true)
                || !is_int($interval) || $interval<300 || $interval>604800)
                return null;
        }
        return $profile;
    }

    public static function site() {
        $profile=self::profile();
        return $profile['site_id']??null;
    }

    public static function tasks() {
        $profile=self::profile();
        return $profile['tasks']??[];
    }
    public static function schedules($schedules) {
        $schedules[self::INTERVAL]=['interval'=>300,'display'=>'MAD4B CSS queue heartbeat (5 min)'];
        return $schedules;
    }
    public static function boot() {
        if (!self::site() || !class_exists('MAD4B_Public_Critical_CSS_Coverage_V1',false)) return;
        add_filter('cron_schedules',[__CLASS__,'schedules']);
        add_action(self::TICK,[__CLASS__,'tick']);
        add_action(self::DIRTY,[__CLASS__,'dirty']);
        add_action('updated_option',[__CLASS__,'changed_option'],30,3);
        add_action('added_option',[__CLASS__,'added_option'],30,2);
        // This only registers a recurring WordPress event. No work or subprocess
        // occurs in the request, preserving frontend and checkout responsiveness.
        if (!wp_next_scheduled(self::TICK)) {
            wp_schedule_event(time()+30,self::INTERVAL,self::TICK);
        }
    }
    private static function initial() {
        return ['contract'=>self::CONTRACT,'site'=>self::site(),
            'host'=>strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST)),
            'next_id'=>0,'jobs'=>[]];
    }
    private static function state() {
        $state=get_option(self::OPTION,[]);
        if (!is_array($state) || ($state['contract']??null)!==self::CONTRACT
            || ($state['site']??null)!==self::site()) return self::initial();
        if (!isset($state['jobs']) || !is_array($state['jobs'])) $state['jobs']=[];
        $state['next_id']=max(0,(int)($state['next_id']??0));
        return $state;
    }
    private static function save($state) {
        // No autoload: this state is read only by a cron event / WP-CLI.
        update_option(self::OPTION,$state,false);
    }
    private static function enqueue(&$state,$task,$reason,$now,$replace=false) {
        if (!isset(self::tasks()[$task])) return;
        $old=$state['jobs'][$task]??[];
        if (!empty($old['pending_id']) && !$replace) return;
        $id=++$state['next_id'];
        $state['jobs'][$task]=[
            'pending_id'=>$id,'requested_at'=>$now,'reason'=>$reason,
            'last_issued'=>$now,
            'last_completed'=>(int)($old['last_completed']??0)
        ];
    }
    public static function tick() {
        if (!self::site()) return;
        $now=time();$state=self::state();
        foreach (self::tasks() as $name=>$interval) {
            $job=$state['jobs'][$name]??[];
            if (empty($job['pending_id']) &&
                $now-(int)($job['last_issued']??0)>=$interval) {
                self::enqueue($state,$name,'scheduled',$now);
            }
        }
        $state['last_tick']=$now;
        self::save($state);
    }
    public static function dirty() {
        if (!self::site()) return;
        $now=time();$state=self::state();
        // On a new source-generation change, replace the pending ticket
        // ID. A worker finishing older generation cannot acknowledge it.
        self::enqueue($state,'public','source_changed',$now,true);
        self::enqueue($state,'bulk_publish','source_changed',$now,true);
        self::enqueue($state,'families','source_changed',$now,true);
        $state['last_dirty']=$now;
        self::save($state);
    }
    public static function changed_option($option,$old_value,$value) {
        if ($option==='mad4b_ccss_public_dirty_v1' && $old_value!==$value) {
            if (!wp_next_scheduled(self::DIRTY))
                wp_schedule_single_event(time()+45,self::DIRTY);
        }
        if ($option==='mad4b_critical_css_home_dirty_v1' && $old_value!==$value) {
            $state=self::state();
            self::enqueue($state,'first_fold','home_changed',time(),true);
            self::save($state);
        }
    }
    public static function added_option($option,$value) {
        if ($option==='mad4b_ccss_public_dirty_v1') {
            if (!wp_next_scheduled(self::DIRTY))
                wp_schedule_single_event(time()+45,self::DIRTY);
        }
    }
    /**
     * OS cron is only a wake-up. WordPress owns exact due times and callbacks.
     * This is a narrow WP-CLI helper, not an HTTP/visitor action.
     * Unrelated WP-Cron events are never run by this CSS-specific wake-up.
     */
    public static function pump() {
        if (!defined('WP_CLI') || !WP_CLI || !self::site()) return self::snapshot();
        foreach ([self::TICK,self::DIRTY] as $hook) {
            $event=wp_get_scheduled_event($hook);
            if (!$event || (int)$event->timestamp>time()) continue;
            $args=is_array($event->args)?$event->args:[];
            if (!empty($event->schedule)) {
                $res=wp_reschedule_event((int)$event->timestamp,$event->schedule,$hook,$args,true);
                if (is_wp_error($res) || !$res) continue;
            }
            $res=wp_unschedule_event((int)$event->timestamp,$hook,$args,true);
            if (is_wp_error($res) || !$res) continue;
            do_action_ref_array($hook,$args);
        }
        return self::snapshot();
    }
    public static function snapshot() {
        $state=self::state();$allowed=self::tasks();
        $pending=[];
        foreach ($allowed as $task=>$interval) {
            $job=$state['jobs'][$task]??[];
            if (!empty($job['pending_id'])) {
                $pending[$task]=[
                    'id'=>(int)$job['pending_id'],
                    'requested_at'=>(int)($job['requested_at']??0),
                    'reason'=>(string)($job['reason']??'scheduled'),
                ];
            }
        }
        return ['contract'=>self::CONTRACT,'site'=>self::site(),
            'host'=>$state['host'],'pending'=>$pending,
            'last_tick'=>(int)($state['last_tick']??0)];
    }
    public static function acknowledge($job,$id) {
        if (!self::site() || !isset(self::tasks()[$job])
            || !is_int($id) || $id<=0) return false;
        $state=self::state();$old=$state['jobs'][$job]??[];
        if ((int)($old['pending_id']??0)!==$id) return false;
        $state['jobs'][$job]['pending_id']=0;
        $state['jobs'][$job]['last_completed']=time();
        $state['jobs'][$job]['reason']='completed';
        $state['last_acked_job']=$job;
        self::save($state);
        return true;
    }
}
add_action('init',['MAD4B_CCSS_WPCron_Dispatch_V1','boot'],35);
}
