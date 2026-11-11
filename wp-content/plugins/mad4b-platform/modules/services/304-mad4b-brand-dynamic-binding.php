<?php
/**
 * Plugin Name: MAD4B Elementor Company Dynamic Binding Parity
 * Description: Synchronizes verified company-brand/contact dynamic tags from WPML source into matching translated widget slots, never copies a fixed company value.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Brand_Dynamic_Binding_V1 {
    const HOOK='mad4b_brand_dynamic_binding_sync_v1';
    const HEALTH='mad4b_brand_dynamic_binding_health_v1';
    const TYPES=['elementor_library','page','tours-and-activities','product'];
    private static $writing=false;
    private static function enabled(){
        $p=get_option('mad4b_scp_brand_context_profile_v1',[]);
        $x=get_option('mad4b_brand_surface_policy_v1',[]);
        return wp_get_environment_type()==='staging'
            && wp_parse_url(home_url(),PHP_URL_HOST)==='tours.dev.mad4b.com'
            && is_array($p)&&is_array($x)&&!empty($p['site_uuid'])
            && hash_equals((string)$p['site_uuid'],(string)($x['site_uuid']??''))
            && hash_equals((string)$p['brand_name'],(string)($x['brand_name']??''));
    }
    private static function lang($id){
        $d=apply_filters('wpml_post_language_details',null,(int)$id);
        return is_array($d)?(string)($d['language_code']??''):'';
    }
    private static function base(){
        return (string)apply_filters('wpml_default_language',null);
    }
    private static function tree($id){
        $raw=get_post_meta((int)$id,'_elementor_data',true);
        $arr=is_string($raw)?json_decode($raw,true):$raw;
        return is_array($arr)?$arr:[];
    }
    private static function allowed($expr){
        if(!is_string($expr)||strlen($expr)>3000)return false;
        $jet=str_contains($expr,'name="jet-options-page"')
            && (bool)preg_match('~contact-and-help-info(?:%3A%3A|::)~i',$expr);
        $brand=str_contains($expr,'name="company-brand-media"');
        return str_contains($expr,'[elementor-tag') && ($jet||$brand);
    }
    private static function field($settings,$key){
        $dyn=$settings['__dynamic__'][$key]??null;
        return self::allowed($dyn)?$dyn:null;
    }
    private static function merge_node($src,$dst,&$count,&$missing){
        $se=(array)($src['settings']??[]);
        $de=(array)($dst['settings']??[]);
        // Bind only existing settings whose source is a trusted company dynamic tag.
        foreach((array)($se['__dynamic__']??[]) as $field=>$expr){
            if(!self::allowed($expr))continue;
            if(($de['__dynamic__'][$field]??null)!==$expr){
                $de['__dynamic__'][$field]=$expr;$count++;
            }
            if(str_contains($expr,'jet-options-page') && isset($de[$field]) && $de[$field]!=='' && $de[$field]!==[]){
                $de[$field]=is_array($de[$field])?[]:'';$count++;
            }
        }
        // Nested icon-list items use the source item's stable _id, not a translated
        // array index. Preserve widget styles and local-only items.
        foreach(['icon_list','social_icon_list'] as $list){
            $source_items=$se[$list]??null;
            if(!is_array($source_items))continue;
            $dest_items=$de[$list]??[];
            if(!is_array($dest_items))continue;
            $pos=[];foreach($dest_items as $i=>$item)if(!empty($item['_id']))$pos[$item['_id']]=$i;
            foreach($source_items as $item){
                $id=(string)($item['_id']??'');if(!$id)continue;
                $trusted=[];
                foreach((array)($item['__dynamic__']??[]) as $key=>$expr)
                    if(self::allowed($expr)&&in_array($key,['text','link'],true))$trusted[$key]=$expr;
                if(!$trusted)continue;
                if(!array_key_exists($id,$pos)){$missing[]=['widget'=>$src['id']??'','item'=>$id];continue;}
                $i=$pos[$id];
                foreach($trusted as $field=>$expr){
                    if(($dest_items[$i]['__dynamic__'][$field]??null)!==$expr){
                        $dest_items[$i]['__dynamic__'][$field]=$expr;$count++;
                    }
                    if($field==='text' && !empty($dest_items[$i]['text'])){
                        $dest_items[$i]['text']='';$count++;
                    }
                    if($field==='link'){
                        $link=$dest_items[$i]['link']??[];
                        if(!is_array($link))$link=[];
                        if(!empty($link['url'])){$link['url']='';$count++;}
                        $dest_items[$i]['link']=$link;
                    }
                }
            }
            $de[$list]=$dest_items;
        }
        $dst['settings']=$de;
        return $dst;
    }
    private static function merge_tree($source,$target,&$count,&$missing){
        $source_map=[];foreach((array)$source as $node)if(isset($node['id']))$source_map[$node['id']]=$node;
        foreach($target as &$node){
            $id=$node['id']??'';
            if($id!==''&&isset($source_map[$id])
                && (($node['widgetType']??$node['elType']??'')===($source_map[$id]['widgetType']??$source_map[$id]['elType']??''))){
                $src=$source_map[$id];
                $node=self::merge_node($src,$node,$count,$missing);
                if(!empty($node['elements']) && !empty($src['elements'])){
                    $node['elements']=self::merge_tree($src['elements'],$node['elements'],$count,$missing);
                }
            }
        }
        unset($node);
        foreach($source_map as $id=>$src){
            if(!isset($src['settings']))continue;
            $matched=false;foreach($target as $t)if(($t['id']??'')===$id){$matched=true;break;}
            if(!$matched){
                $contacts=false;
                foreach((array)($src['settings']['icon_list']??[]) as $item)
                    foreach((array)($item['__dynamic__']??[]) as $x)if(self::allowed($x))$contacts=true;
                if($contacts)$missing[]=['widget'=>$id,'item'=>'entire_widget'];
            }
        }
        return $target;
    }
    public static function reconcile($apply=false,$source_limit=650){
        if(!self::enabled())return new WP_Error('mad4b_dynamic_site_not_bound');
        $sources=[];$seen=0;$total=0;$changed=0;$unmatched=[];$results=[];
        foreach(self::TYPES as $type){
            $ids=get_posts(['post_type'=>$type,'post_status'=>'publish','numberposts'=>-1,
                'fields'=>'ids','suppress_filters'=>true]);
            foreach($ids as $id)if(self::lang($id)===self::base())$sources[]=[(int)$id,$type];
        }
        if(count($sources)>$source_limit)return new WP_Error('mad4b_dynamic_scan_capacity');
        foreach($sources as [$id,$type]){
            $source=self::tree($id);if(!$source)continue;$seen++;
            $trid=apply_filters('wpml_element_trid',null,$id,'post_'.$type);
            if(!$trid)continue;
            $translations=(array)apply_filters('wpml_get_element_translations',null,$trid,'post_'.$type);
            foreach($translations as $lang=>$row){
                if($lang===self::base()||!in_array($lang,['en','ar','fr','de','es','it'],true))continue;
                $dst=(int)($row->element_id??0);if(!$dst||get_post_status($dst)!=='publish')continue;
                $old=(string)get_post_meta($dst,'_elementor_data',true);
                $target=self::tree($dst);if(!$target)continue;
                $n=0;$missing=[];$after=self::merge_tree($source,$target,$n,$missing);
                $total++;if($n>0)$changed++;
                if($missing)$unmatched[]=['source'=>$id,'target'=>$dst,'lang'=>$lang,'unmatched'=>array_slice($missing,0,12)];
                if($n>0){
                    $change=['source'=>$id,'target'=>$dst,'language'=>$lang,'binding_changes'=>$n,'applied'=>false];
                    if($apply){
                        $backup='mad4b_dynamic_binding_backup_'.$dst.'_'.substr(hash('sha256',$old),0,12);
                        if(!get_option($backup)&&!add_option($backup,['source'=>$id,'original'=>$old,'sha256'=>hash('sha256',$old),'at'=>gmdate('c')],'','no'))
                            return new WP_Error('mad4b_dynamic_backup_failed');
                        if(hash('sha256',(string)(get_option($backup)['original']??''))!==hash('sha256',$old))
                            return new WP_Error('mad4b_dynamic_backup_mismatch');
                        self::$writing=true;
                        try{$saved=update_post_meta($dst,'_elementor_data',wp_slash(wp_json_encode($after,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));}
                        finally{self::$writing=false;}
                        if(!$saved||self::tree($dst)!==$after)return new WP_Error('mad4b_dynamic_write_readback_failed');
                        $change['applied']=true;$change['rollback_option']=$backup;
                    }
                    $results[]=$change;
                }
            }
        }
        $report=['contract'=>'mad4b.dynamic-binding-parity.v1','complete'=>true,'applied'=>(bool)$apply,
            'sources_with_elementor'=>$seen,'translated_documents_checked'=>$total,'documents_needing_binding_repair'=>$changed,
            'missing_binding_slots'=>$unmatched,'updates'=>array_slice($results,0,100),'all_values_from_authoritative_source'=>true];
        if($apply)update_option(self::HEALTH,['at'=>gmdate('c'),'checked'=>$total,'corrected'=>$changed,'missing'=>count($unmatched)],false);
        return $report;
    }
    public static function external($issues,$cfg){
        if(!is_array($issues))return $issues;
        $r=self::reconcile(false);
        if(is_wp_error($r)||empty($r['complete']))return $issues;
        $seen=array_fill_keys(array_column($issues,'id'),true);
        foreach($r['updates'] as $x){
            $src=(int)$x['source'];$post=get_post($src);if(!$post)continue;
            $code='loc_binding_'.$x['language'];
            $id=hash('sha256',get_current_blog_id().'|'.$post->post_type.'|'.$src.'|'.$code);
            if(isset($seen[$id]))continue;
            $issues[]=['id'=>$id,'post_type'=>$post->post_type,'object_id'=>$src,
                'code'=>$code,'severity'=>'high','source'=>'mad4b-elementor-localization',
                'label'=>mb_substr('['.$x['language'].'] '.get_the_title($src).': translated Elementor lost '.
                    $x['binding_changes'].' company Dynamic Tag bindings',0,238)];
            $seen[$id]=true;
        }
        return $issues;
    }
    public static function event($mid,$id,$key,$value){
        if(self::$writing||$key!=='_elementor_data'||!in_array(get_post_type($id),self::TYPES,true)||!self::enabled())return;
        if(!wp_next_scheduled(self::HOOK))wp_schedule_single_event(time()+60,self::HOOK);
    }
    public static function cron(){
        $r=self::reconcile(true);
        if(is_wp_error($r))error_log('[MAD4B Dynamic Binding] '.$r->get_error_code());
    }
    public static function cli($args,$opts){
        if(($args[0]??'preview')==='preview')$r=self::reconcile(false);
        elseif(($args[0]??'')==='apply' && ($opts['confirm']??'')==='RECONCILE COMPANY DYNAMIC TAGS')$r=self::reconcile(true);
        else WP_CLI::error('Allowed: preview; apply --confirm="RECONCILE COMPANY DYNAMIC TAGS"');
        if(is_wp_error($r))WP_CLI::error($r->get_error_code());
        WP_CLI::line(wp_json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    }
    public static function boot(){
        if(!self::enabled())return;
        add_filter('mad4b/content_readiness/external_issues',[__CLASS__,'external'],65,2);
        add_action('updated_post_meta',[__CLASS__,'event'],50,4);
        add_action('added_post_meta',[__CLASS__,'event'],50,4);
        add_action(self::HOOK,[__CLASS__,'cron']);
        add_action('init',static function(){
            if(!wp_next_scheduled(self::HOOK))
                wp_schedule_event(time()+180,'hourly',self::HOOK);
        },40);
        if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
            WP_CLI::add_command('mad4b dynamic binding',[__CLASS__,'cli']);
    }
}
add_action('plugins_loaded',['MAD4B_Brand_Dynamic_Binding_V1','boot'],55);
