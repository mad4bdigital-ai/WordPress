<?php
/**
 * Plugin Name: MAD4B Elementor Interactive Style Parity
 * Description: Tenant-scoped hover/button visual token parity for WPML copies; preserves locale content and RTL layout.
 * Version: 0.1.0-staging
 */
defined('ABSPATH') || exit;
final class MAD4B_Elementor_Style_Parity_V1 {
    const CONTRACT='mad4b.elementor-interaction-parity.v1';
    const HEALTH='mad4b_elementor_style_parity_health_v1';
    const CRON='mad4b_elementor_style_parity_scan_v1';
    const FAST='mad4b_elementor_style_parity_after_edit_v1';
    const TYPES=['page','elementor_library','tours-and-activities','product'];
    const WIDGETS=['button','form','call-to-action','jet-ajax-search'];
    const KEYS=['background_color','button_text_color','hover_color','button_background_hover_color','button_hover_color',
        'button_hover_background_color','hover_animation','transition_duration','button_transition_duration',
        'border_color_hover','button_border_color','button_border_hover_color','button_box_shadow_box_shadow',
        'button_box_shadow_box_shadow_hover','button_text_shadow_text_shadow','button_text_shadow_text_shadow_hover',
        'box_shadow_box_shadow_hover','button_hover_animation'];
    private static $locked=false;
    private static function enabled(){
        $p=get_option('mad4b_scp_brand_context_profile_v1',[]);
        return wp_get_environment_type()==='staging' && wp_parse_url(home_url(),PHP_URL_HOST)==='tours.dev.mad4b.com'
            && is_array($p) && ($p['site_uuid']??'')==='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
    }
    private static function lang($id){$d=apply_filters('wpml_post_language_details',null,(int)$id);return is_array($d)?(string)($d['language_code']??''):'';}
    private static function source_lang(){return (string)apply_filters('wpml_default_language',null);}
    private static function tree($id){$r=get_post_meta($id,'_elementor_data',true);$v=is_string($r)?json_decode($r,true):$r;return is_array($v)?$v:[];}
    private static function recognized($node){return in_array((string)($node['widgetType']??''),self::WIDGETS,true);}
    private static function style_plan($source,$target){
        if(!self::recognized($source) || ($source['widgetType']??'')!==($target['widgetType']??''))return [];
        $s=(array)($source['settings']??[]);$t=(array)($target['settings']??[]);$changes=[];
        foreach(self::KEYS as $key){
            // Elementor global color/typography references are semantic design tokens.
            // Never copy the entire __globals__ map: it could contain locale-sensitive fields.
            $sg=$s['__globals__'][$key]??null;
            $tg=$t['__globals__'][$key]??null;
            if(is_string($sg)&&preg_match('~^globals/(?:colors|typography)\\?id=[a-zA-Z0-9_-]+$~',$sg)){
                if($sg!==$tg)$changes[]=['scope'=>'globals','key'=>$key,'old'=>$tg,'new'=>$sg];
                // A stale local override should not defeat an authoritative global token.
                if(!array_key_exists($key,$s)&&array_key_exists($key,$t))$changes[]=['scope'=>'unset_local','key'=>$key];
            }elseif(array_key_exists($key,$s) && $s[$key]!==($t[$key]??null)){
                // Only strict visual state keys, never translated text, link, or direction.
                $changes[]=['scope'=>'setting','key'=>$key,'old'=>$t[$key]??null,'new'=>$s[$key]];
            }
        }
        return $changes;
    }
    private static function map($nodes,&$map){
        foreach((array)$nodes as $node){if(!is_array($node)||empty($node['id']))continue;
            $map[(string)$node['id']]=$node;self::map($node['elements']??[],$map);}
    }
    private static function pairs($source,$target){
        $a=[];$b=[];self::map($source,$a);self::map($target,$b);$out=[];
        foreach($a as $id=>$s)if(isset($b[$id])){
            $plan=self::style_plan($s,$b[$id]);if($plan)$out[$id]=$plan;
        }
        return $out;
    }
    private static function patch($nodes,$plans){
        $out=[];
        foreach($nodes as $node){
            $id=$node['id']??'';
            if(isset($plans[$id])){
                if(!isset($node['settings'])||!is_array($node['settings']))$node['settings']=[];
                foreach($plans[$id] as $change){$key=$change['key'];$scope=$change['scope'];
                    if($scope==='globals')$node['settings']['__globals__'][$key]=$change['new'];
                    elseif($scope==='setting')$node['settings'][$key]=$change['new'];
                    elseif($scope==='unset_local')unset($node['settings'][$key]);
                }
            }
            if(!empty($node['elements'])&&is_array($node['elements']))$node['elements']=self::patch($node['elements'],$plans);
            $out[]=$node;
        }
        return $out;
    }
    public static function merge_test($source,$target){
        $plans=self::pairs($source,$target);
        return ['plans'=>$plans,'tree'=>self::patch($target,$plans)];
    }
    public static function scan($apply=false){
        if(!self::enabled())return new WP_Error('style_parity_wrong_site');
        // Refuse an oversized batch before ANY content is changed.
        if($apply){$pre=self::scan(false);if(is_wp_error($pre))return $pre;
            if(($pre['documents_with_style_drift']??0)>35)return new WP_Error('style_parity_change_budget');}
        $origin=self::source_lang();$docs=0;$pairs=0;$drift=0;$changes=0;$results=[];$alerts=[];$errors=[];$max=450;
        foreach(self::TYPES as $type){
            $ids=get_posts(['post_type'=>$type,'post_status'=>'publish','numberposts'=>-1,'fields'=>'ids','suppress_filters'=>true]);
            foreach($ids as $id){if(self::lang($id)!==$origin)continue;
                $original=self::tree($id);if(!$original)continue;
                $trid=apply_filters('wpml_element_trid',null,$id,'post_'.$type);
                if(!$trid)continue;
                $translations=(array)apply_filters('wpml_get_element_translations',null,$trid,'post_'.$type);
                foreach($translations as $lang=>$ref){
                    if($lang===$origin||!in_array($lang,['ar','fr','de','es','it','en'],true))continue;
                    $target=(int)($ref->element_id??0);if(!$target||get_post_status($target)!=='publish')continue;
                    if(++$docs>$max)return new WP_Error('style_parity_scan_limit');
                    $raw=(string)get_post_meta($target,'_elementor_data',true);
                    $before=self::tree($target);if(!$before)continue;
                    $plans=self::pairs($original,$before);
                    $pairs+=count($plans);if(!$plans)continue;
                    $drift++;$count=array_sum(array_map('count',$plans));$changes+=$count;
                    $row=['source'=>(int)$id,'target'=>$target,'post_type'=>$type,'lang'=>$lang,
                          'widget_ids'=>array_keys($plans),'settings_drift'=>$count,'updated'=>false];
                    $code='loc_style_'.$lang;
                    $alerts[]=['id'=>hash('sha256',get_current_blog_id().'|'.$type.'|'.$id.'|'.$code),
                        'post_type'=>$type,'object_id'=>(int)$id,'code'=>$code,'severity'=>'high',
                        'source'=>'mad4b-elementor-localization',
                        'label'=>mb_substr('['.$lang.'] '.get_the_title($id).': interactive button/hover design differs from original ('
                            .$count.' style settings; '.count($plans).' widgets)',0,238)];
                    if($apply){
                        if($drift>35)return new WP_Error('style_parity_change_budget');
                        $new=self::patch($before,$plans);
                        if($new!==$before){
                            $name='mad4b_style_parity_backup_'.$target.'_'.substr(hash('sha256',$raw),0,12);
                            $backup=['source'=>(int)$id,'target'=>$target,'old'=>$raw,'sha256'=>hash('sha256',$raw),
                                'at'=>gmdate('c'),'locale'=>$lang];
                            if(!get_option($name) && !add_option($name,$backup,'','no'))return new WP_Error('style_parity_backup_failed');
                            if(!is_array(get_option($name))||hash('sha256',(string)(get_option($name)['old']??''))!==hash('sha256',$raw))
                                return new WP_Error('style_parity_backup_readback_failed');
                            self::$locked=true;
                            try{$written=update_post_meta($target,'_elementor_data',wp_slash(wp_json_encode($new,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));}
                            finally{self::$locked=false;}
                            if(!$written||self::tree($target)!==$new)return new WP_Error('style_parity_write_readback_failed');
                            if(class_exists('Elementor\Core\Files\CSS\Post')){
                                try{$css=new \Elementor\Core\Files\CSS\Post($target);$css->delete();}
                                catch(\Throwable $e){$errors[]=['css_regeneration_required'=>$target];}
                            }
                            $row['updated']=true;$row['rollback_option']=$name;
                        }
                    }
                    $results[]=$row;
                }
            }
        }
        $out=['contract'=>self::CONTRACT,'complete'=>true,'applied'=>(bool)$apply,'translated_docs_scanned'=>$docs,
            'documents_with_style_drift'=>$drift,'widgets_with_style_drift'=>$pairs,'style_fields_drift'=>$changes,
            'issues'=>$alerts,'affected'=>array_slice($results,0,80),'warnings'=>$errors,
            'locale_text_links_and_rtl_preserved'=>true];
        if($apply)update_option(self::HEALTH,['at'=>gmdate('c'),'scanned'=>$docs,'fixed_documents'=>$drift,'fields'=>$changes,
            'css_warnings'=>$errors],false);
        return $out;
    }
    public static function external($input,$config){
        if(!is_array($input))return $input;$r=self::scan(false);
        if(is_wp_error($r)||!$r['complete'])return $input;
        $ids=array_fill_keys(array_column($input,'id'),true);
        foreach($r['issues'] as $row)if(!isset($ids[$row['id']]))$input[]=$row;
        return $input;
    }
    public static function changed($mid,$post_id,$key,$value){
        if(self::$locked||$key!=='_elementor_data'||!self::enabled()||self::lang($post_id)!==self::source_lang())return;
        if(!wp_next_scheduled(self::FAST))wp_schedule_single_event(time()+40,self::FAST);
    }
    public static function cron(){
        $r=self::scan(true);if(is_wp_error($r))error_log('[MAD4B style parity] '.$r->get_error_code());
    }
    public static function cli($args,$opts){
        $action=$args[0]??'preview';
        if($action==='preview')$r=self::scan(false);
        elseif($action==='apply'&&($opts['confirm']??'')==='SYNC INTERACTIVE STYLES ONLY')$r=self::scan(true);
        else WP_CLI::error('Allowed: preview, apply --confirm="SYNC INTERACTIVE STYLES ONLY"');
        if(is_wp_error($r))WP_CLI::error($r->get_error_code());
        WP_CLI::line(wp_json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    }
    public static function boot(){
        if(!self::enabled())return;
        add_filter('mad4b/content_readiness/external_issues',[__CLASS__,'external'],75,2);
        add_action('updated_post_meta',[__CLASS__,'changed'],60,4);
        add_action('added_post_meta',[__CLASS__,'changed'],60,4);
        add_action(self::CRON,[__CLASS__,'cron']);
        add_action(self::FAST,[__CLASS__,'cron']);
        add_action('init',static function(){if(!wp_next_scheduled(self::CRON))wp_schedule_event(time()+180,'hourly',self::CRON);},50);
        if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))WP_CLI::add_command('mad4b style parity',[__CLASS__,'cli']);
    }
}
add_action('plugins_loaded',['MAD4B_Elementor_Style_Parity_V1','boot'],65);
