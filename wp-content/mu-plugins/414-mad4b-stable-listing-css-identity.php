<?php
/**
 * Plugin Name: MAD4B Stable Dynamic Listing CSS Identity
 * Description: Deterministic JetEngine listing CSS selectors from object identity without altering vendor code.
 * Version: 0.4.1
 */
defined('ABSPATH') || exit;
final class MAD4B_Stable_Listing_CSS_Identity {
    private static bool $verifiedTourRoute = false;
    private static int $verifiedTourPost = 0;
    private static ?bool $termFragmentRouteReady = null;
    private static bool $verifiedArchiveRoute = false;
    private static string $singularType = '';
    private static string $archiveType = '';

    /** Site-scoped opt-in for public post types. No company names, URLs,
     * template IDs or content types are baked into this shared module.
     *
     * Example per-site option mad4b_stable_dcss_post_types_v1:
     * ['singular'=>['tours-and-activities'],'archive'=>['tours-and-activities']]
     * Other sites supply their own registered public types; empty => OFF.
     */
    private static function allowed_types(string $kind): array {
        if ($kind !== 'singular' && $kind !== 'archive') return [];
        $config=get_option('mad4b_stable_dcss_post_types_v1',[]);
        if (!is_array($config) || !isset($config[$kind])
            || !is_array($config[$kind]) || count($config[$kind])>32) return [];
        $types=[];
        foreach ($config[$kind] as $type) {
            if (!is_string($type) || !preg_match('/^[a-z0-9_-]{1,64}$/D',$type)
                || !post_type_exists($type)) continue;
            $definition=get_post_type_object($type);
            if (!$definition || empty($definition->public)
                || empty($definition->publicly_queryable)
                || ($kind==='archive' && empty($definition->has_archive))) continue;
            $types[]=$type;
        }
        return array_values(array_unique($types));
    }
    /** Keep the canonical public Tour identity when nested listings swap the
     * global WP_Query. Do not infer admission from an internal listing item. */
    public static function capture_tour_route(): void {
        self::$verifiedTourRoute=false;
        self::$verifiedTourPost=0;
        self::$termFragmentRouteReady=null;
        self::$singularType='';
        if (is_admin() || wp_doing_ajax() || is_user_logged_in()
            || ($_SERVER['REQUEST_METHOD']??'GET')!=='GET')return;
        foreach (self::allowed_types('singular') as $type) {
            if (!is_singular($type))continue;
            $id=absint(get_queried_object_id());
            $post=$id?get_post($id):null;
            if (!$post instanceof WP_Post || $post->post_type!==$type
                || $post->post_status!=='publish'
                || !is_post_publicly_viewable($post) || post_password_required($post))return;
            $canonical=(string)wp_parse_url(get_permalink($post),PHP_URL_PATH);
            $path=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
            if ($canonical!=='' && $canonical===$path) {
                self::$verifiedTourRoute=true;
                self::$verifiedTourPost=$id;
                self::$singularType=$type;
            }
            return;
        }
    }

    /** Capture main WP query before nested JetEngine listings replace it.
     * Each tenant independently defines which public CPT archives qualify.
     */
    public static function capture_archive_route(): void {
        self::$verifiedArchiveRoute=false;
        self::$archiveType='';
        if (is_admin() || wp_doing_ajax()
            || (($_SERVER['REQUEST_METHOD']??'GET') !== 'GET')) return;
        foreach (self::allowed_types('archive') as $type) {
            if (!is_post_type_archive($type))continue;
            $canonical=(string)wp_parse_url(
                (string)get_post_type_archive_link($type),PHP_URL_PATH
            );
            $actual=(string)wp_parse_url(
                (string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH
            );
            if ($canonical!=='' && $actual===$canonical) {
                self::$verifiedArchiveRoute=true;
                self::$archiveType=$type;
            }
            return;
        }
    }

    private static function enabled(): bool {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)
            || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
            || !class_exists('MAD4B_Experience_Site_Scope_V1',false)
            || !MAD4B_Experience_Site_Scope_V1::bound()) return false;
        // Only CSS selector identities are normalized. This NEVER grants an
        // HTML cache admission, and an authenticated Woo session cannot enter
        // the private-home CSS optimization.
        $authenticated=is_user_logged_in();
        if ($authenticated && function_exists('WC') && WC() && WC()->session
            && method_exists(WC()->session,'has_session')
            && WC()->session->has_session()) return false;
        // Staging-only tour CSS proof. Normal tour traffic remains native
        // until its own per-company policy is explicitly enabled.
        $home = class_exists('MAD4B_Shared_First_Paint_Performance', false)
            && MAD4B_Shared_First_Paint_Performance::home_view();
        $tour = !$home && self::$verifiedTourRoute
            && in_array(self::$singularType,self::allowed_types('singular'),true)
            && is_singular(self::$singularType);
        $archive = !$home && !$tour && self::$verifiedArchiveRoute
            && in_array(self::$archiveType,self::allowed_types('archive'),true)
            && is_post_type_archive(self::$archiveType)
            && (string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH)
                === (string)wp_parse_url(
                    (string)get_post_type_archive_link(self::$archiveType),PHP_URL_PATH
                );
        if (!$home && !$tour && !$archive) return false;
        if ($archive) {
            return get_option('mad4b_perf_archive_stable_dcss_v1','off')==='on'
                && empty($_SERVER['QUERY_STRING']);
        }
        if ($authenticated && $home) {
            return get_option('mad4b_perf_private_home_stable_dcss_v1','off')==='on'
                && empty($_SERVER['QUERY_STRING']);
        }
        if ($authenticated) return false;
        if ($tour) {
            if (wp_get_environment_type() === 'staging'
                && empty($_SERVER['QUERY_STRING'])
                && (string)($_SERVER['HTTP_X_MAD4B_TOUR_DCSS_PROBE'] ?? '') === 'stage-proof-v1') return true;
            return get_option('mad4b_perf_stable_tour_dcss_v1', 'off') === 'on'
                && empty($_SERVER['QUERY_STRING']);
        }
        // QA query strings are never public Production feature switches.
        if ((string)($_GET['mad4b_stable_dcss'] ?? '') === '1')
            return wp_get_environment_type()==='staging';
        // The staging-only Critical CSS proof must see the *same* stable
        // JetEngine selector graph as a real anonymous, cookie-free home.
        // No QA probe is honored on Production.
        if (wp_get_environment_type()==='staging' && count($_GET)===1
            && (string)($_GET['mad4b_critical_css_probe'] ?? '')==='1')
            return true;
        // The selective-render canary must exercise exactly the same stable
        // selector lifecycle as a future anonymous, cache-enabled request.
        if (wp_get_environment_type()==='staging'
            && count($_GET) === 1 && (string)($_GET['mad4b_public_fragment_qa'] ?? '') === '1'
            && (string)get_option('mad4b_render_public_fragments_enabled', '0') !== '1') return true;
        return get_option('mad4b_perf_stable_dcss_v1','off') === 'on'
            && empty($_SERVER['QUERY_STRING']);
    }
    public static function cache_ready(): bool { return self::enabled(); }
    private static function identifier($object): string {
        if (!is_object($object) || !function_exists('jet_engine')) return '';
        $app = jet_engine();
        if (!isset($app->listings->data)) return '';
        $id = $app->listings->data->get_current_object_id($object);
        if (!is_scalar($id) || (string)$id === '' || strlen((string)$id) > 128) return '';
        // Stable per site, object type and ID; never cache runtime hrtime-based selectors.
        return substr(hash('sha256', get_class($object).':'.(string)$id), 0, 16);
    }
    public static function css_content($content,$listing_id,$inner_templates) {
        if (!self::enabled() || !is_string($content)) return $content;
        $app=jet_engine();
        $object=$app->listings->data->get_current_object();
        $id=self::identifier($object);
        if (!$id) return $content;
        $volatile=$app->listings->data->get_current_object_unique_id($object);
        if (!is_scalar($volatile) || !preg_match('/^[0-9]{5,22}$/D',(string)$volatile)) return $content;
        return str_replace('elementor-dcss-'.(string)$volatile,
            'elementor-dcss-mad4b-'.$id,$content);
    }
    /**
     * Elementor/JetEngine replaces the global WP_Query while rendering
     * nested term listings. The outer canonical Tour was already verified
     * on the wp hook before that global query was swapped. Reuse only this
     * immutable proof for an audited public term fragment, not general
     * Elementor rendering, editor, rates, quotes, or visitor UI sessions.
     */
    private static function public_tour_term_fragment_ready(): bool {
        if (self::$termFragmentRouteReady !== null) return self::$termFragmentRouteReady;
        $valid=self::$verifiedTourRoute && self::$verifiedTourPost>0
            && self::$singularType!==''
            && in_array(self::$singularType,self::allowed_types('singular'),true)
            && ($_SERVER['REQUEST_METHOD']??'')==='GET'
            && empty($_SERVER['QUERY_STRING'])
            && !is_admin() && !wp_doing_ajax() && !is_user_logged_in()
            && !(defined('REST_REQUEST')&&REST_REQUEST)
            && !empty($_SERVER['HTTP_HOST'])
            && empty($_SERVER['HTTP_AUTHORIZATION'])
            && class_exists('MAD4B_Experience_Site_Scope_V1',false)
            && MAD4B_Experience_Site_Scope_V1::bound()
            && (string)get_option('mad4b_perf_stable_tour_dcss_v1','off')==='on';
        if ($valid) {
            $post=get_post(self::$verifiedTourPost);
            $url=$post ? get_permalink($post) : '';
            $valid=$post instanceof WP_Post
                && $post->post_status==='publish'
                && $post->post_type===self::$singularType
                && is_post_publicly_viewable($post)
                && !post_password_required($post)
                && is_string($url)
                && (string)wp_parse_url($url,PHP_URL_PATH)
                    === (string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
        }
        return self::$termFragmentRouteReady=(bool)$valid;
    }

    /**
     * Normalize the *actual* volatile CSS token inside a public JetEngine
     * term fragment before Redis storage. The old hook asks JetEngine for a
     * new hrtime() token after markup is rendered, which need not equal the
     * token already embedded in Elementor inline CSS. Restrict to a single
     * token and the same fallback term selector used by normalize_buffer().
     * Unknown/nested selector layouts never qualify for fragment storage.
     */
    public static function normalize_public_term_fragment($html,$term,$template_id=0) {
        if (!(self::enabled() || self::public_tour_term_fragment_ready())
            || !self::$verifiedTourRoute
            || !($term instanceof WP_Term) || !is_string($html)
            || strlen($html)<100 || strlen($html)>15000)return null;
        // JetEngine's normal Elementor document wrapper carries its own ID.
        // It is safe only when it is exactly the audited listing document,
        // never an arbitrary nested or visitor-selected Elementor template.
        if (str_contains($html,'data-elementor-id=')) {
            $count=preg_match_all('/data-elementor-id=["\x27]([0-9]{1,12})["\x27]/',$html,$docIds);
            $ids=$count?array_values(array_unique($docIds[1])):[];
            if ($count!==1 || count($ids)!==1
                || (int)$template_id<1 || (int)$ids[0] !== (int)$template_id)
                return null;
        }
        $id=(int)$term->term_id;
        if (!$id || !str_contains($html,'jet-listing-dynamic-post-'.$id))return null;
        $selector='elementor-dcss-mad4b-'.substr(hash('sha256',$id.':listing'),0,16);
        // On some tenants JetEngine already emitted the canonical object-
        // based stable selector before Fragments ran. It is equally safe,
        // but the listing fallback must not replace or reject that identity.
        // Accept exactly one matching primary selector and zero volatile
        // selectors; ambiguity always forces the native renderer.
        $objectSelector='elementor-dcss-mad4b-'
            .substr(hash('sha256',get_class($term).':'.$id),0,16);
        if (str_contains($html,$objectSelector)
            && !preg_match('/elementor-dcss-[0-9]{10,22}(?![0-9])/',$html)) {
            preg_match_all('/elementor-dcss-mad4b-[a-f0-9]{16}/',$html,$stableMatches);
            $unique=array_values(array_unique($stableMatches[0]??[]));
            if (count($unique)===1 && $unique[0]===$objectSelector)
                return $html;
            return null;
        }
        if (str_contains($html,$selector)
            && !preg_match('/elementor-dcss-[0-9]{10,22}(?![0-9])/',$html))
            return $html;
        if (!preg_match_all('/elementor-dcss-([0-9]{10,22})(?![0-9])/',$html,$matches)
            || count(array_unique($matches[1]))!==1)return null;
        $volatile='elementor-dcss-'.$matches[1][0];
        $result=str_replace($volatile,$selector,$html);
        return !str_contains($result,$volatile) && str_contains($result,$selector)
            && !preg_match('/elementor-dcss-[0-9]{10,22}(?![0-9])/',$result)
            ?$result:null;
    }
    public static function item_classes($classes,$object,$i,$render) {
        if (!self::enabled() || !is_array($classes))return $classes;
        $id=self::identifier($object);
        if (!$id)return $classes;
        $app=jet_engine();
        $volatile=$app->listings->data->get_current_object_unique_id($object);
        if (!is_scalar($volatile) || !preg_match('/^[0-9]{5,22}$/D',(string)$volatile))return $classes;
        $old='elementor-dcss-'.(string)$volatile;
        foreach($classes as &$class){
            if(is_string($class) && $class === $old)$class='elementor-dcss-mad4b-'.$id;
        }
        unset($class);
        return $classes;
    }
    /**
     * Final-stage repair for CSS added after an eligible cached JetEngine term
     * fragment is returned. No translation, HTML or pricing is changed.
     * Use the already-proven outer stable selector and matching data-post-id;
     * reject mixed/ambiguous inline CSS selectors.
     */
    private static function normalize_cached_term_inline_css(string $buffer): string {
        if (!self::$verifiedTourRoute)return $buffer;
        $mode=(string)get_option('mad4b_render_public_fragments_tour_scope','off');
        if ($mode!=='active' && !($mode==='canary'
            && in_array(wp_get_environment_type(),['staging','production'],true)
            && ($_SERVER['HTTP_X_MAD4B_TOUR_FRAGMENT_QA']??'')==='v1'))return $buffer;
        if (!preg_match('/elementor-dcss-[0-9]{10,22}(?![0-9])/',$buffer))return $buffer;
        $pattern='~(<div\b[^>]*class="[^"]*\bjet-listing-grid__item\s+jet-listing-dynamic-post-([0-9]{1,12})\s+elementor-dcss-mad4b-([a-f0-9]{16})[^"]*"[^>]*\bdata-post-id="\2"[^>]*>\s*<style\b[^>]*>)(.*?)(</style>)~s';
        $fixed=preg_replace_callback($pattern,static function(array $m):string {
            $css=$m[4];
            $term=$m[2];
            if (!str_contains($css,'.jet-listing-dynamic-post-'.$term.':not('))
                return $m[0];
            if (!preg_match_all('/elementor-dcss-([0-9]{10,22})(?![0-9])/',$css,$volatile)
                || count(array_unique($volatile[1]))!==1)return $m[0];
            $before='elementor-dcss-'.$volatile[1][0];
            $after='elementor-dcss-mad4b-'.$m[3];
            $adjusted=str_replace($before,$after,$css);
            if (str_contains($adjusted,$before))return $m[0];
            return $m[1].$adjusted.$m[5];
        },$buffer);
        return is_string($fixed)?$fixed:$buffer;
    }

    public static function normalize_buffer($buffer) {
        if (!self::enabled() || !is_string($buffer) || !str_contains($buffer,'elementor-dcss-'))return $buffer;
        $map=[];
        // Restrict to actual JetEngine listing-item classes and verify the
        // following data-post-id. Do not normalize unrelated inline styles.
        if (!preg_match_all('/jet-listing-dynamic-post-([0-9]{1,12}(?:-[0-9]{1,12}){1,2}|[0-9]{1,12})\\s+(?:jet-listing-grid__list_item\\s+)?elementor-dcss-([0-9]{10,22})(?=[\\s"\\x27>])/D',
            $buffer,$matches,PREG_OFFSET_CAPTURE))return self::normalize_cached_term_inline_css($buffer);
        foreach ($matches[2] as $i=>$match) {
            $volatile=$match[0];
            if (isset($map[$volatile]))continue;
            $postId=$matches[1][$i][0];
            $tail=substr($buffer,$matches[0][$i][1],430);
            if (!preg_match('/\\bdata-post-id=["\\x27]'.preg_quote($postId,'/').'["\\x27]/D',$tail))continue;
            $template='listing';
            if (preg_match('/\\bdata-elementor-id=["\\x27]([0-9]{1,12})["\\x27]/',$tail,$m))$template=$m[1];
            $map[$volatile]='elementor-dcss-mad4b-'.substr(hash('sha256',$postId.':'.$template),0,16);
        }
        if (!$map)return self::normalize_cached_term_inline_css($buffer);
        $replace=[];
        foreach ($map as $volatile=>$stable)$replace['elementor-dcss-'.$volatile]=$stable;
        return self::normalize_cached_term_inline_css(strtr($buffer,$replace));
    }
    public static function boot(): void {
        add_filter('jet-engine/elementor-views/frontend/listing-content',[__CLASS__,'css_content'],9999,3);
        add_filter('jet-engine/listing/item-classes',[__CLASS__,'item_classes'],9999,4);
        add_filter('litespeed_buffer_before',[__CLASS__,'normalize_buffer'],10000,1);
    }
}
add_action('wp',['MAD4B_Stable_Listing_CSS_Identity','capture_tour_route'],115);
add_action('wp',['MAD4B_Stable_Listing_CSS_Identity','capture_archive_route'],116);
add_action('init',['MAD4B_Stable_Listing_CSS_Identity','boot'],30);
