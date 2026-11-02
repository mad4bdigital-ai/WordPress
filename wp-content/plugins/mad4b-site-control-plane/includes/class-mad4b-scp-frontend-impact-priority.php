<?php
/**
 * MAD4B Frontend Impact Priority Policy v1.
 *
 * Read-only, site-neutral live exploration. Crawls public same-origin pages;
 * ranks existing Content Readiness issues and their already linked ContentJobs.
 * Does not create issues, grant privileges, publish, alter job states or send mail.
 */
defined('ABSPATH') || exit;
final class MAD4B_SCP_Frontend_Impact_Priority {
    const VERSION='mad4b.frontend-impact-priority.v1';
    const SNAPSHOT='mad4b_frontend_impact_priority_v1';
    const HOOK='mad4b/frontend_impact_hourly_scan';
    const REST='mad4b-frontend-impact/v1';
    const PAGE='mad4b-frontend-impact-priority';
    const MAX_HTML=1400000;
    public static function boot():void {
        add_action('init',[__CLASS__,'schedule'],45);
        add_action(self::HOOK,[__CLASS__,'scheduled_scan']);
        add_action('rest_api_init',[__CLASS__,'routes']);
        if (class_exists('MAD4B_SCP_Admin_Route_Registry'))
            MAD4B_SCP_Admin_Route_Registry::schedule_submenu([__CLASS__,'menu']);
        add_filter('mad4b/content_readiness/impact_score',[__CLASS__,'sort_score'],10,2);
        if(defined('WP_CLI') && WP_CLI) WP_CLI::add_command('mad4b frontend-impact',[__CLASS__,'cli']);
    }
    public static function schedule():void {
        if(!wp_next_scheduled(self::HOOK) &&
           (bool)apply_filters('mad4b/frontend_impact/auto_scan_enabled',true)) {
            wp_schedule_event(time()+300+(get_current_blog_id()%6)*50,'hourly',self::HOOK);
        }
    }
    public static function scheduled_scan():void {
        // Bounded multilingual home, archive and one verified tour detail.
        // The cron action is not a publication or write authority.
        self::scan(3,true);
    }
    public static function authorized():bool {
        return current_user_can('manage_options') ||
            (class_exists('MAD4B_SCP_Policy') && MAD4B_SCP_Policy::can_read());
    }
    public static function routes():void {
        register_rest_route(self::REST,'/summary',[
            'methods'=>'GET',
            'permission_callback'=>[__CLASS__,'authorized'],
            'callback'=>static function($request) {
                return rest_ensure_response(self::report(
                    max(1,min(100,(int)($request->get_param('limit')?:30)))));
            }
        ]);
    }
    private static function languages():array {
        $registered=(array)apply_filters('wpml_active_languages',null,'skip_missing=0');
        $result=[];
        foreach($registered as $locale=>$row) {
            if(!preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/D',(string)$locale))continue;
            $url=(string)($row['url']??'');
            if(self::same_origin($url))$result[$locale]=trailingslashit($url);
        }
        if(!$result) $result[(string)get_locale()]=trailingslashit(home_url('/'));
        return array_slice($result,0,12,true);
    }
    private static function same_origin(string $url):bool {
        if(!$url || strlen($url)>800)return false;
        $base=wp_parse_url(home_url('/'));$candidate=wp_parse_url($url);
        return is_array($base)&&is_array($candidate)&&
            ($candidate['scheme']??'')==='https' &&
            ($candidate['scheme']??'')===($base['scheme']??'') &&
            strtolower((string)($candidate['host']??''))===strtolower((string)($base['host']??'')) &&
            empty($candidate['user'])&&empty($candidate['pass'])&&empty($candidate['fragment'])&&
            empty($candidate['query']) &&
            !preg_match('#(?:^|/)(?:wp-admin|wp-json|wp-login\.php)(?:/|$)#i',(string)($candidate['path']??''));
    }
    private static function targets(int $per_lang):array {
        $home=self::languages();
        $prior=(array)get_option('mad4b_wpml_frontend_visibility_v1',[]);
        $urls=[];
        foreach($home as $locale=>$homepage){
            $urls[$locale][]=['url'=>$homepage,'kind'=>'home'];
            if($per_lang<2)continue;
            foreach((array)($prior['pages'][$locale]??[]) as $page) {
                if(($page['kind']??'')!=='archive')continue;
                $path=(string)($page['path']??'');
                if(!str_starts_with($path,'/'.$locale.'/')||strlen($path)>260)continue;
                $candidate=home_url($path);
                if(!self::same_origin($candidate))continue;
                $urls[$locale][]=['url'=>$candidate,'kind'=>'archive'];
                break;
            }
            if(count($urls[$locale])===1) {
                $archive=get_post_type_archive_link('tours-and-activities');
                if(is_string($archive) && self::same_origin($archive)) {
                    $candidate=$locale===apply_filters('wpml_default_language',null)
                        ? $archive : trailingslashit($homepage).'tours-and-activities/';
                    if(self::same_origin($candidate))
                        $urls[$locale][]=['url'=>$candidate,'kind'=>'archive'];
                }
            }
            if($per_lang<3)continue;
            // Select one real, previously observed public tour detail. Never
            // guess paths from taxonomy labels or filtered JSF archive URLs.
            foreach((array)($prior['pages'][$locale]??[]) as $page) {
                $path=(string)($page['path']??'');
                if(strlen($path)>300 || !str_contains($path,'/tours-and-activities/')
                   || str_contains($path,'/jsf/') ||
                   ($page['kind']??'')!=='linked_page')continue;
                if($locale!==apply_filters('wpml_default_language',null)
                   && !str_starts_with($path,'/'.$locale.'/'))continue;
                $candidate=home_url($path);
                if(!self::same_origin($candidate))continue;
                $urls[$locale][]=['url'=>$candidate,'kind'=>'tour'];
                break;
            }
            if(count($urls[$locale])<3 && $locale===apply_filters('wpml_default_language',null)) {
                $products=get_posts(['post_type'=>'tours-and-activities',
                    'post_status'=>'publish','numberposts'=>1,'orderby'=>'modified',
                    'order'=>'DESC','suppress_filters'=>true]);
                if($products) {
                    $candidate=get_permalink($products[0]);
                    if(is_string($candidate)&&self::same_origin($candidate))
                        $urls[$locale][]=['url'=>$candidate,'kind'=>'tour'];
                }
            }
        }
        return $urls;
    }
    public static function inspect_html(string $html,string $url,string $kind):array {
        $result=[
            'url'=>$url,'kind'=>$kind,'status'=>200,
            'mega_menus'=>0,'deep_tabs'=>0,'listing_grids'=>0,
            'empty_listings'=>0,'filters'=>0,'forms'=>0,
            'price_surfaces'=>0,'booking_controls'=>0,'fluent_forms'=>0,
            'form_ids'=>[],'post_ids'=>[],
            'elementor_elements'=>0,'navigation_text'=>'',
            'observed_at'=>gmdate('c'),'body_sha256'=>hash('sha256',$html)
        ];
        if(!class_exists('DOMDocument'))return $result;
        $dom=new DOMDocument('1.0','UTF-8');
        $before=libxml_use_internal_errors(true);
        try{$parsed=$dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}
        finally {libxml_clear_errors();libxml_use_internal_errors($before);}
        if(!$parsed)return $result;
        $xp=new DOMXPath($dom);
        $find=static function($name)use($xp) {
            $query="//*[contains(concat(' ',normalize-space(@class),' '),' ".$name." ')]";
            $nodes=$xp->query($query);
            return $nodes instanceof DOMNodeList?$nodes:null;
        };
        // Elementor ships native Menu as widget type n-menu, not mega-menu.
        // Resolve both supported classes from the PUBLIC rendered document.
        $mega=$xp->query('//*[contains(concat(" ",normalize-space(@class)," ")," elementor-widget-n-menu ") or contains(concat(" ",normalize-space(@class)," ")," elementor-widget-mega-menu ")]');
        $result['mega_menus']=$mega instanceof DOMNodeList?$mega->length:0;
        $deep=$find('native-destination-deep-tabs');
        $result['deep_tabs']=$deep?$deep->length:0;
        $grid=$find('jet-listing-grid');
        $result['listing_grids']=$grid?$grid->length:0;
        $empty=$find('jet-listing-not-found');
        $result['empty_listings']=$empty?$empty->length:0;
        $filters=$xp->query('//*[contains(@class,"jet-smart-filters-")]');
        $result['filters']=$filters?$filters->length:0;
        $forms=$xp->query('//form');
        $result['forms']=$forms?$forms->length:0;
        // Visitor-facing presence only. Never collect values, visitors' inputs,
        // hidden tokens, form contents, or personally identifying data.
        $fluent=$xp->query('//form[contains(@class,"fluentform") or starts-with(@id,"fluentform_")]');
        $result['fluent_forms']=$fluent?$fluent->length:0;
        if($fluent)foreach($fluent as $form){
            foreach(['data-form_id','data-form-id','id'] as $attribute){
                $v=(string)$form->getAttribute($attribute);
                if(preg_match('/(?:^|_)([1-9][0-9]{0,6})$/D',$v,$m))
                    $result['form_ids'][]=(int)$m[1];
            }
        }
        $result['form_ids']=array_slice(array_values(array_unique($result['form_ids'])),0,30);
        // Providers may declare their own verified native booking form IDs.
        // Presence of markup is NOT evidence of a completed booking/payment.
        $booking_ids=(array)apply_filters('mad4b/frontend_impact/booking_form_ids',[]);
        $booking_ids=array_values(array_unique(array_filter(array_map('intval',$booking_ids),
            static fn($id)=>$id>0&&$id<=999999)));
        $result['booking_form_contracts']=$kind==='tour'
            ?array_slice(array_values(array_intersect($result['form_ids'],$booking_ids)),0,12)
            :[];
        $price=$xp->query('//*[contains(@class,"etg-tour-price") or contains(@class,"etg-price-native") or contains(@class,"woocommerce-Price-amount") or contains(@class,"jet-listing-dynamic-field__content") and contains(@class,"price")]');
        $result['price_surfaces']=$price?$price->length:0;
        $booking=$xp->query('//*[contains(@class,"booking-form") or contains(@class,"booking-widget") or contains(@class,"rate-selection") or contains(@class,"tour-rate-picker") or contains(@class,"pricing-widget")]');
        $result['booking_controls']=($booking?$booking->length:0)+count($result['booking_form_contracts']);
        $ids=$xp->query('//*[@data-post-id]');
        if($ids)foreach($ids as $node){
            $id=(string)$node->getAttribute('data-post-id');
            if(ctype_digit($id)&&(int)$id>0)$result['post_ids'][]=(int)$id;
            if(count($result['post_ids'])>=320)break;
        }
        $result['post_ids']=array_slice(array_values(array_unique($result['post_ids'])),0,200);
        $elements=$xp->query('//*[@data-id and contains(@class,"elementor-element")]');
        $result['elementor_elements']=$elements?$elements->length:0;
        $pieces=[];
        if($mega)foreach($mega as $node) {
            $text=trim(preg_replace('/\s+/u',' ',(string)$node->textContent));
            if($text!=='')$pieces[]=mb_substr($text,0,9000);
        }
        $result['navigation_text']=mb_substr(implode(' ',$pieces),0,14000);
        return $result;
    }
    private static function explore(string $url,string $kind):array {
        if(!self::same_origin($url))return ['error'=>'foreign_or_unsafe_url'];
        $res=wp_remote_get($url,[
            'timeout'=>12,'redirection'=>0,'sslverify'=>true,
            'limit_response_size'=>self::MAX_HTML,
            'headers'=>['Accept'=>'text/html','Cache-Control'=>'no-cache'],
            'user-agent'=>'MAD4B-FrontendImpact/1.0 (public same-origin read-only; WordPress)',
        ]);
        if(is_wp_error($res))return ['error'=>'transport_'.$res->get_error_code()];
        $status=(int)wp_remote_retrieve_response_code($res);
        $type=(string)wp_remote_retrieve_header($res,'content-type');
        $body=(string)wp_remote_retrieve_body($res);
        if($status!==200||stripos($type,'text/html')===false || strlen($body)<2000 || strlen($body)>=self::MAX_HTML)
            return ['error'=>'invalid_response','status'=>$status,'bytes'=>strlen($body)];
        return self::inspect_html($body,$url,$kind);
    }
    private static function canonical_parent(int $source):int {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT parent FROM {$wpdb->term_taxonomy} WHERE term_id=%d AND taxonomy=%s LIMIT 1",
            $source,'destinations'));
    }
    private static function group_size(int $parent):int {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE parent=%d AND taxonomy=%s",
            $parent,'destinations'));
    }
    private static function scored_issue(array $issue,array $pages):array {
        $code=(string)($issue['code']??'');
        $base=['score'=>null,'confidence'=>'not_mapped','surface'=>'unknown',
            'observed_urls'=>[],'group'=>'','evidence'=>'no_live_element_binding'];
        if(!preg_match('/^dfsb_wpml_term_missing_([a-z0-9_-]+)_(\d+)_([a-z]{2})$/D',$code,$m)) {
            $proposal=apply_filters('mad4b/frontend_impact/score_unmapped_issue',$base,$issue,$pages);
            // Non-taxonomy providers may only return a typed, bounded score.
            return is_array($proposal) && isset($proposal['score']) &&
                is_int($proposal['score']) && $proposal['score']>=0 &&
                $proposal['score']<=100 && is_string($proposal['evidence']??null)
                ?$proposal:$base;
        }
        $tax=$m[1];$source=(int)$m[2];$lang=$m[3];
        if($tax!=='destinations'||!isset($pages[$lang]))return $base;
        $parent=self::canonical_parent($source);
        if(!$parent)return $base;
        $translated=(int)apply_filters('wpml_object_id',$parent,'destinations',false,$lang);
        $term=$translated?get_term($translated,'destinations'):null;
        $name=$term instanceof WP_Term?$term->name:'';
        $hits=[];$rank=0;$kind='not_observed';
        foreach($pages[$lang] as $page){
            $nav=(string)($page['navigation_text']??'');
            if($name!=='' && $nav!=='' && mb_stripos($nav,$name)!==false){
                $hits[]=$page['url'];
                $weight=($page['kind']==='home')?32:24;
                if($weight>$rank){$rank=$weight;$kind=($page['kind']==='home')?'home_megamenu':'archive_navigation';}
            }
        }
        $children=self::group_size($parent);
        $score=35+$rank+min(16,$children*2)+($hits?8:0);
        $score=min(96,max(10,$score));
        $ranking=['score'=>$score,'confidence'=>$hits?'observed_navigation':'source_inferred',
            'surface'=>$kind,'observed_urls'=>array_slice(array_values(array_unique($hits)),0,3),
            'group'=>'destinations/'.$lang.'/'.$parent,
            'evidence'=>$hits?'localized_parent_found_in_public_navigation':'parent_not_seen_in_bounded_live_sample',
            'source_term_id'=>$source,'parent_term_id'=>$parent,
            'localized_parent_id'=>$translated,'source_children'=>$children,'locale'=>$lang];
        // Optional MU adapters enrich evidence from Shared Product Core and
        // presentation policy. They cannot grant eligibility or mutate content.
        $enriched=apply_filters('mad4b/frontend_impact/score_context',$ranking,
            ['taxonomy'=>$tax,'source_term_id'=>$source,'parent_term_id'=>$parent,
             'localized_parent_id'=>$translated,'locale'=>$lang,'issue_code'=>$code]);
        if(!is_array($enriched)||!isset($enriched['score'])||!is_int($enriched['score']))
            return $ranking;
        $enriched['score']=max(0,min(100,$enriched['score']));
        return $enriched;
    }
    public static function scan(int $per_lang=1,bool $internal_cron=false) {
        if(!self::authorized() && !($internal_cron && doing_action(self::HOOK)))
            return new WP_Error('frontend_impact_operator_required');
        $per_lang=max(1,min(3,$per_lang));
        $targets=self::targets($per_lang);
        $previous=self::snapshot();$pages=[];$failures=[];$ok=0;
        foreach($targets as $lang=>$urls){
            foreach($urls as $row){
                $item=self::explore($row['url'],$row['kind']);
                if(isset($item['error'])){
                    $failures[]=['locale'=>$lang,'kind'=>$row['kind'],'reason'=>$item['error']];
                    continue;
                }
                $pages[$lang][]=$item;$ok++;
            }
        }
        // A partial or failed scan must never erase the last trusted result.
        // Missing one locale's home page invalidates cross-language parity.
        $home_complete=true;
        foreach($targets as $lang=>$links) {
            if(!array_filter((array)($pages[$lang]??[]),
                static fn($p)=>($p['kind']??'')==='home'))$home_complete=false;
        }
        if(!$home_complete || $ok<max(2,(int)ceil(
            array_sum(array_map('count',$targets))*0.6)))
            return new WP_Error('frontend_impact_coverage_incomplete','Live crawl coverage incomplete; last good evidence preserved');
        $ledger=(array)get_option('mad4b_content_readiness_issues_v1',[]);
        $scores=[];$groups=[];$categories=[];
        foreach($ledger as $key=>$issue){
            if(!is_array($issue)||($issue['status']??'open')==='resolved')continue;
            $impact=self::scored_issue($issue,$pages);
            if($impact['score']===null)continue;
            $id=(string)($issue['id']??$key);
            $scores[$id]=array_merge(['code'=>(string)($issue['code']??''),
                'severity'=>(string)($issue['severity']??'medium'),
                'content_job_id'=>(string)($issue['content_job_id']??'')],$impact);
            $cat=(string)($impact['component_kind']??'destination_navigation');
            $categories[$cat]=($categories[$cat]??0)+1;
            $group=$impact['group'];if(!$group)continue;
            if(!isset($groups[$group]))$groups[$group]=[
                'group'=>$group,'score'=>0,'issue_count'=>0,
                'source_parent'=>$impact['parent_term_id']??null,
                'localized_parent'=>$impact['localized_parent_id']??null,
                'locale'=>$impact['locale']??null,
                'observed_urls'=>[],'confidence'=>$impact['confidence']
            ];
            $groups[$group]['issue_count']++;
            $groups[$group]['score']=max($groups[$group]['score'],$impact['score']);
            $groups[$group]['observed_urls']=array_values(array_unique(array_merge(
                $groups[$group]['observed_urls'],$impact['observed_urls'])));
        }
        $parity=[];
        $en=(array)($pages['en']??[]);
        $source_archive=null;
        foreach($en as $p)if(($p['kind']??'')==='archive')$source_archive=$p;
        if($source_archive){
            foreach($pages as $locale=>$records) {
                if($locale==='en')continue;
                foreach($records as $p){
                    if(($p['kind']??'')!=='archive')continue;
                    if(($source_archive['filters']??0)>0 && ($p['filters']??0)===0)
                        $parity[]=['locale'=>$locale,'component'=>'jetsmartfilters',
                            'source_url'=>$source_archive['url'],'target_url'=>$p['url'],
                            'source_present'=>(int)$source_archive['filters'],
                            'target_present'=>0,'status'=>'candidate_requires_semantic_verification'];
                    if(($source_archive['listing_grids']??0)>0 && ($p['listing_grids']??0)===0)
                        $parity[]=['locale'=>$locale,'component'=>'jetengine_listing',
                            'source_url'=>$source_archive['url'],'target_url'=>$p['url'],
                            'source_present'=>(int)$source_archive['listing_grids'],
                            'target_present'=>0,'status'=>'candidate_requires_semantic_verification'];
                }
            }
        }
        $data=['contract'=>self::VERSION,'observed_at'=>gmdate('c'),'site'=>home_url(),
            'languages'=>array_keys($targets),'pages'=>$pages,'pages_checked'=>$ok,
            'failures'=>$failures,'scores'=>$scores,'groups'=>array_values($groups),
            'source_issues'=>count($ledger),'issues_ranked'=>count($scores),
            'categories'=>$categories,'surface_parity_candidates'=>$parity,
            'content_jobs_mutated'=>false,'publication_mutated'=>false];
        if(!update_option(self::SNAPSHOT,$data,false) &&
           get_option(self::SNAPSHOT,[])!==$data)
            return new WP_Error('frontend_impact_snapshot_write_failed');
        $read=get_option(self::SNAPSHOT,[]);
        if(!is_array($read)||($read['observed_at']??null)!==$data['observed_at'] ||
           ($read['issues_ranked']??-1)!==count($scores))
            return new WP_Error('frontend_impact_snapshot_readback_failed');
        return ['status'=>'SCANNED','pages'=>$ok,'languages'=>count($pages),
            'issues_ranked'=>count($scores),'groups'=>count($groups),
            'categories'=>$categories,'surface_parity_candidates'=>count($parity),
            'failures'=>$failures,'observed_at'=>$data['observed_at']];
    }
    public static function snapshot():array {
        $data=get_option(self::SNAPSHOT,[]);
        return is_array($data)?$data:[];
    }
    public static function sort_score($score,$issue) {
        if(!is_array($issue))return $score;
        $snap=self::snapshot();
        $fresh=!empty($snap['observed_at'])&&
            (time()-strtotime($snap['observed_at']))<4*HOUR_IN_SECONDS;
        if(!$fresh||($snap['site']??'')!==home_url())return $score;
        $id=(string)($issue['id']??'');
        return isset($snap['scores'][$id]['score'])?(int)$snap['scores'][$id]['score']:$score;
    }
    public static function report(int $limit=30):array {
        $snap=self::snapshot();$rows=array_values((array)($snap['scores']??[]));
        usort($rows,static fn($a,$b)=>($b['score']<=>$a['score']) ?:strcmp($a['code'],$b['code']));
        $groups=(array)($snap['groups']??[]);
        usort($groups,static fn($a,$b)=>($b['score']<=>$a['score'])?:($b['issue_count']<=>$a['issue_count']));
        $at=strtotime((string)($snap['observed_at']??''))?:0;
        $stale=!$at||time()-$at>4*HOUR_IN_SECONDS;
        return [
            'contract'=>self::VERSION,'status'=>!$at?'NO_SNAPSHOT':($stale?'STALE':'FRESH'),
            'last_scan'=>$snap['observed_at']??null,
            'age_seconds'=>$at?max(0,time()-$at):null,
            'sampled_languages'=>count((array)($snap['languages']??[])),
            'sampled_pages'=>(int)($snap['pages_checked']??0),
            'issues_ranked'=>(int)($snap['issues_ranked']??0),
            'clusters'=>count($groups),
            'categories'=>$snap['categories']??[],
            'surface_parity_candidates'=>$snap['surface_parity_candidates']??[],
            'failed_requests'=>$snap['failures']??[],
            'groups'=>array_slice($groups,0,$limit),
            'issues'=>array_slice($rows,0,$limit),
            'read_only_priority_overlay'=>true,
            'source_jobs_unchanged'=>true
        ];
    }
    public static function menu():void {
        if(class_exists('MAD4B_SCP_Admin_Route_Registry'))
            MAD4B_SCP_Admin_Route_Registry::register(self::PAGE,'manage_options');
        add_submenu_page('mad4b-control-plane','Frontend Impact Priority',
            'Frontend Impact','manage_options',self::PAGE,[__CLASS__,'admin']);
    }
    public static function admin():void {
        if(!self::authorized())wp_die('Unauthorized');
        $r=self::report(30);
        echo '<div class="wrap"><h1>Frontend Impact Priority</h1>';
        echo '<p>Verified public HTML sampled in active WPML languages. This page ranks linked Content Alerts; it does not publish, change job states or email anyone.</p>';
        echo '<p><strong>Status:</strong> '.esc_html($r['status']).' | <strong>Last scan:</strong> '.esc_html((string)$r['last_scan']).
            ' | <strong>Pages:</strong> '.(int)$r['sampled_pages'].
            ' | <strong>Ranked issues:</strong> '.(int)$r['issues_ranked'].'</p>';
        echo '<h2>Highest-impact navigation clusters</h2><table class="widefat striped"><thead><tr>'.
            '<th>Locale / Parent</th><th>Priority</th><th>Issues</th><th>Evidence</th></tr></thead><tbody>';
        foreach($r['groups'] as $group) {
            $url=$group['observed_urls'][0]??'';
            echo '<tr><td>'.esc_html((string)$group['group']).'</td><td>'.(int)$group['score'].
                '</td><td>'.(int)$group['issue_count'].'</td><td>'.
                ($url?'<a href="'.esc_url($url).'" target="_blank" rel="noopener">Public page</a>':'Not seen').'</td></tr>';
        }
        echo '</tbody></table><p>Source: <a href="'.esc_url(admin_url('admin.php?page=mad4b-content-readiness-alerts')).
            '">Content Readiness &amp; Alerts</a>. Scan via WP-CLI: <code>wp mad4b frontend-impact scan</code>.</p></div>';
    }
    public static function cli($args):void {
        $action=$args[0]??'status';
        if($action==='scan')$r=self::scan(isset($args[1])?(int)$args[1]:1);
        elseif($action==='status')$r=self::report(20);
        else WP_CLI::error('Allowed: scan [pages_per_language=1|2], status');
        if(is_wp_error($r))WP_CLI::error($r->get_error_code().': '.$r->get_error_message());
        WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
}
