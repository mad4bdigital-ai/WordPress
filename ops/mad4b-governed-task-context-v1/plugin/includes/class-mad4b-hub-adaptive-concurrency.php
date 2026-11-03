<?php
defined('ABSPATH') || exit;
/** Shared-host Staging admission control. No task execution or publication. */
final class MAD4B_Hub_Adaptive_Concurrency {
    const CONTRACT='mad4b.hub.adaptive-concurrency.v1';
    const MAX_ACTIVE=2;
    const WARMUP_SECONDS=300;
    const STATE_KEY='mad4b_hub_adaptive_budget_v1';
    private static function readmem($key){
        $raw=@file_get_contents('/proc/meminfo');
        return is_string($raw)&&preg_match('/^'.preg_quote($key,'/').':\s*(\d+)\s*kB/m',$raw,$m)?(int)$m[1]:0;
    }
    public static function classify($cores,$load,$available,$swapTotal,$swapFree){
        if($cores<1 || $available<1)return ['target'=>0,'reason'=>'host_metrics_unavailable'];
        if($available<4*1048576)return ['target'=>0,'reason'=>'memory_critical'];
        if($swapTotal>0 && $swapFree<128*1024 && $available<8*1048576)
            return ['target'=>0,'reason'=>'swap_and_memory_pressure'];
        if($load >= $cores*1.5)return ['target'=>0,'reason'=>'host_load_high'];
        if($load >= $cores*0.85)return ['target'=>1,'reason'=>'host_load_elevated'];
        return ['target'=>self::MAX_ACTIVE,'reason'=>'healthy'];
    }
    public static function snapshot(){
        $load=sys_getloadavg();
        $load1=is_array($load)?(float)($load[0]??0):0.0;
        $raw=@file_get_contents('/proc/cpuinfo');
        $cores=is_string($raw)?preg_match_all('/^processor\s*:/m',$raw):0;
        if($cores<1)$cores=8;
        $available=self::readmem('MemAvailable');
        $swapTotal=self::readmem('SwapTotal');
        $swapFree=self::readmem('SwapFree');
        $policy=self::classify($cores,$load1,$available,$swapTotal,$swapFree);
        $target=$policy['target'];$reason=$policy['reason'];
        if(!is_array($load)){$target=0;$reason='host_metrics_unavailable';}
        return ['contract'=>self::CONTRACT,'host_cores'=>(int)$cores,
            'load1'=>$load1,'load_per_core'=>round($load1/$cores,3),
            'memory_available_mib'=>(int)floor($available/1024),
            'swap_free_mib'=>(int)floor($swapFree/1024),
            'target'=>(int)$target,'reason'=>$reason,'maximum'=>self::MAX_ACTIVE];
    }
    private static function scoped(){
        return wp_get_environment_type()==='staging'
          && strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST))==='tours.dev.mad4b.com';
    }
    /** Read-only evaluation doesn't alter the recovery clock. */
    public static function status($persist=false){
        $sample=self::snapshot();
        if(!self::scoped())return array_merge($sample,['effective'=>0,'reason'=>'staging_identity_denied']);
        $stored=get_option(self::STATE_KEY,[]);
        if(!is_array($stored))$stored=[];
        $now=time();$target=$sample['target'];
        $goodSince=(int)($stored['good_since']??0);
        if($target!==self::MAX_ACTIVE)$goodSince=0;
        elseif($goodSince<=0 || $goodSince>$now) $goodSince=$now;
        $effective=$target===self::MAX_ACTIVE && ($now-$goodSince<self::WARMUP_SECONDS)?1:$target;
        if($persist){
            $current=['good_since'=>$goodSince,'last_target'=>$target,
                'last_effective'=>$effective,'sampled_at'=>$now,'reason'=>$sample['reason']];
            if($current!==$stored)update_option(self::STATE_KEY,$current,false);
        }
        return array_merge($sample,['effective'=>$effective,
          'warmup_remaining_seconds'=>$target===self::MAX_ACTIVE?
             max(0,self::WARMUP_SECONDS-($now-$goodSince)):0,
          'last_sample_at'=>(int)($stored['sampled_at']??0),
          'global_scope'=>'site_all_batches_all_executors',
          'never_preempts_running_tasks'=>true]);
    }
    public static function boot(){
        add_action('wp_abilities_api_init',[__CLASS__,'abilities'],68);
        add_action('rest_api_init',[__CLASS__,'routes'],68);
    }
    public static function inspect($input=[]){
        if(!MAD4B_Hub_Bulk_Jobs_Queue::readable())return new WP_Error('mad4b_adaptive_read_denied','Not authorized',['status'=>403]);
        $s=self::status(false);
        global $wpdb;
        $table=$wpdb->prefix.'mad4b_hub_bulk_tasks';
        if(preg_match('/^[a-zA-Z0-9_]+$/D',$table)){
            $s['observed_active_tasks']=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE state IN ('RUNNING','CANCEL_REQUESTED')");
        }
        return $s;
    }
    public static function abilities(){
        if(!function_exists('wp_register_ability') || wp_has_ability('mad4b/hub-adaptive-concurrency-status'))return;
        wp_register_ability('mad4b/hub-adaptive-concurrency-status',[
           'label'=>'Content Hub Adaptive Concurrency Status',
           'description'=>'Read-only live shared-host budget and all-batch admission report.',
           'category'=>'mad4b-read','execute_callback'=>[__CLASS__,'inspect'],
           'permission_callback'=>static fn()=>MAD4B_Hub_Bulk_Jobs_Queue::readable(),
           'input_schema'=>['type'=>'object','additionalProperties'=>false],
           'output_schema'=>['type'=>'object','additionalProperties'=>true],
           'meta'=>['public'=>false,'show_in_rest'=>false,'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
             'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]
        ]);
    }
    public static function routes(){
        register_rest_route('mad4b-hub/v1','/control-workflow/adaptive-concurrency/status',[
          'methods'=>'GET','permission_callback'=>static fn()=>MAD4B_Hub_Bulk_Jobs_Queue::readable(),
          'callback'=>static fn($req)=>self::inspect([])]);
    }
}
