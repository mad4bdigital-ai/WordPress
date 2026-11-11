<?php
defined('ABSPATH') || exit;

/**
 * SERP evidence and appearance policy, no artificial ranking, no SEO writes.
 * Historical artifacts are evaluated without changing them.
 */
final class MAD4B_SERP_Presentation_Policy {
    const CONTRACT = 'mad4b.serp-presentation-policy.v1';
    const OPTION = 'mad4b_serp_presentation_policy_v1';
    public static function defaults() {
        return [
            'revision'=>1,
            'presentation'=>['bilingual_ar_en'=>true,'show_source'=>true,'group_by_query'=>true,
                'show_uncertainty'=>true,'show_superseded_history'=>true],
            'evidence'=>['require_https'=>true,'require_provider'=>true,
                'reject_unverified_rank'=>true,'reject_unverified_search_volume'=>true,
                'max_results'=>100,'max_age_days_warning'=>30],
            'appearance'=>['title_min'=>30,'title_max'=>60,
                'description_min'=>110,'description_max'=>160,
                'display_device'=>'both','seo_source'=>'native_rank_math_if_present',
                'guidance_only'=>true,'schema_not_guaranteed'=>true],
            'overrides'=>[],
        ];
    }
    public static function allowed() {
        return class_exists('MAD4B_Content_Creation_Control_Bridge') &&
            MAD4B_Content_Creation_Control_Bridge::can_read();
    }
    public static function can_write() {
        return self::allowed() && current_user_can('manage_options');
    }
    public static function settings() {
        $stored = get_option(self::OPTION, []);
        $base = self::defaults();
        if (!is_array($stored)) $stored=[];
        foreach (['presentation','evidence','appearance'] as $k) {
            if (!empty($stored[$k]) && is_array($stored[$k]))
                $base[$k]=array_intersect_key($stored[$k], $base[$k]) + $base[$k];
        }
        if (!empty($stored['overrides']) && is_array($stored['overrides']))
            $base['overrides']=array_slice($stored['overrides'],0,100,true);
        $base['revision']=max(1,(int)($stored['revision']??1));
        return $base;
    }
    private static function scope(array $input) {
        $job_id = (string)($input['job_id']??'');
        $job = null;
        if ($job_id !== '' && class_exists('MAD4B_SCP_Content_Jobs')) {
            $read = MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]);
            if (is_wp_error($read)) return $read;
            $job = $read['job']??null;
        }
        $profile = (!$job && class_exists('MAD4B_SCP_Context_Authority'))
            ? MAD4B_SCP_Context_Authority::profile() : [];
        return [
            'brand_id'=>(string)($job['brand_id']??($profile['brand_id']??'')),
            'language'=>(string)($job['language']??($input['language']??'')),
            'content_type'=>(string)($job['content_type']??($input['content_type']??'')),
            'job_id'=>$job_id,
        ];
    }
    public static function policy(array $input=[]) {
        if (!self::allowed()) return new WP_Error('mad4b_serp_read_denied','Read permission denied',['status'=>403]);
        $scope=self::scope($input);
        if (is_wp_error($scope)) return $scope;
        $settings=self::settings();
        $effective=$settings;
        // Strictly site/job brand-bound scopes; an override never grants publication or rank proof.
        $keys = ['brand:'.$scope['brand_id'],
                 'brand:'.$scope['brand_id'].'|lang:'.$scope['language'],
                 'brand:'.$scope['brand_id'].'|type:'.$scope['content_type'],
                 'brand:'.$scope['brand_id'].'|lang:'.$scope['language'].'|type:'.$scope['content_type']];
        $applied=[];
        foreach ($keys as $key) {
            if (!isset($settings['overrides'][$key]) || !is_array($settings['overrides'][$key])) continue;
            foreach (['presentation','appearance'] as $part) {
                $row = $settings['overrides'][$key][$part]??null;
                if (is_array($row)) {
                    $effective[$part]=array_merge($effective[$part],array_intersect_key($row,$effective[$part]));
                }
            }
            $applied[]=$key;
        }
        unset($effective['overrides']);
        return ['contract'=>self::CONTRACT, 'scope'=>$scope, 'effective'=>$effective,
            'applied_overrides'=>$applied, 'rank_proof_required'=>true,
            'publication_authorized'=>false, 'mutation_performed'=>false];
    }
    public static function save_override(array $input) {
        if (!self::can_write())
            return new WP_Error('mad4b_serp_policy_write_denied','Policy edit denied',['status'=>403]);
        $current=self::settings();
        $expected=(int)($input['expected_revision']??0);
        if($expected!==$current['revision'])
            return new WP_Error('mad4b_serp_policy_stale','Policy revision changed; reload before edit',['status'=>409]);
        $scope=self::scope(['job_id'=>(string)($input['job_id']??'')]);
        if(is_wp_error($scope))return $scope;
        $brand=(string)$scope['brand_id'];
        if(!preg_match('/^[a-f0-9]{32}$/D',$brand))
            return new WP_Error('mad4b_serp_brand_unverified','Verified brand boundary required');
        $lang=strtolower(trim((string)($input['language']??'')));
        $type=sanitize_key((string)($input['content_type']??''));
        if($lang!=='' && !preg_match('/^[a-z]{2,3}(?:-[a-z]{2})?$/D',$lang))
            return new WP_Error('mad4b_serp_language_invalid','Invalid language');
        if($type!=='' && !preg_match('/^[a-z0-9_-]{2,40}$/D',$type))
            return new WP_Error('mad4b_serp_type_invalid','Invalid content type');
        // Exact scope to avoid global or cross-brand overrides.
        if($lang!=='' && $type!=='')$key='brand:'.$brand.'|lang:'.$lang.'|type:'.$type;
        elseif($lang!=='')$key='brand:'.$brand.'|lang:'.$lang;
        elseif($type!=='')$key='brand:'.$brand.'|type:'.$type;
        else $key='brand:'.$brand;
        $settings=self::settings();
        $values=[];
        if(array_key_exists('show_superseded_history',$input))$values['presentation']['show_superseded_history']=(bool)$input['show_superseded_history'];
        if(array_key_exists('group_by_query',$input))$values['presentation']['group_by_query']=(bool)$input['group_by_query'];
        foreach(['title_min','title_max','description_min','description_max'] as $k)
            if(array_key_exists($k,$input))$values['appearance'][$k]=(int)$input[$k];
        $ap=($settings['overrides'][$key]['appearance']??[]) + $settings['appearance'];
        $ap=array_merge($ap,$values['appearance']??[]);
        if($ap['title_min']<15 || $ap['title_max']>90 || $ap['title_min']>$ap['title_max']
           ||$ap['description_min']<50 || $ap['description_max']>240 || $ap['description_min']>$ap['description_max'])
            return new WP_Error('mad4b_serp_appearance_bounds_invalid','Guidance bounds outside allowed range');
        $overrides=$settings['overrides'];
        if(!isset($overrides[$key])&&count($overrides)>=100)
            return new WP_Error('mad4b_serp_overrides_full','Scope limit reached');
        $next=array_replace_recursive($overrides[$key]??[], $values);
        if(isset($overrides[$key]) && $overrides[$key]===$next)
            return ['contract'=>self::CONTRACT,'scope_key'=>$key,'revision'=>$settings['revision'],
                'rank_and_volume_evidence_requirements_immutable'=>true,
                'mutation_performed'=>false,'publication_authorized'=>false];
        $overrides[$key]=$next;
        $settings['overrides']=$overrides;
        $settings['revision']++;
        update_option(self::OPTION,$settings,false);
        $stored=self::settings();
        if($stored['revision']!==$settings['revision'])
            return new WP_Error('mad4b_serp_policy_persist_failed','Policy revision was not persisted');
        return ['contract'=>self::CONTRACT,'scope_key'=>$key,
            'revision'=>$stored['revision'],'rank_and_volume_evidence_requirements_immutable'=>true,
            'mutation_performed'=>true,'publication_authorized'=>false];
    }
    public static function validate_ingest($input) {
        // Called only on central Staging by native Research Intelligence.
        if (!is_array($input)) return new WP_Error('mad4b_serp_input_invalid','SERP input must be an object');
        $records=$input['results']??[];
        if (!is_array($records)||count($records)<1||count($records)>100)
            return new WP_Error('mad4b_serp_policy_result_count','SERP must contain 1 to 100 results');
        foreach (['job_id','query','market','language','provider_id','collected_at','request_sha256'] as $k) {
            if (!is_scalar($input[$k]??null)||trim((string)$input[$k])==='') return new WP_Error('mad4b_serp_policy_required_field','Missing required SERP field: '.$k);
        }
        foreach ($records as $index=>$row) {
            if (!is_array($row)) return new WP_Error('mad4b_serp_policy_result_invalid','SERP result is not an object');
            $url=(string)($row['url']??'');
            if (!wp_http_validate_url($url) || strtolower((string)wp_parse_url($url,PHP_URL_SCHEME))!=='https')
                return new WP_Error('mad4b_serp_policy_https_required','SERP result must have a valid HTTPS URL: '.(int)$index);
            if (trim((string)($row['title']??''))==='') return new WP_Error('mad4b_serp_policy_title_required','Result title missing: '.(int)$index);
            if (isset($row['rank']) && $row['rank']!==null && $row['rank']!=='') {
                $proof=$row['ranking_proof']??[];
                if (empty($row['rank_verified']) || !is_array($proof) ||
                    empty($proof['provider_request_id']) || empty($proof['engine']) ||
                    empty($proof['captured_at']) || empty($proof['device']) || empty($proof['location']))
                    return new WP_Error('mad4b_serp_policy_unverified_rank','Ranking cannot be recorded without exact provider proof: '.(int)$index);
            }
            if (isset($row['search_volume']) && $row['search_volume']!==null && $row['search_volume']!=='') {
                if (empty($row['volume_proof']['provider_request_id']) || empty($row['volume_proof']['period']))
                    return new WP_Error('mad4b_serp_policy_unverified_volume','Search volume lacks source and period: '.(int)$index);
            }
        }
        return ['contract'=>self::CONTRACT,'policy_status'=>'accepted',
            'evidence_class'=>self::kind($input),'record_count'=>count($records)];
    }
    public static function kind(array $payload) {
        $records=$payload['results']??($payload['result_records']??[]);
        $assumptions=$payload['device_location_assumptions']??[];
        $provider=(string)($payload['provider_id']??'');
        if (in_array($provider,['chatgpt_web_search','web_search','web_discovery'],true) ||
            !empty($assumptions['ranking']) && $assumptions['ranking']==='not measured' ||
            !empty($assumptions['rankings']) && $assumptions['rankings']==='not measured')
            return 'web_discovery';
        $proved=0;
        foreach ($records as $row) {
            if (!is_array($row)) continue;
            if (!empty($row['rank_verified']) && !empty($row['ranking_proof']['provider_request_id'])) $proved++;
        }
        return $proved>0 ? 'verified_serp' : 'web_discovery';
    }
    public static function observation(array $input=[]) {
        if (!self::allowed()) return new WP_Error('mad4b_serp_read_denied','Read permission denied',['status'=>403]);
        $id=(string)($input['job_id']??'');
        if (!wp_is_uuid($id)) return new WP_Error('mad4b_serp_invalid_job','Valid job_id required',['status'=>400]);
        $job = MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$id]);
        if(is_wp_error($job))return $job;
        $read=MAD4B_SCP_Artifacts::list_artifacts(['job_id'=>$id,'artifact_type'=>'serp_research']);
        if(is_wp_error($read))return $read;
        $snapshots=[];
        foreach ($read['items']??[] as $artifact) {
            $p=$artifact['payload']??[];
            $results=[];
            foreach(array_slice($p['result_records']??[],0,100) as $item) {
                if(!is_array($item))continue;
                $url=esc_url_raw((string)($item['url']??''));
                $proof=$item['ranking_proof']??[];
                $rank=(!empty($item['rank_verified']) && !empty($proof['provider_request_id']))
                    ? ($item['rank']??null) : null;
                $results[]=[
                    'query'=>(string)($item['query']??($p['query']??'')),
                    'title'=>(string)($item['title']??''),
                    'url'=>$url,
                    'domain'=>(string)wp_parse_url($url,PHP_URL_HOST),
                    'snippet'=>(string)($item['snippet']??''),
                    'focus'=>(string)($item['focus']??''),
                    'rank'=>$rank,
                    'rank_verified'=>$rank!==null,
                    'search_volume'=>!empty($item['volume_proof']['provider_request_id'])?($item['search_volume']??null):null,
                ];
            }
            $collected=(string)($p['collected_at']??'');
            $captured_epoch=$collected!==''?strtotime($collected):false;
            $age_days=$captured_epoch!==false?max(0,(int)floor((time()-$captured_epoch)/DAY_IN_SECONDS)):null;
            $evidence_type=self::kind($p);
            $snapshots[]=['artifact_id'=>(string)$artifact['artifact_id'],'status'=>(string)$artifact['status'],
                'evidence_class'=>$evidence_type,
                'presentation_class'=>$artifact['status']==='active'?$evidence_type:'historical_snapshot',
                'age_days'=>$age_days,'freshness'=>$age_days===null?'unknown':($age_days>=(int)self::settings()['evidence']['max_age_days_warning']?'review_due':'current'),
                'device_location_assumptions'=>$p['device_location_assumptions']??[],
                'provider'=>(string)($p['provider_id']??''),
                'market'=>(string)($p['market']??''),'language'=>(string)($p['language']??''),
                'collected_at'=>(string)($p['collected_at']??''),'query'=>(string)($p['query']??''),
                'record_count'=>count($results),'results'=>$results,
                'rankings_verified'=>count(array_filter($results,static fn($x)=>$x['rank_verified'])),
                'snapshot_sha256'=>(string)($p['snapshot_sha256']??'')];
        }
        usort($snapshots,static fn($a,$b)=>strcmp($a['status']==='active'?'0':'1', $b['status']==='active'?'0':'1'));
        $policy=self::policy(['job_id'=>$id]);
        return ['contract'=>self::CONTRACT.'.observations.v1','job_id'=>$id,'brand_id'=>$job['job']['brand_id']??'',
            'policy_revision'=>$policy['effective']['revision']??1,'snapshots'=>$snapshots,
            'active_count'=>count(array_filter($snapshots,static fn($s)=>$s['status']==='active')),
            'history_is_read_only'=>true,'publication_authorized'=>false,'mutation_performed'=>false];
    }
    public static function appearance(array $input=[]) {
        if(!self::allowed())return new WP_Error('mad4b_serp_read_denied','Read permission denied',['status'=>403]);
        $id=absint($input['post_id']??0);
        $post=$id?get_post($id):null;
        if(!$post || !current_user_can('edit_post',$id))return new WP_Error('mad4b_serp_post_inaccessible','Accessible post_id required',['status'=>403]);
        $policy=self::policy(['language'=>substr((string)get_locale(),0,2),'content_type'=>$post->post_type]);
        $a=$policy['effective']['appearance'];
        $title=trim((string)get_post_meta($id,'rank_math_title',true));
        $description=trim((string)get_post_meta($id,'rank_math_description',true));
        $title_source=$title!==''?'rank_math':'wordpress_title';
        if($title==='') $title=get_the_title($id);
        $description_source=$description!==''?'rank_math':'excerpt';
        if($description==='') $description=has_excerpt($id)?get_the_excerpt($id):wp_trim_words(wp_strip_all_tags($post->post_content),26,'…');
        $title=wp_strip_all_tags($title);$description=wp_strip_all_tags($description);
        $title_chars=mb_strlen($title);$desc_chars=mb_strlen($description);
        $permalink=get_permalink($id);
        $schema=get_post_meta($id,'rank_math_rich_snippet',true);
        return ['contract'=>self::CONTRACT.'.appearance.v1','post_id'=>$id,'post_type'=>$post->post_type,
            'title'=>$title,'title_source'=>$title_source,'title_chars'=>$title_chars,
            'title_guidance'=>['min'=>$a['title_min'],'max'=>$a['title_max'],'within_range'=>$title_chars>=$a['title_min']&&$title_chars<=$a['title_max']],
            'description'=>$description,'description_source'=>$description_source,'description_chars'=>$desc_chars,
            'description_guidance'=>['min'=>$a['description_min'],'max'=>$a['description_max'],'within_range'=>$desc_chars>=$a['description_min']&&$desc_chars<=$a['description_max']],
            'permalink'=>$permalink,'schema_type'=>(string)$schema,
            'rank_math_active'=>function_exists('rank_math')||defined('RANK_MATH_VERSION'),
            'preview_modes'=>['mobile','desktop'],
            'search_engine_may_rewrite'=>true,'structured_data_does_not_guarantee_rich_results'=>true,
            'read_only'=>true,'mutation_performed'=>false];
    }
    public static function boot() {
        if(wp_get_environment_type()!=='staging')return;
        add_action('rest_api_init',[__CLASS__,'routes'],85);
        add_action('wp_abilities_api_init',[__CLASS__,'abilities'],85);
    }
    public static function routes() {
        $ns='mad4b/content-creation/v1';
        foreach (['serp-policy'=>'policy','serp-observation'=>'observation','serp-appearance'=>'appearance'] as $slug=>$method) {
            register_rest_route($ns,'/'.$slug,['methods'=>'GET','permission_callback'=>[__CLASS__,'allowed'],
                'callback'=>static function($request)use($method){return rest_ensure_response(self::$method($request->get_params()));}]);
        }
        register_rest_route($ns,'/serp-policy/override',['methods'=>'POST',
            'permission_callback'=>[__CLASS__,'can_write'],
            'callback'=>static function($request){return rest_ensure_response(self::save_override((array)$request->get_json_params()));}]);
    }
    public static function abilities() {
        if(!function_exists('wp_register_ability'))return;
        if(!function_exists('wp_has_ability') || !wp_has_ability('mad4b/serp-policy-override')) {
            wp_register_ability('mad4b/serp-policy-override',[
                'label'=>'Scoped SERP policy override','description'=>'Scoped appearance / presentation settings; evidence integrity cannot be disabled',
                'category'=>'mad4b-write','execute_callback'=>[__CLASS__,'save_override'],
                'permission_callback'=>[__CLASS__,'can_write'],
                'input_schema'=>['type'=>'object','properties'=>[
                    'job_id'=>['type'=>'string'],'expected_revision'=>['type'=>'integer'],
                    'language'=>['type'=>'string'],'content_type'=>['type'=>'string'],
                    'title_min'=>['type'=>'integer'],'title_max'=>['type'=>'integer'],
                    'description_min'=>['type'=>'integer'],'description_max'=>['type'=>'integer'],
                    'group_by_query'=>['type'=>'boolean'],'show_superseded_history'=>['type'=>'boolean']],
                    'required'=>['expected_revision'],'additionalProperties'=>false],
                'output_schema'=>['type'=>'object','additionalProperties'=>true],
                'meta'=>['public'=>false,'show_in_rest'=>false,
                    'mcp'=>['public'=>false,'type'=>'tool','surface'=>'write'],
                    'annotations'=>['readonly'=>false,'destructive'=>false,'idempotent'=>false]]]);
        }
        foreach (['mad4b/serp-policy'=>'policy','mad4b/serp-observation'=>'observation','mad4b/serp-appearance'=>'appearance'] as $name=>$method) {
            if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
            wp_register_ability($name,['label'=>$name,'description'=>'Read-only, source-aware SERP policy / review.',
                'category'=>'mad4b-read','execute_callback'=>[__CLASS__,$method],
                'permission_callback'=>[__CLASS__,'allowed'],
                'input_schema'=>['type'=>'object','properties'=>['job_id'=>['type'=>'string'],'post_id'=>['type'=>'integer'],'language'=>['type'=>'string'],'content_type'=>['type'=>'string']],'additionalProperties'=>false],
                'output_schema'=>['type'=>'object','additionalProperties'=>true],
                'meta'=>['public'=>false,'show_in_rest'=>false,'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
                    'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]]);
        }
    }
}
