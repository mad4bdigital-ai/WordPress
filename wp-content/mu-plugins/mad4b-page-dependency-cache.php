<?php
/**
 * Plugin Name: MAD4B Page Dependency Cache Graph
 * Description: Local page, fragment and query dependency index, selective epochs, safe fallback and receipts.
 * Version: 0.4.3
 */
defined('ABSPATH') || exit;

final class MAD4B_Page_Dependency_Cache {
    const VERSION = '0.4.3';
    const OPTION = 'mad4b_page_dependency_cache_v1';
    const RECEIPTS = 'mad4b_page_dependency_receipts_v1';
    const GROUP = 'mad4b-page-dependency-v1';
    const MAX_EDGES = 140;
    const MAX_PAGES = 80;
    private static array $edges = [];
    private static array $queries = [];
    private static string $pageKey = '';
    private static string $path = '';
    private static string $lang = '';
    private static string $device = '';
    private static bool $collecting = false;
    private static bool $unknown = false;

    public static function defaults(): array {
        return ['strategy'=>'page_fragment_query','unknown'=>'legacy_scope',
            'refresh'=>'off','collect'=>'on','ttl_hours'=>48,'max_refresh'=>2];
    }
    public static function policy(): array {
        $raw=get_option(self::OPTION,[]);
        $legacy=array_replace(self::defaults(),is_array($raw)?$raw:[]);
        if(class_exists('MAD4B_Company_Policy_Registry_V2')){
            $resolved=MAD4B_Company_Policy_Registry_V2::resolve(
                'content.cache.dependency',[],[
                    'strategy'=>$legacy['strategy'],'unknown'=>$legacy['unknown'],
                    'refresh'=>$legacy['refresh']]);
            $legacy=array_replace($legacy,$resolved['values']);
        }
        return $legacy;
    }
    public static function provider($providers): array {
        if(!is_array($providers))$providers=[];
        $providers['page-dependency-cache']=[
            'label'=>'Page / Fragment / Query Dependencies',
            'policy_id'=>'content.cache.dependency',
            'fields'=>[
                'strategy'=>['type'=>'enum','options'=>[
                    'page_fragment_query'=>'Page + Fragment + Query (default)',
                    'page_only'=>'Page graph only',
                    'legacy_post_type'=>'Legacy Post Type epochs']],
                'unknown'=>['type'=>'enum','options'=>[
                    'legacy_scope'=>'Fallback to legacy scope when coverage is unknown',
                    'no_cache'=>'Disable persistent query cache when unknown']],
                'refresh'=>['type'=>'enum','options'=>[
                    'off'=>'Do not schedule automatic warming',
                    'low_priority'=>'Warm at most two eligible affected pages']],
            ],
            'allowed_scopes'=>['company','template_family','listing','widget'],
        ];
        return $providers;
    }
    private static function table(string $suffix): string {
        global $wpdb;
        return $wpdb->prefix.'mad4b_pd_'.$suffix;
    }
    public static function install(): array {
        global $wpdb;
        $charset=$wpdb->get_charset_collate();
        $pages=self::table('pages');$edges=self::table('edges');
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        // Page hash includes pathname, WPML language and trusted device.
        $sql1="CREATE TABLE $pages (
            page_hash char(64) NOT NULL,
            page_path varchar(500) NOT NULL,
            language varchar(12) NOT NULL,
            device varchar(12) NOT NULL,
            node_kind varchar(12) NOT NULL,
            epoch bigint(20) unsigned NOT NULL DEFAULT 1,
            hit_count bigint(20) unsigned NOT NULL DEFAULT 0,
            coverage varchar(20) NOT NULL DEFAULT 'unknown',
            updated_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            PRIMARY KEY  (page_hash),
            KEY expires_at (expires_at),
            KEY node_kind (node_kind)
        ) $charset;";
        $sql2="CREATE TABLE $edges (
            dep_hash char(64) NOT NULL,
            page_hash char(64) NOT NULL,
            dep_key varchar(190) NOT NULL,
            expires_at datetime NOT NULL,
            PRIMARY KEY  (dep_hash,page_hash),
            KEY page_hash (page_hash),
            KEY expires_at (expires_at)
        ) $charset;";
        dbDelta($sql1);dbDelta($sql2);
        $ok=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$pages))===$pages
          &&$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$edges))===$edges;
        if($ok)update_option('mad4b_page_dependency_schema_v1','installed',false);
        return ['installed'=>$ok,'pages'=>$pages,'edges'=>$edges];
    }
    public static function ready(): bool {
        return get_option('mad4b_page_dependency_schema_v1')==='installed';
    }
    private static function public_request(): bool {
        if(!self::ready()||is_admin()||wp_doing_ajax()||is_user_logged_in()||is_preview()
            ||(defined('REST_REQUEST')&&REST_REQUEST)
            ||(defined('WP_CLI')&&WP_CLI)
            ||(defined('DOING_CRON')&&DOING_CRON))return false;
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')return false;
        foreach((array)$_GET as $key=>$value)
            if(!preg_match('/^(?:utm_[a-z0-9_]+|gclid|fbclid|msclkid)$/i',(string)$key))return false;
        foreach(array_keys((array)$_COOKIE) as $name)
            if(preg_match('/(?:woocommerce|wp_woocommerce|wordpress_logged_in|session|cart|market|currency|passport|country|jet_smart_filters|quote|token)/i',(string)$name))return false;
        if(function_exists('is_checkout')&&(is_checkout()||is_cart()||is_account_page()))return false;
        return (is_front_page()||is_home()||is_singular()||is_archive())&&!is_search();
    }
    public static function start(): void {
        if(!self::public_request())return;
        $p=self::policy();
        if(($p['strategy']??'')==='legacy_post_type'||($p['collect']??'on')!=='on')return;
        $uri=(string)($_SERVER['REQUEST_URI']??'/');
        $path=(string)(parse_url($uri,PHP_URL_PATH)?:'/');
        if(strlen($path)>500||!str_starts_with($path,'/')||str_contains($path,'..'))return;
        $lang=(string)apply_filters('wpml_current_language','');
        if(!preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/i',$lang))$lang='en';
        $device=(isset($_SERVER['MAD4B_TRUSTED_DEVICE_CLASS'])
            && in_array($_SERVER['MAD4B_TRUSTED_DEVICE_CLASS'],['mobile','desktop'],true))
            ?$_SERVER['MAD4B_TRUSTED_DEVICE_CLASS']:(wp_is_mobile()?'mobile':'desktop');
        self::$path=$path;self::$lang=$lang;self::$device=$device;
        self::$pageKey=self::hash('page|'.$path.'|'.$lang.'|'.$device);
        self::$collecting=true;self::$unknown=false;self::$edges=[];
        $id=get_queried_object_id();
        if($id && (is_singular()||is_page()))self::depend('post:'.(int)$id);
        if(is_tax()||is_category()||is_tag()){
            $term=get_queried_object();
            if($term instanceof WP_Term)self::depend('term:'.$term->taxonomy.':'.$term->term_id);
        }
        self::depend('page_path:'.$path);
    }
    public static function hash(string $key): string {return hash('sha256',$key);}
    public static function depend(string $key): void {
        if(!self::$collecting||strlen($key)>190||!preg_match('/^[a-z0-9:._|\/-]+$/i',$key))return;
        if(count(self::$edges)>=self::MAX_EDGES&&!isset(self::$edges[$key])){self::$unknown=true;return;}
        self::$edges[$key]=true;
    }
    public static function rendered_post($post): void {
        if($post instanceof WP_Post)self::depend('post:'.(int)$post->ID);
    }
    public static function listing($input,$id) {
        $id=absint($id);
        if($id)self::depend('listing:'.$id);
        if(function_exists('jet_engine')){
            $je=jet_engine();
            $data=is_object($je)&&isset($je->listings->data)?$je->listings->data:null;
            if(is_object($data)&&method_exists($data,'get_current_object')){
                $obj=$data->get_current_object();
                if($obj instanceof WP_Post)self::depend('post:'.(int)$obj->ID);
                if($obj instanceof WP_Term)self::depend('term:'.$obj->taxonomy.':'.(int)$obj->term_id);
            }
        }
        return $input;
    }
    public static function elementor_widget($widget): void {
        if(!self::$collecting||!is_object($widget)||!method_exists($widget,'get_name'))return;
        $name=(string)$widget->get_name();
        if(!in_array($name,['template','global-widget','jet-listing-grid'],true))return;
        if(!method_exists($widget,'get_settings_for_display'))return;
        $settings=(array)$widget->get_settings_for_display();
        foreach(['template_id','listing_id','lisitng_id'] as $k){
            $id=absint($settings[$k]??0);
            if($id)self::depend(($k==='template_id'?'template:':'listing:').$id);
        }
    }
    public static function terms_changed($postId,$terms,$ttids,$taxonomy,$append,$oldTtids): void {
        $postId=absint($postId);
        $tax=sanitize_key((string)$taxonomy);
        $type=$postId?get_post_type($postId):'';
        $object=$type?get_post_type_object($type):null;
        if(!$tax||!$object||empty($object->public))return;
        $all=array_values(array_unique(array_filter(array_map('absint',
            array_merge((array)$ttids,(array)$oldTtids)))));
        $all=array_slice($all,0,120);
        if(!$all)return;
        global $wpdb;
        $placeholders=implode(',',array_fill(0,count($all),'%d'));
        $termIds=$wpdb->get_col($wpdb->prepare(
            "SELECT term_id FROM {$wpdb->term_taxonomy}
             WHERE term_taxonomy_id IN ($placeholders)",...$all));
        $deps=['post:'.$postId,'query_pt:'.$type,'query_taxonomy:'.$tax];
        foreach((array)$termIds as $tid){
            $tid=absint($tid);
            $deps[]='term:'.$tax.':'.$tid;
            $deps[]='query_tax:'.$tax.':'.$tid;
        }
        self::invalidate($deps,'object_terms_change');
    }
    private static function query_signature($query): array {
        if(!is_object($query)||!isset($query->id,$query->query_type)||!is_array($query->final_query??null))
            return ['supported'=>false];
        $type=(string)$query->query_type;
        if(!in_array($type,['posts','terms'],true))return ['supported'=>false];
        $args=$query->final_query;
        $id=absint($query->id);
        if(!$id)return ['supported'=>false];
        $deps=['query_id:'.$id];
        if($type==='posts'){
            // Metadata-driven membership/order can change without save_post;
            // never certify such a query from post+term hooks alone.
            foreach(['meta_query','meta_key','meta_value','meta_value_num',
                'comment_count','suppress_filters','post_password'] as $key)
                if(!empty($args[$key]))return ['supported'=>false];
            $orderby=$args['orderby']??'date';
            if(is_array($orderby) || (is_string($orderby)
                && preg_match('/(?:meta|rand|comment|relevance|price|rating|views|stock)/i',$orderby)))
                return ['supported'=>false];
            $statuses=array_values(array_unique((array)($args['post_status']??['publish'])));
            if(count($statuses)!==1||$statuses[0]!=='publish')return ['supported'=>false];
            $types=(array)($args['post_type']??[]);
            if(!$types||count($types)>5)return ['supported'=>false];
            foreach($types as $pt){
                if(!is_string($pt)||!preg_match('/^[a-z0-9_-]{2,60}$/D',$pt)
                    ||preg_match('/(?:rate|booking|order|price|product|customer|stock|supplier)/i',$pt))
                    return ['supported'=>false];
            }
            $taxes=$args['tax_query']??[];
            if(!is_array($taxes))return ['supported'=>false];
            $filterCount=0;
            foreach($taxes as $key=>$item) {
                if($key==='relation'){
                    if($item!=='AND')return ['supported'=>false];
                    continue;
                }
                // Nested OR/NOT IN and non-term-ID operators need broader
                // fallback; a missed future member would serve stale data.
                if(!is_array($item)||!isset($item['taxonomy']))return ['supported'=>false];
                $tax=(string)$item['taxonomy'];
                if(!preg_match('/^[a-z0-9_-]{2,60}$/D',$tax)
                    ||($item['field']??'term_id')!=='term_id'
                    ||($item['operator']??'IN')!=='IN')return ['supported'=>false];
                $terms=$item['terms']??null;
                if(!is_array($terms)||!$terms||count($terms)>40)return ['supported'=>false];
                foreach($terms as $term){
                    if(!(is_int($term)&&$term>0) &&
                       !(is_string($term)&&ctype_digit($term)&&(int)$term>0))
                        return ['supported'=>false];
                    $deps[]='query_tax:'.$tax.':'.(int)$term;
                }
                ++$filterCount;
                if($filterCount>5)return ['supported'=>false];
            }
            // Unfiltered membership may change whenever any post of a type
            // changes. Tax-filtered membership changes are tracked by its
            // old/new term IDs on save and set_object_terms.
            if($filterCount===0)
                foreach($types as $pt)$deps[]='query_pt:'.$pt;
        }else{
            $taxes=(array)($args['taxonomy']??[]);
            if(!$taxes||count($taxes)>10)return ['supported'=>false];
            // Term visibility can depend on associated post counts. The
            // taxonomy-wide invalidation hook tracks those updates.
            foreach($taxes as $tax){
                if(!is_string($tax)||!preg_match('/^[a-z0-9_-]{2,60}$/D',$tax))
                    return ['supported'=>false];
                $deps[]='query_taxonomy:'.$tax;
            }
        }
        $encoded=wp_json_encode($args);
        if(!is_string($encoded)||strlen($encoded)>12288)return ['supported'=>false];
        return ['supported'=>true,
            'hash'=>self::hash($type.'|'.$id.'|'.$encoded),
            'dependencies'=>array_values(array_unique($deps))];
    }
    public static function unknown_query_policy($query): void {
        if(!self::ready()||!is_object($query)||empty($query->cache_query))return;
        $p=self::policy();
        if(($p['unknown']??'')!=='no_cache'||($p['strategy']??'')==='legacy_post_type')return;
        $signature=self::query_signature($query);
        if(!$signature['supported']){
            $query->cache_query=false;
            $query->cache_expires=0;
        }
    }
    public static function observe_query($query): void {
        if(!self::$collecting||!self::ready())return;
        // Hard bound: pages that execute more than 24 unique JetEngine
        // queries retain the old scope fallback instead of persisting all.
        if(count(self::$queries)>=24){self::$unknown=true;return;}
        if(function_exists('mad4b_render_query_policy')){
            $eligible=mad4b_render_query_policy($query);
            if(empty($eligible['cache']))return;
        }
        $info=self::query_signature($query);
        if(!$info['supported']){self::$unknown=true;return;}
        $qKey=$info['hash'];
        self::depend('query:'.$qKey);
        self::$queries[$qKey]=$info['dependencies'];
        foreach($info['dependencies'] as $dep)self::depend($dep);
    }
    /** Add a scoped query epoch without replacing the existing safety epoch. */
    public static function query_version($version,$query,$decision): string {
        if(!self::ready()||empty($decision['cache'])
            ||(self::policy()['strategy']??'')!=='page_fragment_query')return (string)$version;
        $info=self::query_signature($query);
        if(!$info['supported'])return (string)$version;
        return (string)$version.':pdep:'.self::epoch($info['hash']);
    }
    public static function epoch(string $hash): int {
        if(!self::ready()||!preg_match('/^[a-f0-9]{64}$/D',$hash))return 1;
        $key='epoch-'.$hash;
        $v=wp_cache_get($key,self::GROUP);
        if(is_int($v)&&$v>=1)return $v;
        global $wpdb;$table=self::table('pages');
        $n=$wpdb->get_var($wpdb->prepare("SELECT epoch FROM $table WHERE page_hash=%s",$hash));
        $v=max(1,(int)$n);
        wp_cache_set($key,$v,self::GROUP,90);
        return $v;
    }
    private static function persist_node(string $hash,string $path,string $lang,string $device,
                                         string $kind,array $deps,bool $complete): bool {
        if(!self::ready()||!$deps)return false;
        global $wpdb;
        $pages=self::table('pages');$edges=self::table('edges');
        // Query governor permits up to 30-day cache TTL. Keep query epochs
        // and predicates 35 days so stale cache groups can never reappear
        // due to this graph row expiring first.
        $seconds=$kind==='query'?35*DAY_IN_SECONDS
            :3600*max(24,min(168,(int)(self::policy()['ttl_hours']??48)));
        $now=gmdate('Y-m-d H:i:s');$expiry=gmdate('Y-m-d H:i:s',time()+$seconds);
        // A per-node short Redis lock reduces work across PHP workers, not a
        // distributed page-render lock. Existing Nginx lock remains independent.
        $lock='persist-'.$hash;
        $throttle='persist-throttle-'.$hash;
        if(wp_cache_get($throttle,self::GROUP))return false;
        if($kind==='query') {
            // Bound steady-state MariaDB growth. Existing verified query
            // nodes can be refreshed even after the cap is reached.
            $count=wp_cache_get('query-node-budget',self::GROUP);
            if(!is_int($count)) {
                $count=(int)$wpdb->get_var("SELECT COUNT(*) FROM $pages WHERE node_kind='query'");
                wp_cache_set('query-node-budget',$count,self::GROUP,120);
            }
            if($count>=8000) {
                $exists=$wpdb->get_var($wpdb->prepare(
                    "SELECT 1 FROM $pages WHERE page_hash=%s LIMIT 1",$hash));
                if(!$exists)return false;
            }
        }
        if(!wp_cache_add($lock,1,self::GROUP,25))return false;
        try{
            $wpdb->query('START TRANSACTION');
            $wpdb->query($wpdb->prepare(
                "INSERT INTO $pages (page_hash,page_path,language,device,node_kind,epoch,hit_count,coverage,updated_at,expires_at)
                 VALUES (%s,%s,%s,%s,%s,1,1,%s,%s,%s)
                 ON DUPLICATE KEY UPDATE page_path=VALUES(page_path),language=VALUES(language),
                 device=VALUES(device),node_kind=VALUES(node_kind),hit_count=hit_count+1,
                 coverage=VALUES(coverage),updated_at=VALUES(updated_at),expires_at=VALUES(expires_at)",
                $hash,$path,$lang,$device,$kind,$complete?'complete':'unknown',$now,$expiry));
            $wpdb->query($wpdb->prepare("DELETE FROM $edges WHERE page_hash=%s",$hash));
            foreach(array_keys($deps) as $dep){
                $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO $edges (dep_hash,page_hash,dep_key,expires_at)
                      VALUES (%s,%s,%s,%s)",self::hash($dep),$hash,$dep,$expiry));
            }
            if($wpdb->last_error!==''){ $wpdb->query('ROLLBACK');return false; }
            $wpdb->query('COMMIT');
            wp_cache_set($throttle,1,self::GROUP,$kind==='query'?1800:120);
            if($kind==='query')wp_cache_delete('query-node-budget',self::GROUP);
            return true;
        }finally{wp_cache_delete($lock,self::GROUP);}
    }
    public static function finish(): void {
        if(!self::$collecting)return;
        self::$collecting=false;
        // Persist query nodes as graph entries even for queries with empty
        // results; predicate dependencies are required for newly published posts.
        foreach(self::$queries as $hash=>$keys){
            $deps=array_fill_keys($keys,true);
            self::persist_node($hash,'/_mad4b_query/'.$hash,self::$lang,self::$device,'query',$deps,true);
        }
        // Coverage is intentionally partial: Elementor/JetEngine or shortcodes
        // can produce indirect edges not proven by render callbacks. Do not
        // label this page complete or suppress legacy fallback on that basis.
        self::persist_node(self::$pageKey,self::$path,self::$lang,self::$device,'page',
            self::$edges,false);
        self::$queries=[];self::$edges=[];
    }
    public static function invalidate(array $deps,string $cause='manual'): array {
        if(!self::ready())return ['status'=>'schema_not_ready','affected'=>0,'fallback'=>true];
        $deps=array_values(array_unique(array_filter($deps,static fn($d)=>is_string($d)&&
            strlen($d)<=190&&preg_match('/^[a-z0-9:._|\/-]+$/i',$d))));
        if(!$deps)return ['status'=>'unknown','affected'=>0,'fallback'=>true];
        $hashes=array_map([self::class,'hash'],$deps);
        global $wpdb;$pages=self::table('pages');$edges=self::table('edges');
        $placeholders=implode(',',array_fill(0,count($hashes),'%s'));
        $sql=$wpdb->prepare(
            "SELECT DISTINCT e.page_hash,p.page_path,p.node_kind,p.coverage
             FROM $edges e JOIN $pages p ON p.page_hash=e.page_hash
             WHERE e.dep_hash IN ($placeholders) AND e.expires_at>UTC_TIMESTAMP()
               AND p.expires_at>UTC_TIMESTAMP() LIMIT ".(self::MAX_PAGES+1),
             ...$hashes);
        $hits=$wpdb->get_results($sql,ARRAY_A);
        if(!is_array($hits))$hits=[];
        $overflow=count($hits)>self::MAX_PAGES;
        if($overflow)return ['status'=>'graph_overflow','affected'=>0,'fallback'=>true];
        $unknown=false;$changed=[];
        foreach($hits as $row){
            if(($row['coverage']??'unknown')!=='complete')$unknown=true;
            $hash=(string)$row['page_hash'];
            $wpdb->query($wpdb->prepare(
                "UPDATE $pages SET epoch=epoch+1,updated_at=UTC_TIMESTAMP()
                 WHERE page_hash=%s",$hash));
            wp_cache_delete('epoch-'.$hash,self::GROUP);
            wp_cache_delete('persist-throttle-'.$hash,self::GROUP);
            $changed[]=['kind'=>$row['node_kind'],'path'=>$row['page_path'],'hash'=>$hash];
        }
        if(($policy=self::policy()) && ($policy['refresh']??'off')==='low_priority') {
            $budget=max(0,min(2,(int)($policy['max_refresh']??2)));
            $pageHits=array_values(array_filter($changed,static fn($c)=>
                ($c['kind']??'')==='page' && preg_match('#^/[a-z0-9/_-]*$#i',$c['path']??'')));
            $pageHits=array_slice($pageHits,0,$budget);
            foreach($pageHits as $hit) {
                $id='warm-'.$hit['hash'];
                if(!wp_cache_add($id,1,self::GROUP,120))continue;
                wp_schedule_single_event(time()+35,'mad4b/page_dependency/refresh',[$hit['hash']]);
            }
        }
        $receipt=['contract'=>'mad4b.page-dependency.invalidate.v1',
            'at'=>gmdate('c'),'cause'=>sanitize_key($cause),
            'dependencies'=>$deps,'affected'=>count($changed),
            'affected_nodes'=>array_slice($changed,0,25),
            'coverage_unknown'=>$unknown,
            'fallback'=>!$hits||$unknown,
            'fastcgi_coordinator'=>'ttl_only_no_native_purge',
        ];
        $existing=(array)get_option(self::RECEIPTS,[]);
        $existing[]=$receipt;
        update_option(self::RECEIPTS,array_slice($existing,-30),false);
        do_action('mad4b/page_dependency/invalidation_receipt',$receipt);
        return $receipt;
    }
    public static function refresh(string $hash): void {
        if(!self::ready()||(self::policy()['refresh']??'off')!=='low_priority'
            ||!preg_match('/^[a-f0-9]{64}$/D',$hash))return;
        if(!wp_cache_add('refresh-lock-'.$hash,1,self::GROUP,65))return;
        try{
            global $wpdb;$pages=self::table('pages');
            $node=$wpdb->get_row($wpdb->prepare(
                "SELECT page_path,coverage,node_kind FROM $pages
                 WHERE page_hash=%s AND expires_at>UTC_TIMESTAMP()",$hash),ARRAY_A);
            if(!is_array($node)||($node['node_kind']??'')!=='page')return;
            $path=(string)($node['page_path']??'');
            if(!preg_match('#^/[a-z0-9/_-]*$#i',$path)
                ||str_contains($path,'//')||strlen($path)>200)return;
            $url=home_url($path);
            if(wp_parse_url($url,PHP_URL_HOST)!==wp_parse_url(home_url('/'),PHP_URL_HOST))return;
            // Bypass FastCGI while rewarming only safe *public* render/query
            // paths. This does not claim to warm FastCGI or private quotes.
            $response=wp_remote_get($url,[
                'timeout'=>8,'redirection'=>0,'blocking'=>true,'sslverify'=>true,
                'headers'=>['X-MAD4B-Bypass-Cache'=>'1','User-Agent'=>'MAD4B-Page-Dependency-Warmer/0.3'],
            ]);
            do_action('mad4b/page_dependency/refresh_result',[
                'page_hash'=>$hash,'status'=>is_wp_error($response)?'failed':'requested',
                'http_code'=>is_wp_error($response)?null:wp_remote_retrieve_response_code($response),
                'at'=>gmdate('c'),
            ]);
        }finally{wp_cache_delete('refresh-lock-'.$hash,self::GROUP);}
    }
    public static function prune(): void {
        if(!self::ready())return;
        global $wpdb;
        $pages=self::table('pages');$edges=self::table('edges');
        // Bounded per-cron cleanup; expired graph nodes are never considered
        // evidence of complete query coverage.
        $wpdb->query("DELETE FROM $edges WHERE expires_at < UTC_TIMESTAMP() LIMIT 150");
        $wpdb->query("DELETE FROM $pages WHERE expires_at < UTC_TIMESTAMP() LIMIT 150");
    }
    public static function cron_init(): void {
        if(!wp_next_scheduled('mad4b/page_dependency/prune'))
            wp_schedule_event(time()+3600,'hourly','mad4b/page_dependency/prune');
    }
    public static function event_post($postId): void {
        $postId=absint($postId);
        if(!$postId||wp_is_post_revision($postId))return;
        $type=get_post_type($postId);
        $object=$type?get_post_type_object($type):null;
        if(!$object||empty($object->public))return;
        $deps=['post:'.$postId,'query_pt:'.$type];
        $taxonomies=get_object_taxonomies($type,'names');
        foreach((array)$taxonomies as $tax){
            $deps[]='query_taxonomy:'.$tax;
            $terms=wp_get_object_terms($postId,$tax,['fields'=>'ids']);
            if(is_wp_error($terms))continue;
            foreach(array_slice((array)$terms,0,40) as $term)
                $deps[]='query_tax:'.$tax.':'.absint($term);
        }
        self::invalidate($deps,'post_change');
    }
    public static function event_term($termId,$ttid,$taxonomy): void {
        $termId=absint($termId);$tax=sanitize_key((string)$taxonomy);
        if(!$termId||!$tax)return;
        self::invalidate(['term:'.$tax.':'.$termId,'query_tax:'.$tax.':'.$termId,
            'query_taxonomy:'.$tax],'term_change');
    }
    public static function event_template($postId): void {
        $postId=absint($postId);$type=get_post_type($postId);
        if(in_array($type,['elementor_library','jet-engine'],true))
            self::invalidate(['template:'.$postId,'listing:'.$postId],'template_change');
    }
    public static function query_context_key(): array {
        return ['page_hash'=>self::$pageKey,'path'=>self::$path,'language'=>self::$lang,'device'=>self::$device];
    }
    public static function summary(): array {
        global $wpdb;
        $out=['contract'=>'mad4b.page-dependency.status.v1','version'=>self::VERSION,
            'policy'=>self::policy(),'schema_ready'=>self::ready(),
            'fastcgi'=>'bounded_ttl_no_purge','cache_authority'=>'existing_WordPress_and_JetEngine'];
        if(self::ready()){
            $pages=self::table('pages');$edges=self::table('edges');
            $out['pages']=(int)$wpdb->get_var("SELECT COUNT(*) FROM $pages WHERE node_kind='page' AND expires_at>UTC_TIMESTAMP()");
            $out['queries']=(int)$wpdb->get_var("SELECT COUNT(*) FROM $pages WHERE node_kind='query' AND expires_at>UTC_TIMESTAMP()");
            $out['edges']=(int)$wpdb->get_var("SELECT COUNT(*) FROM $edges WHERE expires_at>UTC_TIMESTAMP()");
        }
        $out['recent_receipts']=array_slice((array)get_option(self::RECEIPTS,[]),-5);
        return $out;
    }
    public static function impact_preview(string $dependency): array {
        if(!self::ready())return ['status'=>'schema_not_ready','mutation_performed'=>false];
        if(!preg_match('/^(?:post:[1-9][0-9]*|template:[1-9][0-9]*|listing:[1-9][0-9]*|term:[a-z0-9_-]+:[1-9][0-9]*|query_pt:[a-z0-9_-]+|query_id:[1-9][0-9]*)$/D',$dependency))
            return ['status'=>'invalid_dependency','mutation_performed'=>false];
        global $wpdb;$pages=self::table('pages');$edges=self::table('edges');
        $hash=self::hash($dependency);
        $sql=$wpdb->prepare(
            "SELECT p.page_path,p.language,p.device,p.node_kind,p.coverage,p.epoch
             FROM $edges e JOIN $pages p ON p.page_hash=e.page_hash
             WHERE e.dep_hash=%s AND e.expires_at>UTC_TIMESTAMP()
               AND p.expires_at>UTC_TIMESTAMP()
             ORDER BY p.node_kind,p.page_path LIMIT 51",$hash);
        $rows=$wpdb->get_results($sql,ARRAY_A);
        if(!is_array($rows))$rows=[];
        $truncated=count($rows)>50;
        return ['contract'=>'mad4b.page-dependency.impact-preview.v1',
            'dependency'=>$dependency,'observed_matches'=>min(50,count($rows)),
            'truncated'=>$truncated,'nodes'=>array_slice($rows,0,50),
            'coverage'=>'partial_unknown_requires_legacy_fallback',
            'fastcgi'=>'ttl_only_no_purge','mutation_performed'=>false];
    }
    public static function expose(): void {
        register_rest_route('mad4b-policy/v2','/page-dependencies',[
            'methods'=>'GET','permission_callback'=>static fn()=>current_user_can('manage_options'),
            'callback'=>static fn()=>rest_ensure_response(self::summary()),
        ]);
        register_rest_route('mad4b-policy/v2','/page-dependencies/impact',[
            'methods'=>'GET','permission_callback'=>static fn()=>current_user_can('manage_options'),
            'callback'=>static function(WP_REST_Request $request){
                $dependency=(string)$request->get_param('dependency');
                if(strlen($dependency)>190)
                    return new WP_Error('mad4b_page_dependency_invalid','Invalid dependency',['status'=>422]);
                $result=self::impact_preview($dependency);
                if(($result['status']??'')==='invalid_dependency')
                    return new WP_Error('mad4b_page_dependency_invalid','Unsupported dependency',['status'=>422]);
                return rest_ensure_response($result);
            },
        ]);
    }
    public static function admin_panel(): void {
        if(!current_user_can('manage_options'))return;
        $status=self::summary();
        echo '<hr><h2>Page Dependency Cache</h2>';
        echo '<p>Native dependency collector and reverse graph. Pricing and checkout remain uncached; Nginx FastCGI purge is not yet available.</p>';
        echo '<p><strong>Strategy:</strong> '.esc_html($status['policy']['strategy']).' · <strong>Fallback:</strong> '.
            esc_html($status['policy']['unknown']).' · <strong>Schema:</strong> '.
            ($status['schema_ready']?'Ready':'Pending').' · <strong>Pages:</strong> '.(int)($status['pages']??0).
            ' · <strong>Queries:</strong> '.(int)($status['queries']??0).'</p>';
        echo '<p>Change policy via the dynamic Provider Registry above: <code>content.cache.dependency</code>. Legacy scope invalidation remains active until coverage and FastCGI acceptance.</p>';
    }
    public static function boot(): void {
        add_filter('mad4b/policy_registry/providers',[self::class,'provider'],25);
        add_action('wp',[self::class,'start'],190);
        add_action('the_post',[self::class,'rendered_post'],30);
        add_filter('jet-engine/listing/content/elementor',[self::class,'listing'],4,2);
        add_action('elementor/frontend/widget/before_render',[self::class,'elementor_widget'],5,1);
        add_action('jet-engine/query-builder/query/after-query-setup',[self::class,'observe_query'],90);
        add_action('jet-engine/query-builder/query/after-query-setup',[self::class,'unknown_query_policy'],105);
        add_filter('mad4b/render_query/query_version',[self::class,'query_version'],30,3);
        add_action('init',[self::class,'cron_init'],35);
        add_action('mad4b/page_dependency/prune',[self::class,'prune']);
        add_action('mad4b/page_dependency/refresh',[self::class,'refresh'],10,1);
        add_action('wp_footer',[self::class,'finish'],99999);
        add_action('shutdown',[self::class,'finish'],99999);
        add_action('save_post',[self::class,'event_post'],49);
        add_action('save_post',[self::class,'event_template'],50);
        add_action('set_object_terms',[self::class,'terms_changed'],55,6);
        add_action('created_term',[self::class,'event_term'],45,3);
        add_action('edited_term',[self::class,'event_term'],45,3);
        add_action('deleted_term',[self::class,'event_term'],45,3);
        add_action('rest_api_init',[self::class,'expose']);
        add_action('mad4b/company_adaptive/page_after',[self::class,'admin_panel'],20);
    }
}
MAD4B_Page_Dependency_Cache::boot();
