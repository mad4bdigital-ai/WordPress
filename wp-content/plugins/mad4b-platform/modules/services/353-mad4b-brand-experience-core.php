<?php
/**
 * Plugin Name: MAD4B Brand Experience — JetEngine / WPML / Content Jobs
 * Description: Company-scoped Brand Look & Feel and marketing copy; English originals as WPML post content, not mirrored into translated layouts.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Brand_Experience_Core {
    const PAGE = 'look-and-feel';
    const TYPE = 'mad4b_brand_copy';
    const MAP = 'mad4b_brand_experience_source_map_v1';
    const JOB_MAP = 'mad4b_brand_experience_content_job_map_v1';
    private static $saving = false;

    public static function enabled(): bool {
        // Tenant UI/translation can run without Central Control Plane.
        // Only dispatch_jobs() requires the centrally governed ContentJobs service.
        return function_exists('jet_engine') && defined('ICL_SITEPRESS_VERSION') && !is_multisite();
    }
    public static function strings(): array {
        return [
           'brand_name'=>['Brand name','Brand Core'],
           'brand_tagline'=>['Brand tagline','Brand Core'],
           'brand_voice'=>['Tone of voice','Brand Core'],
           'editorial_guideline'=>['Editorial guidance','Brand Core'],
           'hero_headline'=>['Hero headline','Home & Landing'],
           'hero_subtitle'=>['Hero subtitle','Home & Landing'],
           'hero_cta'=>['Hero call to action','Home & Landing'],
           'announcement_message'=>['Top-bar announcement','Marketing Messages'],
           'campaign_headline'=>['Campaign headline','Marketing Messages'],
           'campaign_body'=>['Campaign description','Marketing Messages'],
           'campaign_cta'=>['Campaign call to action','Marketing Messages'],
           'promo_badge_text'=>['Promotion badge label','Marketing Messages'],
           'lead_capture_title'=>['Lead form introduction','Marketing Messages'],
           'lead_capture_cta'=>['Lead capture CTA','Marketing Messages'],
           'empty_results_message'=>['Empty listing message','Page States'],
           'archive_cta'=>['Archive call to action','Page States'],
           'tour_inquiry_cta'=>['Tour inquiry CTA','Page States'],
           'footer_legal_line'=>['Footer legal line','Page States']
        ];
    }
    public static function visual(): array {
        return [
            'brand_primary_color'=>['Primary color','colorpicker'],
            'brand_secondary_color'=>['Secondary color','colorpicker'],
            'brand_accent_color'=>['Accent color','colorpicker'],
            'brand_background_color'=>['Background color','colorpicker'],
            'brand_surface_color'=>['Surface background','colorpicker'],
            'brand_heading_font'=>['Heading font family','text'],
            'brand_body_font'=>['Body font family','text'],
            'brand_button_radius'=>['Button radius (CSS length)','text'],
            'brand_logo_media'=>['Logo Media ID','media'],
            'brand_favicon_media'=>['Favicon Media ID','media'],
            'brand_pattern_media'=>['Brand pattern Media ID','media'],
            'brand_pattern_strength'=>['Brand pattern contrast','text'],
        ];
    }
    public static function placement(): array {
        return [
          'home_hero_template'=>['Home Hero template ID','text'],
          'archive_hero_template'=>['Archive Hero template ID','text'],
          'single_tour_hero_template'=>['Tour Hero template ID','text'],
          'header_template'=>['Global Header template ID','text'],
          'footer_template'=>['Global Footer template ID','text'],
          'marketing_campaign_enabled'=>['Campaign enabled: 1 / 0','text'],
        ];
    }
    private static function field(string $name,string $title,string $type='text'): array {
        return ['name'=>$name,'title'=>$title,'type'=>$type,
            'object_type'=>'field','width'=>'100%','options'=>[],
            'repeater-fields'=>[],'options_source'=>'manual'];
    }
    private static function tab(string $name,string $title): array {
        return ['name'=>$name,'title'=>$title,'type'=>'text','object_type'=>'tab',
            'width'=>'100%','tab_layout'=>'vertical'];
    }
    public static function add_fields($fields,$page) {
        if(!self::enabled()||!is_object($page)||$page->slug!==self::PAGE) return $fields;
        $existing=[];
        foreach((array)$fields as $f)if(is_array($f)&&isset($f['name']))$existing[(string)$f['name']]=true;
        $append=function(array $field)use(&$fields,&$existing) {
            if(!isset($existing[$field['name']])){$fields[]=$field;$existing[$field['name']]=true;}
        };
        $append(self::tab('mad4b_brand_core_tab','Brand Core · EN Source'));
        foreach(self::strings() as $name=>$def)if($def[1]==='Brand Core')
            $append(self::field('mad4b_'.$name,$def[0],in_array($name,['brand_voice','editorial_guideline'],true)?'textarea':'text'));
        $append(self::tab('mad4b_brand_visual_tab','Brand Look & Feel · Shared Tokens'));
        foreach(self::visual() as $name=>$def)$append(self::field('mad4b_'.$name,$def[0],$def[1]));
        foreach(['Home & Landing'=>'mad4b_brand_home_tab','Marketing Messages'=>'mad4b_brand_marketing_tab',
            'Page States'=>'mad4b_brand_page_states_tab'] as $group=>$tab){
            $append(self::tab($tab,$group.' · EN Copy → Content Jobs'));
            foreach(self::strings() as $name=>$def)if($def[1]===$group)
                $append(self::field('mad4b_'.$name,$def[0],
                    preg_match('/body|message|subtitle|line$/',$name)?'textarea':'text'));
        }
        $append(self::tab('mad4b_brand_template_tab','Theme Builder · Dynamic Page Options'));
        foreach(self::placement() as $name=>$def)$append(self::field('mad4b_'.$name,$def[0],$def[1]));
        return $fields;
    }
    public static function register_type(): void {
        register_post_type(self::TYPE,[
            'labels'=>['name'=>'Brand Copy & Marketing Messages','singular_name'=>'Brand Copy',
                'menu_name'=>'Brand Messages','edit_item'=>'Edit translated brand message'],
            'public'=>false,'publicly_queryable'=>false,
            'show_ui'=>true,'show_in_menu'=>true,'show_in_rest'=>true,
            'menu_icon'=>'dashicons-megaphone',
            'supports'=>['title','editor','revisions','excerpt'],
            'capability_type'=>'post','map_meta_cap'=>true,
            'exclude_from_search'=>true,
        ]);
    }
    public static function wpml_config($config) {
        if(!is_array($config))$config=[];
        if(!isset($config['wpml-config'])||!is_array($config['wpml-config']))
            $config['wpml-config']=[];
        // Post content is the translatable English original; visual tokens
        // and structural template IDs are deliberately NOT translated.
        foreach((array)($config['wpml-config']['custom-types']['custom-type']??[]) as $type) {
            if(is_array($type)&&($type['value']??'')===self::TYPE)return $config;
        }
        $config['wpml-config']['custom-types']['custom-type'][]=[
            'value'=>self::TYPE,'attr'=>['translate'=>'1']
        ];
        return $config;
    }
    public static function source_id(string $field): int {
        $map=get_option(self::MAP,[]);
        if(!is_array($map)||!isset($map[$field]))return 0;
        $id=(int)$map[$field];
        $p=get_post($id);
        return $p&&$p->post_type===self::TYPE?$id:0;
    }
    public static function translate(string $field,?string $language=null): string {
        if(!self::enabled()||!isset(self::strings()[$field]))return '';
        $id=self::source_id($field);
        if(!$id)return '';
        $locale=$language?:apply_filters('wpml_current_language','en');
        $locale=preg_match('/^[a-z]{2}$/D',(string)$locale)?$locale:'en';
        if($locale!=='en') {
            $translated=(int)apply_filters('wpml_object_id',$id,self::TYPE,false,$locale);
            if(!$translated||$translated===$id||get_post_status($translated)!=='publish')return '';
            $details=apply_filters('wpml_post_language_details',null,$translated);
            if(!is_array($details)||($details['language_code']??'')!==$locale)return '';
            $id=$translated;
        }
        if(get_post_status($id)!=='publish')return '';
        return (string)get_post_field('post_content',$id);
    }
    public static function output_shortcode($atts): string {
        $args=shortcode_atts(['key'=>'','lang'=>'','fallback'=>''],(array)$atts,'mad4b_brand_copy');
        $key=sanitize_key($args['key']);$lang=sanitize_key($args['lang']);
        if(!isset(self::strings()[$key]))return '';
        $copy=self::translate($key,$lang?:null);
        // Do not leak EN prose into an untranslated locale.
        return $copy!==''?wp_kses_post($copy):'';
    }
    public static function register_shortcode(): void {
        add_shortcode('mad4b_brand_copy',[__CLASS__,'output_shortcode']);
    }
    public static function sync_after_jet_save($page): void {
        if(self::$saving||!self::enabled()||!is_object($page)||$page->slug!==self::PAGE||
           !current_user_can('manage_options'))return;
        self::$saving=true;
        try {
            $settings=get_option(self::PAGE,[]);
            if(!is_array($settings))return;
            $map=get_option(self::MAP,[]);
            if(!is_array($map))$map=[];
            $changed=[];
            foreach(self::strings() as $name=>$spec){
                $option='mad4b_'.$name;
                $text=isset($settings[$option])&&is_scalar($settings[$option])
                    ?trim((string)$settings[$option]):'';
                if($text==='')continue;
                $id=self::source_id($name);
                if($id && (string)get_post_field('post_content',$id)===$text)continue;
                if($id) {
                    $result=wp_update_post(['ID'=>$id,'post_content'=>$text],true);
                } else {
                    $result=wp_insert_post([
                        'post_type'=>self::TYPE,'post_status'=>'publish',
                        'post_title'=>$spec[0],'post_name'=>'brand-copy-'.sanitize_title($name),
                        'post_content'=>$text,'post_excerpt'=>$spec[1]
                    ],true);
                }
                if(is_wp_error($result)||!(int)$result)continue;
                $id=(int)$result;$map[$name]=$id;
                update_post_meta($id,'_mad4b_brand_field_key',$name);
                if(defined('ICL_SITEPRESS_VERSION')){
                    $details=apply_filters('wpml_post_language_details',null,$id);
                    if(!is_array($details)||empty($details['language_code'])) {
                        do_action('wpml_set_element_language_details',[
                            'element_id'=>$id,'element_type'=>'post_'.self::TYPE,
                            'trid'=>false,'language_code'=>'en',
                            'source_language_code'=>null
                        ]);
                    }
                }
                $changed[]=$name;
            }
            if($changed){
                update_option(self::MAP,$map,false);
                // Never auto-publish translations; run the canonical ContentJobs
                // through an authenticated operator, or WPML's translation queue.
                do_action('mad4b_brand_copy_source_changed',[
                    'fields'=>$changed,'type'=>self::TYPE,'source'=>'jet-engine/look-and-feel']);
            }
        } finally {self::$saving=false;}
    }

    public static function page_metaboxes(): void {
        foreach(['page','elementor_library','post','tours-and-activities'] as $type)
            add_meta_box('mad4b_brand_page_display','Brand Look & Feel · Page Options',
                [__CLASS__,'render_page_metabox'],$type,'normal','default');
    }
    public static function render_page_metabox($post): void {
        wp_nonce_field('mad4b_brand_page_'.$post->ID,'mad4b_brand_page_nonce');
        $current=(string)get_post_meta($post->ID,'_mad4b_brand_page_message_key',true);
        $layout=(string)get_post_meta($post->ID,'_mad4b_brand_page_layout',true);
        echo '<p>Text belongs to the canonical EN Brand Copy and WPML ContentJobs. This page only selects the message and a shared layout.</p>';
        echo '<label>Marketing message <select name="mad4b_brand_page_message_key"><option value="">Site default</option>';
        foreach(self::strings() as $key=>$spec)
            echo '<option value="'.esc_attr($key).'" '.selected($current,$key,false).'>'.esc_html($spec[1].' / '.$spec[0]).'</option>';
        echo '</select></label> <label>Layout <select name="mad4b_brand_page_layout">';
        foreach(['default','compact','cinematic','split','minimal'] as $variant)
            echo '<option value="'.esc_attr($variant).'" '.selected($layout,$variant,false).'>'.esc_html(ucfirst($variant)).'</option>';
        echo '</select></label>';
        echo '<p>Elementor shortcode: <code>[mad4b_brand_copy key="page"]</code>. Translation output is blank until the native WPML translation exists.</p>';
    }
    public static function save_page_meta(int $id): void {
        if(!isset($_POST['mad4b_brand_page_nonce'])||
          !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mad4b_brand_page_nonce'])),'mad4b_brand_page_'.$id)||
          !current_user_can('edit_post',$id)||wp_is_post_revision($id)||
          (defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE))return;
        $key=sanitize_key((string)wp_unslash($_POST['mad4b_brand_page_message_key']??''));
        if($key===''||isset(self::strings()[$key]))
            update_post_meta($id,'_mad4b_brand_page_message_key',$key);
        $layout=sanitize_key((string)wp_unslash($_POST['mad4b_brand_page_layout']??'default'));
        if(in_array($layout,['default','compact','cinematic','split','minimal'],true))
            update_post_meta($id,'_mad4b_brand_page_layout',$layout);
    }
    private static function pending_jobs(): array {
        $langs=apply_filters('wpml_active_languages',null,'skip_missing=0');
        if(!is_array($langs))return [];
        $jobs=[];
        foreach(self::strings() as $field=>$spec){
            $id=self::source_id($field);
            if(!$id||get_post_status($id)!=='publish')continue;
            $sha=hash('sha256',(string)get_post_field('post_content',$id));
            foreach(array_keys($langs) as $lang){
                if($lang==='en'||!preg_match('/^[a-z]{2}$/D',$lang))continue;
                $translated=(int)apply_filters('wpml_object_id',$id,self::TYPE,false,$lang);
                if($translated&&$translated!==$id&&get_post_status($translated)==='publish'
                    &&(string)get_post_field('post_content',$translated)!=='')continue;
                $token=hash('sha256',home_url('/').'|brand-copy|'.$id.'|'.$lang.'|'.$sha);
                $jobs[$token]=['field'=>$field,'source'=>$id,'language'=>$lang,
                    'sha256'=>$sha,'subject'=>'Brand Copy: '.$spec[0].' ['.$lang.'] source #'.$id.' brandcopy-ref:'.$token];
            }
        }
        return $jobs;
    }
    public static function jobs_preview(): array {
        $map=(array)get_option(self::JOB_MAP,[]);
        $pending=self::pending_jobs();
        $unmapped=0;$uncertain=0;
        foreach($pending as $key=>$row){
            if(!isset($map[$key]))$unmapped++;
            elseif(!empty($map[$key]['uncertain']))$uncertain++;
        }
        return ['contract'=>'mad4b.brand-copy.contentjob-intake.v1','total'=>count($pending),
            'unmapped'=>$unmapped,'uncertain'=>$uncertain,'native_mutation'=>false,
            'requires_authorized_actor'=>true,'auto_publish'=>false];
    }
    public static function dispatch_jobs(int $limit=3) {
        if(!class_exists('MAD4B_SCP_Content_Jobs')||
            !class_exists('MAD4B_SCP_Policy')||MAD4B_SCP_Policy::can_mutate()!==true)
            return new WP_Error('brand_copy_native_actor_required');
        if(!class_exists('MAD4B_SCP_Operational_Integrity')||!class_exists('MAD4B_SCP_Schema'))
            return new WP_Error('brand_copy_core_unavailable');
        $captured=MAD4B_SCP_Operational_Integrity::capture();
        if(is_wp_error($captured))return $captured;
        $scope=(array)($captured['scope']??[]);
        if(empty($scope['site_uuid'])||empty($scope['brand_ref']))
            return new WP_Error('brand_copy_scope_missing');
        $tables=MAD4B_SCP_Schema::tables();
        if(empty($tables['content_jobs']))return new WP_Error('brand_copy_table_missing');
        global $wpdb;
        $map=(array)get_option(self::JOB_MAP,[]);
        $result=[];$limit=max(1,min(5,$limit));
        foreach(self::pending_jobs() as $token=>$row){
            if(count($result)>=$limit)break;
            if(!empty($map[$token]['job_id'])||!empty($map[$token]['uncertain']))continue;
            $hits=$wpdb->get_col($wpdb->prepare(
                "SELECT job_id FROM {$tables['content_jobs']}
                 WHERE site_uuid=%s AND brand_id=%s AND subject LIKE %s LIMIT 3",
                $scope['site_uuid'],$scope['brand_ref'],
                '%'.$wpdb->esc_like('brandcopy-ref:'.$token).'%'));
            if(count((array)$hits)>1) {$result[$token]='DUPLICATE_NEEDS_REVIEW';continue;}
            if($hits){
                $map[$token]=['job_id'=>$hits[0],'source'=>$row['source'],'lang'=>$row['language'],'sha256'=>$row['sha256']];
                update_option(self::JOB_MAP,$map,false);
                $result[$token]='RECONCILED';continue;
            }
            $out=MAD4B_SCP_Content_Jobs::create_job([
                'brand_id'=>$scope['brand_ref'],'subject'=>$row['subject'],
                'language'=>$row['language'],'country'=>'global','content_type'=>'translation',
                'target_post_type'=>self::TYPE,'automation_level'=>'assisted',
                'reason'=>'WPML Brand Copy English source #'.$row['source'].
                    ' digest '.$row['sha256'].'; preserve translation review and do not publish automatically.'
            ]);
            if(is_wp_error($out)) {
                $map[$token]=['uncertain'=>true,'error'=>$out->get_error_code(),'source'=>$row['source']];
                update_option(self::JOB_MAP,$map,false);
                $result[$token]=$out->get_error_code();continue;
            }
            $job=$out['job']??[];
            if(empty($job['job_id'])){
                $map[$token]=['uncertain'=>true,'error'=>'missing_native_receipt'];
                update_option(self::JOB_MAP,$map,false);
                $result[$token]='MISSING_NATIVE_RECEIPT';continue;
            }
            $map[$token]=['job_id'=>$job['job_id'],'source'=>$row['source'],
                'lang'=>$row['language'],'sha256'=>$row['sha256']];
            update_option(self::JOB_MAP,$map,false);
            $result[$token]='CREATED';
        }
        return ['contract'=>'mad4b.brand-copy.contentjob-intake.v1','attempts'=>count($result),
            'results'=>$result,'published'=>false];
    }

    public static function readback(): array {
        $map=(array)get_option(self::MAP,[]);
        $result=['contract'=>'mad4b.brand-experience.v1',
            'jetengine_page'=>self::PAGE,'source_type'=>self::TYPE,
            'en_source_count'=>0,'translated_count'=>0,'missing'=>[],
            'fields'=>count(self::strings()),'visual_tokens'=>count(self::visual()),
            'page_bindings'=>count(self::placement()),'post_content_job_native'=>class_exists('MAD4B_SCP_Content_Jobs')];
        foreach(self::strings() as $key=>$label){
            $id=self::source_id($key);
            if(!$id){$result['missing'][]=$key;continue;}
            $result['en_source_count']++;
            foreach(['es','ar','fr','de','it'] as $lang){
                $tr=(int)apply_filters('wpml_object_id',$id,self::TYPE,false,$lang);
                if($tr&&$tr!==$id&&get_post_status($tr)==='publish')$result['translated_count']++;
            }
        }
        return $result;
    }
    public static function cli($args) {
        $cmd=$args[0]??'status';
        if($cmd==='status')$result=self::readback();
        elseif($cmd==='jobs-preview')$result=self::jobs_preview();
        elseif($cmd==='jobs-dispatch')$result=self::dispatch_jobs(3);
        else \WP_CLI::error('Usage: wp mad4b-brand-experience status|jobs-preview|jobs-dispatch');
        if(is_wp_error($result))\WP_CLI::error($result->get_error_code());
        \WP_CLI::line(wp_json_encode($result));
    }
    public static function boot(): void {
        add_action('init',[__CLASS__,'register_type'],9);
        add_action('init',[__CLASS__,'register_shortcode'],15);
        add_action('add_meta_boxes',[__CLASS__,'page_metaboxes'],20);
        add_action('save_post',[__CLASS__,'save_page_meta'],20);
        add_filter('jet-engine/options-pages/raw-fields',[__CLASS__,'add_fields'],20,2);
        add_action('jet-engine/options-pages/after-save/'.self::PAGE,[__CLASS__,'sync_after_jet_save'],20,1);
        add_filter('wpml_config_array',[__CLASS__,'wpml_config'],15,1);
        if(defined('WP_CLI')&&WP_CLI)\WP_CLI::add_command('mad4b-brand-experience',[__CLASS__,'cli']);
    }
}
MAD4B_Brand_Experience_Core::boot();
