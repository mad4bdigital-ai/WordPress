<?php
/** Staging central Hub: post-persist, ContentJob-bound, non-generative Gemini preflight. */
defined('ABSPATH') || exit;
final class MAD4B_Hub_AI_Guarded_Preflight {
    const CONTRACT='mad4b.hub.ai-guarded-preflight.v1';
    public static function boot(){
        add_action('mad4b_hub_research_candidate_persisted',[__CLASS__,'on_persisted'],10,1);
        add_action('wp_abilities_api_init',[__CLASS__,'abilities'],55);
    }
    private static function error($code,$status=409){
        return new WP_Error('mad4b_hub_ai_preflight_'.$code,$code,['status'=>$status,'contract'=>self::CONTRACT]);
    }
    private static function eligible($job_id,$record_key,$version){
        if(!class_exists('MAD4B_Hub_AI_Context_Guard_V1') || !MAD4B_Hub_Bulk_Jobs_Queue::readable())
            return self::error('context_guard_unavailable',503);
        if(!wp_is_uuid($job_id) || !preg_match('/^[a-f0-9]{64}$/D',$record_key)
            ||!preg_match('/^[a-f0-9]{64}$/D',$version))return self::error('invalid_identity',400);
        $j=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]);
        $r=MAD4B_Hub_Canonical_Store::read_record($record_key);
        if(is_wp_error($j))return $j;
        if(is_wp_error($r))return $r;
        $job=$j['job'];
        if(($job['job_id']??'')!==$job_id || ($job['stage']??'')!=='INFORMATION_GAIN'
          ||!in_array(($job['state']??''),['QUEUED','RUNNING'],true)
          ||($job['language']??'')!=='en')return self::error('job_stage_or_state_drift');
        if(($r['job_id']??'')!==$job_id || ($r['version_sha256']??'')!==$version
          ||($r['state']??'')!=='research_candidate'
          ||($r['source_url']??'')!==home_url('/')
          ||($r['provenance']['provider']??'')!=='firecrawl'
          ||($r['provenance']['job_id']??'')!==$job_id
          ||($r['normalized']['publish_authorized']??true)!==false)
            return self::error('canonical_version_or_rights_drift');
        $text=mb_substr((string)($r['normalized']['description']??''),0,1000);
        if(strlen(trim($text))<60)return self::error('source_evidence_too_short');
        return ['job'=>$job,'record'=>$r,'input'=>[
            'company_id'=>'tourism-almallahgroup','job_id'=>$job_id,
            'task_type'=>'research','language'=>'en',
            'goal'=>'Prepare a cited research outline from the owned central staging source. Do not publish, invent prices, or take external actions.',
            'source_documents'=>[['id'=>'hub-owned-homepage','origin'=>home_url('/'),
                'license'=>'owned','text'=>$text]]
        ]];
    }
    public static function enqueue($input=[]){
        if(!MAD4B_Hub_Bulk_Jobs_Queue::writable())return self::error('write_denied',403);
        if(!is_array($input))return self::error('input_invalid',400);
        $jobid=(string)($input['job_id']??'');
        $key=(string)($input['record_key']??'');
        $version=(string)($input['version_sha256']??'');
        $scope=self::eligible($jobid,$key,$version);
        if(is_wp_error($scope))return $scope;
        $batchkey='ai.preflight.'.substr(hash('sha256',$jobid.'|'.$key.'|'.$version),0,48);
        // Replays must read the existing idempotent batch before attempting an
        // INSERT; do not generate a harmless-but-noisy duplicate-key SQL error.
        global $wpdb;
        $b=$wpdb->prefix.'mad4b_hub_bulk_batches';
        $t=$wpdb->prefix.'mad4b_hub_bulk_tasks';
        $existing=$wpdb->get_row($wpdb->prepare(
           "SELECT batch_id FROM $b WHERE batch_key=%s LIMIT 1",$batchkey),ARRAY_A);
        if($existing){
            $old=$wpdb->get_row($wpdb->prepare(
               "SELECT content_job_id,stage,operation,payload_json FROM $t WHERE batch_id=%s AND task_key='guarded-context' LIMIT 1",
               $existing['batch_id']),ARRAY_A);
            $payload=is_array($old)?json_decode((string)$old['payload_json'],true):null;
            if(!is_array($payload)||$old['content_job_id']!==$jobid
               ||$old['stage']!=='INFORMATION_GAIN'||$old['operation']!=='ai_context_preflight'
               ||($payload['version_sha256']??'')!==$version
               ||($payload['record_key']??'')!==$key
               ||(int)($payload['content_job_revision']??0)!==(int)$scope['job']['job_revision'])
                return self::error('idempotency_conflict');
            return ['contract'=>self::CONTRACT,'batch_id'=>$existing['batch_id'],
                'batch_key'=>$batchkey,'job_id'=>$jobid,'replayed'=>true,
                'preflight_only'=>true,'model_invoked'=>false];
        }
        $queued=MAD4B_Hub_Bulk_Jobs_Queue::enqueue([
            'batch_key'=>$batchkey,'max_concurrency'=>1,'items'=>[[
                'task_key'=>'guarded-context','stage'=>'INFORMATION_GAIN',
                'operation'=>'ai_context_preflight','job_id'=>$jobid,'priority'=>8,
                'payload'=>['assigned_executor'=>'vps_runner','content_job_id'=>$jobid,
                    'content_job_revision'=>(int)$scope['job']['job_revision'],
                    'record_key'=>$key,'version_sha256'=>$version,
                    'source'=>'canonical_research_post_persist',
                    'model_execution_authorized'=>false]
            ]]
        ]);
        if(is_wp_error($queued))return $queued;
        return ['contract'=>self::CONTRACT,
           'batch_id'=>$queued['batches'][0]['batch_id']??'',
           'batch_key'=>$batchkey,'job_id'=>$jobid,
           'replayed'=>(bool)($queued['replayed']??false),
           'preflight_only'=>true,'model_invoked'=>false];
    }
    public static function on_persisted($event){
        if(!is_array($event))return;
        $r=self::enqueue($event);
        if(is_wp_error($r)){
            error_log('MAD4B Hub AI preflight not queued: '.$r->get_error_code());
        }
    }
    /** Read-only, bounded content/guard evaluation; never calls the model. */
    public static function receipt($task){
        if(($task['operation']??'')!=='ai_context_preflight'||($task['stage']??'')!=='INFORMATION_GAIN')
            return self::error('task_type_invalid');
        $p=(array)($task['payload']??[]);
        if(($p['model_execution_authorized']??null)!==false || ($p['content_job_id']??'')!==($task['content_job_id']??''))
            return self::error('task_authority_invalid');
        $scope=self::eligible((string)$task['content_job_id'],(string)($p['record_key']??''),
            (string)($p['version_sha256']??''));
        if(is_wp_error($scope))return $scope;
        $compiled=MAD4B_Hub_AI_Context_Guard_V1::compile($scope['input']);
        if(is_wp_error($compiled))return $compiled;
        $hold=($compiled['state']??'')!=='READY_FOR_GEMINI_PREFLIGHT';
        $evidence=['record_key'=>$p['record_key'],'version_sha256'=>$p['version_sha256'],
          'job_id'=>$task['content_job_id'],
          'source_sha256'=>$scope['record']['source_sha256'],
          'fingerprint_sha256'=>$compiled['fingerprint_sha256'],
          'prompt_sha256'=>$compiled['prompt_engineering']['prompt_sha256']??null,
          'injection_state'=>$compiled['injection_defense']['state'],
          'formal_output_contract'=>$compiled['final_output_requirements']['contract']??'',
          'untrusted_source'=>true,'model_invoked'=>false];
        $hash=hash('sha256',wp_json_encode($evidence));
        return ['verified'=>true,'operation'=>'ai_context_preflight','stage'=>'INFORMATION_GAIN',
          'job_id'=>$task['content_job_id'], 'result_kind'=>'guarded_ai_context_preflight',
          'evidence_ref'=>'ai-preflight:'.$hash,'evidence_sha256'=>$hash,
          'receipt'=>$evidence,'completion_state'=>$hold?'pending':'completed',
          'ready_for_gemini_credential'=>!$hold,'gemini_invoked'=>false,
          'content_published'=>false];
    }
    public static function verify($task,$result){
        if(($task['operation']??'')!=='ai_context_preflight'||($result['operation']??'')!=='ai_context_preflight'
          ||($result['result_kind']??'')!=='guarded_ai_context_preflight'
          ||($result['job_id']??'')!==($task['content_job_id']??''))return false;
        $ctx=json_decode((string)($task['payload_json']??''),true);
        if(!is_array($ctx))return false;
        $recheck=self::receipt(['operation'=>$task['operation'],'stage'=>$task['stage'],
           'content_job_id'=>$task['content_job_id'],'payload'=>$ctx]);
        return is_array($recheck)&&($recheck['verified']??false)===true
          &&hash_equals((string)($result['evidence_sha256']??''),(string)($recheck['evidence_sha256']??''))
          &&hash_equals((string)($result['evidence_ref']??''),(string)($recheck['evidence_ref']??''));
    }
    public static function abilities(){
        if(!function_exists('wp_register_ability')||wp_has_ability('mad4b/hub-ai-preflight-enqueue'))return;
        wp_register_ability('mad4b/hub-ai-preflight-enqueue',[
          'label'=>'Queue guarded ContentJob AI context preflight',
          'description'=>'Idempotent, exact ContentJob and canonical version scoped; no model calls or publication.',
          'category'=>'mad4b-write','execute_callback'=>[__CLASS__,'enqueue'],
          'permission_callback'=>[MAD4B_Hub_Bulk_Jobs_Queue::class,'writable'],
          'input_schema'=>['type'=>'object','properties'=>[
             'job_id'=>['type'=>'string'],'record_key'=>['type'=>'string'],'version_sha256'=>['type'=>'string']],
             'required'=>['job_id','record_key','version_sha256'],'additionalProperties'=>false],
          'output_schema'=>['type'=>'object','additionalProperties'=>true],
          'meta'=>['public'=>false,'mcp'=>['public'=>false,'type'=>'tool','surface'=>'write'],
                   'annotations'=>['readonly'=>false,'destructive'=>false,'idempotent'=>true]]
        ]);
    }
}
