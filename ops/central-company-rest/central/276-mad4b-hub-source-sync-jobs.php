<?php
/**
 * Plugin Name: MAD4B Content Hub Source Sync Jobs
 * Description: Company-scoped Control MCP plan/queue/status; VPS host runner performs transport separately.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Hub_Source_Sync_Jobs_V1 {
    const OPTION='mad4b_hub_source_sync_jobs_v1';
    const CONTRACT='mad4b.hub.source-sync-jobs.v1';
    const NAME='tourism_wp';
    const DATASET='tourism_main';
    const COMPANY='tourism-almallahgroup';
    const HOST='tourism.almallahgroup-mg.com';
    const TRANSPORT='wp_cli_cross_ssh';
    const TYPES=['tours-and-activities','properties','transportations'];

    private static function enabled(): bool {
        return wp_get_environment_type()==='staging' &&
            wp_parse_url(home_url('/'),PHP_URL_HOST)==='tours.dev.mad4b.com' &&
            class_exists('MAD4B_Hub_Source_Providers_V1') &&
            class_exists('MAD4B_Hub_Auto_Distribute_V1') &&
            class_exists('MAD4B_Hub_Source_Import_V1');
    }
    private static function fail($code,$http=409) {
        return new WP_Error($code,'Hub source sync rejected: '.$code,['status'=>$http]);
    }
    private static function exact_provider(): bool {
        if(!self::enabled())return false;
        $authorized=MAD4B_Hub_Source_Providers_V1::authorize(
            self::COMPANY,self::NAME,self::DATASET,self::TRANSPORT,self::TYPES,self::HOST);
        if(is_wp_error($authorized))return false;
        $settings=MAD4B_Hub_Auto_Distribute_V1::settings();
        return ($settings['source_mode']??null)==='import_to_hub' &&
            ($settings['source_transport']??null)===self::TRANSPORT &&
            ($settings['source_post_types']??[])===self::TYPES &&
            empty($settings['consumers']) && empty($settings['enabled']);
    }
    private static function jobs(): array {
        $jobs=get_option(self::OPTION,[]);
        return is_array($jobs)?$jobs:[];
    }
    private static function lock() {
        global $wpdb;
        if(!isset($wpdb)||!is_object($wpdb))return false;
        return (string)$wpdb->get_var("SELECT GET_LOCK('mad4b_hub_source_sync_jobs_v1',0)")==='1';
    }
    private static function unlock(): void {
        global $wpdb;
        $wpdb->get_var("SELECT RELEASE_LOCK('mad4b_hub_source_sync_jobs_v1')");
    }
    private static function save(array $jobs): bool {
        $jobs=array_slice($jobs,-30);
        $result=update_option(self::OPTION,$jobs,false);
        return $result!==false || get_option(self::OPTION,[])===$jobs;
    }
    public static function plan($input=[]): array {
        $jobs=self::jobs();
        $receipt=get_option(MAD4B_Hub_Source_Import_V1::RECEIPT,[]);
        $active=[];
        foreach($jobs as $job)
            if(in_array($job['status']??null,['queued','running'],true))
                $active[]=['job_id'=>$job['job_id'],'status'=>$job['status']];
        return [
            'contract'=>self::CONTRACT,'site'=>home_url('/'),
            'company_id'=>self::COMPANY,'provider_id'=>self::NAME,
            'dataset_id'=>self::DATASET,'canonical_host'=>self::HOST,
            'transport'=>self::TRANSPORT,'source_types'=>self::TYPES,
            'ready'=>self::exact_provider(),
            'existing_active_jobs'=>$active,'queued_execution_automatic'=>false,
            'requires_vps_runner'=>true,
            'publish_enabled'=>false,'auto_merge_enabled'=>false,
            'last_snapshot_sha256'=>$receipt['snapshot_sha256']??null,
            'last_imported_count'=>$receipt['imported']??null
        ];
    }
    public static function request($input=[]) {
        if(!self::enabled() || !current_user_can('manage_options'))
            return self::fail('administrator_required',403);
        if(!is_array($input)||array_keys($input)!==['company_id','provider_id','dataset_id','transport','request_key'] ||
            ($input['company_id']??'')!==self::COMPANY ||
            ($input['provider_id']??'')!==self::NAME ||
            ($input['dataset_id']??'')!==self::DATASET ||
            ($input['transport']??'')!==self::TRANSPORT ||
            !is_string($input['request_key']) ||
            !preg_match('/^[a-zA-Z0-9_.-]{8,80}$/D',$input['request_key']))
            return self::fail('invalid_job_request',400);
        if(!self::exact_provider())return self::fail('source_policy_not_ready');
        if(!self::lock())return self::fail('job_queue_locked');
        try {
            $jobs=self::jobs();
            foreach($jobs as $job) {
                if($job['request_key']===$input['request_key']) {
                    if($job['provider_id']!==self::NAME || $job['company_id']!==self::COMPANY)
                        return self::fail('request_key_collision');
                    return $job;
                }
                if(in_array($job['status'],['queued','running'],true))
                    return self::fail('source_job_already_active');
            }
            $job=[
                'job_id'=>wp_generate_uuid4(),
                'request_key'=>$input['request_key'],
                'company_id'=>self::COMPANY,'provider_id'=>self::NAME,
                'dataset_id'=>self::DATASET,'transport'=>self::TRANSPORT,
                'status'=>'queued','submitted_at'=>gmdate('c'),
                'started_at'=>null,'finished_at'=>null,
                'snapshot_sha256'=>null,'imported'=>null,'created'=>null,
                'updated'=>null,'error_code'=>null,
            ];
            $jobs[]=$job;
            if(!self::save($jobs))return self::fail('queue_write_failed',503);
            return $job;
        } finally {self::unlock();}
    }
    public static function status($input=[]): array {
        $jobs=self::jobs();
        $id=is_array($input)?($input['job_id']??''):'';
        if(is_string($id)&&$id!=='') {
            $jobs=array_values(array_filter($jobs,static function($job)use($id){
                return ($job['job_id']??'')===$id;
            }));
        }
        return ['contract'=>self::CONTRACT,
            'company_id'=>self::COMPANY,'provider_id'=>self::NAME,
            'jobs'=>array_slice($jobs,-12),'auto_publish'=>false];
    }
    private static function host_only(): bool {
        return self::enabled() && defined('WP_CLI') && WP_CLI &&
            defined('MAD4B_HUB_SOURCE_HOST_RUNNER') &&
            MAD4B_HUB_SOURCE_HOST_RUNNER===true;
    }
    public static function claim() {
        if(!self::host_only() || !self::exact_provider())
            return self::fail('vps_runner_only',403);
        if(!self::lock())return self::fail('job_queue_locked');
        try {
            $jobs=self::jobs();
            foreach($jobs as &$job) {
                if(($job['status']??'')!=='queued')continue;
                $job['status']='running';$job['started_at']=gmdate('c');
                $claimed=$job;
                if(!self::save($jobs))return self::fail('claim_write_failed',503);
                return $claimed;
            }
            return self::fail('no_pending_sync_request',404);
        } finally {self::unlock();}
    }
    public static function finish($id,$success,$error='') {
        if(!self::host_only() || !is_string($id) ||
           !preg_match('/^[0-9a-f-]{36}$/D',$id) || !is_bool($success) ||
           !in_array($error,['','source_export_failed','source_import_failed','receipt_mismatch'],true))
            return self::fail('runner_receipt_invalid',403);
        if(!self::lock())return self::fail('job_queue_locked');
        try {
            $jobs=self::jobs();
            foreach($jobs as &$job) {
                if($job['job_id']!==$id)continue;
                if($job['status']!=='running')return self::fail('job_not_running');
                $receipt=get_option(MAD4B_Hub_Source_Import_V1::RECEIPT,[]);
                $ledger=get_option(MAD4B_Hub_Source_Providers_V1::LEDGER,[]);
                $last=is_array($ledger)&&$ledger?end($ledger):[];
                $verified=$success && is_array($receipt) &&
                    ($receipt['source_company']??'')===self::COMPANY &&
                    ($receipt['source_provider']??'')===self::NAME &&
                    ($receipt['source_dataset']??'')===self::DATASET &&
                    ($receipt['transport']??'')===self::TRANSPORT &&
                    ($receipt['snapshot_sha256']??'')!=='' &&
                    ($receipt['snapshot_sha256']??'')===($last['snapshot_sha256']??null) &&
                    ($receipt['imported']??-1)===($last['imported']??null);
                if($success&&!$verified)$error='receipt_mismatch';
                $job['status']=$verified?'completed':'failed';
                $job['finished_at']=gmdate('c');
                $job['error_code']=$verified?null:($error?:'source_import_failed');
                $job['snapshot_sha256']=$verified?$receipt['snapshot_sha256']:null;
                $job['imported']=$verified?(int)$receipt['imported']:null;
                $job['created']=$verified?(int)($receipt['created']??0):null;
                $job['updated']=$verified?(int)($receipt['updated']??0):null;
                $complete=$job;
                if(!self::save($jobs))return self::fail('completion_write_failed',503);
                return $complete;
            }
            return self::fail('job_unknown',404);
        } finally {self::unlock();}
    }
    public static function register_abilities(): void {
        if(!self::enabled() || !function_exists('wp_register_ability'))return;
        $definitions=[
            'mad4b/hub-source-sync-plan'=>[
                'label'=>'Content Hub Source Sync Plan',
                'description'=>'Read enrolled company provider import readiness. Does not enqueue or execute.',
                'callback'=>[__CLASS__,'plan'],'write'=>false,
                'input'=>['type'=>'object','properties'=>[],'additionalProperties'=>false],
            ],
            'mad4b/hub-source-sync-request'=>[
                'label'=>'Request Content Hub Source Sync',
                'description'=>'Admin-only idempotent queue request; VPS runner must separately execute.',
                'callback'=>[__CLASS__,'request'],'write'=>true,
                'input'=>[
                    'type'=>'object','properties'=>[
                        'company_id'=>['type'=>'string','enum'=>[self::COMPANY]],
                        'provider_id'=>['type'=>'string','enum'=>[self::NAME]],
                        'dataset_id'=>['type'=>'string','enum'=>[self::DATASET]],
                        'transport'=>['type'=>'string','enum'=>[self::TRANSPORT]],
                        'request_key'=>['type'=>'string','minLength'=>8,'maxLength'=>80],
                    ],
                    'required'=>['company_id','provider_id','dataset_id','transport','request_key'],
                    'additionalProperties'=>false,
                ],
            ],
            'mad4b/hub-source-sync-status'=>[
                'label'=>'Content Hub Source Sync Jobs',
                'description'=>'Inspect provider-scoped source import jobs, receipts and failure states.',
                'callback'=>[__CLASS__,'status'],'write'=>false,
                'input'=>['type'=>'object','properties'=>[
                    'job_id'=>['type'=>'string','maxLength'=>36]
                ],'additionalProperties'=>false],
            ],
        ];
        foreach($definitions as $name=>$spec) {
            if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
            wp_register_ability($name,[
                'label'=>$spec['label'],'description'=>$spec['description'],
                'category'=>'mad4b-read',
                'execute_callback'=>$spec['callback'],
                'permission_callback'=>static function(){return current_user_can('manage_options');},
                'input_schema'=>$spec['input'],
                'output_schema'=>['type'=>'object','additionalProperties'=>true],
                'meta'=>[
                    'public'=>false,'show_in_rest'=>false,
                    'mcp'=>['public'=>false,'type'=>'tool','surface'=>$spec['write']?'write':'read'],
                    'annotations'=>['readonly'=>!$spec['write'],
                        'destructive'=>false,'idempotent'=>true],
                ],
            ]);
        }
    }
    public static function boot(): void {
        if(!self::enabled())return;
        add_action('wp_abilities_api_init',[__CLASS__,'register_abilities'],26);
    }
}
MAD4B_Hub_Source_Sync_Jobs_V1::boot();
