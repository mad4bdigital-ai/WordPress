<?php
/**
 * Plugin Name: MAD4B SEO Opportunities
 * Description: Site-wide review-only SEO opportunity intelligence. No automatic content jobs or publication.
 * Version: 0.1.0-staging
 * Requires PHP: 8.0
 */
defined('ABSPATH') || exit;
final class MAD4B_SEO_Opportunities {
    const CONTRACT='mad4b.seo-opportunities.v1';
    const STORE='mad4b_seo_opportunities_v1';
    const SNAPSHOT='mad4b_seo_opportunities_snapshot_v1';
    const MAX_TARGETS=2500;
    const MAX_AGE=90;
    public static function boot():void {
        add_action('rest_api_init',static function():void {
            register_rest_route('mad4b-seo/v1','/opportunities',array(
                'methods'=>'GET','permission_callback'=>static fn()=>current_user_can('manage_options'),
                'callback'=>static fn($req)=>rest_ensure_response(self::listing((int)$req->get_param('page'),(int)$req->get_param('limit'))),
            ));
        });
        foreach(array('mad4b_seo_opportunities_daily','mad4b_seo_opportunities_refresh')as $hook)
            add_action($hook,array(__CLASS__,'scheduled_scan'));
        add_action('transition_post_status',static function($new,$old,$post):void {
            if($new!=='publish'||$old==='publish'||!is_object($post)||
                !in_array((string)$post->post_type,self::types(),true))return;
            if(!wp_next_scheduled('mad4b_seo_opportunities_refresh')){
                wp_schedule_single_event(time()+300,'mad4b_seo_opportunities_refresh');
            }
        },20,3);
        add_action('admin_menu',static function():void {
            $parent=class_exists('MAD4B_SCP_Admin_Route_Registry',false)?'mad4b-control-plane':'tools.php';
            add_submenu_page($parent,'SEO Opportunities','SEO Opportunities','manage_options','mad4b-seo-opportunities',array(__CLASS__,'admin'));
        },99);
        if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI')) {
            WP_CLI::add_command('mad4b-seo-opportunities',array(__CLASS__,'cli'));
        }
    }
    public static function scheduled_scan():void {
        if(!wp_cache_add('reconcile_lock',time(),'mad4b_seo_opportunities',900))return;
        try{
            $result=self::scan(self::MAX_TARGETS,true);
            if(!empty($result['error']))error_log('MAD4B SEO Opportunities scheduled scan: '.sanitize_key($result['error']));
        }catch(Throwable $e){
            error_log('MAD4B SEO Opportunities scan interrupted; old ledger retained');
        }finally{
            wp_cache_delete('reconcile_lock','mad4b_seo_opportunities');
        }
    }
    private static function url_key(string $url):string {
        $a=wp_parse_url($url);$b=wp_parse_url(home_url('/'));
        if(!is_array($a)||!is_array($b)||empty($a['host'])||empty($b['host'])||
            strcasecmp((string)$a['host'],(string)$b['host'])!==0||
            !in_array(strtolower((string)($a['scheme']??'')),array('https','http'),true)||
            isset($a['user'])||isset($a['pass'])||isset($a['fragment'])||
            (int)($a['port']??(strtolower((string)$a['scheme'])==='https'?443:80))!==
                (int)($b['port']??(strtolower((string)($b['scheme']??'https'))==='https'?443:80)))return '';
        $path=rawurldecode((string)($a['path']??'/'));
        if(str_contains($path,'..')||str_contains($path,"\0"))return '';
        return strtolower((string)$a['host']).'|'.untrailingslashit('/'.ltrim($path,'/'));
    }
    public static function source_status():array {
        global $wpdb;
        $table=$wpdb->prefix.'rank_math_analytics_gsc';
        $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table;
        $last=$exists?(string)$wpdb->get_var("SELECT MAX(created) FROM {$table}"):'';
        $age=$last?time()-(strtotime($last.' UTC')?:0):PHP_INT_MAX;
        $fresh=$age>=0 && $age<=self::MAX_AGE*DAY_IN_SECONDS;
        $native=array();
        if(class_exists('MAD4B_SCP_G5_External_Providers',false)){
            foreach(MAD4B_SCP_G5_External_Providers::adapters() as $id=>$adapter){
                if($id!=='google-search-console')continue;
                try{
                    $check=MAD4B_SCP_G5_External_Providers::descriptor_guard($adapter,$adapter->descriptor());
                    if($check===true)$native[]=$id;
                }catch(Throwable $e){}
            }
        }
        $cp=class_exists('MAD4B_SCP_Native_Search_Console',false)
            ? MAD4B_SCP_Native_Search_Console::status() : array();
        return array('rank_math'=>array('state'=>$fresh?'fresh':'stale_or_absent','last_row'=>$last?:null,
            'age_days'=>$age===PHP_INT_MAX?null:(int)floor($age/DAY_IN_SECONDS)),
            'native'=>array('state'=>$native?'certified_adapter':'unavailable','providers'=>$native),
            'control_plane_gsc'=>array(
                'state'=>!empty($cp['retained_evidence_ready'])?'fresh_retained':
                    (!empty($cp['capture_ready'])?'consent_ready':'consent_or_connection_required'),
                'pages'=>(int)($cp['pages']??0)),
            'allowed_performance_sources'=>$fresh?array('rank_math'):
                (!empty($cp['retained_evidence_ready'])?array('mad4b_native_gsc'):$native),
            'no_credentials_accessed'=>true);
    }
    private static function rank_gsc(array $targetUrls,array $source):array {
        global $wpdb;
        if($source['rank_math']['state']!=='fresh'||!$targetUrls)return array();
        $table=$wpdb->prefix.'rank_math_analytics_gsc';
        $cutoff=gmdate('Y-m-d H:i:s',time()-self::MAX_AGE*DAY_IN_SECONDS);
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT page,SUM(clicks) clicks,SUM(impressions) impressions,
             SUM(ctr*impressions)/NULLIF(SUM(impressions),0) ctr,
             SUM(position*impressions)/NULLIF(SUM(impressions),0) position,
             MAX(created) observed_at FROM {$table} WHERE created>=%s
             GROUP BY page ORDER BY SUM(impressions) DESC LIMIT 5000",$cutoff),ARRAY_A);
        $out=array();
        foreach((array)$rows as $row){
            $key=self::url_key((string)$row['page']);
            if(!$key||!isset($targetUrls[$key])||(int)$row['impressions']<20)continue;
            $out[$key]=array('source'=>'rank_math_gsc','clicks'=>(int)$row['clicks'],
                'impressions'=>(int)$row['impressions'],'ctr'=>(float)$row['ctr'],
                'position'=>(float)$row['position'],'observed_at'=>$row['observed_at']);
        }
        return $out;
    }
    private static function native_gsc(array $targetUrls,array $source):array {
        // First reuse the native Control Plane's property-bound, fresh, retained
        // observations. It never exposes OAuth credentials to this plugin.
        $native=apply_filters('mad4b/seo_opportunities/native_page_metrics',array(),$targetUrls);
        if(is_array($native) && $native)return $native;
        // Next use MAD4B's G5 policy, consent, rights and provenance checks.
        // Server-owned connection code may supply immutable observation refs.
        // No generic Google HTTP calls or user-submitted credential handles.
        if(($source['native']['state']??'')!=='certified_adapter' ||
            !class_exists('MAD4B_SCP_G5_Growth_Evidence',false) ||
            !current_user_can('manage_options'))return array();
        $refs=apply_filters('mad4b/seo_opportunities/certified_g5_refs',array(),home_url('/'));
        if(!is_array($refs)||!$refs||count($refs)>20)return array();
        foreach($refs as $ref){
            if(!is_array($ref)||array_keys($ref)!==array('provider_id','observation_id') ||
                ($ref['provider_id']??'')!=='google-search-console' ||
                !is_string($ref['observation_id']) ||
                !preg_match('/^[a-f0-9]{64}$/D',$ref['observation_id']))return array();
        }
        $evidence=MAD4B_SCP_G5_Growth_Evidence::preview(array('observation_refs'=>$refs));
        if(is_wp_error($evidence)||!is_array($evidence)||!empty($evidence['authorizing']))return array();
        $out=array();
        foreach((array)($evidence['observations']??array())as $observed){
            if(($observed['provider_id']??'')!=='google-search-console' ||
                (int)($observed['observed_at']??0)<time()-self::MAX_AGE*DAY_IN_SECONDS ||
                (int)($observed['valid_until']??0)<=time() ||
                ($observed['cost']['state']??'')!=='settled' ||
                ($observed['sampling']['state']??'')!=='complete')continue;
            $url=(string)($observed['dimensions']['surface_ref']??'');
            $key=self::url_key($url);
            if(!$key||!isset($targetUrls[$key]))continue;
            $metrics=array();
            foreach((array)($observed['metrics']??array())as $metric){
                $semantic=(string)($metric['semantic_id']??'');
                $value=$metric['value']??null;
                if(!in_array($semantic,array('clicks','impressions','ctr','position'),true) ||
                    !is_numeric($value)||!is_finite((float)$value))continue;
                // Rank Math uses percentage points (0..100). G5 metric
                // semantics must state their unit; never assume a 0..1 CTR.
                if($semantic==='ctr'){
                    $unit=(string)($metric['unit']??'');
                    if(in_array($unit,array('ratio','fraction'),true))$value=(float)$value*100.0;
                    elseif(!in_array($unit,array('percent','percentage'),true))continue;
                }
                $metrics[$semantic]=(float)$value;
            }
            if(count($metrics)!==4 || $metrics['impressions']<20)continue;
            $out[$key]=array('source'=>'mad4b_g5_certified','clicks'=>(int)$metrics['clicks'],
                'impressions'=>(int)$metrics['impressions'],'ctr'=>$metrics['ctr'],
                'position'=>$metrics['position'],'observed_at'=>gmdate('c',$observed['observed_at']),
                'observation_id'=>$observed['observation_id']);
        }
        return $out;
    }
    private static function types():array {
        $types=array();
        foreach(get_post_types(array('public'=>true),'objects')as $name=>$obj){
            if(in_array($name,array('attachment','elementor_library'),true))continue;
            if(is_post_type_viewable($obj))$types[]=$name;
        }
        return $types;
    }
    private static function scores(array $ids):array {
        global $wpdb;
        $table=$wpdb->prefix.'rank_math_analytics_objects';
        if(!$ids||$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)return array();
        $scores=array();
        foreach(array_chunk($ids,150) as $chunk){
            $sql=$wpdb->prepare("SELECT object_id,seo_score,created FROM {$table} WHERE object_id IN (".
                implode(',',array_fill(0,count($chunk),'%d')).") ORDER BY created DESC,id DESC",...$chunk);
            foreach((array)$wpdb->get_results($sql,ARRAY_A)as $row){
                if(!isset($scores[(int)$row['object_id']]))$scores[(int)$row['object_id']]=$row;
            }
        }
        return $scores;
    }
    private static function opportunity(int $id,string $kind,int $priority,string $url,string $message,array $evidence):array {
        return array('id'=>hash('sha256',get_current_blog_id().'|'.$id.'|'.$kind),
            'post_id'=>$id,'post_type'=>get_post_type($id),'title'=>get_the_title($id),
            'url'=>$url,'kind'=>$kind,'priority'=>$priority,'recommendation'=>$message,
            'evidence'=>$evidence,'source'=>'mad4b-seo-opportunities','status'=>'candidate',
            'authorizing'=>false);
    }
    public static function scan(int $limit=self::MAX_TARGETS,bool $write=false):array {
        $scheduled=doing_action('mad4b_seo_opportunities_daily')||doing_action('mad4b_seo_opportunities_refresh');
        if(PHP_SAPI!=='cli'&&!$scheduled&&!current_user_can('manage_options'))
            return array('error'=>'unauthorized');
        $limit=max(1,min(self::MAX_TARGETS,$limit));
        $q=new WP_Query(array('post_type'=>self::types(),'post_status'=>'publish','fields'=>'ids',
            'posts_per_page'=>$limit,'no_found_rows'=>false,'orderby'=>'ID','order'=>'ASC',
            'suppress_filters'=>true,'ignore_sticky_posts'=>true));
        $ids=array_map('intval',$q->posts);
        $complete=(int)$q->found_posts<=count($ids);
        $targets=array();$urlIndex=array();$excluded=0;$duplicateUrls=0;
        $rankTemplates=(array)get_option('rank-math-options-titles',array());
        foreach($ids as $id){
            $url=get_permalink($id);
            $language='';
            if(has_filter('wpml_post_language_details')!==false && is_string($url)){
                $details=apply_filters('wpml_post_language_details',null,$id);
                $language=is_array($details)?sanitize_key((string)($details['language_code']??'')):'';
                if($language!=='' && has_filter('wpml_permalink')!==false){
                    $translated=apply_filters('wpml_permalink',$url,$language,true);
                    if(is_string($translated) && $translated!=='')$url=$translated;
                }
            }
            $robots=(array)get_post_meta($id,'rank_math_robots',true);
            $type=get_post_type($id);
            if(!$robots)$robots=(array)($rankTemplates['pt_'.$type.'_robots']??array());
            $key=is_string($url)?self::url_key($url):'';
            if(!$key||in_array('noindex',$robots,true)){++$excluded;continue;}
            // WPML and migrated legacy records can resolve to the exact same
            // public URL. One URL must not create multiple competing tasks.
            if(isset($urlIndex[$key])){++$duplicateUrls;continue;}
            $targets[$id]=array('url'=>$url,'key'=>$key,'language'=>$language);
            $urlIndex[$key]=$id;
        }
        $status=self::source_status();
        $analytics=self::rank_gsc($urlIndex,$status);
        if(!$analytics)$analytics=self::native_gsc($urlIndex,$status);
        $scores=self::scores(array_keys($targets));
        $rows=array();
        foreach($targets as $id=>$target){
            $global=(string)($rankTemplates['pt_'.get_post_type($id).'_description']??'');
            if(trim((string)get_post_meta($id,'rank_math_description',true))==='' && trim($global)===''){
                $r=self::opportunity($id,'description_source_review',48,$target['url'],
                    'No page-specific or post-type Rank Math description source; review the rendered snippet.',
                    array('per_page_description'=>'empty','post_type_template'=>'empty','rendered_description_not_asserted'=>true));
                $rows[$r['id']]=$r;
            }
            $seo=$scores[$id]??null;
            if($seo && is_numeric($seo['seo_score']) && (int)$seo['seo_score']>0 &&
                (int)$seo['seo_score']<50 && strtotime($seo['created'].' UTC')>=time()-self::MAX_AGE*DAY_IN_SECONDS){
                $r=self::opportunity($id,'low_rank_math_seo_score',55,$target['url'],
                    'Review Rank Math SEO score and its underlying checks.',
                    array('score'=>(int)$seo['seo_score'],'observed_at'=>$seo['created']));
                $rows[$r['id']]=$r;
            }
            $g=$analytics[$target['key']]??null;
            if($g){
                if($g['impressions']>=100 && $g['ctr']<=2.0 && $g['ctr']>=0 &&
                    $g['position']>=3 && $g['position']<=20){
                    $r=self::opportunity($id,'high_impressions_low_ctr',80,$target['url'],
                        'Search listing has high impressions and low CTR: review title, snippet and intent.',$g);
                    $rows[$r['id']]=$r;
                }
                if($g['impressions']>=100 && $g['position']>10 && $g['position']<=30){
                    $r=self::opportunity($id,'ranking_striking_distance',70,$target['url'],
                        'Potential page-one ranking: investigate content and user intent.',$g);
                    $rows[$r['id']]=$r;
                }
            }
        }
        uasort($rows,static fn($a,$b)=>$b['priority']<=>$a['priority']?:strcmp($a['url'],$b['url']));
        $summary=array('contract'=>self::CONTRACT,'status'=>$write?'reconciled':'dry_run',
            'scanned'=>count($ids),'eligible'=>count($targets),'excluded'=>$excluded,
            'published_total'=>(int)$q->found_posts,'complete'=>$complete,
            'duplicate_public_urls'=>$duplicateUrls,
            'multilingual'=>has_filter('wpml_permalink')!==false,
            'post_types'=>self::types(),'opportunities'=>count($rows),
            'by_kind'=>array_count_values(array_column($rows,'kind')),
            'fresh_gsc_pages'=>count($analytics),'providers'=>$status,
            'jobs_created'=>0,'indexing_changed'=>false,'published_changed'=>false,
            'at'=>gmdate('c'));
        if($write){
            if(!$complete)return $summary+array('error'=>'partial_scan_refused');
            $previous=(array)get_option(self::STORE,array());
            foreach($rows as $key=>&$row){
                $p=$previous[$key]??array();
                $row['first_seen']=$p['first_seen']??$summary['at'];
                $row['last_seen']=$summary['at'];
                $row['status']=in_array($p['status']??'',array('reviewing','dismissed'),true)?$p['status']:'candidate';
            }
            unset($row);
            foreach($previous as $key=>$old)if(!isset($rows[$key])&&is_array($old)){
                $old['status']='resolved';$old['resolved_at']=$summary['at'];$rows[$key]=$old;
            }
            update_option(self::STORE,$rows,false);
            if(get_option(self::STORE)!==$rows)return $summary+array('error'=>'readback_mismatch');
            update_option(self::SNAPSHOT,$summary,false);
        }
        $summary['sample']=array_slice(array_values($rows),0,8);
        return $summary;
    }
    public static function listing(int $page=1,int $limit=30):array {
        $page=max(1,$page);$limit=max(1,min(100,$limit));
        $rows=array_values(array_filter((array)get_option(self::STORE,array()),
            static fn($row)=>is_array($row)&&($row['status']??'')!=='resolved'));
        usort($rows,static fn($a,$b)=>($b['priority']??0)<=>($a['priority']??0));
        return array('contract'=>self::CONTRACT,'summary'=>get_option(self::SNAPSHOT,array()),
            'total'=>count($rows),'page'=>$page,'items'=>array_slice($rows,($page-1)*$limit,$limit),
            'read_only'=>true);
    }
    public static function admin():void {
        if(!current_user_can('manage_options'))wp_die('Not allowed');
        $result=self::listing(1,50);
        echo '<div class="wrap"><h1>SEO Opportunities</h1><p>All published public content. Read-only opportunities; use approved WP-CLI to scan. No automatic Content Jobs or publication.</p>';
        echo '<p>Open opportunities: <strong>'.(int)$result['total'].'</strong></p><table class="widefat striped"><thead><tr><th>Page</th><th>Opportunity</th><th>Priority</th></tr></thead><tbody>';
        foreach($result['items']as $row)echo '<tr><td><a href="'.esc_url($row['url']).'">'.esc_html($row['title']).'</a><br>'.esc_html($row['post_type']).'</td><td>'.esc_html($row['recommendation']).'</td><td>'.(int)$row['priority'].'</td></tr>';
        echo '</tbody></table></div>';
    }
    public static function cli($args,$opts):void {
        $cmd=(string)($args[0]??'status');
        if($cmd==='scan')$data=self::scan(isset($opts['limit'])?(int)$opts['limit']:self::MAX_TARGETS,!empty($opts['apply']));
        elseif($cmd==='list')$data=self::listing(1,isset($opts['limit'])?(int)$opts['limit']:20);
        elseif($cmd==='status')$data=array('source'=>self::source_status(),'ledger'=>self::listing(1,2));
        else WP_CLI::error('Expected scan [--apply] [--limit=N], list or status.');
        WP_CLI::line(wp_json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        if(isset($data['error']))WP_CLI::error($data['error']);
    }
}
// Optional extension loaded only when both governed plugins exist.
 // The Control Plane bootstrap remains byte-identical to its release baseline.
 add_action('plugins_loaded',static function():void {
     if(!class_exists('MAD4B_SCP_G5_External_Providers',false)
         ||!class_exists('MAD4B_SCP_Google_Drive_Context',false)
         ||!class_exists('MAD4B_SCP_Admin_Route_Registry',false))return;
     $source=WP_PLUGIN_DIR.'/mad4b-site-control-plane/includes/class-mad4b-scp-native-search-console.php';
     if(!is_file($source)||!is_readable($source))return;
     require_once $source;
     if(class_exists('MAD4B_SCP_Native_Search_Console',false))
         MAD4B_SCP_Native_Search_Console::boot();
 },31);
 add_action('plugins_loaded',array('MAD4B_SEO_Opportunities','boot'),32);
register_activation_hook(__FILE__,static function():void{
    if(!wp_next_scheduled('mad4b_seo_opportunities_daily'))
        wp_schedule_event(time()+6*HOUR_IN_SECONDS,'daily','mad4b_seo_opportunities_daily');
});
register_deactivation_hook(__FILE__,static function():void{
    wp_clear_scheduled_hook('mad4b_seo_opportunities_daily');
    wp_clear_scheduled_hook('mad4b_seo_opportunities_refresh');
});
