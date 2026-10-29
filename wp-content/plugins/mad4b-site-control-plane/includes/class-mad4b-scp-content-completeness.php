<?php
/**
 * Site-neutral Content Completeness & SEO Readiness.
 * Immutable source scans; exact-field rules, per-language evidence, no content writes.
 */
defined('ABSPATH') || exit;

final class MAD4B_SCP_Content_Completeness {
    const OPTION = 'mad4b_content_completeness_rules_v1';
    const PAGE = 'mad4b-content-completeness';
    const NS = 'mad4b-content-readiness/v1';
    const MAX_RULES = 60;
    const MAX_POSTS = 1200;
    const MAX_TERMS = 1200;
    const TYPES = array('post_title','post_content','post_excerpt','elementor_text','featured_image',
        'meta_text','meta_image','repeater_rows','image_alt','h1','seo_title','seo_description',
        'seo_og_title','seo_og_description','seo_canonical',
        'term_description','term_meta_text','term_meta_image');
    public static function boot() {
        add_action('admin_menu',array(__CLASS__,'menu'),33);
        add_action('admin_post_mad4b_completeness_save',array(__CLASS__,'save'));
        add_action('rest_api_init',array(__CLASS__,'routes'));
    }
    public static function settings() {
        $stored=(array)get_option(self::OPTION,array());
        return array('enabled'=>!empty($stored['enabled']),
            'exclude_qa'=>!empty($stored['exclude_qa']),
            'rules'=>is_array($stored['rules']??null)?$stored['rules']:array());
    }
    private static function read_permission() {return current_user_can('edit_others_posts');}
    public static function admin_permission() {return MAD4B_SCP_Readiness_Integrations::can_manage();}
    private static function fail($code,$msg,$status=400) {
        return new WP_Error($code,$msg,array('status'=>$status));
    }
    public static function normalize($input) {
        if(!is_array($input) || array_diff(array_keys($input),array('enabled','exclude_qa','rules')))
            return self::fail('ccr_settings_invalid','Unknown settings key.',422);
        if(!is_bool($input['enabled']??null) || !is_bool($input['exclude_qa']??null) ||
           !is_array($input['rules']??null) || count($input['rules'])>self::MAX_RULES)
            return self::fail('ccr_settings_invalid','Expected booleans and at most 60 rules.',422);
        $normalized=array();$seen=array();
        foreach($input['rules'] as $rule) {
            if(!is_array($rule) || array_diff(array_keys($rule),array(
                'id','target','post_type','taxonomy','field','meta_key','language',
                'min_chars','severity','inherit_from_meta','inherit_post_type','conditional_meta','conditional_value','fallback_meta_keys')))
                return self::fail('ccr_rule_unknown_field','Unknown completeness rule parameter.',422);
            $id=sanitize_key((string)($rule['id']??''));
            if(!preg_match('/^[a-z][a-z0-9_]{2,39}$/D',$id)||isset($seen[$id]))
                return self::fail('ccr_rule_identifier','A rule ID must be unique and stable.',422);
            $seen[$id]=true;
            $target=sanitize_key((string)($rule['target']??'post'));
            $field=sanitize_key((string)($rule['field']??''));
            $type=sanitize_key((string)($rule['post_type']??''));
            $taxonomy=sanitize_key((string)($rule['taxonomy']??''));
            $meta=sanitize_key((string)($rule['meta_key']??''));
            $language=strtolower(trim((string)($rule['language']??'*')));
            $severity=sanitize_key((string)($rule['severity']??'medium'));
            $min=$rule['min_chars']??1;
            if(!in_array($target,array('post','term'),true) || !in_array($field,self::TYPES,true) ||
               !in_array($severity,array('low','medium','high'),true) ||
               !preg_match('/^(\*|[a-z]{2,3}(?:-[a-z]{2,4})?)$/D',$language) ||
               !is_int($min)||$min<1||$min>500)
                return self::fail('ccr_rule_invalid','Invalid field, locale, severity, or length threshold.',422);
            if($target==='post' && (!post_type_exists($type)||
                in_array($field,array('term_description','term_meta_text','term_meta_image'),true)))
                return self::fail('ccr_post_type_invalid','Rule requires a registered post type.',422);
            if($target==='term' && (!taxonomy_exists($taxonomy)||
                !in_array($field,array('term_description','term_meta_text','term_meta_image',
                    'seo_title','seo_description','seo_og_title','seo_og_description','seo_canonical'),true)))
                return self::fail('ccr_taxonomy_invalid','Term rule requires a registered taxonomy.',422);
            if(in_array($field,array('meta_text','meta_image','repeater_rows','term_meta_text','term_meta_image'),true)&&!$meta)
                return self::fail('ccr_meta_key_required','Specify a trusted source meta key.',422);
            $fallback=$rule['fallback_meta_keys']??array();
            if(!is_array($fallback)||count($fallback)>6)return self::fail('ccr_fallback_invalid','Up to six explicit fallback meta keys are supported.',422);
            foreach($fallback as $key)if(!is_string($key)||sanitize_key($key)!==$key||!$key)
                return self::fail('ccr_fallback_invalid','Invalid fallback meta key.',422);
            $parent=sanitize_key((string)($rule['inherit_from_meta']??''));
            $parentType=sanitize_key((string)($rule['inherit_post_type']??''));
            if(($parent||$parentType)&&($target!=='post'||!$parent||!$parentType||!post_type_exists($parentType)))
                return self::fail('ccr_inheritance_invalid','Explicit source relation and parent post type required.',422);
            $condition=sanitize_key((string)($rule['conditional_meta']??''));
            $conditionValue=isset($rule['conditional_value']) && is_scalar($rule['conditional_value'])
                ? sanitize_text_field((string)$rule['conditional_value']):'';
            $normalized[]=array('id'=>$id,'target'=>$target,
                'post_type'=>$target==='post'?$type:'','taxonomy'=>$target==='term'?$taxonomy:'',
                'field'=>$field,'meta_key'=>$meta,'language'=>$language,'min_chars'=>$min,
                'severity'=>$severity,'inherit_from_meta'=>$parent,'inherit_post_type'=>$parentType,
                'conditional_meta'=>$condition,'conditional_value'=>$conditionValue,
                'fallback_meta_keys'=>array_values(array_unique($fallback)));
        }
        return array('enabled'=>$input['enabled'],'exclude_qa'=>$input['exclude_qa'],'rules'=>$normalized);
    }
    public static function revision($settings=null) {
        return hash('sha256',wp_json_encode($settings??self::settings(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
    private static function language($id,$target,$type) {
        if(!defined('ICL_SITEPRESS_VERSION')) return substr(get_locale(),0,2);
        if($target==='post') {
            $details=apply_filters('wpml_post_language_details',null,$id);
            return is_array($details)?sanitize_key((string)($details['language_code']??'')):'';
        }
        return sanitize_key((string)apply_filters('wpml_element_language_code',null,
            array('element_id'=>$id,'element_type'=>'tax_'.$type)));
    }
    private static function text($v) {
        if(is_array($v)||is_object($v))return '';
        return trim(html_entity_decode(wp_strip_all_tags((string)$v),ENT_QUOTES,'UTF-8'));
    }
    private static function text_length($v) {
        $s=self::text($v);
        return function_exists('mb_strlen')?mb_strlen($s,'UTF-8'):strlen($s);
    }
    private static function media_exists($value) {
        if(is_array($value)) {
            foreach($value as $item) if(self::media_exists($item))return true;
            return false;
        }
        if(is_string($value)) {
            $json=json_decode($value,true);
            if(json_last_error()===JSON_ERROR_NONE && is_array($json))return self::media_exists($json);
            if(preg_match('/^[0-9]+(?:,[0-9]+)+$/D',$value))return self::media_exists(explode(',',$value));
        }
        $id=absint($value);
        return $id>0 && get_post_type($id)==='attachment'
            && str_starts_with((string)get_post_mime_type($id),'image/');
    }
    private static function elementor_source($id,$onlyH1=false) {
        $raw=get_post_meta($id,'_elementor_data',true);
        if(!$raw)return array('state'=>'absent','source'=>'_elementor_data');
        $tree=is_string($raw)?json_decode($raw,true):$raw;
        if(!is_array($tree))return array('state'=>'unknown','source'=>'elementor_unparseable');
        $texts=array();$dynamic=false;$walk=function($nodes)use(&$walk,&$texts,&$dynamic,$onlyH1){
            foreach($nodes as $node) {
                if(!is_array($node))continue;
                $settings=(array)($node['settings']??array());
                if(!empty($settings['__dynamic__']))$dynamic=true;
                $isHeading=($node['widgetType']??'')==='heading';
                if(!$onlyH1||($isHeading && ($settings['header_size']??'h2')==='h1')){
                    $keys=$onlyH1?array('title'):array('editor','title','text','description','text_content','content');
                    foreach($keys as $key)if(isset($settings[$key])&&is_scalar($settings[$key]))
                        $texts[]=self::text($settings[$key]);
                }
                if(!empty($node['elements']) && is_array($node['elements']))$walk($node['elements']);
            }
        };
        $walk($tree);
        $value=trim(implode(' ',$texts));
        if($value!=='')return array('state'=>'present','value'=>$value,'source'=>'elementor_widget_source');
        return array('state'=>$dynamic?'unknown':'absent','source'=>$dynamic?'elementor_dynamic_unrendered':'elementor_empty');
    }
    private static function seo_source($id,$rule,$isTerm=false) {
        $type=$rule['field'];
        $keys=array(
            'seo_title'=>'rank_math_title','seo_description'=>'rank_math_description',
            'seo_og_title'=>'rank_math_facebook_title','seo_og_description'=>'rank_math_facebook_description',
            'seo_canonical'=>'rank_math_canonical_url',
        );
        $key=$keys[$type]??'';
        $value=$isTerm?get_term_meta($id,$key,true):get_post_meta($id,$key,true);
        if(self::text_length($value)>0) {
            if(preg_match('/%[a-z0-9_-]+%|\{\{/i',(string)$value))
                return array('state'=>'unknown','source'=>'rank_math_explicit_dynamic_unverified');
            return array('state'=>self::text_length($value)>=$rule['min_chars']?'present':'absent',
                'value'=>$value,'source'=>'rank_math_explicit');
        }
        if(!$isTerm && in_array($type,array('seo_title','seo_description'),true)){
            $templates=(array)get_option('rank-math-options-titles',array());
            $postType=get_post_type($id);
            $template=(string)($templates['pt_'.$postType.'_'.($type==='seo_title'?'title':'description')]??'');
            if($template!==''){
                if(preg_match('/%excerpt%|%content%/i',$template)){
                    $excerpt=self::text(get_post_field('post_excerpt',$id));
                    $content=self::text(get_post_field('post_content',$id));
                    if($excerpt!=='' && !preg_match('/\[elementor-template\b|\[woocommerce_/i',$excerpt))
                        return array('state'=>'present','value'=>$excerpt,'source'=>'rank_math_dynamic_template');
                    if($content!=='' && !preg_match('/\[elementor-template\b|\[woocommerce_/i',$content))
                        return array('state'=>'present','value'=>$content,'source'=>'rank_math_dynamic_template');
                    $builder=self::elementor_source($id);
                    if($builder['state']==='present')return array('state'=>'present','value'=>$builder['value'],'source'=>'rank_math_elementor_fallback');
                    if($builder['state']==='unknown')return $builder;
                    if(preg_match('/\\[elementor-template\\b|\\[woocommerce_|\\[jet_/i',$content))
                        return array('state'=>'unknown','source'=>'rank_math_dynamic_shortcode_unrendered');
                    return array('state'=>'absent','source'=>'rank_math_template_without_excerpt');
                }
                if(preg_match('/%title%/i',$template) && self::text(get_the_title($id))!=='')
                    return array('state'=>'present','value'=>get_the_title($id),'source'=>'rank_math_dynamic_template');
                if(preg_match('/%[a-z0-9_-]+%/i',$template))
                    return array('state'=>'unknown','source'=>'rank_math_unknown_dynamic_variable');
                return array('state'=>'present','value'=>$template,'source'=>'rank_math_static_template');
            }
        }
        // Do not equate a missing override with a missing rendered SEO tag.
        return array('state'=>'unknown','source'=>'seo_provider_output_unverified');
    }
    private static function source($id,$rule) {
        $field=$rule['field'];$term=$rule['target']==='term';
        if(in_array($field,array('seo_title','seo_description','seo_og_title','seo_og_description','seo_canonical'),true))
            return self::seo_source($id,$rule,$term);
        if($term) {
            $t=get_term($id,$rule['taxonomy']);
            if(!$t||is_wp_error($t))return array('state'=>'unknown','source'=>'taxonomy_term_unresolved');
            if($field==='term_description')$value=$t->description;
            else $value=get_term_meta($id,$rule['meta_key'],true);
        } else {
            switch($field) {
            case 'post_title':$value=get_the_title($id);break;
            case 'post_content':$value=get_post_field('post_content',$id);break;
            case 'post_excerpt':$value=get_post_field('post_excerpt',$id);break;
            case 'featured_image':$value=get_post_thumbnail_id($id);break;
            case 'image_alt':
                $thumbnail=get_post_thumbnail_id($id);
                if(!$thumbnail)return array('state'=>'unknown','source'=>'featured_image_missing_use_separate_media_rule');
                $value=get_post_meta($thumbnail,'_wp_attachment_image_alt',true);break;
            case 'elementor_text':return self::elementor_source($id);
            case 'h1':
                $builder=self::elementor_source($id,true);
                if($builder['state']!=='absent')return $builder;
                $raw=(string)get_post_field('post_content',$id);
                if(preg_match('/<h1\b[^>]*>(.*?)<\/h1>/is',$raw,$m))
                    return array('state'=>'present','value'=>$m[1],'source'=>'wordpress_h1');
                return array('state'=>'unknown','source'=>'h1_template_output_unverified');
            default:$value=get_post_meta($id,$rule['meta_key'],true);break;
            }
        }
        if($field==='repeater_rows') {
            if(is_string($value)) {
                $parsed=json_decode($value,true);
                if(is_array($parsed))$value=$parsed;
            }
            if(is_array($value)) {
                $rows=array_values(array_filter($value,static function($row){
                    return is_array($row)&&count(array_filter($row,static function($v){
                        return $v!==null&&$v!==''&&$v!==array();
                    }))>0;
                }));
                return array('state'=>count($rows)>=$rule['min_chars']?'present':'absent',
                    'source'=>'jetengine_repeater_source','value'=>'');
            }
            return $value===''||$value===null?
                array('state'=>'absent','source'=>'jetengine_repeater_empty'):
                array('state'=>'unknown','source'=>'jetengine_repeater_structure_unverified');
        }
        if(in_array($field,array('featured_image','meta_image','term_meta_image'),true)) {
            if(is_string($value) && preg_match('~^https?://~i',trim($value)))
                return array('state'=>'unknown','source'=>'external_media_url_unverified');
            return array('state'=>self::media_exists($value)?'present':'absent',
                'source'=>$field,'value'=>'');
        }
        if(is_array($value))return array('state'=>'unknown','source'=>'structured_meta_requires_rule_adapter');
        if(self::text_length($value)<$rule['min_chars']) {
            foreach((array)($rule['fallback_meta_keys']??array()) as $key){
                $candidate=$term?get_term_meta($id,$key,true):get_post_meta($id,$key,true);
                if(is_array($candidate)||is_object($candidate))continue;
                if(self::text_length($candidate)>=$rule['min_chars'])
                    return array('state'=>'present','value'=>$candidate,'source'=>'verified_fallback_meta_'.$key);
            }
        }
        if(!$term && $field==='post_content' && get_post_meta($id,'_elementor_edit_mode',true)==='builder') {
            $builder=self::elementor_source($id);
            if($builder['state']==='present')return $builder;
            if($builder['state']==='unknown')return $builder;
        }
        if(is_string($value) && preg_match('/\[elementor-template\b|\[woocommerce_|\[jet_/i',$value))
            return array('state'=>'unknown','source'=>'shortcode_dynamic_template_unverified');
        if(!$term && $field==='post_content' && self::text_length($value)<$rule['min_chars'] &&
            (get_post_meta($id,'_elementor_template_type',true) || get_post_meta($id,'_wp_page_template',true)==='elementor_header_footer'))
            return array('state'=>'unknown','source'=>'template_document_not_rendered');
        if(self::text_length($value)>= $rule['min_chars'])
            return array('state'=>'present','value'=>$value,'source'=>$field);
        if(is_string($value)&&preg_match('/\{\{|\[jet_|%[a-z_]+%/i',$value))
            return array('state'=>'unknown','source'=>'dynamic_macro_unresolved');
        return array('state'=>'absent','source'=>$field);
    }
    private static function inherited($id,$rule) {
        if(!$rule['inherit_from_meta'])return null;
        $parent=absint(get_post_meta($id,$rule['inherit_from_meta'],true));
        if(!$parent || $parent===$id || get_post_type($parent)!==$rule['inherit_post_type'] ||
            !in_array(get_post_status($parent),array('publish','private'),true))return null;
        $value=self::source($parent,$rule);
        if($value['state']==='present')return array('state'=>'present','source'=>'explicit_related_post_'.$parent);
        return $value['state']==='unknown'?$value:null;
    }
    private static function issue($id,$rule,$language,$source) {
        $prefix='ccr_'.$rule['id'];
        $identity=$rule['target']==='term'?'term|'.$rule['taxonomy']:'post|'.$rule['post_type'];
        $key=hash('sha256',get_current_blog_id().'|'.$identity.'|'.$id.'|'.$prefix.'|'.$language);
        return array('id'=>$key,'post_type'=>$rule['target']==='term'?'term:'.$rule['taxonomy']:$rule['post_type'],
            'object_id'=>$id,'code'=>$prefix,'severity'=>$rule['severity'],
            'label'=>'Missing required '.$rule['field'].' for '.$rule['target'].' #'.$id.
                ' ('.$language.', rule '.$rule['id'].')',
            'source'=>'content-completeness','source_evidence'=>array(
                'rule_id'=>$rule['id'],'field'=>$rule['field'],'meta_key'=>$rule['meta_key'],
                'language'=>$language,'source'=>$source['source']??'unknown','target'=>$rule['target']),
        );
    }
    private static function excludes($post,$settings) {
        if(!$settings['exclude_qa'])return false;
        if(preg_match('/\bQA ONLY\b/i',(string)$post->post_title))return true;
        if(in_array((int)$post->ID,array_map('intval',array_filter(array(
            get_option('woocommerce_cart_page_id'),get_option('woocommerce_checkout_page_id'),
            get_option('woocommerce_myaccount_page_id'),get_option('woocommerce_shop_page_id')))),true))return true;
        return false;
    }
    public static function discover($input=null) {
        $settings=is_array($input)?$input:self::settings();
        $normalized=self::normalize($settings);
        if(is_wp_error($normalized))return array('complete'=>false,'issues'=>array(),'evaluated'=>0,
            'error'=>$normalized->get_error_code());
        if(!$normalized['enabled'])return array('complete'=>true,'issues'=>array(),'evaluated'=>0,'skipped_unknown'=>0);
        $issues=array();$scanned=0;$unknown=0;$complete=true;
        foreach($normalized['rules'] as $rule) {
            if($rule['target']==='post'){
                $ids=get_posts(array('post_type'=>$rule['post_type'],'post_status'=>'publish',
                    'posts_per_page'=>self::MAX_POSTS+1,'fields'=>'ids','suppress_filters'=>true,
                    'orderby'=>'ID','order'=>'ASC','no_found_rows'=>true));
            }else{
                $ids=get_terms(array('taxonomy'=>$rule['taxonomy'],'hide_empty'=>false,
                    'number'=>self::MAX_TERMS+1,'fields'=>'ids'));
            }
            if(is_wp_error($ids)||!is_array($ids)){$complete=false;continue;}
            $limit=$rule['target']==='post'?self::MAX_POSTS:self::MAX_TERMS;
            if(count($ids)>$limit){$complete=false;$ids=array_slice($ids,0,$limit);}
            foreach($ids as $id) {
                $id=absint($id);
                if($rule['target']==='post'){
                    $post=get_post($id);
                    if(!$post||self::excludes($post,$normalized))continue;
                    if($rule['conditional_meta']) {
                        $actual=(string)get_post_meta($id,$rule['conditional_meta'],true);
                        if($actual!==$rule['conditional_value'])continue;
                    }
                }
                $lang=self::language($id,$rule['target'],
                    $rule['target']==='post'?$rule['post_type']:$rule['taxonomy']);
                if(!$lang){$unknown++;continue;}
                if($rule['language']!=='*'&&$lang!==$rule['language'])continue;
                $scanned++;
                $resolved=self::source($id,$rule);
                if($resolved['state']==='absent'&&$rule['target']==='post'){
                    $inherited=self::inherited($id,$rule);
                    if($inherited)$resolved=$inherited;
                }
                if($resolved['state']==='absent')$issues[]=self::issue($id,$rule,$lang,$resolved);
                elseif($resolved['state']==='unknown')$unknown++;
                if(count($issues)>=MAD4B_SCP_Content_Readiness_Alerts::MAX_ISSUES){
                    $complete=false;break 2;
                }
            }
        }
        return array('contract'=>'mad4b.content-completeness.discovery.v1',
            'complete'=>$complete,'issues'=>$issues,'evaluated'=>$scanned,'skipped_unknown'=>$unknown);
    }
    public static function menu() {
        add_submenu_page('mad4b-control-plane','Content Completeness & SEO',
            'Completeness & SEO','mad4b_manage_company_operations',self::PAGE,array(__CLASS__,'render'));
    }
    public static function render() {
        if(!MAD4B_SCP_Readiness_Integrations::can_manage())wp_die('Forbidden',403);
        $settings=self::settings();
        echo '<div class="wrap"><h1>Content Completeness &amp; SEO Rules</h1>';
        echo '<p>Rules are company-local. The scanner never fills content, changes pricing or generates SEO metadata. Dynamic sources without verified evidence are not reported as empty.</p>';
        if(isset($_GET['saved']))echo '<div class="notice notice-success"><p>Rules saved and validated.</p></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('mad4b_completeness_save');
        echo '<input type="hidden" name="action" value="mad4b_completeness_save">';
        echo '<p><label><input type="checkbox" name="enabled" value="1" '.checked($settings['enabled'],true,false).'> Enable completeness checks</label></p>';
        echo '<p><label><input type="checkbox" name="exclude_qa" value="1" '.checked($settings['exclude_qa'],true,false).'> Exclude explicit QA ONLY fixtures and WooCommerce system pages</label></p>';
        echo '<p><label for="mad4b_ccr_json"><strong>Per-content-type rules (JSON array)</strong></label></p>';
        echo '<textarea id="mad4b_ccr_json" name="rules_json" rows="19" style="width:100%;max-width:1100px;font-family:monospace;">'.
            esc_textarea(wp_json_encode($settings['rules'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)).
            '</textarea>';
        echo '<p class="description">Rule: id, target=post|term, post_type or taxonomy, field, optional meta_key, language=*, min_chars, severity. Optional explicit inherit_from_meta/inherit_post_type and conditional_meta/conditional_value. No HTML execution.</p>';
        submit_button('Save completeness rules');
        echo '</form><p><a href="'.esc_url(admin_url('admin.php?page=mad4b-content-readiness-alerts')).'">View issue registry</a></p></div>';
    }
    public static function save() {
        if(!self::admin_permission())wp_die('Forbidden',403);
        check_admin_referer('mad4b_completeness_save');
        $text=(string)wp_unslash($_POST['rules_json']??'[]');
        $rules=json_decode($text,true);
        $proposed=self::normalize(array('enabled'=>!empty($_POST['enabled']),
            'exclude_qa'=>!empty($_POST['exclude_qa']),'rules'=>$rules));
        if(is_wp_error($proposed))wp_die('Rules rejected: '.$proposed->get_error_code(),400);
        update_option(self::OPTION,$proposed,false);
        if(self::revision(self::settings())!==self::revision($proposed))
            wp_die('Rules readback mismatch.',500);
        wp_safe_redirect(add_query_arg(array('page'=>self::PAGE,'saved'=>1),admin_url('admin.php')));exit;
    }
    public static function routes() {
        register_rest_route(self::NS,'/admin/completeness',array(
            array('methods'=>'GET','callback'=>array(__CLASS__,'rest_rules'),'permission_callback'=>array(__CLASS__,'admin_permission')),
            array('methods'=>'PATCH','callback'=>array(__CLASS__,'rest_update'),'permission_callback'=>array(__CLASS__,'admin_permission')),
        ));
        register_rest_route(self::NS,'/admin/completeness/preview',array(
            'methods'=>'GET','callback'=>array(__CLASS__,'rest_preview'),
            'permission_callback'=>array(__CLASS__,'admin_permission')));
        register_rest_route(self::NS,'/admin/completeness/scan',array(
            'methods'=>'POST','callback'=>array(__CLASS__,'rest_scan'),
            'permission_callback'=>array(__CLASS__,'admin_permission')));
    }
    public static function rest_rules() {
        $settings=self::settings();
        return rest_ensure_response(array('contract'=>'mad4b.content-completeness.rules.v1',
            'settings'=>$settings,'revision'=>self::revision($settings),'supported_fields'=>self::TYPES));
    }
    public static function rest_update($request) {
        $payload=$request->get_json_params();
        if(!is_array($payload)||!is_array($payload['settings']??null)||
            !is_string($payload['if_match']??null)||
            !hash_equals(self::revision(),$payload['if_match']))
            return self::fail('ccr_revision_conflict','Refresh current rules before changing them.',409);
        $candidate=self::normalize($payload['settings']);
        if(is_wp_error($candidate))return $candidate;
        update_option(self::OPTION,$candidate,false);
        if(self::revision()!==self::revision($candidate))
            return self::fail('ccr_readback_failed','Saved rules did not verify.',500);
        return self::rest_rules();
    }
    public static function rest_preview($request) {
        $result=self::discover();
        if(!$result['complete'])return self::fail('ccr_discovery_incomplete','Rule discovery was incomplete.',409);
        $page=max(1,absint($request->get_param('page')??1));
        $size=max(1,min(100,absint($request->get_param('per_page')??25)));
        return rest_ensure_response(array('contract'=>'mad4b.content-completeness.preview.v1',
            'complete'=>true,'read_only'=>true,'evaluated'=>$result['evaluated'],
            'skipped_unknown'=>$result['skipped_unknown']??0,'total'=>count($result['issues']),
            'page'=>$page,'per_page'=>$size,
            'issues'=>array_slice($result['issues'],($page-1)*$size,$size)));
    }
    public static function rest_scan() {
        $scan=MAD4B_SCP_Content_Readiness_Alerts::discover();
        if(empty($scan['complete']))return self::fail('ccr_discovery_incomplete','No partial reconciliation permitted.',409);
        $result=MAD4B_SCP_Content_Readiness_Alerts::reconcile($scan);
        if(is_wp_error($result))return $result;
        return rest_ensure_response(array('contract'=>'mad4b.content-completeness.scan.v1',
            'complete'=>true,'counts'=>$result,'scanned'=>$scan['scanned']??0));
    }
}
