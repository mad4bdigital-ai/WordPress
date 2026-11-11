<?php
defined('ABSPATH') || exit;

/**
 * Exact native-Ability stage adapters. Never invents provider observations,
 * AI-generated text, rates, translations, distribution or publication.
 * Each adapter is opt-in PER TASK with exact stage_input and job identity.
 */
final class MAD4B_Hub_Six_Stage_Adapters {
    const CONTRACT='mad4b.hub.six-native-stage-adapters.v1';
    public static function map() {
        return array(
            'SERP_RESEARCH'=>array('id'=>'native.serp_record','ability'=>'mad4b/research-serp-record',
                'kind'=>'artifact','artifact_type'=>'serp_research','requires'=>array('job_id','provider_id','request_sha256','collected_at','results')),
            'WRITING'=>array('id'=>'native.draft_append','ability'=>'mad4b/draft-append',
                'kind'=>'artifact','artifact_type'=>'draft','requires'=>array('job_id','content')),
            'PRICING'=>array('id'=>'native.pricing_preview','ability'=>'mad4b/hub-native-rate-generator-preview',
                'kind'=>'pricing_preview','requires'=>array('request')),
            'WPML_TRANSLATION'=>array('id'=>'native.wpml_post_language','ability'=>'mad4b/translation-set-post-language',
                'kind'=>'translation_readback','requires'=>array('post_id','provider','language','expected_language','expected_group')),
            'COMPANY_DISTRIBUTION'=>array('id'=>'native.distribution_sync_request','ability'=>'mad4b/hub-source-sync-request',
                'kind'=>'distribution_pending','requires'=>array('company_id','provider_id','dataset_id','transport','request_key')),
            'PUBLISHING'=>array('id'=>'native.canonical_release','ability'=>'mad4b/hub-canonical-release',
                'kind'=>'canonical_pending','requires'=>array('record_key','expected_version_sha256','draft_artifact_id','final_qa_artifact_id','title'))
        );
    }
    public static function job_stage_allowed($stage,$job){
        if(!is_array($job)||!in_array($job['state']??'',array('NEW','QUEUED','RUNNING'),true))
            return false;
        $current=(string)($job['stage']??'');
        if($current===$stage)return true;
        $windows=array(
          'PRICING'=>array('FINAL_QA','PUBLISHING','POST_PUBLISH'),
          'WPML_TRANSLATION'=>array('PUBLISHING','POST_PUBLISH'),
          'COMPANY_DISTRIBUTION'=>array('POST_PUBLISH')
        );
        return in_array($current,$windows[$stage]??array(),true);
    }
    public static function boot(){
        add_filter('mad4b_hub_dynamic_executor_manifests',array(__CLASS__,'manifests'),20,1);
        add_filter('mad4b_hub_bulk_stage_dispatch',array(__CLASS__,'dispatch'),20,3);
        add_filter('mad4b_hub_bulk_verify_stage_receipt',array(__CLASS__,'verify'),20,3);
        add_action('rest_api_init',array(__CLASS__,'routes'),56);
        add_action('wp_abilities_api_init',array(__CLASS__,'abilities'),56);
    }
    private static function error($type,$code=409){
        return new WP_Error('mad4b_native_adapter_'.$type,$type,array('status'=>$code,'contract'=>self::CONTRACT));
    }
    public static function manifests($manifests){
        if(!is_array($manifests))$manifests=array();
        foreach(self::map() as $stage=>$m){
            $ability=function_exists('wp_get_ability')?wp_get_ability($m['ability']):null;
            $manifests[]=array('id'=>$m['id'],'stages'=>array($stage),
                'ability'=>$m['ability'],'executor'=>'vps_runner',
                'priority'=>75,'enabled'=>$ability!==null,
                'ready'=>$ability!==null);
        }
        return $manifests;
    }
    public static function preflight($stage,$ability,$context=array()){
        $map=self::map();$cfg=$map[$stage]??null;
        if(!$cfg || !is_array($context)
           || ($context['stage_adapter_id']??$cfg['id'])!==$cfg['id']
           || ($context['stage_ability']??'')!==$cfg['ability']
           || $ability!==$cfg['ability']
           || !isset($context['stage_input'])
           || !is_array($context['stage_input']))return false;
        $input=$context['stage_input'];
        if(array_diff($cfg['requires'],array_keys($input)))return false;
        if(strlen(wp_json_encode($input))>14500)return false;
        // All six commercial stage operations require the canonical ContentJob.
        if(empty($context['content_job_id'])
           ||!preg_match('/^[a-f0-9-]{36}$/D',(string)$context['content_job_id']))
            return false;
        if(!empty($context['content_job_id'])){
            $record=MAD4B_SCP_Content_Jobs::get_job(
                array('job_id'=>$context['content_job_id']));
            if(is_wp_error($record)
                ||!self::job_stage_allowed($stage,$record['job']??array()))
                return false;
        }
        if(($cfg['kind']==='artifact')
            &&($input['job_id']??'')!==($context['content_job_id']??''))
            return false;
        if($cfg['kind']==='artifact'&&$stage==='SERP_RESEARCH'
           &&(empty($input['results'])||!is_array($input['results'])
             ||empty($input['source_refs'])||!is_array($input['source_refs'])
             ||($input['provider_state']??'succeeded')!=='succeeded'
             ||!preg_match('/^[a-f0-9]{64}$/D',(string)($input['request_sha256']??''))))
            return false;
        if($cfg['kind']==='artifact'&&$stage==='WRITING'
           &&(strlen(trim((string)($input['content']??'')))<80
           ||empty($input['blueprint_artifact_id'])
           ||empty($input['blueprint_qa_artifact_id'])
           ||empty($input['context_artifact_id'])))return false;
        if($cfg['kind']==='pricing_preview'
           &&(!is_array($input['request']) || !$input['request']
              ||!class_exists('Shared_Product_Rate_Generation')
              ||!current_user_can('edit_posts')))return false;
        if($cfg['kind']==='translation_readback'){
            if(!is_numeric($input['post_id'])||!get_post((int)$input['post_id'])
               ||!in_array($input['provider'],array('wpml','polylang'),true))
                return false;
            $read=wp_get_ability('mad4b/translation-get-post');
            $existing=$read?$read->execute(array('post_id'=>(int)$input['post_id'],
                      'provider'=>$input['provider'])):null;
            if(!is_array($existing)
               ||($existing['language']??'')!==$input['expected_language']
               ||(string)($existing['group']??'')!==(string)$input['expected_group'])
                return false;
        }
        if($cfg['kind']==='distribution_pending'){
            $planner=wp_get_ability('mad4b/hub-source-sync-plan');
            $plan=$planner?$planner->execute(array()):null;
            if(!is_array($plan)||empty($plan['ready'])
               ||($plan['company_id']??'')!==$input['company_id']
               ||($plan['provider_id']??'')!==$input['provider_id']
               ||($plan['dataset_id']??'')!==$input['dataset_id']
               ||($plan['transport']??'')!==$input['transport'])
                return false;
        }
        if($cfg['kind']==='canonical_pending'){
            $record=MAD4B_Hub_Canonical_Store::read_record((string)$input['record_key']);
            if(is_wp_error($record)||($record['job_id']??'')!==($context['content_job_id']??''))
                return false;
            $assessment=MAD4B_Hub_Release_Gate::evaluate($input);
            if(is_wp_error($assessment)||empty($assessment['eligible']))
                return false;
        }
        $obj=wp_get_ability($cfg['ability']);
        if(!$obj)return false;
        $schema=$obj->get_input_schema();
        $required=is_array($schema)?($schema['required']??array()):array();
        foreach((array)$required as $field)
            if(!array_key_exists($field,$input))return false;
        return true;
    }
    public static function status($input=array()){
        if(!MAD4B_Hub_Bulk_Jobs_Queue::readable())return self::error('permission',403);
        $out=array();
        foreach(self::map() as $stage=>$cfg){
            $ability=wp_get_ability($cfg['ability']);
            $out[$stage]=array(
                'adapter'=>$cfg['id'],'native_ability'=>$cfg['ability'],
                'registered'=>$ability!==null,'kind'=>$cfg['kind'],
                'required_input'=>$cfg['requires'],
                'execution_ready'=>false,
                'readiness_requires'=>'exact_stage_input_and_original_ability_permissions',
                'business_completion_on_dispatch'=>($cfg['kind']==='artifact'),
                'awaits_external_or_commercial_completion'=>in_array($cfg['kind'],array('pricing_preview','distribution_pending','canonical_pending'),true)
            );
        }
        // mad4b_semantic_bridge_v1: verification is not synchronization.
        $rb=function_exists('wp_get_ability')?wp_get_ability('mad4b/hub-jetengine-semantic-readback'):null;
        $out['JETENGINE_SYNC']=array(
            'adapter'=>'native.jetengine_semantic_readback',
            'native_ability'=>'mad4b/hub-jetengine-semantic-readback',
            'registered'=>$rb!==null,'readback_ready'=>$rb!==null,
            'kind'=>'verify_only','execution_ready'=>false,
            'requires'=>array('post_id','expected_snapshot_sha256'),
            'readiness_requires'=>'independent_source_and_target_semantic_receipts_and_all_required_wpml_languages',
            'wpml_language_coverage_owner'=>'MAD4B_WPML_Design_Reconciliation_V1',
            'missing_locale_is_not_success'=>true,
            'business_completion_on_dispatch'=>false,
            'awaits_original_jetengine_sync'=>true,
            'never_authorizes_import_or_mutation'=>true);
        // Original INTEGRATION_QA stage: verify shortcode dependencies without
        // pretending the actual browser action or HTTP request was executed.
        $shortcodeRead=function_exists('wp_get_ability')
            ?wp_get_ability('mad4b/hub-shortcode-js-dependency-readback'):null;
        $out['INTEGRATION_QA']=array(
            'adapter'=>'native.shortcode_js_deps_readback',
            'native_ability'=>'mad4b/hub-shortcode-js-dependency-readback',
            'registered'=>$shortcodeRead!==null,
            'kind'=>'verification_only',
            'readback_ready'=>$shortcodeRead!==null,
            'execution_ready'=>false,'html_render_verified'=>false,
            'js_execution_verified'=>false,'interaction_verified'=>false,
            'requires'=>array('post_id','expected_graph_sha256'),
            'required_followup'=>'browser_asset_dom_and_event_receipt',
            'business_completion_on_dispatch'=>false);
        return array('contract'=>self::CONTRACT,'stages'=>$out,
           'auto_mutation_without_input'=>false,'mutation_performed'=>false);
    }
    public static function plan($input=array()){
        if(!MAD4B_Hub_Bulk_Jobs_Queue::readable())return self::error('permission',403);
        $stage=(string)($input['stage']??'');$cfg=self::map()[$stage]??null;
        if($stage==='JETENGINE_SYNC')return array(
            'contract'=>self::CONTRACT,'stage'=>'JETENGINE_SYNC',
            'adapter'=>'native.jetengine_semantic_readback',
            'native_ability'=>'mad4b/hub-jetengine-semantic-readback',
            'input_structurally_ready'=>isset($input['stage_input']['post_id'],
                $input['stage_input']['expected_snapshot_sha256']),
            'executed'=>false,'sync_execution_ready'=>false,
            'requires_original_mutation_adapter'=>true,
            'readback_available'=>function_exists('wp_get_ability')&&
                (bool)wp_get_ability('mad4b/hub-jetengine-semantic-readback'),
            'executor_if_ineligible'=>'mcp_client');
        if(!$cfg)return self::error('stage_invalid',400);
        $task=array(
            'stage_adapter_id'=>$cfg['id'],'stage_ability'=>(string)($input['stage_ability']??''),
            'stage_input'=>$input['stage_input']??null,
            'content_job_id'=>(string)($input['job_id']??'')
        );
        $eligible=self::preflight($stage,$cfg['ability'],$task);
        return array('contract'=>self::CONTRACT,'stage'=>$stage,'adapter'=>$cfg['id'],
            'native_ability'=>$cfg['ability'],'input_structurally_ready'=>$eligible,
            'executed'=>false,'requires_original_ability_permission'=>true,
            'missing_fields'=>array_values(array_diff($cfg['requires'],
                array_keys(is_array($task['stage_input'])?$task['stage_input']:array()))),
            'executor_if_ineligible'=>'mcp_client');
    }
    private static function receipt($task,$cfg,$proof,$evidence,$pending=false){
        return array('verified'=>true,'operation'=>'stage_work',
            'stage'=>$task['stage'],'job_id'=>$task['content_job_id'],
            'native_ability'=>$cfg['ability'],'native_adapter_id'=>$cfg['id'],
            'evidence_ref'=>$evidence,'evidence_sha256'=>hash('sha256',wp_json_encode($proof)),
            'result_kind'=>$cfg['kind'],'completion_state'=>$pending?'pending':'completed',
            'stage_execution_performed'=>true,'receipt_source'=>'native_ability_and_readback');
    }
    private static function validate_result($output,$cfg,$task){
        $kind=$cfg['kind'];$input=$task['payload']['stage_input'];
        if($kind==='artifact'){
            $artifact=$output['artifact']??null;
            if(!is_array($artifact)||empty($artifact['artifact_id']))return self::error('missing_artifact_receipt');
            $a=MAD4B_SCP_Artifacts::get_artifact(array('artifact_id'=>$artifact['artifact_id']));
            if(is_wp_error($a))return $a;
            $row=$a['artifact'];
            if(($row['job_id']??'')!==$task['content_job_id']
              ||($row['artifact_type']??'')!==$cfg['artifact_type']
              ||($row['status']??'')!=='active')return self::error('artifact_readback_invalid');
            return self::receipt($task,$cfg,
               array('artifact_id'=>$row['artifact_id'],'payload'=>$row['payload']),
               'artifact:'.$row['artifact_id']);
        }
        if($kind==='pricing_preview'){
            if(!is_array($output)||!$output)return self::error('empty_pricing_preview');
            $a=wp_get_ability($cfg['ability']);
            $recheck=$a->execute($input);
            if(is_wp_error($recheck))return $recheck;
            if(hash('sha256',wp_json_encode($output))!==hash('sha256',wp_json_encode($recheck)))
                return self::error('pricing_snapshot_changed');
            return self::receipt($task,$cfg,$output,
              'pricing-preview:'.substr(hash('sha256',wp_json_encode($output)),0,32),true);
        }
        if($kind==='translation_readback'){
            $post=(int)$input['post_id'];
            $a=wp_get_ability('mad4b/translation-get-post');
            if(!$a)return self::error('translation_readback_missing');
            $ctx=$a->execute(array('post_id'=>$post,'provider'=>$input['provider']));
            if(is_wp_error($ctx))return $ctx;
            if(($ctx['post_id']??0)!==$post
               ||($ctx['language']??'')!==$input['language'])
                return self::error('translated_post_language_not_confirmed');
            return self::receipt($task,$cfg,
                array('post_id'=>$post,'language'=>$ctx['language'],'trid'=>$ctx['trid']??null),
                'translated-post:'.$post,true);
        }
        if($kind==='distribution_pending'){
            // A sync *request* is not proof that a company received the content.
            if(!is_array($output)||!$output)return self::error('sync_request_receipt_missing');
            $status=wp_get_ability('mad4b/hub-source-sync-status');
            if(!$status)return self::error('sync_readback_unavailable');
            $read=$status->execute(array());
            if(is_wp_error($read))return $read;
            $jobs=$read['jobs']??array();
            if(!is_array($jobs))return self::error('sync_jobs_readback_invalid');
            $key=(string)$input['request_key'];
            $found=false;
            foreach($jobs as $job){
                if(is_array($job)&&($job['request_key']??'')===$key){$found=true;break;}
            }
            if(!$found)return self::error('sync_request_not_confirmed');
            return self::receipt($task,$cfg,array('request_key'=>$key,'observed'=>true),
              'source-sync-request:'.$key,true);
        }
        if($kind==='canonical_pending'){
            if(($output['released']??false)!==true || !is_array($output['record']??null))
                return self::error('canonical_release_not_committed');
            $record=MAD4B_Hub_Canonical_Store::read_record((string)$input['record_key']);
            if(is_wp_error($record))return $record;
            if(($record['state']??'')!=='approved'
                ||($record['version_sha256']??'')!==($output['record']['version_sha256']??'')
                ||($record['job_id']??'')!==$task['content_job_id'])
                return self::error('canonical_release_readback_invalid');
            // Canonical Hub release is not public WordPress publishing.
            return self::receipt($task,$cfg,array('record_key'=>$record['record_key'],
                'version'=>$record['version_sha256'],'state'=>$record['state']),
                'canonical-record:'.$record['record_key'],true);
        }
        return self::error('unsupported_receipt_kind');
    }
    public static function dispatch($prior,$task,$heartbeat){
        if($prior!==null)return $prior;
        if(($task['operation']??'')!=='stage_work')return null;
        $cfg=self::map()[$task['stage']]??null;
        if(!$cfg || ($task['payload']['stage_adapter_id']??'')!==$cfg['id'])return null;
        $ctx=$task['payload'];
        $ctx['content_job_id']=$task['content_job_id'];
        if(!self::preflight($task['stage'],$cfg['ability'],$ctx))
            return null; // Let the Queue park, never fabricate stage completion.
        $a=wp_get_ability($cfg['ability']);
        if(!$a)return null;
        $input=$ctx['stage_input'];
        $permission=$a->check_permissions($input);
        if(is_wp_error($permission))return $permission;
        if($permission!==true)return self::error('native_permission_denied',403);
        // Changing a record requires bounded execution and exact caller-supplied inputs.
        $alive=$heartbeat(180);
        if(is_wp_error($alive))return $alive;
        $output=$a->execute($input);
        if(is_wp_error($output))return $output;
        return self::validate_result($output,$cfg,$task);
    }
    public static function verify($prior,$result,$task){
        if($prior===true)return true;
        if(($task['operation']??'')!=='stage_work')return false;
        $cfg=self::map()[$task['stage']]??null;
        if(!$cfg || ($result['native_adapter_id']??'')!==$cfg['id']
          ||($result['native_ability']??'')!==$cfg['ability']
          ||empty($result['evidence_ref']))return false;
        $ctx=is_array($task['payload']??null)
             ?$task['payload']:json_decode((string)($task['payload_json']??''),true);
        if(!is_array($ctx))return false;
        $ctx['content_job_id']=$task['content_job_id'];
        if(!self::preflight($task['stage'],$cfg['ability'],$ctx))return false;
        // Independently fetch the current outcome, without repeating mutation.
        $kind=$cfg['kind'];$ref=(string)$result['evidence_ref'];$evidence=null;
        if($kind==='artifact'){
            if(!str_starts_with($ref,'artifact:'))return false;
            $a=MAD4B_SCP_Artifacts::get_artifact(array('artifact_id'=>substr($ref,9)));
            if(is_wp_error($a))return false;
            $row=$a['artifact'];
            if(($row['job_id']??'')!==$task['content_job_id']
               ||($row['artifact_type']??'')!==$cfg['artifact_type']
               ||($row['status']??'')!=='active')return false;
            $evidence=array('artifact_id'=>$row['artifact_id'],'payload'=>$row['payload']);
        }elseif($kind==='pricing_preview'){
            $a=wp_get_ability($cfg['ability']);
            $output=$a?$a->execute($ctx['stage_input']):null;
            if(!is_array($output))return false;
            $evidence=$output;
        }elseif($kind==='translation_readback'){
            $a=wp_get_ability('mad4b/translation-get-post');
            $post=(int)$ctx['stage_input']['post_id'];
            $v=$a?$a->execute(array('post_id'=>$post,
                'provider'=>$ctx['stage_input']['provider'])):null;
            if(!is_array($v)||($v['language']??'')!==$ctx['stage_input']['language'])
                return false;
            $evidence=array('post_id'=>$post,'language'=>$v['language'],'trid'=>$v['trid']??null);
        }elseif($kind==='distribution_pending'){
            $read=wp_get_ability('mad4b/hub-source-sync-status');
            $out=$read?$read->execute(array()):null;
            if(!is_array($out))return false;
            $key=$ctx['stage_input']['request_key'];$found=false;
            foreach((array)($out['jobs']??array()) as $j)
                if(is_array($j)&&($j['request_key']??'')===$key){$found=true;break;}
            if(!$found)return false;
            $evidence=array('request_key'=>$key,'observed'=>true);
        }elseif($kind==='canonical_pending'){
            $out=MAD4B_Hub_Canonical_Store::read_record($ctx['stage_input']['record_key']);
            if(is_wp_error($out)||($out['state']??'')!=='approved'
              ||($out['job_id']??'')!==$task['content_job_id'])return false;
            $evidence=array('record_key'=>$out['record_key'],'version'=>$out['version_sha256'],'state'=>$out['state']);
        }else return false;
        return hash_equals((string)$result['evidence_sha256'],
            hash('sha256',wp_json_encode($evidence)));
    }
    /** Native Shared Product Rate Generator: preview-only, no Rate CPT mutation. */
    public static function rate_preview($input=array()){
        if(!MAD4B_Hub_Bulk_Jobs_Queue::readable()||!current_user_can('edit_posts'))
            return self::error('rate_preview_permission',403);
        $request=$input['request']??null;
        if(!is_array($request)||!$request ||
           !class_exists('Shared_Product_Rate_Generation'))
            return self::error('rate_generator_not_available',503);
        $result=Shared_Product_Rate_Generation::prepare($request);
        if(is_wp_error($result))return $result;
        if(($result['schema']??'')!=='shared-rate-generation.v1'
            ||($result['post_status']??'')!=='draft'
            ||($result['checkout_ready']??true)!==false
            ||($result['commercial_ready']??true)!==false
            ||!is_string($result['fingerprint']??null))
            return self::error('rate_generator_contract_invalid');
        return array('contract'=>self::CONTRACT,
            'schema'=>$result['schema'],'fingerprint'=>$result['fingerprint'],
            'post_status'=>'draft','checkout_ready'=>false,
            'commercial_ready'=>false,'mutation_performed'=>false,
            'source'=>'Shared_Product_Rate_Generation::prepare',
            'native_plan'=>$result);
    }
    /**
     * Static, provider-neutral shortcode dependency readback for original
     * INTEGRATION_QA. No do_shortcode(), no enqueue action, and no execution
     * of user-submitted JS/PHP. Browser evidence remains separate.
     */
    public static function shortcode_js_readback($input=array()){
        if(!MAD4B_Hub_Bulk_Jobs_Queue::readable())
            return self::error('shortcode_dependency_permission_denied',403);
        $post_id=absint($input['post_id']??0);
        $expected=strtolower((string)($input['expected_graph_sha256']??''));
        if(!$post_id||!get_post($post_id)||!preg_match('/^[a-f0-9]{64}$/D',$expected))
            return self::error('shortcode_dependency_invalid_input',400);
        if(!class_exists('MAD4B_Hub_Field_Fusion_V1'))
            return self::error('shortcode_dependency_owner_unavailable',503);
        $ids=array($post_id);
        foreach(array_slice((array)($input['consumer_ids']??[]),0,59) as $v){
            $id=absint($v);
            if($id)$ids[]=$id;
        }
        $graph=MAD4B_Hub_Field_Fusion_V1::semantic_shortcode_graph(array_values(array_unique($ids)));
        $hash=hash('sha256',wp_json_encode($graph,JSON_UNESCAPED_SLASHES));
        if(!hash_equals($expected,$hash))
            return new WP_Error('mad4b_shortcode_graph_changed',
                'Exact Shortcode dependency graph differs from expected snapshot',
                array('status'=>409));
        return array('contract'=>'mad4b.hub.shortcode-js-static-readback.v1',
            'post_id'=>$post_id,'graph_sha256'=>$hash,'static_graph_matches'=>true,
            'registered_shortcodes_verified'=>$graph['registered_used_tag_count'],
            'unavailable_shortcodes_in_explicit_widgets'=>
                $graph['unregistered_or_uncallable_used_tag_count'],
            'ambiguous_non_widget_tokens'=>$graph['ambiguous_unregistered_token_count'],
            'graph_truncated'=>$graph['coverage']['truncated'],
            'frontend_markup_status'=>'BROWSER_READBACK_REQUIRED',
            'javascript_network_status'=>'BROWSER_READBACK_REQUIRED',
            'javascript_events_status'=>'INTERACTION_TEST_REQUIRED',
            'integration_qa_pass'=>false,'stage_completed'=>false,
            'mutation_performed'=>false);
    }
    /** mad4b_semantic_bridge_v1: exact readback, no synthetic stage completion. */
    public static function semantic_readback($input=array()){
        if(!MAD4B_Hub_Bulk_Jobs_Queue::readable())
            return self::error('semantic_permission_denied',403);
        $post_id=absint($input['post_id']??0);
        $expected=strtolower((string)($input['expected_snapshot_sha256']??''));
        if(!$post_id||!preg_match('/^[a-f0-9]{64}$/D',$expected))
            return self::error('semantic_invalid_input',400);
        $snapshot=MAD4B_Hub_Source_Reader::semantic_candidate($post_id,[
            'field_names'=>(array)($input['field_names']??[]),
            'consumer_ids'=>(array)($input['consumer_ids']??[]),
            'discover_consumers'=>false]);
        if(is_wp_error($snapshot))return $snapshot;
        $actual=(string)($snapshot['snapshot_sha256']??'');
        if(!hash_equals($expected,$actual))
            return new WP_Error('mad4b_semantic_readback_mismatch',
                'Exact semantic snapshot did not match',['status'=>409]);
        $family=(array)($snapshot['wpml_family_coverage']??[]);
        $coverage=(array)($family['summary']??[]);
        $dependency=(array)($snapshot['wpml_dependency_manifest']??[]);
        $nestedSummary=(array)($dependency['summary']??[]);
        return array('contract'=>'mad4b.hub.jetengine.semantic-readback.v1',
            'post_id'=>$post_id,'expected_sha256'=>$expected,
            'observed_sha256'=>$actual,'snapshot_matches'=>true,
            'schema_readback'=>true,
            'wpml_required_languages'=>(array)($family['required_languages']??[]),
            'wpml_unmet_locale_count'=>(int)($coverage['unmet_language_count']??-1),
            'wpml_missing_languages'=>(array)($coverage['missing_languages']??[]),
            'wpml_structural_family_pass'=>(bool)($family['structural_readback_pass']??false),
            'wpml_full_business_completion_allowed'=>false,
            'wpml_independent_locale_receipts_required'=>true,
            'wpml_nested_families_observed'=>(int)($dependency['family_count']??0),
            'wpml_nested_unmet_locale_instances'=>(int)($nestedSummary['unmet_locale_instances']??-1),
            'wpml_same_text_term_review_count'=>(int)($nestedSummary['same_text_term_translation_reviews']??-1),
            'wpml_manifest_truncated'=>(bool)($nestedSummary['scope_truncated']??true),
            'wpml_business_readiness'=>'BLOCKED_UNTIL_ALL_LOCALES_AND_RENDER_QA',
            'visibility_readback'=>'STATIC_ONLY',
            'style_readback'=>'GENERATED_ASSET_ONLY',
            'interaction_readback'=>'BROWSER_TEST_REQUIRED',
            'stage_completed'=>false,'mutation_performed'=>false);
    }
    public static function routes(){
        foreach(array('/control-workflow/native-stages/status'=>'status',
             '/control-workflow/native-stages/plan'=>'plan') as $path=>$method){
            register_rest_route('mad4b-hub/v1',$path,array(
                'methods'=>'GET','callback'=>static fn($r)=>self::$method($r->get_params()),
                'permission_callback'=>static fn()=>MAD4B_Hub_Bulk_Jobs_Queue::readable()));
        }
    }
    public static function abilities(){
        if(!function_exists('wp_register_ability'))return;
        if(!wp_has_ability('mad4b/hub-native-rate-generator-preview')){
            wp_register_ability('mad4b/hub-native-rate-generator-preview',array(
                'label'=>'MAD4B Native Shared Rate Generator Preview',
                'description'=>'Read-only Shared_Product_Rate_Generation prepare. Never creates or publishes a Rate.',
                'category'=>'mad4b-read','execute_callback'=>array(__CLASS__,'rate_preview'),
                'permission_callback'=>static fn()=>MAD4B_Hub_Bulk_Jobs_Queue::readable()
                      &&current_user_can('edit_posts'),
                'input_schema'=>array('type'=>'object','properties'=>array(
                    'request'=>array('type'=>'object','additionalProperties'=>true)),
                    'required'=>array('request'),'additionalProperties'=>false),
                'output_schema'=>array('type'=>'object','additionalProperties'=>true),
                'meta'=>array('public'=>false,'show_in_rest'=>false,
                    'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
                    'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true))
            ));
        }
        if(!wp_has_ability('mad4b/hub-shortcode-js-dependency-readback')){
            wp_register_ability('mad4b/hub-shortcode-js-dependency-readback',array(
                'label'=>'MAD4B Shortcode to JavaScript Dependency Readback',
                'description'=>'Exact readonly Field Fusion registry+asset dependency digest. Browser interaction remains unverified.',
                'category'=>'mad4b-read',
                'execute_callback'=>array(__CLASS__,'shortcode_js_readback'),
                'permission_callback'=>static fn()=>MAD4B_Hub_Bulk_Jobs_Queue::readable(),
                'input_schema'=>array('type'=>'object','properties'=>array(
                    'post_id'=>array('type'=>'integer','minimum'=>1),
                    'expected_graph_sha256'=>array('type'=>'string','pattern'=>'^[a-f0-9]{64}$'),
                    'consumer_ids'=>array('type'=>'array','maxItems'=>59,
                        'items'=>array('type'=>'integer','minimum'=>1))),
                    'required'=>array('post_id','expected_graph_sha256'),
                    'additionalProperties'=>false),
                'output_schema'=>array('type'=>'object','additionalProperties'=>true),
                'meta'=>array('public'=>false,'show_in_rest'=>false,
                    'mad4b_hub_stages'=>array('INTEGRATION_QA','JETENGINE_SYNC'),
                    'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
                    'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true))
            ));
        }
        if(!wp_has_ability('mad4b/hub-jetengine-semantic-readback')){
            wp_register_ability('mad4b/hub-jetengine-semantic-readback',array(
                'label'=>'MAD4B JetEngine Semantic Dependency Readback',
                'description'=>'Verifies an on-demand Field Fusion graph digest. Does not sync or apply JetEngine data.',
                'category'=>'mad4b-read',
                'execute_callback'=>array(__CLASS__,'semantic_readback'),
                'permission_callback'=>static fn()=>MAD4B_Hub_Bulk_Jobs_Queue::readable(),
                'input_schema'=>array('type'=>'object','properties'=>array(
                    'post_id'=>array('type'=>'integer','minimum'=>1),
                    'expected_snapshot_sha256'=>array('type'=>'string','pattern'=>'^[a-f0-9]{64}$'),
                    'field_names'=>array('type'=>'array','items'=>array('type'=>'string')),
                    'consumer_ids'=>array('type'=>'array','items'=>array('type'=>'integer'))),
                    'required'=>array('post_id','expected_snapshot_sha256'),
                    'additionalProperties'=>false),
                'output_schema'=>array('type'=>'object','additionalProperties'=>true),
                'meta'=>array('public'=>false,'show_in_rest'=>false,
                    'mad4b_hub_stages'=>array('JETENGINE_SYNC'),
                    'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
                    'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true))
            ));
        }
        foreach(array('status','plan') as $method){
            $name='mad4b/hub-native-stages-'.$method;
            if(wp_has_ability($name))continue;
            wp_register_ability($name,array(
                'label'=>'MAD4B Native Stage Adapters '.$method,
                'description'=>'Inspect exact native stage adapter readiness without execution.',
                'category'=>'mad4b-read','execute_callback'=>array(__CLASS__,$method),
                'permission_callback'=>static fn()=>MAD4B_Hub_Bulk_Jobs_Queue::readable(),
                'input_schema'=>array('type'=>'object','properties'=>array(
                    'stage'=>array('type'=>'string'),'job_id'=>array('type'=>'string'),
                    'stage_ability'=>array('type'=>'string'),
                    'stage_input'=>array('type'=>'object','additionalProperties'=>true)),
                    'additionalProperties'=>false),
                'output_schema'=>array('type'=>'object','additionalProperties'=>true),
                'meta'=>array('public'=>false,'show_in_rest'=>false,
                    'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
                    'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true))
            ));
        }
    }
}
