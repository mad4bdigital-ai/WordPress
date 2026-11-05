<?php
defined('ABSPATH') || exit;

/** Version-bound, company-governed QA promotion from research to canonical
 * content, never directly publishing a WordPress post or remote company data.
 */
final class MAD4B_Hub_Release_Gate {
    const CONTRACT='mad4b.hub.canonical-release.v1';
    public static function boot() {
        add_action('rest_api_init',array(__CLASS__,'routes'),41);
        add_action('wp_abilities_api_init',array(__CLASS__,'abilities'),45);
    }
    public static function allowed() {
        return MAD4B_Hub_Control_Ingestion::allowed()
            && current_user_can('edit_posts');
    }
    private static function error($name,$code=409) {
        return new WP_Error('mad4b_hub_release_'.$name,$name,
            array('status'=>$code,'contract'=>self::CONTRACT));
    }
    private static function artifact($id,$job,$type) {
        if(!is_string($id)||!preg_match('/^[a-f0-9-]{36}$/D',$id))
            return self::error('artifact_id_invalid',400);
        $result=MAD4B_SCP_Artifacts::get_artifact(array('artifact_id'=>$id));
        if(is_wp_error($result))return $result;
        $a=$result['artifact']??null;
        if(!is_array($a)||($a['status']??'')!=='active'
            ||($a['job_id']??'')!==$job ||($a['artifact_type']??'')!==$type)
            return self::error('artifact_job_or_type_mismatch');
        return $a;
    }
    public static function evaluate($input) {
        if(!self::allowed())return self::error('operator_denied',403);
        if(!is_array($input))return self::error('invalid_input',400);
        $key=(string)($input['record_key']??'');
        $version=(string)($input['expected_version_sha256']??'');
        if(!preg_match('/^[a-f0-9]{64}$/D',$key)
            ||!preg_match('/^[a-f0-9]{64}$/D',$version))
            return self::error('record_version_required',400);
        $record=MAD4B_Hub_Canonical_Store::read_record($key);
        if(is_wp_error($record))return $record;
        if($record['state']!=='research_candidate'
            ||$record['version_sha256']!==$version)
            return self::error('stale_research_version');
        $job=(string)$record['job_id'];
        $draft=self::artifact($input['draft_artifact_id']??null,$job,'draft');
        if(is_wp_error($draft))return $draft;
        $final=self::artifact($input['final_qa_artifact_id']??null,$job,'final_qa');
        if(is_wp_error($final))return $final;
        $dp=$draft['payload']??array();
        $qp=$final['payload']??array();
        if(($dp['contract']??'')!=='mad4b.article-draft.v1'
            ||empty($dp['draft_only'])
            ||($qp['contract']??'')!=='mad4b.final-qa.v1'
            ||($qp['draft_artifact_id']??'')!==$draft['artifact_id']
            ||empty($qp['pass']) ||empty($qp['can_store_in_hub'])
            ||!empty($qp['hard_blockers']))
            return self::error('final_qa_not_eligible');
        $components=$qp['component_artifact_ids']??array();
        foreach(array('fact_ledger','editorial_qa','seo_qa') as $type) {
            $a=self::artifact($components[$type]??null,$job,$type);
            if(is_wp_error($a))return $a;
            if(empty($a['payload']['pass']) || !empty($a['payload']['hard_blockers'])
                ||($a['payload']['draft_artifact_id']??'')!==$draft['artifact_id'])
                return self::error('component_qa_failed');
        }
        // Image is a required commercial-release dependency, not optional
        // output decoration. The image must resolve to a valid original
        // WordPress Media Library asset on this exact staging site.
        $featured_media_id=absint($input['featured_media_id']??($dp['featured_media_id']??0));
        if(!class_exists('MAD4B_Editorial_Featured_Image_Gate'))
            return self::error('featured_image_guard_missing',503);
        $image=MAD4B_Editorial_Featured_Image_Gate::verify_image($featured_media_id);
        if(empty($image['valid']))
            return new WP_Error('mad4b_hub_release_featured_image_required',
                'Canonical release blocked: assign a valid featured image before approving an article for distribution.',
                ['status'=>422,'reason'=>$image['reason'],'attachment_id'=>$featured_media_id,
                 'contract'=>self::CONTRACT,'publish_authorized'=>false]);
        $title=sanitize_text_field((string)($input['title']??''));
        $body=(string)($dp['content']??'');
        if(strlen($title)<8||strlen($title)>180
            ||strlen(trim(wp_strip_all_tags($body)))<150
            ||strlen($body)>200000)
            return self::error('draft_content_bounds');
        $scope=MAD4B_SCP_Operational_Integrity::capture();
        if(is_wp_error($scope))return $scope;
        if(($scope['scope']['site_uuid']??'')!==$record['site_uuid']
            ||($scope['scope']['brand_ref']??'')!==$record['brand_id'])
            return self::error('brand_site_scope_changed');
        $rights=is_array($input['rights']??null)?$input['rights']:array();
        $origin=sanitize_key((string)($rights['origin']??'external_reference'));
        $decision=MAD4B_Hub_Usage_Rights::assess(
            array('origin'=>$origin,'consumer_company_id'=>$record['brand_id'],
                'license_id'=>(string)($rights['license_id']??'')),
            array('quality_state'=>'passed','hard_blockers'=>array(),
                'external_media'=>!empty($rights['external_media'])),
            'distribution'
        );
        if(empty($decision['allowed']))return self::error('company_policy_denied');
        // "Not verified" remains informational; it is not a legal approval.
        $normalized=array('content_kind'=>'article','title'=>$title,
            'description'=>wp_trim_words(wp_strip_all_tags($body),55),
            'body'=>wp_kses_post($body),'language'=>$record['locale'],
            'quality_state'=>'passed','rights_state'=>$decision['rights_metadata']['license_state'],
            'publish_authorized'=>true,'source_content_sha256'=>$record['source_sha256'],
            'external_media'=>!empty($rights['external_media']),
            'featured_media_id'=>$featured_media_id);
        $provenance=array('contract'=>self::CONTRACT,
            'source_origin'=>$origin,'site_uuid'=>$record['site_uuid'],
            'brand_id'=>$record['brand_id'],'job_id'=>$job,
            'research_version_sha256'=>$version,'source_sha256'=>$record['source_sha256'],
            'source_url'=>$record['source_url'],
            'draft_artifact_id'=>$draft['artifact_id'],
            'final_qa_artifact_id'=>$final['artifact_id'],
            'qa_component_artifact_ids'=>$components,
            'license_state'=>$decision['rights_metadata']['license_state'],
            'rights_handling'=>'company_responsibility',
            'rights_legal_status'=>'not_determined_by_system',
            'featured_media_id'=>$featured_media_id,
            'featured_media_verified'=>true,
            'external_distribution_authorized'=>true,
            'test_only'=>false);
        $j=wp_json_encode($normalized,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $p=wp_json_encode($provenance,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!$j||!$p)return self::error('serialization_failed');
        return array('contract'=>self::CONTRACT,'eligible'=>true,
            'record_key'=>$key,'expected_version_sha256'=>$version,
            'new_version_sha256'=>hash('sha256',$j.'|'.$p),
            'job_id'=>$job,'normalized'=>$normalized,'provenance'=>$provenance,
            'wordpress_post_created'=>false,'remote_distribution_performed'=>false);
    }
    public static function release($input) {
        $review=self::evaluate($input);
        if(is_wp_error($review))return $review;
        global $wpdb;
        $t=MAD4B_Hub_Canonical_Store::tables();
        $key=$review['record_key'];$now=gmdate('Y-m-d H:i:s');
        if(false===$wpdb->query('START TRANSACTION'))return self::error('transaction_failed');
        try {
            $row=$wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$t['records']} WHERE record_key=%s FOR UPDATE",$key),ARRAY_A);
            if(!is_array($row) || $row['status']!=='research_candidate'
                ||$row['current_version_sha256']!==$review['expected_version_sha256'])
                throw new RuntimeException('release_version_conflict');
            if(!$wpdb->insert($t['versions'],array(
                'record_id'=>(int)$row['id'],
                'version_sha256'=>$review['new_version_sha256'],
                'source_sha256'=>$review['normalized']['source_content_sha256'],
                'job_id'=>$review['job_id'],'provider_id'=>'content_intelligence_pipeline',
                'normalized_json'=>wp_json_encode($review['normalized'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'provenance_json'=>wp_json_encode($review['provenance'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'observed_at'=>$now,'created_at'=>$now)))
                throw new RuntimeException('version_append_failed');
            if(false===$wpdb->update($t['records'],array(
                'status'=>'approved','current_version_sha256'=>$review['new_version_sha256'],
                'revision'=>(int)$row['revision']+1,'updated_at'=>$now),array('id'=>$row['id'])))
                throw new RuntimeException('record_update_failed');
            if(false===$wpdb->query('COMMIT'))throw new RuntimeException('commit_failed');
        }catch(Throwable $e){
            $wpdb->query('ROLLBACK');
            return self::error('conflict_or_persistence_failure');
        }
        $readback=MAD4B_Hub_Canonical_Store::read_record($key);
        if(is_wp_error($readback)||$readback['state']!=='approved'
            ||$readback['version_sha256']!==$review['new_version_sha256'])
            return self::error('readback_requires_reconciliation');
        return array('contract'=>self::CONTRACT,'released'=>true,
            'record'=>$readback,'wordpress_post_created'=>false,
            'remote_distribution_performed'=>false);
    }
    public static function routes() {
        foreach(array('assess'=>'evaluate','release'=>'release') as $route=>$method)
            register_rest_route('mad4b-hub/v1','/canonical-release/'.$route,array(
                'methods'=>'POST','callback'=>static function($req)use($method){
                    return self::$method($req->get_json_params());
                },'permission_callback'=>array(__CLASS__,'allowed')));
    }
    public static function abilities() {
        if(!function_exists('wp_register_ability'))return;
        foreach(array('mad4b/hub-canonical-release-assess'=>'evaluate',
            'mad4b/hub-canonical-release'=>'release') as $name=>$method){
            if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
            wp_register_ability($name,array(
                'label'=>'MAD4B Canonical Content '.$method,
                'description'=>'Verify exact Draft and QA Artifacts and release one company-governed Hub version; never publishes a post.',
                'category'=>'mad4b-write','execute_callback'=>array(__CLASS__,$method),
                'permission_callback'=>array(__CLASS__,'allowed'),
                'input_schema'=>array('type'=>'object','properties'=>array(
                    'record_key'=>array('type'=>'string'),'expected_version_sha256'=>array('type'=>'string'),
                    'draft_artifact_id'=>array('type'=>'string'),'final_qa_artifact_id'=>array('type'=>'string'),
                    'title'=>array('type'=>'string'),
                    'featured_media_id'=>array('type'=>'integer'),
                    'rights'=>array('type'=>'object','additionalProperties'=>true)),
                    'required'=>array('record_key','expected_version_sha256','draft_artifact_id',
                        'final_qa_artifact_id','title'),'additionalProperties'=>false),
                'output_schema'=>array('type'=>'object','additionalProperties'=>true),
                'meta'=>array('public'=>false,'show_in_rest'=>false,
                    'mcp'=>array('public'=>false,'type'=>'tool'),
                    'annotations'=>array('readonly'=>$method==='evaluate',
                        'destructive'=>false,'idempotent'=>false)),
            ));
        }
    }
}
