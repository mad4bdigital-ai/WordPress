<?php
defined('ABSPATH') || exit;

/** MCP-first owned-source research ingestion: WP drafts are NOT a destination. */
final class MAD4B_Hub_Control_Ingestion {
    const CONTRACT = 'mad4b.control-research-to-hub.v1';

    public static function boot() {
        add_action('wp_abilities_api_init',array(__CLASS__,'register_abilities'),39);
    }
    public static function allowed() {
        return MAD4B_Content_Hub::permission()
            && class_exists('MAD4B_SCP_Policy')
            && MAD4B_SCP_Policy::can_mutate();
    }
    public static function register_abilities() {
        if(!function_exists('wp_register_ability'))return;
        $definitions=array(
            'mad4b/control-research-to-hub'=>array('method'=>'run','readonly'=>false),
            'mad4b/content-hub-ingestion-status'=>array('method'=>'status','readonly'=>true),
        );
        foreach($definitions as $name=>$definition) {
            if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
            wp_register_ability($name,array(
                'label'=>$definition['readonly']?'Read Control-to-Hub Ingestion':'Control MCP Research to Content Hub Database',
                'description'=>'Staging central-only owned-source research, ContentJob, Firecrawl evidence and canonical candidate readback. Never publishes.',
                'category'=>$definition['readonly']?'mad4b-read':'mad4b-write',
                'execute_callback'=>array(__CLASS__,$definition['method']),
                'permission_callback'=>$definition['readonly']?array('MAD4B_Content_Hub','permission'):array(__CLASS__,'allowed'),
                'input_schema'=>array('type'=>'object','properties'=>array(
                    'operation_key'=>array('type'=>'string','minLength'=>8,'maxLength'=>80),
                    'language'=>array('type'=>'string','default'=>'en'),
                    'subject'=>array('type'=>'string','maxLength'=>500),
                ),'required'=>array('operation_key'),'additionalProperties'=>false),
                'output_schema'=>array('type'=>'object','additionalProperties'=>true),
                'meta'=>array('public'=>false,'show_in_rest'=>false,
                    'mcp'=>array('public'=>false,'type'=>'tool','surface'=>$definition['readonly']?'read':'write'),
                    'annotations'=>array('readonly'=>$definition['readonly'],'destructive'=>false,'idempotent'=>true)),
            ));
        }
    }
    private static function scope() {
        if(!class_exists('MAD4B_SCP_Operational_Integrity'))
            return new WP_Error('mad4b_hub_control_scope_missing');
        $checkpoint=MAD4B_SCP_Operational_Integrity::capture();
        if(is_wp_error($checkpoint))return $checkpoint;
        return $checkpoint['scope']??new WP_Error('mad4b_hub_control_scope_missing');
    }
    private static function operation($input) {
        $input=is_array($input)?$input:array();
        $key=trim((string)($input['operation_key']??''));
        if(!preg_match('/^[A-Za-z0-9._:-]{8,80}$/',$key))
            return new WP_Error('mad4b_hub_operation_key_invalid','Bounded idempotency key required.');
        $language=sanitize_key((string)($input['language']??'en'));
        if($language!=='en')return new WP_Error('mad4b_hub_language_not_enabled','English is the source-of-truth language for the first owned-source test.');
        return array('key'=>$key,'language'=>$language,'subject'=>sanitize_text_field((string)($input['subject']??'Owned-source homepage research')));
    }
    public static function rest_run($request) {
        $params=is_object($request)&&method_exists($request,'get_json_params')
            ?$request->get_json_params():array();
        $out=self::run(is_array($params)?$params:array());
        return is_wp_error($out)?$out:rest_ensure_response($out);
    }
    public static function rest_status($request) {
        $params=is_object($request)&&method_exists($request,'get_params')
            ?$request->get_params():array();
        $out=self::status(is_array($params)?$params:array());
        return is_wp_error($out)?$out:rest_ensure_response($out);
    }
    public static function status($input) {
        if(!MAD4B_Content_Hub::permission())return new WP_Error('mad4b_hub_actor_denied');
        $op=self::operation($input);
        if(is_wp_error($op))return $op;
        $scope=self::scope();
        if(is_wp_error($scope))return $scope;
        $key=MAD4B_Hub_Canonical_Store::run_key((string)$scope['site_uuid'],(string)$scope['brand_ref'],$op['key']);
        $row=MAD4B_Hub_Canonical_Store::find_run($key);
        if(!$row)return array('contract'=>self::CONTRACT,'state'=>'not_found','operation_key'=>$op['key']);
        $out=array('contract'=>self::CONTRACT,'state'=>$row['state'],'run_key'=>$key,
            'job_id'=>$row['job_id'],'record_key'=>$row['record_key'],
            'last_error_code'=>$row['last_error_code'],'publication_authorized'=>false);
        if($row['record_key'] && $row['state']==='persisted')
            $out['hub_record']=MAD4B_Hub_Canonical_Store::read_record($row['record_key']);
        return $out;
    }
    public static function run($input) {
        if(!self::allowed())return new WP_Error('mad4b_hub_actor_denied','Central WordPress operator required.');
        $op=self::operation($input);
        if(is_wp_error($op))return $op;
        $scope=self::scope();
        if(is_wp_error($scope))return $scope;
        $site=(string)$scope['site_uuid'];
        $brand=(string)$scope['brand_ref'];
        $key=MAD4B_Hub_Canonical_Store::run_key($site,$brand,$op['key']);
        $tables=MAD4B_Hub_Canonical_Store::tables();
        global $wpdb;
        foreach($tables as $table)
            if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)
                return new WP_Error('mad4b_hub_database_not_initialized','Install central Hub tables before running.');
        $claim=MAD4B_Hub_Canonical_Store::claim_run($key,$site,$brand);
        if(is_wp_error($claim))return $claim;
        if($claim['state']==='persisted') {
            // Resume exact stored job; a replay must not call Firecrawl again.
            $continued=MAD4B_Hub_Workflow_Continuity::resume($input);
            if(is_wp_error($continued))return $continued;
            $status=self::status($input);
            if(is_wp_error($status))return $status;
            $status['continuity']=$continued;
            return $status;
        }
        if($claim['state']==='already_running')
            return array('contract'=>self::CONTRACT,'state'=>'in_progress',
                'run_key'=>$key,'job_id'=>$claim['row']['job_id'],
                'message'=>'An existing run owns this idempotency key.');
        $job_id=(string)($claim['row']['job_id']??'');
        try {
            if($job_id==='') {
                $job=MAD4B_SCP_Content_Jobs::create_job(array(
                    'brand_id'=>$brand,'subject'=>$op['subject'],
                    'language'=>$op['language'],'country'=>'eg',
                    'content_type'=>'research_observation',
                    'automation_level'=>'autonomous',
                    'research_depth'=>'standard',
                    'reason'=>'Control MCP launched an owned-source research ingestion run.',
                ));
                if(is_wp_error($job))throw new RuntimeException($job->get_error_code());
                $job_id=(string)($job['job']['job_id']??'');
                if($job_id==='')throw new RuntimeException('content_job_id_missing');
                $saved=MAD4B_Hub_Canonical_Store::run_update($key,array('job_id'=>$job_id));
                if(is_wp_error($saved))throw new RuntimeException($saved->get_error_code());
            }
            $job=MAD4B_SCP_Content_Jobs::get_job(array('job_id'=>$job_id));
            if(is_wp_error($job))throw new RuntimeException($job->get_error_code());
            $current=$job['job'];
            if($current['state']==='NEW') {
                $queued=MAD4B_SCP_Content_Jobs::transition_job(array(
                    'job_id'=>$job_id,'expected_revision'=>(int)$current['job_revision'],
                    'state'=>'QUEUED','stage'=>'SITE_DISCOVERY','reason'=>'Research source is owned by the active central Staging site.',
                ));
                if(is_wp_error($queued))throw new RuntimeException($queued->get_error_code());
                $current=$queued['job'];
            }
            if($current['state']==='QUEUED') {
                $started=MAD4B_SCP_Content_Jobs::transition_job(array(
                    'job_id'=>$job_id,'expected_revision'=>(int)$current['job_revision'],
                    'state'=>'RUNNING','stage'=>'SCRAPING','reason'=>'Begin bounded Firecrawl observation of the central owned source.',
                ));
                if(is_wp_error($started))throw new RuntimeException($started->get_error_code());
            }
            MAD4B_Hub_Canonical_Store::run_update($key,array('state'=>'provider_running','last_error_code'=>''));
            $captured=MAD4B_SCP_Firecrawl_Provider::scrape_owned_source(home_url('/'));
            if(is_wp_error($captured))throw new RuntimeException($captured->get_error_code());
            $group=MAD4B_SCP_Research_Groups::group('hub-'.substr($key,0,36),$site,'en');
            if(is_wp_error($group))throw new RuntimeException($group->get_error_code());
            $research=MAD4B_SCP_Research_Groups::add_record((int)$group['id'],'firecrawl','en',
                (string)$captured['source_url'],array(
                    'source_sha256'=>$captured['source_sha256'],
                    'scraped_markdown'=>substr((string)$captured['markdown'],0,50000),
                    'captured_at'=>$captured['captured_at'],
                    'source_kind'=>'owned_central_site_homepage',
                    'content_job_id'=>$job_id,
                ));
            if(is_wp_error($research))throw new RuntimeException($research->get_error_code());
            $captured['research_group_uid']=$group['group_uid'];
            $captured['research_record_uid']=$research['record_uid'];
            $record=MAD4B_Hub_Canonical_Store::upsert_evidence($job_id,$captured,$scope,'en');
            if(is_wp_error($record))throw new RuntimeException($record->get_error_code());
            $saved=MAD4B_Hub_Canonical_Store::run_update($key,array(
                'state'=>'persisted','record_key'=>$record['record_key'],'last_error_code'=>'',
            ));
            if(is_wp_error($saved))throw new RuntimeException($saved->get_error_code());
            $continued=MAD4B_Hub_Workflow_Continuity::resume($input);
            if(is_wp_error($continued))
                return new WP_Error('mad4b_hub_research_persisted_continuity_pending',
                    'Research persisted. Resume the same idempotency key; do not rescrape.',
                    array('run_key'=>$key,'job_id'=>$job_id,
                        'continuity_error'=>$continued->get_error_code(),'no_rescrape'=>true));
            return array('contract'=>self::CONTRACT,'state'=>'research_candidate_persisted',
                'continuity'=>$continued,
                'run_key'=>$key,'content_job_id'=>$job_id,
                'research_group_uid'=>$group['group_uid'],
                'research_record_uid'=>$research['record_uid'],
                'hub_record'=>$record,
                'next_stage'=>'coverage_gap_analysis',
                'qa_completed'=>false,'publication_authorized'=>false,
                'external_distribution_authorized'=>false,
                'wordpress_draft_created'=>false,
            );
        }catch(Throwable $e){
            $code=preg_match('/^[a-z0-9_.-]{1,100}$/',$e->getMessage())
                ?$e->getMessage():'mad4b_hub_ingestion_failed';
            MAD4B_Hub_Canonical_Store::run_update($key,array(
                'state'=>'failed','last_error_code'=>$code));
            return new WP_Error('mad4b_hub_ingestion_stage_failed',
                'The Control MCP ingestion run stopped. Read its status and retry the same idempotency key.',
                array('run_key'=>$key,'job_id'=>$job_id,'stage_error_code'=>$code));
        }
    }
}
