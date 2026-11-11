<?php
/**
 * Plugin Name: MAD4B Central Transport Dispatch Status Bridge
 * Description: Bounded read-only VPS dispatch status projection on central Staging.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Central_Dispatch_Status_V1 {
    const OPT='mad4b_transport_dispatch_status_v1';
    const CONTRACT='mad4b.dispatch-status-snapshot.v1';
    public static function enabled():bool {
        return function_exists('wp_get_environment_type') &&
            wp_get_environment_type()==='staging' &&
            untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com';
    }
    public static function admin():bool {
        return self::enabled() && current_user_can('manage_options');
    }
    public static function sanitize($raw) {
        if(!is_array($raw) || ($raw['contract']??null)!==self::CONTRACT ||
           ($raw['environment']??null)!=='staging' ||
           ($raw['central_origin']??null)!=='https://tours.dev.mad4b.com' ||
           ($raw['production_mutation']??true)!==false ||
           ($raw['remote_mutation_performed']??true)!==false)
            return new WP_Error('mad4b_dispatch_bad_contract','Bounded Staging-only status required');
        $ts=strtotime((string)($raw['generated_at']??''));
        if(!$ts || $ts>time()+120 || $ts<time()-900)
            return new WP_Error('mad4b_dispatch_status_stale','Fresh status timestamp required');
        $counts=$raw['counts']??null;
        $allowed=['queued','running','retry_scheduled','parked_authority','blocked',
                  'review_required','succeeded'];
        if(!is_array($counts) || count($counts)>count($allowed))
            return new WP_Error('mad4b_dispatch_counts_invalid','Invalid queue counts');
        $clean=[];
        foreach($counts as $key=>$value) {
            if(!in_array($key,$allowed,true)||!is_int($value)||$value<0||$value>100000)
                return new WP_Error('mad4b_dispatch_counts_invalid','Invalid queue count');
            $clean[$key]=$value;
        }
        $targets=$raw['targets']??null;
        if(!is_array($targets)||count($targets)>25)
            return new WP_Error('mad4b_dispatch_targets_invalid','Invalid target inventory');
        $summary=[];
        foreach($targets as $target=>$item) {
            if(!is_string($target)||
               !preg_match('/^[a-z][a-z0-9-]{2,47}:(?:staging|production)$/D',$target)||
               !is_array($item) ||
               !in_array($item['state']??'',[
                   'PRODUCTION_MUTATION_DENIED','READ_ADAPTER_READY_MANAGED_PENDING',
                   'READ_AND_MANAGED_ENROLLMENT_REQUIRED',
                   'PRODUCTION_HOST_AUTHORITY_ENROLLMENT_REQUIRED',
                   'HOST_NATIVE_OPERATOR_AUTHORIZED_MANAGED_PENDING'],true))
                return new WP_Error('mad4b_dispatch_target_invalid','Invalid target status');
            $summary[$target]=$item['state'];
        }
        $managed=$raw['managed']??null;
        if(!is_array($managed) ||
           ($managed['site_uuid']??null)!=='49c562d1-8f2f-456f-b454-26816c6ba4cb' ||
           ($managed['target']??null)!=='all-royal-egypt:staging' ||
           !in_array(($managed['state']??''),[
               'NOT_AUTHORIZED','READY_FOR_ONE_TIME_SIGNED_MANAGED_EXECUTION'],true) ||
           !is_array($managed['blockers']??null)||
           count($managed['blockers'])>16)
            return new WP_Error('mad4b_dispatch_managed_invalid','Exact managed status required');
        $blockers=[];
        foreach($managed['blockers'] as $b) {
            if(!is_string($b)||!preg_match('/^[a-z][a-z0-9_]{2,95}$/D',$b))
                return new WP_Error('mad4b_dispatch_blocker_invalid','Invalid blocker');
            $blockers[]=$b;
        }
        return [
            'contract'=>self::CONTRACT,
            'generated_at'=>gmdate('c',$ts),
            'environment'=>'staging',
            'central_origin'=>'https://tours.dev.mad4b.com',
            'counts'=>$clean,
            'targets'=>$summary,
            'managed'=>['target'=>'all-royal-egypt:staging',
                'site_uuid'=>$managed['site_uuid'],
                'state'=>$managed['state'],
                'blockers'=>$blockers,
                'report_only'=>true],
            'production_mutation'=>false,
            'remote_mutation_performed'=>false,
            'authorization_granted'=>false,
        ];
    }
    public static function import($args,$assoc_args):void {
        if(!self::admin())WP_CLI::error('Central Staging administrator required',77);
        $input=fread(STDIN,32769);
        if(!is_string($input)||strlen($input)>32768 || trim($input)==='')
            WP_CLI::error('Bounded JSON status over stdin required',64);
        $data=json_decode($input,true);
        $clean=self::sanitize($data);
        if(is_wp_error($clean))WP_CLI::error($clean->get_error_code(),77);
        if(!update_option(self::OPT,$clean,false) &&
            get_option(self::OPT,[])!==$clean)
            WP_CLI::error('Status readback persistence failed',75);
        WP_CLI::line(wp_json_encode([
            'contract'=>'mad4b.dispatch-status-readback.v1',
            'state'=>'CENTRAL_STATUS_READBACK_VERIFIED',
            'source_sha256'=>hash('sha256',$input),
            'target_count'=>count($clean['targets']),
            'counts'=>$clean['counts'],
            'production_mutation'=>false,
        ],JSON_UNESCAPED_SLASHES));
    }
    public static function legacy_queue_readback():array {
        // Existing Hub queues retain ownership and execution authority.
        // Read-only aggregate status; no claim, enqueue or execution.
        $status=[
            'contract'=>'mad4b.dispatch-legacy-queue-readback.v1',
            'source_sync'=>['state'=>'UNAVAILABLE'],
            'bulk_mcp'=>['state'=>'UNAVAILABLE'],
            'mutation_performed'=>false,
        ];
        if(class_exists('MAD4B_Hub_Source_Sync_Jobs_V1',false)) {
            try {
                $s=MAD4B_Hub_Source_Sync_Jobs_V1::plan();
                if(is_array($s) && ($s['contract']??'')==='mad4b.hub.source-sync-jobs.v1') {
                    $status['source_sync']=[
                        'state'=>'READ_ONLY_PROJECTION',
                        'provider_ready'=>($s['ready']??false)===true,
                        'active_job_count'=>count($s['existing_active_jobs']??[]),
                        'requires_vps_runner'=>($s['requires_vps_runner']??false)===true,
                    ];
                }
            }catch(Throwable $e) {
                $status['source_sync']=['state'=>'DIAGNOSTIC_UNAVAILABLE'];
            }
        }
        if(class_exists('MAD4B_Bulk_MCP_Dispatcher_V1',false)) {
            try {
                $b=MAD4B_Bulk_MCP_Dispatcher_V1::readiness();
                if(is_array($b) && ($b['contract']??'')==='mad4b.hub.mcp-dispatcher.v1'){
                    $status['bulk_mcp']=[
                        'state'=>'READ_ONLY_PROJECTION',
                        'queue_ready'=>($b['queue_ready']??false)===true,
                        'existing_batches'=>is_int($b['existing_batches']??null)
                            ?$b['existing_batches']:null,
                        'requires_mcp_client'=>($b['connected_client_required_for_unattended_mcp_stage_execution']??false)===true,
                    ];
                }else{
                    $status['bulk_mcp']=['state'=>'DIAGNOSTIC_UNAVAILABLE'];
                }
            }catch(Throwable $e) {
                $status['bulk_mcp']=['state'=>'DIAGNOSTIC_UNAVAILABLE'];
            }
        }
        return $status;
    }
    public static function api() {
        $data=get_option(self::OPT,[]);
        if(!is_array($data))$data=[];
        $t=isset($data['generated_at'])?strtotime($data['generated_at']):false;
        return new WP_REST_Response([
            'contract'=>'mad4b.dispatch-status-api.v1',
            'state'=>($t && $t>=time()-1200)?'RECENT':'STALE_OR_NOT_SYNCED',
            'snapshot'=>$data,
            'legacy_queue_readback'=>self::legacy_queue_readback(),
            'production_mutation'=>false,
        ],200);
    }
    public static function boot():void {
        if(!self::enabled())return;
        if(defined('WP_CLI') && WP_CLI)
            WP_CLI::add_command('mad4b-dispatch-status import',[__CLASS__,'import']);
        add_action('rest_api_init',static function(){
            register_rest_route('mad4b-central/v1','/transport-dispatch-status',[
                'methods'=>'GET','permission_callback'=>[__CLASS__,'admin'],
                'callback'=>[__CLASS__,'api']]);
        });
    }
}
MAD4B_Central_Dispatch_Status_V1::boot();
