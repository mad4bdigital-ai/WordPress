<?php
defined('ABSPATH') || exit;

/** Recover a persisted canonical research run into its original ContentJob.
 * This worker never calls Firecrawl, generates missing facts or publishes.
 */
final class MAD4B_Hub_Workflow_Continuity {
    const CONTRACT = 'mad4b.hub.workflow-continuity.v1';

    public static function boot() {
        add_action('wp_abilities_api_init', array(__CLASS__, 'abilities'), 44);
        add_action('rest_api_init', array(__CLASS__, 'routes'), 40);
    }

    public static function allowed() {
        return class_exists('MAD4B_Hub_Control_Ingestion')
            && MAD4B_Hub_Control_Ingestion::allowed()
            && current_user_can('edit_posts');
    }

    /** Read-only status must not require the mutation principal. */
    public static function allowed_read() {
        return class_exists('MAD4B_Hub_Control_Ingestion')
            && class_exists('MAD4B_Content_Hub')
            && MAD4B_Content_Hub::permission()
            && class_exists('MAD4B_SCP_Policy')
            && MAD4B_SCP_Policy::can_read()
            && current_user_can('edit_posts');
    }

    private static function fail($reason, $http=409) {
        return new WP_Error('mad4b_hub_continuity_'.$reason, $reason,
            array('status'=>$http,'contract'=>self::CONTRACT));
    }

    private static function operation($input) {
        if(!is_array($input))return self::fail('invalid_input',400);
        $key=trim((string)($input['operation_key']??''));
        if(!preg_match('/^[A-Za-z0-9._:-]{8,80}$/D',$key))
            return self::fail('operation_key_invalid',400);
        $scope=MAD4B_SCP_Operational_Integrity::capture();
        if(is_wp_error($scope))return $scope;
        $site=(string)($scope['scope']['site_uuid']??'');
        $brand=(string)($scope['scope']['brand_ref']??'');
        if(!$site || !$brand)return self::fail('scope_missing');
        $hash=MAD4B_Hub_Canonical_Store::run_key($site,$brand,$key);
        $run=MAD4B_Hub_Canonical_Store::find_run($hash);
        if(!is_array($run) || $run['site_uuid']!==$site || $run['brand_id']!==$brand)
            return self::fail('run_missing',404);
        if($run['state']!=='persisted' || !$run['record_key'] || !$run['job_id'])
            return self::fail('run_not_persisted');
        $record=MAD4B_Hub_Canonical_Store::read_record($run['record_key']);
        if(is_wp_error($record))return $record;
        if($record['site_uuid']!==$site || $record['brand_id']!==$brand
            || $record['job_id']!==$run['job_id'])
            return self::fail('record_scope_mismatch');
        $job=MAD4B_SCP_Content_Jobs::get_job(array('job_id'=>$run['job_id']));
        if(is_wp_error($job))return $job;
        if(($job['job']['site_uuid']??'')!==$site
            ||($job['job']['brand_id']??'')!==$brand)
            return self::fail('job_scope_mismatch');
        return array('run'=>$run,'record'=>$record,'job'=>$job['job'],'run_key'=>$hash);
    }

    private static function existing($job_id, $run_key) {
        $res=MAD4B_SCP_Artifacts::list_artifacts(array(
            'job_id'=>$job_id,'artifact_type'=>'scraped_page'));
        if(is_wp_error($res))return $res;
        foreach((array)($res['items']??array()) as $row) {
            if(($row['producer_ref']??'')!==$run_key)continue;
            if(($row['status']??'')!=='active')return self::fail('source_artifact_superseded');
            return $row;
        }
        return null;
    }

    private static function next($evidence,$job,$record,$recovered=false) {
        $planned=$job['stage']==='INFORMATION_GAIN';
        return array('contract'=>self::CONTRACT,'state'=>$planned?'information_gain_ready':'research_evidence_ready',
            'job_id'=>(string)$job['job_id'],
            'job_state'=>(string)$job['state'],
            'job_stage'=>(string)$job['stage'],
            'artifact_id'=>(string)$evidence['artifact_id'],
            'record_key'=>$record['record_key'],
            'version_sha256'=>$record['version_sha256'],
            'reused_artifact'=>$recovered,
            'next_ability'=>$planned?'mad4b/research-keyword-record':'mad4b/research-coverage-build',
            'next_input'=>$planned
                ?array('job_id'=>$job['job_id'],'scope'=>'targeted_keyword_and_serp_research')
                :array('job_id'=>$job['job_id'],
                    'source_artifact_ids'=>array($evidence['artifact_id'])),
            'next_loop'=>$planned?'targeted_research_iteration':'coverage_and_information_gain',
            'automatic_research_completed'=>false,
            'quality_evaluated'=>false,'publication_performed'=>false,
            'company_distribution_performed'=>false);
    }

    public static function status($input) {
        if(!self::allowed_read())return self::fail('operator_denied',403);
        $op=self::operation($input);
        if(is_wp_error($op))return $op;
        $artifact=self::existing($op['job']['job_id'],$op['run_key']);
        if(is_wp_error($artifact))return $artifact;
        return array('contract'=>self::CONTRACT,'run_state'=>$op['run']['state'],
            'job_id'=>$op['job']['job_id'],'job_state'=>$op['job']['state'],
            'job_stage'=>$op['job']['stage'],'artifact_id'=>$artifact['artifact_id']??'',
            'next_ability'=>$artifact?'mad4b/research-coverage-build':'mad4b/control-workflow-resume',
            'needs_reconciliation'=>!$artifact ||
                ($op['job']['stage']==='SCRAPING'),
            'no_rescrape'=>true,'mutation_performed'=>false);
    }

    public static function resume($input) {
        if(!self::allowed())return self::fail('operator_denied',403);
        $op=self::operation($input);
        if(is_wp_error($op))return $op;
        // Advisory connection-scoped lock protects from duplicate Artifact
        // appends; existing artifact is always rechecked under the lock.
        global $wpdb;
        $lock='mad4b.hub.cont.'.substr($op['run_key'],0,44);
        $obtained=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock));
        if((string)$obtained!=='1')return self::fail('busy',423);
        try {
            $fresh=self::operation($input);
            if(is_wp_error($fresh))return $fresh;
            $op=$fresh;
            $job=$op['job'];
            if(in_array($job['state'],array('CANCELLED','FAILED','COMPLETED'),true))
                return self::fail('terminal_job');
            if($op['record']['state']!=='research_candidate')
                return self::fail('research_record_changed');
            $existing=self::existing($job['job_id'],$op['run_key']);
            if(is_wp_error($existing))return $existing;
            $reused=(bool)$existing;
            if(!$existing) {
                if($job['state']!=='RUNNING' || $job['stage']!=='SCRAPING')
                    return self::fail('stage_mismatch');
                $source=$op['record']['provenance']??array();
                $normalized=$op['record']['normalized']??array();
                if(($source['source_sha256']??'')!==$op['record']['source_sha256']
                    ||($source['source_url']??'')!==$op['record']['source_url']
                    ||($source['job_id']??'')!==$job['job_id'])
                    return self::fail('source_provenance_inconsistent');
                $artifact=MAD4B_SCP_Artifacts::append_artifact(array(
                    'job_id'=>$job['job_id'],'artifact_type'=>'scraped_page',
                    'producer_stage'=>'SCRAPING','producer_ref'=>$op['run_key'],
                    'reason'=>'Resume persisted Firecrawl research into original ContentJob',
                    'payload'=>array('contract'=>'mad4b.hub.research-pointer.v1',
                        'provider_id'=>'firecrawl',
                        'source_url'=>$op['record']['source_url'],
                        'source_sha256'=>$op['record']['source_sha256'],
                        'observed_at'=>$source['captured_at']??'',
                        'hub_record_key'=>$op['record']['record_key'],
                        'hub_version_sha256'=>$op['record']['version_sha256'],
                        'research_group_uid'=>$source['research_group_uid']??'',
                        'research_record_uid'=>$source['research_record_uid']??'',
                        'evidence_excerpt'=>$normalized['description']??'',
                        'evidence_status'=>'source_observed_not_qa_evaluated'),
                    'metadata'=>array('run_key'=>$op['run_key'],
                        'site_uuid'=>$job['site_uuid'],'brand_id'=>$job['brand_id'],
                        'provider_execution'=>'already_persisted_no_rescrape'),
                ));
                if(is_wp_error($artifact))return $artifact;
                $existing=$artifact['artifact'];
            }
            // If the artifact commit succeeded but the job transition did not,
            // retry will transition the same existing artifact.
            if($job['state']==='RUNNING' && $job['stage']==='SCRAPING') {
                $moved=MAD4B_SCP_Content_Jobs::transition_job(array(
                    'job_id'=>$job['job_id'],
                    'expected_revision'=>(int)$job['job_revision'],
                    'state'=>'RUNNING','stage'=>'COMPETITOR_ANALYSIS',
                    'artifact_id'=>$existing['artifact_id'],
                    'reason'=>'Persisted research evidence reconciled; advance to coverage',
                ));
                if(is_wp_error($moved))return $moved;
                $job=$moved['job'];
            }
            if(!in_array($job['stage'],array('COMPETITOR_ANALYSIS','INFORMATION_GAIN'),true))
                return self::fail('stage_advanced_check_job');
            return self::next($existing,$job,$op['record'],$reused);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }

    /** First deterministic, evidence-only coverage iteration.
     * Never invents SERP facts, keywords, competitors, or AI review results.
     */
    public static function baseline($input) {
        $ready=self::resume($input);
        if(is_wp_error($ready))return $ready;
        $op=self::operation($input);
        if(is_wp_error($op))return $op;
        $job=$op['job'];
        if(!in_array($job['stage'],array('COMPETITOR_ANALYSIS','INFORMATION_GAIN'),true))
            return self::fail('baseline_stage_invalid');
        $source_id=$ready['artifact_id'];
        global $wpdb;
        $lock='mad4b.hub.ig.'.substr($op['run_key'],0,44);
        if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1')
            return self::fail('research_planner_busy',423);
        try {
            $matrix_set=MAD4B_SCP_Artifacts::list_artifacts(array(
                'job_id'=>$job['job_id'],'artifact_type'=>'evidence_coverage_matrix'));
            if(is_wp_error($matrix_set))return $matrix_set;
            $matrix=null;
            foreach((array)($matrix_set['items']??array()) as $row){
                if(($row['status']??'')!=='active')continue;
                if(($row['payload']['source_artifact_ids']??array())!==array($source_id))
                    continue; // Ignore a separate research-iteration matrix; find the exact owned-source baseline.
                $matrix=$row;break;
            }
            if(!$matrix){
                $made=MAD4B_SCP_Research_Intelligence::build_coverage_matrix(array(
                    'job_id'=>$job['job_id'],'matrix_type'=>'evidence',
                    'source_artifact_ids'=>array($source_id),
                    'dimensions'=>array('kind'=>'source_coverage','source_scope'=>'central_owned_homepage',
                        'observed_provider'=>'firecrawl','observed_source_count'=>1),
                    'items'=>array(
                        array('dimension'=>'first_party_homepage','observation'=>'source_observed',
                            'evidence_artifact_id'=>$source_id),
                        array('dimension'=>'serp_intent','observation'=>'not_yet_observed'),
                        array('dimension'=>'competitor_sources','observation'=>'not_yet_observed'),
                        array('dimension'=>'destination_entities','observation'=>'not_yet_verified'),
                    ),'analysis_process_version'=>'hub-evidence-baseline-v1',
                ));
                if(is_wp_error($made))return $made;
                $matrix=$made['artifact'];
            }
            $plans=MAD4B_SCP_Artifacts::list_artifacts(array(
                'job_id'=>$job['job_id'],'artifact_type'=>'information_gain'));
            if(is_wp_error($plans))return $plans;
            $plan=null;
            foreach((array)($plans['items']??array()) as $row){
                if(($row['status']??'')!=='active')continue;
                if(($row['payload']['baseline_coverage_refs']??array())!==array($matrix['artifact_id']))
                    continue; // Ignore a later Information Gain plan; find the exact baseline lineage.
                $plan=$row;break;
            }
            if(!$plan){
                $made=MAD4B_SCP_Research_Intelligence::build_information_gain(array(
                    'job_id'=>$job['job_id'],
                    'coverage_artifact_ids'=>array($matrix['artifact_id']),
                    'missing_questions_entities_topics'=>array(
                        'SERP and buyer intent not observed','Competitor evidence not observed',
                        'Destination entities not verified'),
                    'first_party_evidence_opportunities'=>array(
                        'Confirm first-party offerings and target entities'),
                    'recommended_priorities'=>array(
                        'research_keyword_intent','research_serp_snapshot','competitor_evidence_sampling'),
                    'unsupported_areas_to_avoid'=>array(
                        'Do not infer exact ranking, competitor prices or destination claims from one home page'),
                ));
                if(is_wp_error($made))return $made;
                $plan=$made['artifact'];
            }
            $fresh=MAD4B_SCP_Content_Jobs::get_job(array('job_id'=>$job['job_id']));
            if(is_wp_error($fresh))return $fresh;
            $job=$fresh['job'];
            if($job['state']!=='RUNNING')return self::fail('research_job_not_running');
            if($job['stage']==='COMPETITOR_ANALYSIS'){
                $transition=MAD4B_SCP_Content_Jobs::transition_job(array(
                    'job_id'=>$job['job_id'],'expected_revision'=>(int)$job['job_revision'],
                    'state'=>'RUNNING','stage'=>'INFORMATION_GAIN',
                    'artifact_id'=>$plan['artifact_id'],
                    'reason'=>'Bounded evidence matrix and information gain opportunities are recorded',
                ));
                if(is_wp_error($transition))return $transition;
                $job=$transition['job'];
            }elseif($job['stage']!=='INFORMATION_GAIN'){
                return self::fail('baseline_stage_advanced');
            }
            return array('contract'=>self::CONTRACT,'state'=>'information_gain_ready',
                'job_id'=>$job['job_id'],'job_stage'=>$job['stage'],
                'source_artifact_id'=>$source_id,
                'coverage_artifact_id'=>$matrix['artifact_id'],
                'information_gain_artifact_id'=>$plan['artifact_id'],
                'next_ability'=>'mad4b/research-keyword-record',
                'research_loop_complete'=>false,
                'unobserved_dimensions'=>array('serp_intent','competitor_sources','destination_entities'),
                'automatic_provider_execution_performed'=>false,'publication_performed'=>false);
        }finally{
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }

    public static function routes() {
        register_rest_route('mad4b-hub/v1','/control-workflow/status',array(
            'methods'=>'GET','callback'=>static function($req) {
                return self::status($req->get_params());
            },'permission_callback'=>array(__CLASS__,'allowed_read')));
        register_rest_route('mad4b-hub/v1','/control-workflow/baseline',array(
            'methods'=>'POST','callback'=>static function($req) {
                return self::baseline($req->get_json_params());
            },'permission_callback'=>array(__CLASS__,'allowed')));
        register_rest_route('mad4b-hub/v1','/control-workflow/resume',array(
            'methods'=>'POST','callback'=>static function($req) {
                return self::resume($req->get_json_params());
            },'permission_callback'=>array(__CLASS__,'allowed')));
    }
    public static function abilities() {
        if(!function_exists('wp_register_ability'))return;
        foreach(array('mad4b/control-workflow-status'=>'status',
            'mad4b/control-workflow-resume'=>'resume',
            'mad4b/control-workflow-baseline'=>'baseline') as $name=>$method) {
            if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
            wp_register_ability($name,array(
                'label'=>'MAD4B Control research workflow '.$method,
                'description'=>'Recover exact persisted ContentJob and resume its immutable research Artifact and next stage. No publishing.',
                'category'=>$method==='status'?'mad4b-read':'mad4b-write',
                'execute_callback'=>array(__CLASS__,$method),
                'permission_callback'=>array(__CLASS__,$method==='status'?'allowed_read':'allowed'),
                'input_schema'=>array('type'=>'object','properties'=>array(
                    'operation_key'=>array('type'=>'string','minLength'=>8,'maxLength'=>80)),
                    'required'=>array('operation_key'),'additionalProperties'=>false),
                'output_schema'=>array('type'=>'object','additionalProperties'=>true),
                'meta'=>array('public'=>false,'show_in_rest'=>false,
                    'mcp'=>array('public'=>false,'type'=>'tool','surface'=>$method==='status'?'read':'write'),
                    'annotations'=>array('readonly'=>$method==='status',
                        'destructive'=>false,'idempotent'=>true)),
            ));
        }
    }
}
