<?php
defined('ABSPATH') || exit;

/** Central Staging-only SEO lifecycle. No LLM execution, publishing or indexation. */
final class MAD4B_SEO_Content_Lifecycle {
    const CONTRACT='mad4b.seo-content-lifecycle.v1';
    const FIELDS=['title'=>'rank_math_title','description'=>'rank_math_description',
        'focus_keyword'=>'rank_math_focus_keyword'];
    public static function boot() {
        if(wp_get_environment_type()!=='staging')return;
        add_action('wp_abilities_api_init',[__CLASS__,'abilities'],90);
        add_action('rest_api_init',[__CLASS__,'routes'],90);
    }
    public static function can_read() {
        return class_exists('MAD4B_Content_Creation_Control_Bridge') &&
            MAD4B_Content_Creation_Control_Bridge::can_read();
    }
    public static function can_write() {
        return self::can_read() && current_user_can('manage_options') &&
            MAD4B_Content_Creation_Control_Bridge::can_write() &&
            getenv('MAD4B_CENTRAL_HUB_ENABLED')==='1' &&
            home_url('/')==='https://tours.dev.mad4b.com/';
    }
    private static function error($code,$message='SEO cycle cannot continue') {
        return new WP_Error('mad4b_seo_cycle_'.$code,$message,['status'=>409]);
    }
    private static function text($s,$max=300) {
        $s=trim(wp_strip_all_tags((string)$s));
        return function_exists('mb_substr')?mb_substr($s,0,$max,'UTF-8'):substr($s,0,$max);
    }
    private static function length($s) {
        return function_exists('mb_strlen')?mb_strlen($s,'UTF-8'):strlen($s);
    }
    private static function hash($data) {
        return hash('sha256',wp_json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
    private static function target(array $input,$write=false) {
        if(!self::can_read())return self::error('access_denied');
        if(wp_get_environment_type()!=='staging' || home_url('/')!=='https://tours.dev.mad4b.com/')
            return self::error('site_scope');
        $kind=(string)($input['target_kind']??'');
        $id=absint($input['target_id']??0);
        if(!in_array($kind,['post','term'],true)||!$id)return self::error('target_invalid');
        $requested_lang=strtolower(trim((string)($input['language']??'')));
        $stored=[];
        $title='';$body='';$status='';$url='';$type='';$lang='';
        if($kind==='post'){
            $post=get_post($id);
            if(!$post || in_array($post->post_type,['revision','attachment'],true))return self::error('post_missing');
            if(!current_user_can($write?'edit_post':'read_post',$id))return self::error('post_capability');
            $type=$post->post_type;$status=$post->post_status;$title=get_the_title($id);
            $body=(string)$post->post_content;$url=(string)get_permalink($id);
            $lang=apply_filters('wpml_element_language_code',null,
                ['element_id'=>$id,'element_type'=>$type]);
        }else{
            $term=get_term($id);
            if(!$term || is_wp_error($term))return self::error('term_missing');
            $taxonomy=get_taxonomy($term->taxonomy);
            if(!$taxonomy)return self::error('taxonomy_missing');
            $cap=$write?$taxonomy->cap->edit_terms:$taxonomy->cap->manage_terms;
            if(!current_user_can($cap))return self::error('term_capability');
            $type=$term->taxonomy;$status='registered_term';$title=$term->name;
            $body=$term->description;$url=get_term_link($term);
            $lang=apply_filters('wpml_element_language_code',null,
                ['element_id'=>$id,'element_type'=>'tax_'.$term->taxonomy]);
        }
        if(is_wp_error($url))$url='';
        $lang=is_string($lang)?strtolower($lang):'';
        if($requested_lang!=='' && $lang!=='' && $requested_lang!==$lang)
            return self::error('language_mismatch');
        if($requested_lang!=='' && $lang==='' && defined('ICL_SITEPRESS_VERSION'))
            return self::error('language_unverified');
        $job_id=trim((string)($input['job_id']??''));
        $job_context=null;
        if($job_id!=='') {
            if(!function_exists('wp_is_uuid') || !wp_is_uuid($job_id) ||
                !class_exists('MAD4B_SCP_Content_Jobs'))return self::error('job_invalid');
            $j=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]);
            if(is_wp_error($j))return $j;
            $job_context=$j['job']??null;
            if(!is_array($job_context))return self::error('job_missing');
            $brand=MAD4B_SCP_Context_Authority::profile();
            if(($brand['brand_id']??'')!==($job_context['brand_id']??''))
                return self::error('brand_scope_mismatch');
            $job_lang=strtolower((string)($job_context['language']??''));
            if($job_lang!=='' && $lang!=='' && $job_lang!==$lang)
                return self::error('job_language_mismatch');
        }
        foreach(self::FIELDS as $field=>$key)
            $stored[$field]=$kind==='post'?get_post_meta($id,$key,true):get_term_meta($id,$key,true);
        $score=$kind==='post'?get_post_meta($id,'rank_math_seo_score',true):null;
        $data=['kind'=>$kind,'id'=>$id,'type'=>$type,'status'=>$status,
            'title'=>(string)$title,'content_sha256'=>hash('sha256',$body),
            'meta'=>$stored,'language'=>$lang];
        return ['contract'=>self::CONTRACT,'target_kind'=>$kind,'target_id'=>$id,
            'object_type'=>$type,'status'=>$status,'title'=>(string)$title,
            'source_job_id'=>$job_id,'source_job_content_type'=>$job_context['content_type']??null,
            'content'=>$body,'permalink'=>$url,'language'=>$lang,
            'language_verified'=>$lang!=='','seo_meta'=>$stored,
            'score'=>is_numeric($score)?(float)$score:null,
            'score_origin'=>'rank_math_saved_post_meta',
            'score_freshness'=>'not_verified_current',
            'sha256'=>self::hash($data),
            'is_elementor'=>$kind==='post'&&get_post_meta($id,'_elementor_edit_mode',true)==='builder',
            'is_blocks'=>$kind==='post'&&has_blocks($body)];
    }
    public static function plan($input=[]) {
        if(!is_array($input))return self::error('input_invalid');
        $target=self::target($input);
        if(is_wp_error($target))return $target;
        $keyword=self::text($target['seo_meta']['focus_keyword'],150);
        $text=wp_strip_all_tags(strip_shortcodes($target['content']));
        $checks=[
            'seo_title_present'=>trim((string)$target['seo_meta']['title'])!=='',
            'seo_description_present'=>trim((string)$target['seo_meta']['description'])!=='',
            'focus_keyword_present'=>$keyword!=='',
            'visible_content_present'=>trim($text)!=='',
            'title_keyword_match'=>$keyword!=='' && stripos($target['title'],$keyword)!==false,
            'description_keyword_match'=>$keyword!=='' &&
                stripos((string)$target['seo_meta']['description'],$keyword)!==false,
        ];
        $description=(string)$target['seo_meta']['description'];$seo_title=(string)$target['seo_meta']['title'];
        $checks['title_guidance_30_60']=self::length($seo_title)>=30&&self::length($seo_title)<=60;
        $checks['description_guidance_110_160']=self::length($description)>=110&&self::length($description)<=160;
        $suggestions=[];
        foreach($checks as $key=>$passed)if(!$passed)$suggestions[]=$key;
        return ['contract'=>self::CONTRACT.'.plan.v1','target'=>$target,
            'checks'=>$checks,'improvement_opportunities'=>$suggestions,
            'rank_math_score'=>$target['score'],'rank_math_score_freshness'=>'saved_value_unverified',
            'analysis_ability_available'=>function_exists('wp_has_ability')&&wp_has_ability('rank-math/analyze-post-content'),
            'required_evidence'=>['current WordPress object','focus keyword intent','Brand Context where available','supported product facts','language-specific URL'],
            'available_operations'=>['generate_suggestion','seo_meta_apply','draft_content_append','term_description_append'],
            'publication_authorized'=>false,'mutation_performed'=>false];
    }
    public static function generate($input=[]) {
        if(!is_array($input))return self::error('input_invalid');
        $p=self::plan($input);if(is_wp_error($p))return $p;
        $t=$p['target'];
        $keyword=self::text((string)($input['focus_keyword']??$t['seo_meta']['focus_keyword']),150);
        if($keyword==='')$keyword=self::text($t['title'],120);
        $title=self::text($t['title'],90);
        // Rank Math resolves %sitename% from WordPress Settings > General > Site Title.
        // Store the variable, not a hard-coded company name or stale resolved value.
        $site=self::text(get_bloginfo('name'),90);
        $generated_title=self::text($title,60);
        if($site!=='' && stripos($title,$site)===false && stripos($title,'%sitename%')===false
           && self::length($title)<54)
            $generated_title.=' | %sitename%';
        $plain=self::text(strip_shortcodes($t['content']),210);
        if($plain==='')$plain=$title;
        $description=self::text($plain,155);
        return ['contract'=>self::CONTRACT.'.suggestion.v1',
            'target_kind'=>$t['target_kind'],'target_id'=>$t['target_id'],
            'expected_sha256'=>$t['sha256'],
            'fields'=>['title'=>$generated_title,'description'=>$description,'focus_keyword'=>$keyword],
            'site_name'=>['source'=>'wordpress_site_title','rank_math_variable'=>'%sitename%',
                'current_preview_value'=>$site,
                'title_preview'=>str_replace('%sitename%',$site,$generated_title),
                'is_dynamic'=>str_contains($generated_title,'%sitename%')],
            'source_basis'=>'stored_first_party_title_and_content_only',
            'generation_mode'=>'deterministic_baseline_not_llm',
            'needs_editorial_review'=>true,
            'rank_math_score_before'=>$t['score'],'rank_math_score_after'=>null,
            'wording_may_need_brand_alignment'=>true,
            'published_content_mutation_authorized'=>false,'mutation_performed'=>false];
    }
    public static function apply($input=[]) {
        if(!is_array($input) || !self::can_write())return self::error('write_denied');
        $target=self::target($input,true);if(is_wp_error($target))return $target;
        if($target['source_job_content_type']==='research_observation')
            return self::error('research_job_not_content_mutation_authority');
        $expected=strtolower(trim((string)($input['expected_sha256']??'')));
        if(!preg_match('/^[a-f0-9]{64}$/',$expected)||!hash_equals($target['sha256'],$expected))
            return self::error('stale_target');
        $mode=(string)($input['mode']??'seo_meta');
        $fields=$input['fields']??[];
        if($mode==='seo_meta'){
            if(!is_array($fields)||!$fields || array_diff(array_keys($fields),array_keys(self::FIELDS)))
                return self::error('field_not_allowed');
            $clean=[];
            foreach($fields as $key=>$value) {
                if(!is_string($value) || self::length($value)>500)return self::error('value_invalid');
                $clean[$key]=sanitize_text_field($value);
            }
            if($target['target_kind']==='post'){
                // Route all post SEO writes through the certified, reversible native adapter.
                if(!function_exists('wp_get_ability')||!wp_has_ability('seo/get-meta')||!wp_has_ability('seo/update-meta'))
                    return self::error('native_seo_adapter_unavailable');
                $get=wp_get_ability('seo/get-meta');$update=wp_get_ability('seo/update-meta');
                $current=$get->execute(['post_id'=>$target['target_id']]);
                if(is_wp_error($current)||empty($current['sha256']))return self::error('native_read_failed');
                $result=$update->execute(['post_id'=>$target['target_id'],
                    'fields'=>$clean,'expected_sha256'=>$current['sha256']]);
                if(is_wp_error($result))return $result;
            }else{
                // Term metadata has no certified native write adapter yet: staged,
                // exact-term guarded commit with readback and local compensation.
                $prior=[];
                foreach($clean as $field=>$value){
                    $key=self::FIELDS[$field];
                    $prior[$key]=['exists'=>metadata_exists('term',$target['target_id'],$key),
                        'value'=>get_term_meta($target['target_id'],$key,true)];
                }
                $failed=false;
                foreach($clean as $field=>$value){
                    $key=self::FIELDS[$field];
                    if(get_term_meta($target['target_id'],$key,true)===$value)continue;
                    $written=update_term_meta($target['target_id'],$key,$value);
                    if(is_wp_error($written)||$written===false){$failed=true;break;}
                }
                if(!$failed)foreach($clean as $field=>$value)
                    if(get_term_meta($target['target_id'],self::FIELDS[$field],true)!==$value){$failed=true;break;}
                if($failed){
                    foreach($prior as $key=>$before) {
                        if($before['exists'])update_term_meta($target['target_id'],$key,$before['value']);
                        else delete_term_meta($target['target_id'],$key);
                    }
                    return self::error('term_readback_failed_rollback_attempted');
                }
            }
        }elseif($mode==='append_content'){
            if(!empty($fields))return self::error('mixed_write_denied');
            $append=trim((string)($input['content_append']??''));
            if($append===''||strlen($append)>5000)return self::error('content_invalid');
            if($target['target_kind']==='post'){
                if($target['status']!=='draft' || $target['is_elementor'] || $target['is_blocks'])
                    return self::error('content_structure_or_publication_guard');
                $body=$target['content']."\n".wp_kses_post($append);
                $saved=wp_update_post(['ID'=>$target['target_id'],'post_content'=>$body],true);
                if(is_wp_error($saved)||!$saved)return self::error('draft_update_failed');
            }else{
                // Term descriptions are published archive content, deliberately
                // not silently edited by a generic cycle. Use per-term review.
                return self::error('term_description_review_required');
            }
        }else return self::error('mode_invalid');
        $after=self::target($input,true);
        if(is_wp_error($after))return self::error('readback_failed');
        if($after['sha256']===$target['sha256'])
            return ['contract'=>self::CONTRACT.'.apply.v1','target_kind'=>$target['target_kind'],
                'target_id'=>$target['target_id'],'mode'=>$mode,
                'before_sha256'=>$target['sha256'],'after_sha256'=>$after['sha256'],
                'no_change'=>true,'mutation_performed'=>false,'publication_performed'=>false];
        if(class_exists('MAD4B_SCP_Audit'))MAD4B_SCP_Audit::record(
            'mad4b/seo-cycle-apply',
            ['target_kind'=>$target['target_kind'],'target_id'=>$target['target_id'],
                'mode'=>$mode,'before_sha256'=>$target['sha256'],'after_sha256'=>$after['sha256']],
            'success',true
        );
        return ['contract'=>self::CONTRACT.'.apply.v1','target_kind'=>$target['target_kind'],
            'target_id'=>$target['target_id'],'mode'=>$mode,'before_sha256'=>$target['sha256'],
            'after_sha256'=>$after['sha256'],'changed_fields'=>$mode==='seo_meta'?array_keys($clean):['content'],
            'rank_math_score_recomputed'=>false,'publication_performed'=>false,
            'mutation_performed'=>true,'verify_next'=>'mad4b/seo-cycle-verify'];
    }
    public static function verify($input=[]) {
        if(!is_array($input))return self::error('input_invalid');
        $p=self::plan($input);if(is_wp_error($p))return $p;
        $expected=strtolower((string)($input['expected_sha256']??''));
        $fields=$input['fields']??[];
        $matching=[];
        if(is_array($fields))foreach($fields as $name=>$value)
            if(isset(self::FIELDS[$name]))$matching[$name]=((string)$p['target']['seo_meta'][$name]===(string)$value);
        $all_match=!in_array(false,$matching,true);
        return ['contract'=>self::CONTRACT.'.verify.v1',
            'target_kind'=>$p['target']['target_kind'],'target_id'=>$p['target']['target_id'],
            'sha256'=>$p['target']['sha256'],
            'expected_revision_matches'=>$expected!==''&&hash_equals($p['target']['sha256'],$expected),
            'field_readback'=>$matching,'all_requested_fields_match'=>$all_match,
            'rank_math_score'=>$p['rank_math_score'],
            'rank_math_score_recomputed'=>false,'rank_math_score_freshness'=>'unverified_cached_value',
            'rendered_html_checked'=>false,'indexation_checked'=>false,
            'requires_rendered_acceptance'=>true,'publication_performed'=>false,
            'mutation_performed'=>false];
    }
    public static function evaluate($input=[]) {
        if(!is_array($input))return self::error('input_invalid');
        $p=self::plan($input);if(is_wp_error($p))return $p;
        $t=$p['target'];
        $post_id=(int)$t['target_id'];
        $base=['contract'=>self::CONTRACT.'.evaluation.v1',
            'target_kind'=>$t['target_kind'],'target_id'=>$post_id,
            'rank_math_cached_score'=>$p['rank_math_score'],
            'cached_score_freshness'=>'unknown',
            'rank_math_score_recomputed'=>false,
            'source'=>'rank_math_native_content_analysis_data',
            'test_outcomes_by_plugin'=>null,
            'requires_editor_client_scoring'=>true,
            'object_sha256'=>$t['sha256'],'mutation_performed'=>false];
        if($t['target_kind']!=='post') {
            $base['source']='taxonomy_meta_diagnostics_only';
            $base['diagnostic_checks']=$p['checks'];
            $base['native_analysis_available']=false;
            return $base;
        }
        $class='RankMath\\Abilities\\Content_Analysis\\Content_Analysis_Data';
        if(!class_exists($class)||!current_user_can('rank_math_onpage_analysis')) {
            $base['native_analysis_available']=false;
            $base['diagnostic_checks']=$p['checks'];
            return $base;
        }
        $post=get_post($post_id);
        if(!$post||!current_user_can('edit_post',$post_id))return self::error('rank_math_analysis_permission');
        $keyword=self::text($t['seo_meta']['focus_keyword']??'',150);
        $data=(new $class())->get($post,$keyword);
        $content=$data['content']??[];
        $images=$content['images']??[];
        $base['native_analysis_available']=true;
        $base['native_test_count']=count($data['tests_to_run']??[]);
        $base['native_test_ids']=$data['tests_to_run']??[];
        $base['native_content_facts']=[
            'word_count'=>(int)($content['word_count']??0),
            'heading_count'=>count((array)($content['headings']??[])),
            'image_count'=>count((array)$images),
            'link_count'=>count((array)($content['links']??[])),
            'keyword'=>$keyword,
        ];
        $base['diagnostic_checks']=$p['checks'];
        $base['source_disclosure']='Native Rank Math extracts facts/test IDs; it does not calculate a new score in PHP. Run Rank Math editor scoring and save it for a new certified score.';
        return $base;
    }
    public static function routes() {
        foreach(['seo-cycle-plan'=>['plan',false],'seo-cycle-generate'=>['generate',false],
            'seo-cycle-apply'=>['apply',true],'seo-cycle-verify'=>['verify',false],
            'seo-cycle-evaluate'=>['evaluate',false]] as $route=>$row)
            register_rest_route('mad4b/content-creation/v1','/'.$route,[
                'methods'=>$row[1]?'POST':'GET',
                'permission_callback'=>[__CLASS__,$row[1]?'can_write':'can_read'],
                'callback'=>static function($req)use($row){
                    $args=$row[1]?(array)$req->get_json_params():$req->get_params();
                    return rest_ensure_response(call_user_func([__CLASS__,$row[0]],$args));
                }]);
    }
    public static function abilities() {
        if(!function_exists('wp_register_ability'))return;
        foreach(['mad4b/seo-cycle-plan'=>['plan',true],
            'mad4b/seo-cycle-generate'=>['generate',true],
            'mad4b/seo-cycle-apply'=>['apply',false],
            'mad4b/seo-cycle-verify'=>['verify',true],
            'mad4b/seo-cycle-evaluate'=>['evaluate',true]] as $name=>$row){
            if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
            $props=['target_kind'=>['type'=>'string','enum'=>['post','term']],
                'target_id'=>['type'=>'integer','minimum'=>1],
                'job_id'=>['type'=>'string'],
                'language'=>['type'=>'string'],'focus_keyword'=>['type'=>'string'],
                'expected_sha256'=>['type'=>'string'],'mode'=>['type'=>'string'],
                'fields'=>['type'=>'object'],'content_append'=>['type'=>'string']];
            wp_register_ability($name,['label'=>$name,
                'description'=>'Staging-only Rank Math SEO lifecycle; native governed post SEO writes.',
                'category'=>$row[1]?'mad4b-read':'mad4b-write',
                'execute_callback'=>[__CLASS__,$row[0]],
                'permission_callback'=>[__CLASS__,$row[1]?'can_read':'can_write'],
                'input_schema'=>['type'=>'object','properties'=>$props,
                    'required'=>['target_kind','target_id'],'additionalProperties'=>false],
                'output_schema'=>['type'=>'object','additionalProperties'=>true],
                'meta'=>['public'=>false,'show_in_rest'=>false,
                    'mcp'=>['public'=>false,'type'=>'tool','surface'=>$row[1]?'read':'write'],
                    'annotations'=>['readonly'=>$row[1],'destructive'=>!$row[1],
                        'idempotent'=>$row[1]]]]);
        }
    }
}
