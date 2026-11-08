<?php
/**
 * Plugin Name: MAD4B Cache Control
 * Description: Safe, preview-first, policy-aware selective FastCGI path invalidation. Does not flush Redis.
 * Version: 1.1.1
 */
defined('ABSPATH') || exit;

if (!class_exists('MAD4B_Cache_Control_V1', false)) {
final class MAD4B_Cache_Control_V1 {
    const VERSION = '1.1.1';
    const PAGE = 'mad4b-cache-lifecycle';
    const RECEIPTS = 'mad4b_cache_control_receipts_v1';
    const MAX_PATHS = 80;
    const TTL = 600;

    private static function lang_codes(): array {
        $codes = [];
        if (has_filter('wpml_active_languages')) {
            $rows = apply_filters('wpml_active_languages', null, ['skip_missing'=>0,'orderby'=>'code']);
            if (is_array($rows)) foreach ($rows as $key=>$row) {
                $code = sanitize_key(is_array($row) ? (string)($row['code']??$key) : (string)$key);
                if (preg_match('/^[a-z]{2,5}$/D', $code)) $codes[$code] = true;
            }
        }
        $fallback = sanitize_key((string)apply_filters('wpml_default_language','en'));
        if (preg_match('/^[a-z]{2,5}$/D',$fallback)) $codes[$fallback] = true;
        if (!$codes) $codes['en'] = true;
        return array_slice(array_keys($codes),0,12);
    }

    private static function safe_taxonomy(string $tax): bool {
        if (!preg_match('/^[a-z][a-z0-9_-]{1,50}$/D',$tax) ||
            preg_match('/(?:product|price|rate|booking|payment|order|stock|customer|reservation)/i',$tax))
            return false;
        $obj=get_taxonomy($tax);
        return $obj && !empty($obj->public);
    }

    private static function safe_post_type(string $type): bool {
        if (!in_array($type,['page','post','tours-and-activities'],true)) return false;
        $obj=get_post_type_object($type);
        return $obj && !empty($obj->public);
    }

    /**
     * Canonical public path validator. Pure: it must never call home_url(),
     * whose result can vary by WPML/market/visitor on admin POST requests.
     * Reject encoded ASCII (reserved bytes/dot/slash/private suffix tricks)
     * while permitting valid UTF-8 percent-encoded non-ASCII slugs.
     */
    public static function is_safe_public_path($path): bool {
        if (!is_string($path) || $path==='' || strlen($path)>500 ||
            $path[0]!=='/' || !preg_match('~^/[A-Za-z0-9_%/.-]*$~D',$path) ||
            strpos($path,'//')!==false ||
            preg_match('~(?:^|/)\.\.?(/|$)~',$path) ||
            preg_match('/%(?![0-9a-fA-F]{2})/',$path) ||
            preg_match('/%[0-7][0-9a-fA-F]/i',$path))
            return false;
        $decoded=rawurldecode($path);
        if (preg_match('//u',$decoded)!==1 ||
            preg_match('/[\x00-\x1f\x7f]/',$decoded) ||
            preg_match('~(?:^|/)\.\.?(/|$)~',$decoded) ||
            strpos($decoded,'//')!==false ||
            preg_match('~/(?:[a-z]{2,5}/)?(?:wp-admin|wp-login\.php|wp-json|cart|checkout|my-account|account|order-pay|order-received|payment|booking)(?:/|$)~i',$decoded))
            return false;
        return true;
    }

    /** Accept only absolute, same-origin WordPress permalinks. */
    public static function path_from_url($url): ?string {
        if (!is_string($url) || strlen($url)>1024) return null;
        $parts=wp_parse_url($url);
        $home=wp_parse_url(home_url('/'));
        if (!is_array($parts)||!is_array($home)||
            strtolower((string)($parts['host']??''))!==strtolower((string)($home['host']??''))||
            strtolower((string)($parts['scheme']??''))!==strtolower((string)($home['scheme']??'https'))||
            (int)($parts['port']??0)!==(int)($home['port']??0)||
            isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment']))
            return null;
        $path=(string)($parts['path']??'/');
        return self::is_safe_public_path($path) ? $path : null;
    }

    private static function is_private_commerce_page(int $id): bool {
        if (get_post_type($id)!=='page')return false;
        foreach (['woocommerce_cart_page_id','woocommerce_checkout_page_id',
                  'woocommerce_myaccount_page_id','woocommerce_terms_page_id'] as $option) {
            $page=(int)get_option($option,0);
            if ($page && $page===$id)return true;
            if ($page && has_filter('wpml_object_id')) {
                foreach (self::lang_codes() as $lang) {
                    if ((int)apply_filters('wpml_object_id',$page,'page',false,$lang)===$id)
                        return true;
                }
            }
        }
        return false;
    }

    private static function parse_selection(string $selection): array {
        if (preg_match('/^post:([1-9][0-9]{0,9})$/D',$selection,$m)) {
            $id=(int)$m[1]; $post=get_post($id);
            if (!$post || $post->post_status!=='publish' || !self::safe_post_type($post->post_type))
                return ['error'=>'unsupported_or_unpublished_post'];
            if (self::is_private_commerce_page($id))return ['error'=>'private_commerce_page_denied'];
            return ['kind'=>'post','post_type'=>$post->post_type,'id'=>$id,
                'name'=>get_the_title($id)];
        }
        if (preg_match('/^term:([a-z][a-z0-9_-]{1,50}):([1-9][0-9]{0,9})$/D',$selection,$m)) {
            $tax=$m[1];$id=(int)$m[2];
            if (!self::safe_taxonomy($tax)) return ['error'=>'unsupported_taxonomy'];
            $term=get_term($id,$tax);
            if (!$term instanceof WP_Term || is_wp_error($term))return ['error'=>'unknown_term'];
            return ['kind'=>'term','taxonomy'=>$tax,'id'=>$id,'name'=>$term->name];
        }
        return ['error'=>'invalid_selection'];
    }

    private static function source_policy(array $entity): array {
        $ctx=['component'=>'published_content'];
        $registry=['available'=>class_exists('MAD4B_Company_Policy_Registry_V2',false),
            'origin'=>'local_fallback','rule_id'=>null,'revision'=>null];
        $strategy='stable_scoped';
        if ($registry['available']) {
            $resolved=MAD4B_Company_Policy_Registry_V2::resolve('content.cache.scoped',$ctx,
                ['strategy'=>'stable_scoped']);
            $strategy=(string)($resolved['values']['strategy']??$strategy);
            $registry['origin']=(string)($resolved['receipt']['origin']??'default');
            $registry['rule_id']=$resolved['receipt']['rule_id']??null;
            $registry['revision']=$resolved['receipt']['registry_revision']??null;
        }
        $scope=get_option('mad4b_published_content_scope_cache_v1',[]);
        $scope=is_array($scope)?$scope:[];
        $production=wp_get_environment_type()==='production';
        $local_enabled=(!$production || ($scope['status']??'paused')==='active')
            && ($scope['status']??'active')!=='paused'
            && ($scope['strategy']??'')!=='global';
        $native=['status'=>'unavailable'];
        if (class_exists('MAD4B_Page_Dependency_Cache',false)) {
            $native=MAD4B_Page_Dependency_Cache::policy();
        }
        $prices=['available'=>class_exists('MAD4B_Pricing_Cache_Inheritance_V1',false),
            'mode'=>'native_quote_never_shared'];
        if ($prices['available'] && $entity['kind']==='post' &&
            $entity['post_type']==='tours-and-activities') {
            $overview=MAD4B_Pricing_Cache_Inheritance_V1::overview(['tour_id'=>$entity['id']]);
            $prices['static_blueprint']=$overview['resources']['mode_policy_definition']['cacheable']??false;
            $prices['quote']=$overview['resources']['customer_quote']['cacheable']??false;
        }
        return [
            'registry'=>$registry,'strategy'=>$strategy,'company_scope_active'=>$local_enabled,
            'company_scope_status'=>$scope['status']??($production?'not_opted_in':'default_active'),
            'dependency_strategy'=>$native['strategy']??'unknown',
            'dependency_unknown_fallback'=>$native['unknown']??'legacy_scope',
            'pricing'=>$prices,
            'fastcgi_ttl_seconds'=>1800,
            'operation'=>'explicit_paths_only_no_redis_flush',
            'coverage'=>'manual_selection_not_full_dependency_coverage',
        ];
    }

    public static function preview_target(string $selection): array {
        $entity=self::parse_selection($selection);
        if (isset($entity['error']))return $entity;
        $paths=[];$skipped=[];$labels=[];
        $codes=self::lang_codes();
        foreach ($codes as $language) {
            $id=(int)$entity['id'];
            if (has_filter('wpml_object_id')) {
                $kind=$entity['kind']==='post'?$entity['post_type']:$entity['taxonomy'];
                $translated=apply_filters('wpml_object_id',$id,$kind,false,$language);
                if (!$translated)continue;
                $id=(int)$translated;
            }
            if (!$id)continue;
            if ($entity['kind']==='post') {
                $p=get_post($id);
                if (!$p || $p->post_status!=='publish' || $p->post_type!==$entity['post_type']
                    || self::is_private_commerce_page($id))continue;
                $url=get_permalink($id);
                $title=get_the_title($id);
            } else {
                $t=get_term($id,$entity['taxonomy']);
                if (!$t instanceof WP_Term || is_wp_error($t))continue;
                $url=get_term_link($t);
                $title=$t->name;
            }
            if (is_wp_error($url))continue;
            // WPML get_permalink() inside wp-admin/WP-CLI may return the
            // default-language path even for a correctly translated object.
            if (has_filter('wpml_permalink'))
                $url=apply_filters('wpml_permalink',$url,$language);
            $path=self::path_from_url($url);
            if ($path===null) {
                $skipped[]=['language'=>$language,'reason'=>'outside_approved_public_host_or_path'];
                continue;
            }
            $paths[$path]=true;
            $labels[$path]=['language'=>$language,'label'=>sanitize_text_field($title),'id'=>$id];
        }
        // Independently include the source canonical URL even when WPML filters
        // are unavailable in the administrative context.
        {
            $original_url=$entity['kind']==='post'?get_permalink($entity['id']):
                get_term_link($entity['id'],$entity['taxonomy']);
            if (!is_wp_error($original_url) && ($original_path=self::path_from_url($original_url))!==null
                && !($entity['kind']==='post' && self::is_private_commerce_page($entity['id']))) {
                $paths[$original_path]=true;
                if (!isset($labels[$original_path]))
                    $labels[$original_path]=['language'=>'source','label'=>$entity['name'],'id'=>$entity['id']];
            }
        }
        // An untranslated site may not expose WPML active languages.
        if (!$paths) {
            $url=$entity['kind']==='post'?get_permalink($entity['id']):
                get_term_link($entity['id'],$entity['taxonomy']);
            if (!is_wp_error($url) && ($path=self::path_from_url($url))!==null) {
                $paths[$path]=true;
                $labels[$path]=['language'=>'default','label'=>$entity['name'],'id'=>$entity['id']];
            }
        }
        if (!$paths || count($paths)>self::MAX_PATHS)
            return ['error'=>'no_safe_public_paths'];
        $policy=self::source_policy($entity);
        $preview=['contract'=>'mad4b.cache-control.preview.v1',
            'selection'=>$selection,'entity'=>$entity,'policy'=>$policy,
            'paths'=>array_keys($paths),'labels'=>$labels,'skipped'=>$skipped,
            'notes'=>['FastCGI path key rotation only; no Redis flush or browser cache purge',
               'Other pages that embed this entity may require native dependency lifecycle invalidation']];
        if (class_exists('MAD4B_Page_Dependency_Cache',false) &&
            MAD4B_Page_Dependency_Cache::ready()) {
            $dep=$entity['kind']==='post'?'post:'.$entity['id']:
                 'term:'.$entity['taxonomy'].':'.$entity['id'];
            $impact=MAD4B_Page_Dependency_Cache::impact_preview($dep);
            $preview['dependency_impact']=[
                'matches'=>(int)($impact['observed_matches']??0),
                'truncated'=>(bool)($impact['truncated']??false),
                'coverage'=>$impact['coverage']??'unknown',
                'sample_paths'=>array_slice(array_values(array_unique(array_filter(array_map(
                    static fn($n)=>is_array($n)?($n['page_path']??''):'',
                    (array)($impact['nodes']??[]))))),0,12),
                'automatically_included'=>false];
        }
        $preview['fingerprint']=hash('sha256',wp_json_encode([
            $preview['selection'],$preview['paths'],$preview['policy'],
            $preview['dependency_impact']??null]));
        return $preview;
    }

    private static function url(array $params=[]): string {
        return add_query_arg($params,admin_url('tools.php?page='.self::PAGE));
    }

    private static function redirect_error(string $code): void {
        wp_safe_redirect(self::url(['error'=>sanitize_key($code)]));exit;
    }

    private static function require_admin(string $nonce): void {
        if (!current_user_can('manage_options'))
            wp_die('Insufficient permissions','Forbidden',['response'=>403]);
        check_admin_referer($nonce);
    }

    public static function request_preview(): void {
        self::require_admin('mad4b_cache_control_preview');
        $selection=isset($_POST['selection'])&&is_scalar($_POST['selection'])
            ?sanitize_text_field(wp_unslash((string)$_POST['selection'])):'';
        $manual_kind=isset($_POST['manual_kind'])&&is_scalar($_POST['manual_kind'])
            ?sanitize_key(wp_unslash((string)$_POST['manual_kind'])):'';
        $manual_id=isset($_POST['manual_id'])&&is_scalar($_POST['manual_id'])
            ?(string)wp_unslash($_POST['manual_id']):'';
        $manual_tax=isset($_POST['manual_tax'])&&is_scalar($_POST['manual_tax'])
            ?sanitize_key(wp_unslash((string)$_POST['manual_tax'])):'';
        if ($manual_id!=='') {
            if (!ctype_digit($manual_id) || (int)$manual_id<1)self::redirect_error('invalid_manual_id');
            if ($manual_kind==='term')
                $selection='term:'.$manual_tax.':'.(int)$manual_id;
            elseif ($manual_kind==='post')$selection='post:'.(int)$manual_id;
            else self::redirect_error('invalid_manual_kind');
        }
        $preview=self::preview_target($selection);
        if (isset($preview['error']))self::redirect_error($preview['error']);
        $token=bin2hex(random_bytes(16));
        if (!set_transient('mad4b_cc_'.$token,['uid'=>get_current_user_id(),
            'preview'=>$preview,'created'=>time()],self::TTL))
            self::redirect_error('preview_store_unavailable');
        wp_safe_redirect(self::url(['preview'=>$token]));exit;
    }

    public static function socket_purge(array $paths): array {
        if (!$paths || count($paths)>self::MAX_PATHS)
            return ['status'=>'invalid_batch','paths'=>0];
        foreach ($paths as $path) {
            if (!self::is_safe_public_path($path))
                return ['status'=>'invalid_path','paths'=>0];
        }
        $socket=WP_CONTENT_DIR.'/.mad4b-purge/origin.sock';
        if (!file_exists($socket)||is_link($socket))
            return ['status'=>'socket_unavailable','paths'=>0];
        $errno=0;$err='';
        $handle=@stream_socket_client('unix://'.$socket,$errno,$err,2);
        if (!$handle)return ['status'=>'socket_connect_failed','paths'=>0];
        stream_set_timeout($handle,5);
        $line=wp_json_encode(array_values($paths))."\n";
        $w=$line===false?false:@fwrite($handle,$line);
        $reply=$w===strlen((string)$line)?json_decode((string)fgets($handle,4096),true):null;
        fclose($handle);
        if (!is_array($reply) || ($reply['status']??'')!=='ok')
            return ['status'=>'socket_failed','paths'=>0];
        return ['status'=>(int)($reply['versioned']??0)===count($paths)?'ok':'partial',
            'paths'=>(int)($reply['paths']??0),
            'versioned'=>(int)($reply['versioned']??0),
            'removed'=>(int)($reply['removed']??0)];
    }

    /**
     * Pure authorization-context check. No socket operation, no user mutation.
     * The WordPress handler still requires manage_options and a valid nonce.
     */
    public static function preview_access_state($data,int $uid,int $now): string {
        if (!is_array($data) || !isset($data['uid'],$data['created']) ||
            (int)$data['uid']!==$uid || $uid<=0)
            return 'preview_expired';
        if (!is_int($data['created']) || $data['created']>$now ||
            $now-$data['created']>self::TTL)
            return 'preview_expired';
        $saved=$data['preview']??null;
        if (!is_array($saved) || !is_string($saved['selection']??null) ||
            !is_array($saved['paths']??null))
            return 'preview_invalid';
        return 'ok';
    }

    public static function preview_fingerprint_matches($stored,$fresh): bool {
        if (!is_array($stored) || !is_array($fresh) ||
            isset($fresh['error']) || !is_string($stored['fingerprint']??null) ||
            !is_string($fresh['fingerprint']??null) ||
            !preg_match('/^[a-f0-9]{64}$/D',$stored['fingerprint']) ||
            !preg_match('/^[a-f0-9]{64}$/D',$fresh['fingerprint']))
            return false;
        return hash_equals($stored['fingerprint'],$fresh['fingerprint']) &&
            ($stored['selection']??null)===($fresh['selection']??null) &&
            ($stored['paths']??null)===($fresh['paths']??null);
    }

    public static function request_apply(): void {
        if (!current_user_can('manage_options'))wp_die('Forbidden','Forbidden',['response'=>403]);
        $token=isset($_POST['preview_token'])&&is_scalar($_POST['preview_token'])
            ?sanitize_text_field(wp_unslash((string)$_POST['preview_token'])):'';
        if (!preg_match('/^[a-f0-9]{32}$/D',$token))self::redirect_error('bad_preview_token');
        check_admin_referer('mad4b_cache_control_apply_'.$token);
        $key='mad4b_cc_'.$token;
        $data=get_transient($key);
        $state=self::preview_access_state($data,get_current_user_id(),time());
        if ($state!=='ok')self::redirect_error($state);
        // Consume once, before any socket call.
        delete_transient($key);
        $stored=$data['preview']??null;
        if (!is_array($stored)||!is_string($stored['selection']??null))
            self::redirect_error('preview_invalid');
        $fresh=self::preview_target($stored['selection']);
        if (!self::preview_fingerprint_matches($stored,$fresh))
            self::redirect_error('policy_or_paths_changed_preview_again');
        $response=self::socket_purge($fresh['paths']);
        $receipt=['contract'=>'mad4b.cache-control.fastcgi-path-generation.v1',
            'id'=>bin2hex(random_bytes(8)),'at'=>gmdate('c'),
            'uid'=>get_current_user_id(),'selection'=>$fresh['selection'],
            'paths'=>$fresh['paths'],'policy_source'=>$fresh['policy']['registry'],
            'policy_strategy'=>$fresh['policy']['strategy'],
            'result'=>$response,'redis_flushed'=>false,
            'coverage'=>'selected_paths_only_not_full_dependency_coverage'];
        $prev=get_option(self::RECEIPTS,[]);
        if (!is_array($prev))$prev=[];
        $prev[]=$receipt;
        update_option(self::RECEIPTS,array_slice($prev,-30),false);
        do_action('mad4b/cache_control/fastcgi_receipt',$receipt);
        wp_safe_redirect(self::url(['receipt'=>$receipt['id']]));exit;
    }

    private static function choices(string $search): array {
        $items=[];
        foreach (['page','tours-and-activities','post'] as $type) {
            if (!self::safe_post_type($type))continue;
            $args=['post_type'=>$type,'post_status'=>'publish',
                'numberposts'=>30,'orderby'=>'modified','order'=>'DESC','suppress_filters'=>false];
            if ($search!=='')$args['s']=$search;
            $posts=get_posts($args);
            foreach ($posts as $p)
                $items['post:'.$p->ID]=sprintf('%s — %s (#%d)',ucwords(str_replace('-',' ',$type)),
                    wp_strip_all_tags(get_the_title($p->ID)),$p->ID);
        }
        $taxes=get_taxonomies(['public'=>true],'objects');
        $count=0;
        foreach ($taxes as $tax=>$obj) {
            if (!self::safe_taxonomy($tax)||++$count>8)continue;
            $query=['taxonomy'=>$tax,'hide_empty'=>false,'number'=>15];
            if ($search!=='')$query['search']=$search;
            $terms=get_terms($query);
            if (is_wp_error($terms))continue;
            foreach ($terms as $t)
                $items['term:'.$tax.':'.$t->term_id]=sprintf('%s — %s (#%d)',
                    $obj->labels->singular_name??$tax,$t->name,$t->term_id);
        }
        return $items;
    }

    public static function render(): void {
        if (!current_user_can('manage_options'))wp_die('Forbidden','Forbidden',['response'=>403]);
        $search=isset($_GET['s'])&&is_scalar($_GET['s'])?
            sanitize_text_field(wp_unslash((string)$_GET['s'])):'';
        $search=substr($search,0,80);
        $preview_token=isset($_GET['preview'])&&is_scalar($_GET['preview'])?
            sanitize_text_field(wp_unslash((string)$_GET['preview'])):'';
        $stored=preg_match('/^[a-f0-9]{32}$/D',$preview_token)?
            get_transient('mad4b_cc_'.$preview_token):null;
        if (self::preview_access_state($stored,get_current_user_id(),time())!=='ok')
            $stored=null;
        $receipt_id=isset($_GET['receipt'])&&is_scalar($_GET['receipt'])?
            sanitize_text_field(wp_unslash((string)$_GET['receipt'])):'';
        echo '<div class="wrap"><h1>MAD4B Cache Control</h1>';
        echo '<p>Policy-aware FastCGI path generation. This does not flush Redis, change rates, or bypass private checkout rules.</p>';
        echo '<p><a href="'.esc_url(admin_url('tools.php?page=mad4b-pricing-cache-inheritance')).'">Pricing Cache Inheritance</a>';
        if (class_exists('MAD4B_Company_Adaptive_Controls',false))
            echo ' | <a href="'.esc_url(admin_url('options-general.php?page=mad4b-company-adaptive-controls')).'">Dynamic Policy Registry</a>';
        echo '</p>';
        $local_policy=self::source_policy(['kind'=>'post','post_type'=>'page','id'=>0]);
        echo '<h2>Current company cache policy</h2>';
        echo '<p>Provider Registry v2: <strong>'.
            ($local_policy['registry']['available']?'available':'NOT LOADED — local fallback only').
            '</strong> · Resolved strategy: '.esc_html($local_policy['strategy']).
            ' · Scoped content lifecycle: '.($local_policy['company_scope_active']?'active':'inactive / legacy fallback').
            ' · Dependency policy: '.esc_html($local_policy['dependency_strategy']).'</p>';
        if (!$local_policy['registry']['available'] || !$local_policy['company_scope_active'])
            echo '<p class="notice notice-warning" style="padding:10px">A manual URL operation here is limited to explicitly previewed paths. It does not activate scoped policy inheritance or guarantee the invalidation of dependent listings.</p>';
        if (!empty($_GET['error'])&&is_scalar($_GET['error']))
            echo '<div class="notice notice-error"><p>'.esc_html(sanitize_key(wp_unslash((string)$_GET['error']))).'</p></div>';
        if ($receipt_id!=='') {
            foreach (array_reverse((array)get_option(self::RECEIPTS,[])) as $r) {
                if (($r['id']??'')!==$receipt_id || ($r['uid']??0)!==get_current_user_id())continue;
                echo '<div class="notice notice-info"><p>FastCGI result: <strong>'.
                    esc_html($r['result']['status']??'unknown').'</strong>. Versioned '.
                    (int)($r['result']['versioned']??0).' path(s); Redis not flushed.</p></div>';
                break;
            }
        }
        if ($stored) {
            $p=$stored['preview'];$policy=$p['policy'];
            echo '<h2>Preview — '.esc_html($p['entity']['name']).'</h2>';
            echo '<p>Inheritance: <strong>'.esc_html($policy['registry']['origin']).'</strong> · '.
                'Registry '.($policy['registry']['available']?'available':'not loaded').
                ' · Strategy '.esc_html($policy['strategy']).' · Company scoped policy '.
                ($policy['company_scope_active']?'active':'inactive/fallback').'</p>';
            echo '<p>Dependency policy: '.esc_html($policy['dependency_strategy']).
                ' · Unknown dependencies: '.esc_html($policy['dependency_unknown_fallback']).'</p>';
            echo '<p><strong>Only the following public URLs will be versioned:</strong></p>';
            echo '<table class="widefat striped"><thead><tr><th>Language</th><th>Page</th><th>Path</th></tr></thead><tbody>';
            foreach ($p['paths'] as $path) {
                $label=$p['labels'][$path]??[];
                echo '<tr><td>'.esc_html($label['language']??'').'</td><td>'.
                    esc_html($label['label']??'').'</td><td><code>'.esc_html($path).'</code></td></tr>';
            }
            echo '</tbody></table>';
            foreach ($p['skipped'] as $skip)
                echo '<p>Skipped language '.esc_html($skip['language']).': '.esc_html($skip['reason']).'</p>';
            if (isset($p['dependency_impact'])) {
                $impact=$p['dependency_impact'];
                echo '<p><strong>Dependency graph (read-only):</strong> '.
                    (int)$impact['matches'].' matching nodes. Coverage '.
                    esc_html($impact['coverage']).'; related pages are NOT automatically purged.</p>';
                if ($impact['sample_paths'])
                    echo '<p>Related examples: '.esc_html(implode(', ',$impact['sample_paths'])).'</p>';
            }
            echo '<p class="description">Content that embeds the selected item elsewhere may need its own native dependency invalidation. Browser caches expire separately.</p>';
            echo '<form action="'.esc_url(admin_url('admin-post.php')).'" method="post">';
            echo '<input type="hidden" name="action" value="mad4b_cache_control_apply">';
            echo '<input type="hidden" name="preview_token" value="'.esc_attr($preview_token).'">';
            wp_nonce_field('mad4b_cache_control_apply_'.$preview_token);
            submit_button('Invalidate only these FastCGI paths','primary','submit',false);
            echo '</form><hr>';
        }
        echo '<h2>Select a published page, tour, post or taxonomy term</h2>';
        echo '<form method="get" action="'.esc_url(admin_url('tools.php')).'">';
        echo '<input type="hidden" name="page" value="'.esc_attr(self::PAGE).'">';
        echo '<input name="s" type="search" value="'.esc_attr($search).
            '" placeholder="Search title / term" maxlength="80"> ';
        submit_button('Find','secondary','submit',false);echo '</form>';
        $choices=self::choices($search);
        echo '<form action="'.esc_url(admin_url('admin-post.php')).'" method="post">';
        echo '<input type="hidden" name="action" value="mad4b_cache_control_preview">';
        wp_nonce_field('mad4b_cache_control_preview');
        echo '<p><label for="mad4b-cc-choice">Available published items</label><br>';
        echo '<select name="selection" id="mad4b-cc-choice" style="min-width:480px;max-width:100%">';
        foreach ($choices as $id=>$label)
            echo '<option value="'.esc_attr($id).'">'.esc_html($label).'</option>';
        echo '</select></p>';
        echo '<p>Or enter a known ID: <select name="manual_kind"><option value="post">Post / Page / Tour</option>'.
            '<option value="term">Taxonomy term</option></select> ';
        echo '<input type="number" min="1" name="manual_id" placeholder="Post or term ID"> ';
        echo '<input type="text" maxlength="50" name="manual_tax" placeholder="taxonomy slug (for term)"></p>';
        submit_button('Preview FastCGI Invalidation','secondary','submit',false);
        echo '</form><hr><h2>Policy inheritance and safety</h2>';
        echo '<p>Pricing and quote calculations stay with the native engine. Cache decisions are resolved per company, but a missing policy registry is shown explicitly rather than silently assumed.</p>';
        echo '<p>Only confirmed public URL paths are eligible; no blanket Redis or Nginx cache clear is offered.</p>';
        $history=array_reverse((array)get_option(self::RECEIPTS,[]));
        $history=array_values(array_filter($history,static fn($r)=>is_array($r)
            && ($r['uid']??0)===get_current_user_id()));
        echo '<h2>Recent FastCGI path generations</h2>';
        if (!$history)echo '<p>No manual cache invalidation receipts yet.</p>';
        else {
            echo '<table class="widefat striped"><thead><tr><th>UTC</th><th>Target</th><th>Versioned</th><th>Status</th><th>Policy</th></tr></thead><tbody>';
            foreach (array_slice($history,0,10) as $entry) {
                echo '<tr><td>'.esc_html($entry['at']??'').'</td><td><code>'.
                    esc_html($entry['selection']??'').'</code></td><td>'.
                    (int)($entry['result']['versioned']??0).'</td><td>'.
                    esc_html($entry['result']['status']??'unknown').'</td><td>'.
                    esc_html($entry['policy_source']['origin']??'unknown').'</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    public static function boot(): void {
        add_action('admin_menu',static function(): void {
            add_submenu_page('tools.php','MAD4B Cache Control','MAD4B Cache Control',
                'manage_options',self::PAGE,[self::class,'render']);
        });
        add_action('admin_post_mad4b_cache_control_preview',[self::class,'request_preview']);
        add_action('admin_post_mad4b_cache_control_apply',[self::class,'request_apply']);
    }
}
MAD4B_Cache_Control_V1::boot();
}
