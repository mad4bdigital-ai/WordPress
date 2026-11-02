<?php
/**
 * Plugin Name: MAD4B WPML Design Reconciliation
 * Description: Source-of-truth visual and provider-configuration parity across linked WPML objects. Does not translate locale text or prices.
 * Version: 0.1.0-staging
 */
defined('ABSPATH') || exit;
/** Shared guard for 301, 305 and 349. Defaults ON, never controls cross-company operations. */
function mad4b_wpml_auto_sync_allowed_v1($source_id=0,$target_id=0) {
    if(get_option('mad4b_wpml_design_auto_sync_enabled_v1','yes')!=='yes')return false;
    foreach([(int)$source_id,(int)$target_id] as $post_id) {
        if($post_id>0&&get_post_meta($post_id,'_mad4b_wpml_auto_sync_excluded',true)==='yes')return false;
    }
    return true;
}
final class MAD4B_WPML_Design_Reconciliation_V1 {
    const EVENT = 'mad4b_wpml_design_reconciliation_v1';
    const QUEUE = 'mad4b_wpml_design_sync_queue_v1';
    const AUDIT = 'mad4b_wpml_design_sync_audit_v1';
    const TYPES = ['page','elementor_library','elementor-hf','jet-engine','jet-smart-filters','jet-woo-builder'];
    private static $busy = false;
    private static function runtime_site() {
        return wp_get_environment_type()==='staging'
            && rtrim(home_url(),'/')==='https://tours.dev.mad4b.com'
            && defined('ICL_SITEPRESS_VERSION');
    }

    public static function enabled() {
        return self::runtime_site() && mad4b_wpml_auto_sync_allowed_v1();
    }
    private static function language($id) {
        $data=apply_filters('wpml_post_language_details',null,(int)$id);
        return is_array($data) ? (string)($data['language_code']??'') : '';
    }
    private static function translations($id,$type) {
        $trid=apply_filters('wpml_element_trid',null,(int)$id,'post_'.$type);
        if(!$trid)return [];
        $refs=apply_filters('wpml_get_element_translations',null,$trid,'post_'.$type);
        $out=[];
        foreach((array)$refs as $lang=>$o)if(is_object($o)&&!empty($o->element_id))$out[$lang]=(int)$o->element_id;
        // WPML's translations collection can omit drafts. Resolve through the
        // native WPML object-ID filter, then enforce the same source TRID,
        // language, type, and an explicitly unpublished draft status.
        // Never equate a draft with an approved/published translation.
        $active=(array)apply_filters('wpml_active_languages',null);
        foreach(array_keys($active) as $lang) {
            if(!is_string($lang)||isset($out[$lang])||$lang===self::language($id))continue;
            $candidate=(int)apply_filters('wpml_object_id',(int)$id,$type,false,$lang);
            if(!$candidate||$candidate===(int)$id||get_post_type($candidate)!==$type
                ||get_post_status($candidate)!=='draft'||self::language($candidate)!==$lang)continue;
            $trid2=(int)apply_filters('wpml_element_trid',null,$candidate,'post_'.$type);
            if($trid2!==(int)$trid)continue;
            $out[$lang]=$candidate;
        }
        return $out;
    }
    private static function canonical($id) {
        $post=get_post((int)$id);
        if(!$post||!in_array($post->post_type,self::TYPES,true))return false;
        return self::language($id)===(string)apply_filters('wpml_default_language',null);
    }
    private static function tree($id) {
        $raw=get_post_meta((int)$id,'_elementor_data',true);
        if(!is_string($raw)||$raw==='')return [];
        $parsed=json_decode($raw,true);
        return is_array($parsed)?$parsed:[];
    }
    private static function style_key($key,$rtl) {
        if($key==='__globals__'||$key==='custom_css')return true;
        if(preg_match('/(?:text|title|editor|label|content|description|html|shortcode|link|url|image|gallery|video|media|placeholder|query|condition|dynamic|source|currency|price|rate|nationality)/i',$key))return false;
        if($rtl&&preg_match('/(?:margin|padding|align|justify|direction|float|order|typography|font|transform)/i',$key))return false;
        return (bool)preg_match('/^(?:_?(?:margin|padding|width|height|align|flex|grid|gap|order|position|overflow|z_index|border|shadow|background|typography|font|color|opacity|css_classes|size|display|hide_on|sticky|animation|transform|custom_css|justify|direction|content_width|object_fit|space_between|column|row|section|container|jet_sticky|vertical_gap|horizontal_gap|columns|carousel|prev_|next_))/', $key);
    }
    private static function translated_object($source_id,$post_type,$lang) {
        $id=(int)apply_filters('wpml_object_id',(int)$source_id,$post_type,false,$lang);
        return $id && get_post_type($id)===$post_type ? $id : 0;
    }
    private static function reference_key($key) {
        $key=strtolower((string)$key);
        if(in_array($key,['template_id','popup_id','header_template','footer_template'],true))return 'elementor_library';
        if(in_array($key,['listing_id','lisitng_id','listing_item_id'],true))return 'jet-engine';
        if(in_array($key,['filter_id','filterid'],true))return 'jet-smart-filters';
        return '';
    }
    private static function content_key($key) {
        return (bool)preg_match('/(?:text|title|editor|label|content|description|html|placeholder|link|url|media|image|gallery|alt|caption|currency|price|rate|nationality|__dynamic__|conditions|shortcode)/i',(string)$key);
    }
    private static function sync_globals($source,$target) {
        foreach((array)$source as $k=>$v) {
            if(preg_match('/(?:color|typography|background|border|shadow|font)/i',(string)$k))$target[$k]=$v;
        }
        return $target;
    }
    private static function node($source,$target,$lang,&$issues,&$changed) {
        $source_s=(array)($source['settings']??[]);
        $target_s=(array)($target['settings']??[]);
        foreach($source_s as $k=>$v) {
            $type=self::reference_key($k);
            if($type&&is_numeric($v)&&(int)$v>0) {
                $translated=self::translated_object((int)$v,$type,$lang);
                if(!$translated) {
                    $shared=false;
                    if($type==='elementor_library'
                       &&class_exists('MAD4B_WPML_Translation_Need_V1')){
                        $need=MAD4B_WPML_Translation_Need_V1::source((int)$v);
                        $shared=!is_wp_error($need)
                           &&($need['classification']??'')==='shared_dynamic_provider';
                    }
                    if(!$shared || (string)($target_s[$k]??'')!==(string)$v)
                        $issues[]=['code'=>'missing_linked_reference','field'=>$k,'source_id'=>(int)$v,'lang'=>$lang,'widget'=>$source['id']??''];
                }elseif((string)($target_s[$k]??'')!==(string)$translated) {
                    $target_s[$k]=is_int($v)?$translated:(string)$translated;
                    $changed++;
                }
                continue;
            }
            if($k==='__globals__'&&is_array($v)) {
                $before=(array)($target_s[$k]??[]);
                $after=self::sync_globals($v,$before);
                if($after!==$before){$target_s[$k]=$after;$changed++;}
                continue;
            }
            if(!self::style_key((string)$k,$lang==='ar'))continue;
            if(!array_key_exists($k,$target_s)||$target_s[$k]!==$v){$target_s[$k]=$v;$changed++;}
        }
        $target['settings']=$target_s;
        $srcchildren=(array)($source['elements']??[]);
        $dstchildren=(array)($target['elements']??[]);
        if($srcchildren){
            $byid=[];foreach($dstchildren as $n)if(is_array($n)&&isset($n['id']))$byid[(string)$n['id']]=$n;
            $merged=[];$used=[];
            foreach($srcchildren as $sn){
                $sid=(string)($sn['id']??'');
                if(!$sid||!isset($byid[$sid])){
                    $issues[]=['code'=>'missing_widget_translation','lang'=>$lang,'widget'=>$sid,'type'=>$sn['widgetType']??$sn['elType']??''];
                    continue;
                }
                $used[$sid]=true;$tn=$byid[$sid];
                if(($sn['widgetType']??$sn['elType']??'')!==($tn['widgetType']??$tn['elType']??'')){
                    $issues[]=['code'=>'widget_type_conflict','lang'=>$lang,'widget'=>$sid];$merged[]=$tn;continue;
                }
                $merged[]=self::node($sn,$tn,$lang,$issues,$changed);
            }
            foreach($dstchildren as $dn)if(!isset($used[(string)($dn['id']??'')]))$merged[]=$dn;
            if($merged!==$dstchildren){$target['elements']=$merged;$changed++;}
        }
        return $target;
    }
    private static function elements($src,$dst,$lang,&$issues,&$changed) {
        $byid=[];foreach((array)$dst as $n)if(is_array($n)&&isset($n['id']))$byid[(string)$n['id']]=$n;
        $out=[];$used=[];
        foreach((array)$src as $n){
            $id=(string)($n['id']??'');
            if(!$id||!isset($byid[$id])){
                $issues[]=['code'=>'missing_root_widget_translation','lang'=>$lang,'widget'=>$id];
                continue;
            }
            $used[$id]=true;
            $out[]=self::node($n,$byid[$id],$lang,$issues,$changed);
        }
        foreach((array)$dst as $n)if(!isset($used[(string)($n['id']??'')]))$out[]=$n;
        if($out!==$dst)$changed++;
        return $out;
    }
    private static function provider_keys($type) {
        if($type==='jet-engine')return ['_listing_data'];
        if($type!=='jet-smart-filters')return [];
        return [
            '_filter_type','_data_source','_is_hierarchical','_ih_source_map','_ih_terms_orderby',
            '_ih_terms_order',
            '_query_builder_value_prop','_query_builder_label_prop','_source_taxonomy',
            '_terms_orderby','_terms_order','_terms_relational_operator','_source_callback',
            '_source_min','_source_max','_source_step','_range_inputs_enabled',
            '_source_post_type','_glossary_id','_source_custom_field','_source_get_from_field_data',
            '_custom_field_source_plugin','_is_custom_checkbox','_use_exclude_include',
            '_data_exclude_include','_query_var','_is_custom_query_var','_custom_query_var',
            '_query_compare','_is_default_filter_value','_default_filter_value',
            '_show_empty_terms','_only_child','_group_by_parent','_group_collapsible',
            '_terms_orderby_meta_value','_date_source','_date_type','_date_format',
            '_date_period_type','_date_available_range','_date_period_start_end_enabled',
            '_values_decimal_num','_values_decimal_sep','_values_thousand_sep',
        ];
    }
    private static function provider_plan($source,$target,$type) {
        $plan=[];
        foreach(self::provider_keys($type) as $k) {
            if(!metadata_exists('post',$source,$k))continue;
            $value=get_post_meta($source,$k,true);
            if(!metadata_exists('post',$target,$k)||get_post_meta($target,$k,true)!==$value)$plan[$k]=$value;
        }
        return $plan;
    }
    private static function backup($src,$dst,$kind,$old) {
        $hash=substr(hash('sha256',wp_json_encode($old)),0,14);
        $key='mad4b_wpml_design_backup_'.$dst.'_'.$kind.'_'.$hash;
        if(get_option($key,false)===false){
            $saved=add_option($key,['src'=>$src,'dst'=>$dst,'at'=>gmdate('c'),'value'=>$old],false);
            if(!$saved)return false;
        }
        return $key;
    }
    public static function reconcile($source,$apply=false) {
        $source=(int)$source;$post=get_post($source);
        if(!self::enabled()||!self::canonical($source))return new WP_Error('mad4b_wpml_design_invalid_source');
        if(!mad4b_wpml_auto_sync_allowed_v1($source))return new WP_Error('mad4b_wpml_design_source_excluded');
        $type=$post->post_type;$src=self::tree($source);
        $pairs=self::translations($source,$type);
        $results=[];$affected=0;$unresolved=0;
        foreach($pairs as $lang=>$target) {
            if($lang===self::language($source)||!get_post($target))continue;
            if(!mad4b_wpml_auto_sync_allowed_v1($source,$target)) {
                $results[]=['lang'=>$lang,'target'=>$target,'skipped'=>'post_excluded','would_update'=>false,'updated'=>false,'issues'=>[]];
                continue;
            }
            $issues=[];$changes=0;$meta=self::provider_plan($source,$target,$type);
            $dst=self::tree($target);$out=$dst;
            if($src&&$dst)$out=self::elements($src,$dst,$lang,$issues,$changes);
            elseif($src&&!$dst)$issues[]=['code'=>'empty_translated_elementor_tree','lang'=>$lang];
            $meta_count=count($meta);$changed=($out!==$dst)||$meta_count>0;
            $row=['lang'=>$lang,'target'=>$target,'would_update'=>$changed,'updated'=>false,'elementor_changes'=>$changes,'provider_meta_keys'=>array_keys($meta),'issues'=>$issues];
            $unresolved+=count($issues);
            if($apply&&$changed){
                if(self::$busy)return new WP_Error('mad4b_wpml_design_recursion');
                $old=[];if($out!==$dst)$old['_elementor_data']=get_post_meta($target,'_elementor_data',true);
                foreach($meta as $k=>$v)$old[$k]=['exists'=>metadata_exists('post',$target,$k),'value'=>get_post_meta($target,$k,true)];
                $back=self::backup($source,$target,'reconcile',$old);
                if(!$back)return new WP_Error('mad4b_wpml_design_backup_failed');
                self::$busy=true;
                try {
                    if($out!==$dst)update_post_meta($target,'_elementor_data',wp_slash(wp_json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
                    foreach($meta as $k=>$v)update_post_meta($target,$k,$v);
                } finally {self::$busy=false;}
                if($out!==$dst && self::tree($target)!==$out)return new WP_Error('mad4b_wpml_design_elementor_readback_failed');
                foreach($meta as $k=>$v)if(get_post_meta($target,$k,true)!==$v)return new WP_Error('mad4b_wpml_design_provider_readback_failed');
                if($out!==$dst&&class_exists('Elementor\\Core\\Files\\CSS\\Post')) {
                    try{ $css=new \Elementor\Core\Files\CSS\Post($target);$css->delete();}catch(\Throwable $e){$row['css_warning']='regeneration_required';}
                }
                clean_post_cache($target);
                $row['updated']=true;$row['rollback_option']=$back;
                $affected++;
            }
            $results[]=$row;
        }
        return ['contract'=>'mad4b.wpml.design-reconciliation.v1','source'=>$source,'type'=>$type,'apply'=>(bool)$apply,'updated_translations'=>$affected,'unresolved_refs_or_widgets'=>$unresolved,'translations'=>$results,'localized_text_overwritten'=>false,'rates_overwritten'=>false];
    }
    public static function enqueue($id) {
        if(self::$busy||!self::enabled()||!self::canonical($id)
            ||!mad4b_wpml_auto_sync_allowed_v1($id))return;
        $queue=get_option(self::QUEUE,[]);
        if(!is_array($queue))$queue=[];
        $queue[(int)$id]=time();
        if(count($queue)>60)$queue=array_slice($queue,-60,null,true);
        update_option(self::QUEUE,$queue,false);
        if(!wp_next_scheduled(self::EVENT))wp_schedule_single_event(time()+25,self::EVENT);
    }
    public static function on_meta($mid,$id,$key,$value) {
        if($key==='_elementor_data'||$key==='_elementor_page_settings'||$key==='_listing_data'
           ||str_starts_with((string)$key,'_filter_')||str_starts_with((string)$key,'_source_')
           ||in_array($key,['_query_var','_is_custom_query_var','_custom_query_var'],true))self::enqueue($id);
    }
    public static function run_queue() {
        if(!self::enabled())return;
        $queue=get_option(self::QUEUE,[]);
        if(!is_array($queue)||!$queue)return;
        $subset=array_slice($queue,0,8,true);$audit=get_option(self::AUDIT,[]);
        if(!is_array($audit))$audit=[];
        foreach($subset as $id=>$time) {
            $result=self::reconcile((int)$id,true);
            $audit[]=['at'=>gmdate('c'),'source'=>(int)$id,'result'=>is_wp_error($result)?$result->get_error_code():['updated'=>$result['updated_translations'],'issues'=>$result['unresolved_refs_or_widgets']]];
            unset($queue[$id]);
        }
        update_option(self::QUEUE,$queue,false);
        update_option(self::AUDIT,array_slice($audit,-50),false);
        if($queue&&!wp_next_scheduled(self::EVENT))wp_schedule_single_event(time()+25,self::EVENT);
    }
    public static function cli($args,$assoc) {
        $mode=$args[0]??'status';
        if($mode==='status'){ \WP_CLI::line(wp_json_encode(['enabled'=>self::enabled(),'queue'=>get_option(self::QUEUE,[]),'audit'=>array_slice((array)get_option(self::AUDIT,[]),-6)]));return; }
        if($mode==='run'){self::run_queue();\WP_CLI::success('Queued reconciliation pass complete');return;}
        if(!in_array($mode,['preview','sync'],true)||empty($args[1]))\WP_CLI::error('Usage: wp mad4b-design-sync status|run|preview <source_id>|sync <source_id>');
        $result=self::reconcile((int)$args[1],$mode==='sync');
        if(is_wp_error($result))\WP_CLI::error($result->get_error_code());
        \WP_CLI::line(wp_json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
    /** Native WordPress options form: only applies within this WPML site. */
    public static function admin_settings() {
        register_setting('mad4b_wpml_design_settings','mad4b_wpml_design_auto_sync_enabled_v1',[
            'type'=>'string','default'=>'yes',
            'sanitize_callback'=>static fn($v)=>($v==='yes'?'yes':'no'),
        ]);
    }
    public static function admin_menu() {
        add_options_page('WPML Design Sync','WPML Design Sync','manage_options','mad4b-wpml-design-sync',[__CLASS__,'settings_page']);
    }
    public static function settings_page() {
        if(!current_user_can('manage_options'))return;
        $yes=mad4b_wpml_auto_sync_allowed_v1();
        echo '<div class="wrap"><h1>WPML Design Sync</h1>';
        echo '<p>Synchronize source designs across linked translations on this WordPress site. No cross-company content distribution.</p>';
        echo '<form method="post" action="options.php">';
        settings_fields('mad4b_wpml_design_settings');
        echo '<input type="hidden" name="mad4b_wpml_design_auto_sync_enabled_v1" value="no">';
        echo '<label style="display:block;margin:18px 0;font-size:17px"><input type="checkbox" name="mad4b_wpml_design_auto_sync_enabled_v1" value="yes" '.checked($yes,true,false).'> <strong>Enable automatic synchronization (Master)</strong></label>';
        echo '<p>Default: enabled. Turning it off stops automatic design writes by engines 301, 305 and 349. Existing translations remain unchanged.</p>';
        echo '<p>Every post, template, Listing and Filter can be excluded individually from the edit screen. Localized text and pricing are never overwritten.</p>';
        submit_button('Save master synchronization preference');
        echo '</form></div>';
    }
    public static function metaboxes() {
        foreach(get_post_types(['show_ui'=>true],'names') as $type) {
            if(in_array($type,['attachment','revision'],true))continue;
            add_meta_box('mad4b_wpml_auto_sync','WPML Design Sync',[__CLASS__,'metabox'],$type,'side','default');
        }
    }
    public static function metabox($post) {
        wp_nonce_field('mad4b_wpml_auto_sync_'.$post->ID,'mad4b_wpml_sync_nonce');
        $excluded=get_post_meta($post->ID,'_mad4b_wpml_auto_sync_excluded',true)==='yes';
        echo '<label><input type="checkbox" name="mad4b_wpml_auto_sync_excluded" value="yes" '.checked($excluded,true,false).'> <strong>Exclude this item from automatic design sync</strong></label>';
        echo '<p class="description">Overrides the Master Switch. Applies to this post whether it is a source or a translation; content stays editable.</p>';
    }
    public static function save_exclusion($id) {
        if(wp_is_post_revision($id)||wp_is_post_autosave($id))return;
        if(!isset($_POST['mad4b_wpml_sync_nonce']))return;
        $nonce=sanitize_text_field(wp_unslash($_POST['mad4b_wpml_sync_nonce']));
        if(!wp_verify_nonce($nonce,'mad4b_wpml_auto_sync_'.$id)||!current_user_can('edit_post',$id))return;
        $excluded=isset($_POST['mad4b_wpml_auto_sync_excluded'])&&$_POST['mad4b_wpml_auto_sync_excluded']==='yes'?'yes':'no';
        update_post_meta($id,'_mad4b_wpml_auto_sync_excluded',$excluded);
    }
    public static function boot() {
        if(!self::runtime_site())return;
        add_action('admin_init',[__CLASS__,'admin_settings']);
        add_action('admin_menu',[__CLASS__,'admin_menu']);
        add_action('add_meta_boxes',[__CLASS__,'metaboxes']);
        add_action('save_post',[__CLASS__,'save_exclusion'],95);
        add_action('added_post_meta',[__CLASS__,'on_meta'],95,4);
        add_action('updated_post_meta',[__CLASS__,'on_meta'],95,4);
        add_action('elementor/editor/after_save',static function($id){self::enqueue((int)$id);},95,2);
        add_action('save_post_jet-smart-filters',static function($id){self::enqueue((int)$id);},100);
        add_action('save_post_jet-engine',static function($id){self::enqueue((int)$id);},100);
        add_action('init',static function(){
            if(self::enabled()&&get_option(self::QUEUE,[])&&!wp_next_scheduled(self::EVENT))
                wp_schedule_single_event(time()+20,self::EVENT);
        },85);
        add_action(self::EVENT,[__CLASS__,'run_queue']);
        if(defined('WP_CLI')&&WP_CLI)\WP_CLI::add_command('mad4b-design-sync',[__CLASS__,'cli']);
    }
}
add_action('plugins_loaded',['MAD4B_WPML_Design_Reconciliation_V1','boot'],75);
