<?php
/**
 * Plugin Name: MAD4B Elementor/WPML Integrity & Translation Outbox
 * Description: Read-only localization gap discovery, safe shared-style synchronization and authenticated MCP Client task pickup.
 * Version: 0.1.0-staging
 */
defined('ABSPATH') || exit;
final class MAD4B_Elementor_Localization_V1 {
    const OPTION='mad4b_elementor_localization_outbox_v1';
    const REPORT='mad4b_elementor_localization_health_v1';
    const RENDER='mad4b_elementor_render_observations_v1';
    const SOURCE='mad4b-elementor-localization';
    private static $busy=false;
    private static $text_keys=['title','editor','text','description','button_text','placeholder','label','heading','subtitle','caption','content','text_content','html','before_text','after_text','item_text','tab_title','tab_content','button_label'];
    private static function enabled() {
        return wp_get_environment_type()==='staging'
            && wp_parse_url(home_url(),PHP_URL_HOST)==='tours.dev.mad4b.com'
            && defined('ICL_SITEPRESS_VERSION');
    }
    private static function types(){return ['page','tours-and-activities','elementor_library','product'];}
    private static function languages() {
        $langs=apply_filters('wpml_active_languages',null,'skip_missing=0');
        return is_array($langs)?array_values(array_intersect(['en','fr','de','es','it','ar'],array_keys($langs))):[];
    }
    private static function default_lang(){return (string)apply_filters('wpml_default_language',null);}
    private static function lang($id){
        $d=apply_filters('wpml_post_language_details',null,(int)$id);
        return is_array($d)?(string)($d['language_code']??''):'';
    }
    private static function translations($id,$type){
        $trid=apply_filters('wpml_element_trid',null,$id,'post_'.$type);
        if(!$trid)return [];
        $data=apply_filters('wpml_get_element_translations',null,$trid,'post_'.$type);
        $out=[];
        foreach((array)$data as $lang=>$v)if(is_object($v)&&!empty($v->element_id))$out[$lang]=(int)$v->element_id;
        return $out;
    }
    private static function tree($id){
        $raw=get_post_meta($id,'_elementor_data',true);
        if(!$raw)return [];
        $value=is_string($raw)?json_decode($raw,true):$raw;
        return is_array($value)?$value:[];
    }
    private static function flatten($tree,$parent='root',&$map=[]){
        foreach((array)$tree as $order=>$node){
            if(!is_array($node)||!isset($node['id']))continue;
            $id=(string)$node['id'];
            if(!preg_match('/^[a-z0-9]{5,15}$/i',$id)||isset($map[$id]))continue;
            $map[$id]=['id'=>$id,'parent'=>$parent,'order'=>(int)$order,
                'type'=>(string)($node['widgetType']??$node['elType']??''),
                'settings'=>(array)($node['settings']??[])];
            if(!empty($node['elements']))self::flatten($node['elements'],$id,$map);
        }
        return $map;
    }
    private static function text($value){
        if(!is_scalar($value)||is_bool($value))return '';
        $s=trim(html_entity_decode(wp_strip_all_tags((string)$value),ENT_QUOTES,'UTF-8'));
        if(str_contains($s,'[')||str_contains($s,'{{')||preg_match('/^https?:|^\d+$/',$s))return '';
        return preg_replace('/\s+/u',' ',$s);
    }
    private static function english_text($text){
        return strlen($text)>=9 && preg_match('/[A-Za-z]{3,}/',$text);
    }
    private static function alerts_for($src_id,$type,$tr,$langs,$default){
        $source=self::tree($src_id);
        if(!$source)return [[],[]];
        $base=self::flatten($source);$issues=[];$jobs=[];
        $render=get_option(self::RENDER,[]);
        $render=is_array($render)?$render:[];
        if($type==='page' && isset($render['h1'][$src_id]) && (int)$render['h1'][$src_id]['count']===0){
            $code='loc_h1_'.$default;
            $issues[]=['id'=>hash('sha256',get_current_blog_id().'|'.$type.'|'.$src_id.'|'.$code),
                'code'=>$code,'post_type'=>$type,'object_id'=>$src_id,'severity'=>'medium',
                'label'=>get_the_title($src_id).': browser-verified published page has no visible H1',
                'source'=>self::SOURCE];
        }
        foreach($langs as $lang){
            if($lang===$default)continue;
            $dst=$tr[$lang]??0;
            $codes=[];
            if(!$dst||!get_post($dst)||get_post_status($dst)!=='publish'){
                $codes['missing']='Published eligible content has no published '.$lang.' translation';
            } else {
                $target=self::flatten(self::tree($dst));
                $missing=array_diff_key($base,$target);
                $changed=[];
                foreach($base as $id=>$node){
                    if(!isset($target[$id]))continue;
                    $t=$target[$id];
                    if($t['type']!==$node['type']||$t['parent']!==$node['parent']||$t['order']!==$node['order'])
                        $changed[]=$id;
                }
                if($missing||$changed) $codes['layout']='Elementor layout differs from source in '.$lang.' ('.count($missing).' absent, '.count($changed).' moved/changed)';
                $equal=[];
                foreach($base as $id=>$node){
                    if(!isset($target[$id]))continue;
                    foreach(self::$text_keys as $key){
                        $a=self::text($node['settings'][$key]??'');
                        $b=self::text($target[$id]['settings'][$key]??'');
                        if(self::english_text($a)&&$b===$a){$equal[]=$id.':'.$key;}
                    }
                }
                if($equal) $codes['text']='Elementor text remains identical to English in '.$lang.' ('.count($equal).' fields)';

            }
            $rendered=0;
            foreach((array)($render['leaks']??[]) as $leak){
                if(!is_array($leak)||($leak['lang']??'')!==$lang)continue;
                $template_id=(int)($leak['template_id']??0);
                $owner=$template_id?apply_filters('wpml_object_id',$template_id,'elementor_library',false,$default):0;
                if(!$owner && $template_id)$owner=$template_id;
                if($type==='elementor_library' && (int)$owner===$src_id)$rendered++;
                elseif($type==='page' && !$template_id && (int)($leak['page_id']??0)===$src_id)$rendered++;
            }
            if($rendered && !isset($codes['missing']))
                $codes['text']='Browser confirms '.$rendered.' English Elementor widgets in '.$lang.' rendered output';
            foreach($codes as $code=>$message){
                $full='loc_'.$code.'_'.$lang;
                $row=['id'=>hash('sha256',get_current_blog_id().'|'.$type.'|'.$src_id.'|'.$full),
                    'code'=>$full,'post_type'=>$type,'object_id'=>$src_id,
                    'severity'=>in_array($code,['missing','layout'],true)?'high':'medium',
                    'label'=>mb_substr('['.$lang.'] '.get_the_title($src_id).': '.$message,0,239),
                    'source'=>self::SOURCE];
                $issues[]=$row;
                if(in_array($code,['missing','layout','text'],true)){
                    $jobs[]=['key'=>hash('sha256',home_url().'|'.$src_id.'|'.$lang.'|'.$code),
                        'source_post_id'=>$src_id,'target_post_id'=>$dst,'post_type'=>$type,
                        'target_language'=>$lang,'gap_code'=>$full,'issue_id'=>$row['id'],
                        'source_sha256'=>hash('sha256',wp_json_encode($source)),
                        'scope'=>'company-published-visible-content',
                        'stage'=>'WPML_TRANSLATION','executor'=>'mcp_client',
                        'operation'=>$code==='layout'?'align_elementor_layout_and_translate':'translate_missing_elementor_strings',
                        'preserve_translations'=>true,'no_automatic_publish'=>true];
                }
            }
        }
        return [$issues,$jobs];
    }
    private static function active_templates(){
        // Resolve only templates referenced by published frontend content; never
        // blanket-queue all saved Elementor library drafts/popup experiments.
        $active=[];
        $scan=function($tree)use(&$scan,&$active){
            foreach((array)$tree as $node){
                if(!is_array($node))continue;
                $v=$node['settings']['template_id']??0;
                if(is_numeric($v) && (int)$v>0)$active[(int)$v]=true;
                $shortcode=$node['settings']['shortcode']??'';
                if(is_string($shortcode)&&preg_match_all('/\[elementor-template\s+[^\]]*id=[\"\']?([0-9]+)/',$shortcode,$matches))
                    foreach($matches[1] as $id)$active[(int)$id]=true;
                if(!empty($node['elements']))$scan($node['elements']);
            }
        };
        foreach(['page','tours-and-activities','product'] as $type){
            $ids=get_posts(['post_type'=>$type,'post_status'=>'publish','numberposts'=>-1,
                'fields'=>'ids','suppress_filters'=>true]);
            foreach($ids as $id)$scan(self::tree($id));
        }
        // Follow one-level references in linked templates, to detect actual
        // rendered template sections without scanning the whole library.
        $first=array_keys($active);
        foreach(array_slice($first,0,160) as $id)$scan(self::tree($id));
        return $active;
    }
    public static function inspect($limit=250){
        $limit=max(1,min(500,(int)$limit));
        if(!self::enabled())return ['complete'=>false,'issues'=>[],'jobs'=>[],'reason'=>'target_unavailable'];
        $langs=self::languages();$default=self::default_lang();
        $issues=[];$jobs=[];$seen=0;$counts=[];$active=self::active_templates();
        foreach(self::types() as $type){
            $ids=get_posts(['post_type'=>$type,'post_status'=>'publish','numberposts'=>-1,
                'fields'=>'ids','suppress_filters'=>true,'orderby'=>'ID','order'=>'DESC']);
            $counts[$type]=count($ids);
            foreach($ids as $id){
                if($type==='elementor_library'&&!isset($active[$id]))continue;
                if(self::lang($id)!==$default)continue;
                $seen++;if($seen>$limit)break 2;
                [$a,$b]=self::alerts_for((int)$id,$type,self::translations($id,$type),$langs,$default);
                array_push($issues,...$a);array_push($jobs,...$b);
            }
        }
        return ['contract'=>'mad4b.elementor-localization.scan.v1','complete'=>$seen<=$limit,
            'scanned_sources'=>min($seen,$limit),'post_counts'=>$counts,
            'issues'=>$issues,'jobs'=>$jobs,'locale_count'=>count($langs),
            'source_language'=>$default];
    }
    public static function external($rows,$cfg){
        if(!is_array($rows)||!self::enabled())return $rows;
        $scan=self::inspect(450);
        if(!$scan['complete'])return $rows; // Existing issue ledger refuses incomplete scans.
        return array_merge($rows,$scan['issues']);
    }
    public static function outbox(){
        $jobs=get_option(self::OPTION,[]);
        return is_array($jobs)?$jobs:[];
    }
    public static function queue_scan(){
        if(!self::enabled())return new WP_Error('mad4b_localization_unavailable');
        $s=self::inspect(450);
        if(!$s['complete'])return new WP_Error('mad4b_localization_incomplete_scan');
        $old=self::outbox();$new=$old;$active=[];$now=gmdate('c');
        foreach($s['jobs'] as $job){
            $key=$job['key'];$active[$key]=1;
            $before=$new[$key]??[];
            $same=isset($before['source_sha256'])&&$before['source_sha256']===$job['source_sha256'];
            $state=$same?($before['status']??'queued'):'queued';
            if(in_array($state,['completed','verified'],true))$state='queued';
            if($state==='leased' && strtotime($before['lease_until']??'1970-01-01')<time())$state='queued';
            $new[$key]=array_merge($job,[
                'status'=>$state,'created_at'=>$before['created_at']??$now,
                'updated_at'=>$now,'attempts'=>(int)($before['attempts']??0),
                'last_error'=>$before['last_error']??null,
                'lease_until'=>$state==='leased'?($before['lease_until']??null):null]);
        }
        foreach($new as $key=>$job)if(!isset($active[$key])&&in_array($job['status'],['queued','leased'],true)){
            $new[$key]['status']='resolved_after_readback';
            $new[$key]['updated_at']=$now;
        }
        if(count($new)>2300)return new WP_Error('mad4b_localization_queue_capacity');
        if($new!==$old){
            update_option(self::OPTION,$new,false);
            if(get_option(self::OPTION,[])!==$new)return new WP_Error('mad4b_localization_queue_readback_failed');
        }
        update_option(self::REPORT,['at'=>$now,'scan_sources'=>$s['scanned_sources'],
            'issues'=>count($s['issues']),'jobs'=>count($s['jobs']),
            'pending'=>count(array_filter($new,static fn($j)=>($j['status']??'')==='queued')),
            'mcp_execution_verified'=>false],false);
        do_action('mad4b/localization/jobs_reconciled',['at'=>$now,'queued'=>count($s['jobs'])]);
        return ['contract'=>$s['contract'],'status'=>'scanned','sources'=>$s['scanned_sources'],
            'issues'=>count($s['issues']),'jobs'=>count($s['jobs']),
            'outbox_total'=>count($new),'actual_mcp_execution'=>false];
    }
    private static function is_style($key,$rtl=false){
        $key=(string)$key;
        if(in_array($key,['template_id','shortcode','__dynamic__','jedv_conditions','display_condition_list','__globals__'],true))return false;
        // Never replace locale-bound media/URLs or Arabic typography/direction.
        if(preg_match('/(?:image|video|media|icon|url|link|placeholder)/i',$key))return false;
        if($rtl && preg_match('/(?:direction|align|justify|order|float|font|typography|margin|padding|transform)/i',$key))return false;
        return (bool)preg_match('/^(?:_?(?:margin|padding|width|height|align|flex|grid|gap|order|position|overflow|z_index|border|box_shadow|background|shape_divider|typography|font|color|opacity|css_classes|size|display|hide_on|sticky|animation|transform|custom_css|justify|direction|content_width|object_fit|space_between|column|row|section|container|jet_sticky))/', $key);
    }
    private static function merge_existing($source,$target,&$updated=0,$rtl=false){
        $map=[];foreach((array)$target as $node)if(is_array($node)&&isset($node['id']))$map[$node['id']]=$node;
        $output=[];$used=[];
        foreach((array)$source as $s){
            $id=$s['id']??'';
            if(!$id||!isset($map[$id]))continue; // Missing widget cannot expose untranslated English.
            $t=$map[$id];$used[$id]=true;
            if(($s['widgetType']??$s['elType']??'')!==($t['widgetType']??$t['elType']??'')){$output[]=$t;continue;}
            foreach((array)($s['settings']??[]) as $k=>$v)if(self::is_style($k,$rtl)) {
                if(!isset($t['settings'][$k])||$t['settings'][$k]!==$v){$t['settings'][$k]=$v;$updated++;}
            }
            $original=$t['elements']??[];
            $t['elements']=self::merge_existing($s['elements']??[],$original,$updated,$rtl);
            $output[]=$t;
        }
        foreach($map as $id=>$t)if(!isset($used[$id]))$output[]=$t; // Preserve locale-specific widgets.
        if(array_keys($output)!==array_keys($target)||$output!==$target)$updated++;
        return $output;
    }
    public static function sync_source($id,$apply=false){
        $id=(int)$id;$post=get_post($id);
        if(!self::enabled()||!$post||!in_array($post->post_type,self::types(),true)||self::lang($id)!==self::default_lang())
            return new WP_Error('mad4b_localization_source_invalid');
        $source=self::tree($id);
        if(!$source)return new WP_Error('mad4b_localization_not_elementor');
        $results=[];
        foreach(self::translations($id,$post->post_type) as $lang=>$target_id){
            if($lang===self::default_lang()||get_post_status($target_id)!=='publish')continue;
            $before=self::tree($target_id);
            if(!$before)continue;
            $changes=0;$after=self::merge_existing($source,$before,$changes,$lang==='ar');
            $delta=$after!==$before;
            $row=['lang'=>$lang,'target_id'=>$target_id,'style_or_order_changes'=>$changes,
                'would_update'=>$delta,'updated'=>false,'missing_nodes'=>count(array_diff_key(self::flatten($source),self::flatten($before)))];
            if($apply&&$delta){
                $old=get_post_meta($target_id,'_elementor_data',true);
                $backup='mad4b_layout_backup_'.(int)$target_id.'_'.substr(hash('sha256',(string)$old),0,12);
                update_option($backup,['source'=>$id,'target'=>$target_id,'at'=>gmdate('c'),'original'=>$old],false);
                if(!get_option($backup))return new WP_Error('mad4b_localization_backup_failed');
                self::$busy=true;
                try{$ok=update_post_meta($target_id,'_elementor_data',wp_slash(wp_json_encode($after)));}
                finally{self::$busy=false;}
                if(!$ok||self::tree($target_id)!==$after)return new WP_Error('mad4b_localization_sync_readback_failed');
                $row['updated']=true;$row['rollback_option']=$backup;
            }
            $results[]=$row;
        }
        return ['contract'=>'mad4b.elementor-structure-sync.v1','source'=>$id,'applied'=>(bool)$apply,'translations'=>$results,
            'translation_texts_overwritten'=>false,'missing_widgets_copied'=>false];
    }
    public static function after_meta($meta_id,$id,$key,$value){
        if(self::$busy||$key!=='_elementor_data'||!self::enabled())return;
        if(self::lang($id)!==self::default_lang())return;
        self::sync_source($id,true);
        wp_schedule_single_event(time()+30,'mad4b_localization_scan_v1');
    }
    public static function cron_scan(){
        $r=self::queue_scan();
        if(is_wp_error($r))error_log('[MAD4B Localization] Scan: '.$r->get_error_code());
    }
    public static function cli($args,$opts){
        $mode=$args[0]??'status';
        if($mode==='status'){
            WP_CLI::line(wp_json_encode(['report'=>get_option(self::REPORT,[]),
                'pending'=>array_slice(array_values(array_filter(self::outbox(),static fn($x)=>$x['status']==='queued')),0,8)],JSON_PRETTY_PRINT));
            return;
        }
        if($mode==='preview'){
            $id=(int)($opts['source']??35138);
            $r=self::sync_source($id,false);
        }elseif($mode==='scan'){$r=self::queue_scan();}
        elseif($mode==='sync'){
            $id=(int)($opts['source']??0);
            if(!$id||($opts['confirm']??'')!=='SYNC ORIGINAL STYLES ONLY')
                WP_CLI::error('Exact --source and --confirm required');
            $r=self::sync_source($id,true);
        }else WP_CLI::error('Allowed: status, preview, scan, sync');
        if(is_wp_error($r))WP_CLI::error($r->get_error_code());
        WP_CLI::line(wp_json_encode($r,JSON_PRETTY_PRINT));
    }
    private static function with_outbox_lock($callback){
        global $wpdb;
        $lock='mad4b_elementor_localization_v1';
        $ok=(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,2)',$lock))==='1';
        if(!$ok)return new WP_Error('mad4b_localization_busy');
        try{return $callback();}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    public static function claim($input){
        if(!self::enabled()||!current_user_can('edit_others_posts'))
            return new WP_Error('mad4b_localization_claim_unauthorized');
        $key=(string)($input['key']??'');$expected=(string)($input['expected_source_sha256']??'');
        if(!preg_match('/^[a-f0-9]{64}$/D',$key)||!preg_match('/^[a-f0-9]{64}$/D',$expected))
            return new WP_Error('mad4b_localization_bad_request');
        return self::with_outbox_lock(static function()use($key,$expected){
            $jobs=self::outbox();$j=$jobs[$key]??null;
            if(!$j||$j['status']!=='queued'||!hash_equals($j['source_sha256'],$expected))
                return new WP_Error('mad4b_localization_stale_or_not_queued');
            $current=hash('sha256',wp_json_encode(self::tree($j['source_post_id'])));
            if(!hash_equals($expected,$current))return new WP_Error('mad4b_localization_source_moved');
            if((int)($j['attempts']??0)>=5)return new WP_Error('mad4b_localization_retry_limit');
            $j['status']='leased';$j['attempts']=(int)($j['attempts']??0)+1;
            $j['lease_until']=gmdate('c',time()+1800);
            $j['claimed_at']=gmdate('c');$j['claimed_by_user']=get_current_user_id();
            $jobs[$key]=$j;
            update_option(self::OPTION,$jobs,false);
            if(get_option(self::OPTION,[])!==$jobs)return new WP_Error('mad4b_localization_claim_write_failed');
            return ['contract'=>'mad4b.localization.claim.v1','job'=>$j,
                'client_must_translate_using_governed_content_abilities'=>true,
                'auto_publish'=>false];
        });
    }
    public static function ack($input){
        if(!self::enabled()||!current_user_can('edit_others_posts'))
            return new WP_Error('mad4b_localization_ack_unauthorized');
        $key=(string)($input['key']??'');$expected=(string)($input['expected_source_sha256']??'');
        $receipt=(string)($input['receipt_id']??'');
        if(!preg_match('/^[a-f0-9]{64}$/D',$key)||!preg_match('/^[a-f0-9]{64}$/D',$expected)
            ||!preg_match('/^[a-zA-Z0-9:._-]{8,150}$/D',$receipt))
            return new WP_Error('mad4b_localization_ack_bad_request');
        return self::with_outbox_lock(static function()use($key,$expected,$receipt){
            $jobs=self::outbox();$j=$jobs[$key]??null;
            if(!$j||($j['status']??'')!=='leased'||!hash_equals($expected,$j['source_sha256']))
                return new WP_Error('mad4b_localization_ack_not_leased');
            if((int)($j['claimed_by_user']??0)!==get_current_user_id())
                return new WP_Error('mad4b_localization_claimed_by_other');
            $post=get_post($j['source_post_id']);if(!$post)return new WP_Error('mad4b_localization_source_gone');
            $current=hash('sha256',wp_json_encode(self::tree($j['source_post_id'])));
            if(!hash_equals($expected,$current))return new WP_Error('mad4b_localization_source_changed');
            [$issues]=self::alerts_for((int)$post->ID,$post->post_type,self::translations($post->ID,$post->post_type),
                self::languages(),self::default_lang());
            $still=array_filter($issues,static fn($i)=>$i['id']===$j['issue_id']);
            $passed=!$still;
            $j['status']=$passed?'verified':'needs_review';
            $j['acknowledged_at']=gmdate('c');$j['receipt_id']=$receipt;
            $j['readback_passed']=$passed;
            $j['last_error']=$passed?null:'source_issue_still_visible';
            $jobs[$key]=$j;
            update_option(self::OPTION,$jobs,false);
            if(get_option(self::OPTION,[])!==$jobs)return new WP_Error('mad4b_localization_ack_write_failed');
            return ['contract'=>'mad4b.localization.ack.v1','status'=>$j['status'],
                'source_readback_verified'=>$passed,'receipt_id'=>$receipt];
        });
    }
    public static function rest_register(){
        register_rest_route('mad4b-localization/v1','/jobs',[
            'methods'=>'GET','permission_callback'=>static fn()=>current_user_can('edit_others_posts'),
            'callback'=>static function($request){
                $list=array_values(array_filter(self::outbox(),static fn($x)=>($x['status']??'')==='queued'));
                return rest_ensure_response(['contract'=>'mad4b.localization.mcp-outbox.v1',
                    'executor'=>'mcp_client','requires_authenticated_client'=>true,
                    'status'=>'queued_not_executed','jobs'=>array_slice($list,0,40)]);
            }]);
        foreach(['claim'=>'claim','ack'=>'ack'] as $action=>$method)
            register_rest_route('mad4b-localization/v1','/jobs/'.$action,[
                'methods'=>'POST','permission_callback'=>static fn()=>current_user_can('edit_others_posts'),
                'callback'=>static function($request)use($method){
                    $r=self::$method($request->get_json_params());
                    return is_wp_error($r)?$r:rest_ensure_response($r);
                }]);
    }
    public static function register_abilities(){
        if(!function_exists('wp_register_ability')||!self::enabled())return;
        $name='mad4b/localization-jobs';
        if(function_exists('wp_has_ability')&&wp_has_ability($name))return;
        wp_register_ability($name,[
            'label'=>'MAD4B Localization MCP Client Job Outbox',
            'description'=>'Authenticated MCP Client reads pending Elementor/WPML translation jobs. Reading does not execute translation.',
            'category'=>'mad4b-read',
            'execute_callback'=>static fn($in)=>['contract'=>'mad4b.localization.mcp-outbox.v1',
                'status'=>'queued_not_executed','jobs'=>array_slice(array_values(array_filter(
                    self::outbox(),static fn($x)=>($x['status']??'')==='queued')),0,40)],
            'permission_callback'=>static fn($in)=>current_user_can('edit_others_posts'),
            'input_schema'=>['type'=>'object','properties'=>[],'additionalProperties'=>false],
            'output_schema'=>['type'=>'object','additionalProperties'=>true],
            'meta'=>['mcp'=>['public'=>false,'type'=>'tool','surface'=>'read']]
        ]);
        foreach(['mad4b/localization-claim'=>'claim','mad4b/localization-ack'=>'ack'] as $ability=>$method){
            if(function_exists('wp_has_ability')&&wp_has_ability($ability))continue;
            $schema=['type'=>'object','properties'=>[
                'key'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],
                'expected_source_sha256'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],
                'receipt_id'=>['type'=>'string','minLength'=>8,'maxLength'=>150],
            ],'required'=>$method==='ack'?['key','expected_source_sha256','receipt_id']:['key','expected_source_sha256'],
                'additionalProperties'=>false];
            wp_register_ability($ability,[
                'label'=>$method==='claim'?'Claim Localized Content Job':'Acknowledge Localized Content Result',
                'description'=>'Authenticated exact-source MCP client operation with durable readback.',
                'category'=>'mad4b-write','execute_callback'=>static fn($in)=>self::$method($in),
                'permission_callback'=>static fn($in)=>current_user_can('edit_others_posts'),
                'input_schema'=>$schema,'output_schema'=>['type'=>'object','additionalProperties'=>true],
                'meta'=>['mcp'=>['public'=>false,'type'=>'tool','surface'=>'write']]
            ]);
        }
    }
    public static function admin(){
        if(!current_user_can('edit_others_posts'))return;
        $h=get_option(self::REPORT,[]);
        echo '<div class="notice notice-info"><p>MAD4B Localization: '.esc_html((string)($h['issues']??'not scanned')).
            ' Elementor/WPML gaps; MCP client jobs queued: '.esc_html((string)($h['pending']??'unknown')).
            ' — <a href="'.esc_url(admin_url('admin.php?page=mad4b-content-readiness-alerts')).'">Content Readiness</a></p></div>';
    }
    public static function boot(){
        if(!self::enabled())return;
        add_filter('mad4b/content_readiness/external_issues',[__CLASS__,'external'],30,2);
        add_action('updated_post_meta',[__CLASS__,'after_meta'],20,4);
        add_action('added_post_meta',[__CLASS__,'after_meta'],20,4);
        add_action('elementor/editor/after_save',static function($post_id){
            if(self::$busy||!self::enabled())return;
            $r=self::sync_source((int)$post_id,true);
            if(!is_wp_error($r))wp_schedule_single_event(time()+30,'mad4b_localization_scan_v1');
        },100,1);
        add_action('mad4b_localization_scan_v1',[__CLASS__,'cron_scan']);
        add_action('init',static function(){if(!wp_next_scheduled('mad4b_localization_scan_v1'))
            wp_schedule_event(time()+60,'hourly','mad4b_localization_scan_v1');},30);
        add_action('rest_api_init',[__CLASS__,'rest_register']);
        add_action('wp_abilities_api_init',[__CLASS__,'register_abilities'],40);
        add_action('admin_notices',[__CLASS__,'admin']);
        if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
            WP_CLI::add_command('mad4b localization',[__CLASS__,'cli']);
    }
}
add_action('plugins_loaded',['MAD4B_Elementor_Localization_V1','boot'],30);
