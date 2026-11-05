<?php
defined('ABSPATH') || exit;

/** Image SEO: first-party observations, governed Media adapter, contextual draft ALT. */
final class MAD4B_SEO_Image_Lifecycle {
    const CONTRACT='mad4b.seo-image-lifecycle.v1';
    const USAGE_META='_mad4b_seo_image_usage_v1';
    const LIMIT=35;
    public static function boot(){
        if(wp_get_environment_type()!=='staging')return;
        add_action('wp_abilities_api_init',[__CLASS__,'abilities'],91);
        add_action('rest_api_init',[__CLASS__,'routes'],91);
        add_filter('wp_get_attachment_image_attributes',[__CLASS__,'render_attributes'],40,3);
    }
    private static function err($key){return new WP_Error('mad4b_seo_image_'.$key,'Image SEO operation denied',['status'=>409]);}
    private static function hash($x){return hash('sha256',wp_json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
    private static function text($v,$limit=500){
        $v=trim(wp_strip_all_tags((string)$v));
        return function_exists('mb_substr')?mb_substr($v,0,$limit,'UTF-8'):substr($v,0,$limit);
    }
    public static function can_read(){return class_exists('MAD4B_SEO_Content_Lifecycle')&&MAD4B_SEO_Content_Lifecycle::can_read();}
    public static function can_write(){return self::can_read()&&MAD4B_SEO_Content_Lifecycle::can_write();}
    private static function object($input,$write=false){
        if(!is_array($input)||!self::can_read())return self::err('access_denied');
        if(wp_get_environment_type()!=='staging'||home_url('/')!=='https://tours.dev.mad4b.com/')return self::err('staging_only');
        $kind=(string)($input['target_kind']??'');
        $id=absint($input['target_id']??0);
        if($kind==='post'){
            $post=$id?get_post($id):null;
            if(!$post||in_array($post->post_type,['attachment','revision'],true))return self::err('post_invalid');
            if(!current_user_can($write?'edit_post':'read_post',$id))return self::err('post_permission');
            $title=get_the_title($id);$content=(string)$post->post_content;
            $type=$post->post_type;$status=$post->post_status;
            $lang=apply_filters('wpml_element_language_code',null,['element_id'=>$id,'element_type'=>$type]);
        }elseif($kind==='term'){
            $term=$id?get_term($id):null;
            if(!$term||is_wp_error($term))return self::err('term_invalid');
            $tax=get_taxonomy($term->taxonomy);
            if(!$tax||!current_user_can($write?$tax->cap->edit_terms:$tax->cap->manage_terms))return self::err('term_permission');
            $title=$term->name;$content=$term->description;$type=$term->taxonomy;$status='registered_term';
            $lang=apply_filters('wpml_element_language_code',null,['element_id'=>$id,'element_type'=>'tax_'.$type]);
        }else return self::err('target_kind_invalid');
        $lang=is_string($lang)?strtolower($lang):'';
        $required_lang=strtolower(trim((string)($input['language']??'')));
        if($required_lang!==''&&($lang===''||$required_lang!==$lang))return self::err('language_mismatch');
        $job_id=(string)($input['job_id']??'');
        if($job_id!==''){
            if(!wp_is_uuid($job_id))return self::err('job_invalid');
            $job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]);
            if(is_wp_error($job))return $job;
            $data=$job['job']??[];
            $profile=MAD4B_SCP_Context_Authority::profile();
            if(($data['brand_id']??'')!==($profile['brand_id']??''))return self::err('brand_mismatch');
            if(!empty($data['language'])&&$lang!==strtolower((string)$data['language']))
                return self::err('job_language_mismatch');
            if($write&&($data['content_type']??'')==='research_observation')
                return self::err('research_not_write_authority');
        }
        return ['kind'=>$kind,'id'=>$id,'type'=>$type,'status'=>$status,'title'=>$title,
            'content'=>$content,'language'=>$lang,'source_job_id'=>$job_id];
    }
    private static function references($o){
        $ids=[];
        $put=static function($id,$source)use(&$ids){
            $id=absint($id);
            if($id>0&&!isset($ids[$id])&&wp_attachment_is_image($id)&&count($ids)<self::LIMIT)
                $ids[$id]=$source;
        };
        if($o['kind']==='post'){
            $id=$o['id'];
            $put(get_post_thumbnail_id($id),'featured');
            if(preg_match_all('/wp-image-([0-9]+)/',$o['content'],$m))
                foreach($m[1] as $attachment)$put($attachment,'content_html');
            if(preg_match_all('/<!--\s*wp:image\s+(\{[^}]+\})/',$o['content'],$matches))
                foreach($matches[1] as $json){$row=json_decode($json,true);if(is_array($row))$put($row['id']??0,'wordpress_block');}
            // Only known reference fields. URL->attachment inference is intentionally prohibited.
            foreach(['_product_image_gallery','tour_gallery','_tour_gallery','prop_gallery','gallery','tour_images'] as $key){
                $v=get_post_meta($id,$key,true);
                if(is_string($v)&&preg_match('/^[\d,\s]+$/',$v))
                    foreach(explode(',',$v) as $candidate)$put($candidate,'gallery:'.$key);
                elseif(is_array($v))foreach(array_slice($v,0,self::LIMIT) as $item)
                    $put(is_array($item)?($item['id']??$item['attachment_id']??0):$item,'gallery:'.$key);
            }
            // Only explicitly marked Image widgets, no arbitrary Elementor JSON IDs.
            $json=get_post_meta($id,'_elementor_data',true);
            if(is_string($json)&&strlen($json)<500000&&$json!==''){
                $tree=json_decode($json,true);
                if(is_array($tree)){
                    $stack=[$tree];$seen=0;
                    while($stack&&$seen++<350&&count($ids)<self::LIMIT){
                        $nodes=array_pop($stack);
                        if(!is_array($nodes))continue;
                        foreach($nodes as $node){
                            if(!is_array($node))continue;
                            $widget=(string)($node['widgetType']??'');
                            if(in_array($widget,['image','image-box','image-carousel','media-carousel'],true)){
                                $settings=$node['settings']??[];
                                if(is_array($settings)){
                                    $put($settings['image']['id']??0,'elementor');
                                    foreach((array)($settings['carousel']??[]) as $image)
                                        if(is_array($image))$put($image['id']??0,'elementor_carousel');
                                }
                            }
                            if(isset($node['elements'])&&is_array($node['elements']))$stack[]=$node['elements'];
                        }
                    }
                }
            }
        }else{
            foreach(['thumbnail_id','featured_image','image'] as $key){
                $value=get_term_meta($o['id'],$key,true);
                $put(is_array($value)?($value['id']??0):$value,'term:'.$key);
            }
        }
        return $ids;
    }
    private static function usage($o){$x=get_post_meta($o['id'],self::USAGE_META,true);return $o['kind']==='post'&&is_array($x)?$x:[];}
    private static function attachment($id){
        $p=get_post($id);if(!$p||$p->post_type!=='attachment'||!wp_attachment_is_image($id))return self::err('attachment_invalid');
        if(!current_user_can('read_post',$id))return self::err('attachment_permission');
        $data=['id'=>$id,'title'=>$p->post_title,'caption'=>$p->post_excerpt,
            'description'=>$p->post_content,'alt'=>(string)get_post_meta($id,'_wp_attachment_image_alt',true),
            'url'=>wp_get_attachment_url($id),'mime'=>$p->post_mime];
        return $data;
    }
    private static function resolve($value){
        return str_replace('{{site_name}}',self::text(get_bloginfo('name'),120),(string)$value);
    }
    public static function plan($input=[]){
        $o=self::object($input);if(is_wp_error($o))return $o;
        $refs=self::references($o);$overrides=self::usage($o);$rows=[];
        foreach($refs as $id=>$source){
            $a=self::attachment($id);if(is_wp_error($a))continue;
            $record=$overrides[$id]??[];
            $stored=is_array($record)?$record:[];
            $alt=isset($stored['alt_template'])?self::resolve($stored['alt_template']):$a['alt'];
            $issues=[];
            if(trim($alt)==='')$issues[]='missing_alt_requires_visual_review';
            if(trim($a['title'])==='')$issues[]='missing_media_title';
            if(trim($a['caption'])==='')$issues[]='missing_caption_optional';
            if(trim($a['description'])==='')$issues[]='missing_media_description_optional';
            $rows[]=['attachment_id'=>$id,'source'=>$source,'media'=>$a,
                'context_alt_template'=>$stored['alt_template']??null,
                'effective_alt_preview'=>$alt,'issues'=>$issues,
                'media_source_sha256'=>self::hash($a)];
        }
        $state=['target'=>$o['kind'].':'.$o['id'],'language'=>$o['language'],'images'=>$rows,'usage'=>$overrides];
        return ['contract'=>self::CONTRACT.'.plan.v1',
            'target'=>array_diff_key($o,array_flip(['content'])),
            'site_name'=>['source'=>'wordpress_site_title','preview'=>get_bloginfo('name'),
                'rank_math_title_variable'=>'%sitename%','image_usage_template'=>'{{site_name}}'],
            'image_count'=>count($rows),'images'=>$rows,
            'source_sha256'=>self::hash($state),
            'discovery_note'=>'Featured, post/block HTML, known JetEngine gallery fields, and supported Elementor image widgets; custom renderers need separate integration.',
            'global_media_write_ability'=>'media/update-metadata',
            'contextual_alt_scope'=>'per_post_usage_not_attachment',
            'rank_math_image_score_recomputed'=>false,'rendered_html_checked'=>false,
            'publication_performed'=>false,'mutation_performed'=>false];
    }
    public static function generate($input=[]){
        $plan=self::plan($input);if(is_wp_error($plan))return $plan;
        $suggestions=[];
        foreach($plan['images'] as $row){
            $is_logo=!empty($input['logo_attachment_id'])&&(int)$input['logo_attachment_id']===$row['attachment_id'];
            $alt=$is_logo?'{{site_name}} logo':$row['effective_alt_preview'];
            $suggestions[]=['attachment_id'=>$row['attachment_id'],'source'=>$row['source'],
                'context_alt_template'=>$alt,
                'preview_alt'=>self::resolve($alt),
                'media_title'=>$row['media']['title'],
                'media_caption'=>$row['media']['caption'],
                'media_description'=>$row['media']['description'],
                'alt_source'=>$is_logo?'explicit_logo_role_plus_wordpress_site_title':($alt!==''?'stored_description':'missing'),
                'visual_accuracy_confirmed'=>false,'review_required'=>true];
        }
        return ['contract'=>self::CONTRACT.'.suggestion.v1',
            'target_kind'=>$plan['target']['kind'],'target_id'=>$plan['target']['id'],
            'expected_sha256'=>$plan['source_sha256'],'suggestions'=>$suggestions,
            'no_visual_content_inferred'=>true,'company_brand_variable'=>'{{site_name}}',
            'publication_performed'=>false,'mutation_performed'=>false];
    }
    public static function apply($input=[]){
        if(!self::can_write()||!is_array($input))return self::err('write_denied');
        $o=self::object($input,true);if(is_wp_error($o))return $o;
        $p=self::plan($input);if(is_wp_error($p))return $p;
        $expected=(string)($input['expected_sha256']??'');
        if(!preg_match('/^[a-f0-9]{64}$/D',$expected)||!hash_equals($p['source_sha256'],$expected))
            return self::err('stale_snapshot');
        $id=absint($input['attachment_id']??0);
        $images=array_column($p['images'],null,'attachment_id');
        if(!isset($images[$id]))return self::err('image_not_in_target');
        $mode=(string)($input['mode']??'');
        if($mode==='context_alt'){
            // A draft may be reviewed before publication; no live page changes.
            if($o['kind']!=='post'||$o['status']!=='draft')return self::err('context_requires_draft');
            $alt=(string)($input['alt_template']??'');
            if(!is_string($input['alt_template']??null)||strlen($alt)>500||
                preg_match('/\{\{(?!site_name\}\})/', $alt))return self::err('alt_template_invalid');
            if(empty($input['visual_confirmed']))return self::err('visual_confirmation_required');
            if(trim(self::resolve($alt))===''&&empty($input['decorative']))
                return self::err('empty_alt_requires_decorative');
            $values=self::usage($o);
            $values[$id]=['alt_template'=>sanitize_text_field($alt),
                'decorative'=>!empty($input['decorative']),'source'=>'reviewed_image_usage'];
            $before=self::usage($o);
            if($before===$values){
                return ['contract'=>self::CONTRACT.'.apply.v1','mode'=>$mode,
                    'attachment_id'=>$id,'no_change'=>true,
                    'global_media_metadata_changed'=>false,'publication_performed'=>false,
                    'mutation_performed'=>false];
            }
            if(!update_post_meta($o['id'],self::USAGE_META,$values))
                return self::err('usage_write_failed');
            $read=get_post_meta($o['id'],self::USAGE_META,true);
            if(!is_array($read)||($read[$id]['alt_template']??null)!==$values[$id]['alt_template']){
                // Exact post-scoped compensation. Never touch the shared attachment.
                if($before)update_post_meta($o['id'],self::USAGE_META,$before);
                else delete_post_meta($o['id'],self::USAGE_META);
                return self::err('usage_readback_failed_rollback_attempted');
            }
            $after=self::plan($input);if(is_wp_error($after))return $after;
            if(class_exists('MAD4B_SCP_Audit'))MAD4B_SCP_Audit::record(
                'mad4b/seo-image-context-alt',
                ['post_id'=>$o['id'],'attachment_id'=>$id,'before_sha256'=>$expected,'after_sha256'=>$after['source_sha256']]);
            return ['contract'=>self::CONTRACT.'.apply.v1','mode'=>$mode,'attachment_id'=>$id,
                'before_sha256'=>$expected,'after_sha256'=>$after['source_sha256'],
                'effective_alt'=>self::resolve($values[$id]['alt_template']),
                'global_media_metadata_changed'=>false,'publication_performed'=>false,'mutation_performed'=>true];
        }
        if($mode==='global_media'){
            // Native Media adapter owns Title/Caption/Description/Alt across usages.
            if(empty($input['visual_confirmed']))return self::err('visual_confirmation_required');
            if(!function_exists('wp_get_ability')||!wp_has_ability('media/get')||
                !wp_has_ability('media/update-metadata'))return self::err('media_adapter_unavailable');
            $fields=$input['fields']??null;
            if(!is_array($fields)||!$fields||array_diff(array_keys($fields),['title','caption','description','alt']))
                return self::err('fields_invalid');
            foreach($fields as $k=>$v)if(!is_string($v)||strlen($v)>2048)return self::err('value_invalid');
            $current=wp_get_ability('media/get')->execute(['attachment_id'=>$id]);
            if(is_wp_error($current)||empty($current['sha256']))return self::err('native_read_denied');
            $r=wp_get_ability('media/update-metadata')->execute(
                array_merge(['attachment_id'=>$id,'expected_sha256'=>$current['sha256']],$fields));
            if(is_wp_error($r))return $r;
            return ['contract'=>self::CONTRACT.'.apply.v1','mode'=>$mode,'attachment_id'=>$id,
                'media_result'=>$r,'publication_performed'=>false,'mutation_performed'=>true];
        }
        return self::err('mode_invalid');
    }
    public static function verify($input=[]){
        $p=self::plan($input);if(is_wp_error($p))return $p;
        $id=absint($input['attachment_id']??0);
        $rows=array_column($p['images'],null,'attachment_id');
        if(!isset($rows[$id]))return self::err('image_not_in_target');
        $expected=(string)($input['expected_sha256']??'');
        return ['contract'=>self::CONTRACT.'.verify.v1','attachment_id'=>$id,
            'target_kind'=>$p['target']['kind'],'target_id'=>$p['target']['id'],
            'effective_alt_preview'=>$rows[$id]['effective_alt_preview'],
            'context_template'=>$rows[$id]['context_alt_template'],
            'expected_sha256_matches'=>$expected!==''&&hash_equals($p['source_sha256'],$expected),
            'global_attachment_alt'=>$rows[$id]['media']['alt'],
            'runtime_filter_supported'=>'wp_get_attachment_image_attributes',
            'raw_html_and_elementor_rendered_coverage_unverified'=>true,
            'rank_math_image_score_recomputed'=>false,
            'publication_performed'=>false,'mutation_performed'=>false];
    }
    public static function render_attributes($attr,$attachment,$size){
        if(!is_array($attr)||!is_object($attachment)||!is_singular())return $attr;
        $post_id=(int)get_queried_object_id();if(!$post_id)return $attr;
        $map=get_post_meta($post_id,self::USAGE_META,true);
        $id=(int)($attachment->ID??0);
        if(!is_array($map)||!isset($map[$id]['alt_template']))return $attr;
        $attr['alt']=self::resolve($map[$id]['alt_template']);
        return $attr;
    }
    public static function routes(){
        foreach(['seo-image-plan'=>['plan',false],'seo-image-generate'=>['generate',false],
            'seo-image-apply'=>['apply',true],'seo-image-verify'=>['verify',false]] as $route=>$row)
            register_rest_route('mad4b/content-creation/v1','/'.$route,[
                'methods'=>$row[1]?'POST':'GET',
                'permission_callback'=>[__CLASS__,$row[1]?'can_write':'can_read'],
                'callback'=>static function($req)use($row){
                    $data=$row[1]?(array)$req->get_json_params():$req->get_params();
                    return rest_ensure_response(call_user_func([__CLASS__,$row[0]],$data));
                }]);
    }
    public static function abilities(){
        if(!function_exists('wp_register_ability'))return;
        foreach(['mad4b/seo-image-plan'=>['plan',true],
            'mad4b/seo-image-generate'=>['generate',true],
            'mad4b/seo-image-apply'=>['apply',false],
            'mad4b/seo-image-verify'=>['verify',true]] as $name=>$row){
            if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
            wp_register_ability($name,['label'=>$name,
                'description'=>'SEO image usage and metadata, preserve native media ownership.',
                'category'=>$row[1]?'mad4b-read':'mad4b-write',
                'execute_callback'=>[__CLASS__,$row[0]],
                'permission_callback'=>[__CLASS__,$row[1]?'can_read':'can_write'],
                'input_schema'=>['type'=>'object','properties'=>[
                    'target_kind'=>['type'=>'string','enum'=>['post','term']],
                    'target_id'=>['type'=>'integer'],'job_id'=>['type'=>'string'],
                    'language'=>['type'=>'string'],'logo_attachment_id'=>['type'=>'integer'],
                    'attachment_id'=>['type'=>'integer'],'mode'=>['type'=>'string'],
                    'expected_sha256'=>['type'=>'string'],'visual_confirmed'=>['type'=>'boolean'],
                    'alt_template'=>['type'=>'string'],'decorative'=>['type'=>'boolean'],
                    'fields'=>['type'=>'object']],
                    'required'=>['target_kind','target_id'],'additionalProperties'=>false],
                'output_schema'=>['type'=>'object','additionalProperties'=>true],
                'meta'=>['public'=>false,'show_in_rest'=>false,
                    'mcp'=>['public'=>false,'type'=>'tool','surface'=>$row[1]?'read':'write'],
                    'annotations'=>['readonly'=>$row[1],'destructive'=>!$row[1],
                        'idempotent'=>$row[1]]]]);
        }
    }
}
