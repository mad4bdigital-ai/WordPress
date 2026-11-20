<?php
/**
 * Plugin Name: MAD4B Elementor WPML Data Shape Guard
 * Description: Preserve Elementor widget tree shape for WPML before ID translation, without changing persisted content.
 * Version: 1.1.4
 */
defined('ABSPATH') || exit;
final class MAD4B_Elementor_WPML_Schema_Guard_V1 {
    /**
     * Native WPML bulk prefetch at JetEngine's taxonomy-option boundary.
     * JetEngine get_terms() builds 100s of options during init; WPML's native
     * get_term filter otherwise executes one ICL translation query per term.
     * We do NOT cache HTML, data, rates or visitor state, and do not replace
     * translation logic. One request-scoped native WPML prefetch only.
     */
    private static array $tax_prefetched = [];
    private static array $tax_stats = [
        'taxonomies'=>0,'ids'=>0,'native_prefetch_queries'=>0,
        'prefetch_ms'=>0,'rejected'=>0,'guard_mismatch'=>0
    ];
    private static function tax_prefetch_mode(): string {
        $mode=(string)get_option('mad4b_wpml_jetengine_taxonomy_prefetch_mode','off');
        if($mode==='active')return 'active';
        if($mode!=='canary')return '';
        $qa=(string)($_SERVER['HTTP_X_MAD4B_MB_PREFETCH_QA']??'');
        return $qa==='v1' && ($_SERVER['HTTP_X_MAD4B_BYPASS_CACHE']??'')==='1'
            ? 'canary' : '';
    }
    public static function jetengine_taxonomy_prefetch($args,$field,$taxonomy) {
        if(!is_array($args) || !is_array($field) || !is_string($taxonomy)
            || !preg_match('/^[a-z0-9_-]{2,60}$/D',$taxonomy)
            || !taxonomy_exists($taxonomy)
            || is_admin() || wp_doing_ajax()
            || (defined('WP_CLI') && WP_CLI)
            || (defined('REST_REQUEST') && REST_REQUEST)
            || ($_SERVER['REQUEST_METHOD']??'GET')!=='GET'
            || !self::tax_prefetch_mode()
            || !class_exists('WPML_Term_Translation',false)
            || !function_exists('wp_using_ext_object_cache')
            || !wp_using_ext_object_cache())return $args;
        if(isset(self::$tax_prefetched[$taxonomy]))return $args;
        // One attempt per taxonomy; native JetEngine/WPML remains authoritative
        // if eligibility or the installed WPML interface has changed.
        self::$tax_prefetched[$taxonomy]=true;
        global $wpml_term_translations,$wpdb;
        if(!($wpml_term_translations instanceof \WPML_Term_Translation)
            || !is_object($wpdb)) {
            self::$tax_stats['rejected']++;
            return $args;
        }
        try {
            $method=new \ReflectionMethod($wpml_term_translations,'prefetch_ids');
            $vendor=$method->getFileName();
            if($method->getNumberOfRequiredParameters()!==1
                || !is_string($vendor)
                || !hash_equals('e242bcba4053d418ae4de26bd42d4922280829fc11f3a278db09ed630927d6fe',
                    (string)hash_file('sha256',$vendor))) {
                self::$tax_stats['guard_mismatch']++;
                return $args;
            }
            // Bound the batch to the actual JetEngine source taxonomy. This
            // reads only native WordPress term-taxonomy identifiers; no term
            // labels, market/customer fields or derived language IDs.
            $sql=$wpdb->prepare(
                "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy=%s LIMIT 801",
                $taxonomy
            );
            $ids=$wpdb->get_col($sql);
            if(!is_array($ids) || !$ids || count($ids)>800
                || $wpdb->last_error!=='') {
                self::$tax_stats['rejected']++;
                return $args;
            }
            $ids=array_values(array_unique(array_filter(array_map('absint',$ids))));
            if(!$ids)return $args;
            $q0=get_num_queries();$t0=hrtime(true);
            // The upstream WPML API itself fills its in-memory translation
            // identity cache; all subsequent get_terms() hooks execute native.
            $wpml_term_translations->prefetch_ids($ids);
            self::$tax_stats['taxonomies']++;
            self::$tax_stats['ids']+=count($ids);
            self::$tax_stats['native_prefetch_queries']+=get_num_queries()-$q0;
            self::$tax_stats['prefetch_ms']+=(hrtime(true)-$t0)/1e6;
        }catch(\Throwable $e) {
            self::$tax_stats['rejected']++;
        }
        return $args;
    }
    public static function jetengine_tax_prefetch_qa():void {
        if(($_SERVER['HTTP_X_MAD4B_MB_PREFETCH_QA']??'')!=='v1')return;
        $lang=(string)apply_filters('wpml_current_language','');
        if(!preg_match('/^[a-z]{2,5}$/D',$lang))return;
        file_put_contents('/tmp/mad4b-jetengine-term-prefetch-'.$lang.'.json',
            wp_json_encode(self::$tax_stats+['mode'=>self::tax_prefetch_mode()],
                JSON_PRETTY_PRINT),LOCK_EX);
    }

    public static function normalized($items,$post_id=0){
        if(!is_array($items))return $items;
        $walk=static function(&$nodes)use(&$walk){
            foreach($nodes as &$node){
                if(!is_array($node) || !isset($node['elType']) || !isset($node['id']))continue;
                if(!array_key_exists('elements',$node))$node['elements']=[];
                if(is_array($node['elements']))$walk($node['elements']);
            }
            unset($node);
        };
        $walk($items);
        return $items;
    }
    // Per-request only. Never persist WPML conversion output or visitor context.
    private static int $memo_document = 0;
    private static array $memo = [];
    private static int $memo_bytes = 0;
    private static array $memo_stats = ['calls'=>0,'hits'=>0,'native'=>0,'rejected'=>0,
        'hash_ms'=>0,'native_ms'=>0,'hit_bytes'=>0];

    public static function memo_document_context($data,$document_id=0) {
        self::$memo_document=absint($document_id);
        return $data;
    }

    private static function memo_context(): array {
        $post=$GLOBALS['post']??null;
        $post_id=$post instanceof WP_Post?(int)$post->ID:0;
        $jet_id=0;
        if (function_exists('jet_engine')) {
            $jet=jet_engine();
            if (is_object($jet) && isset($jet->listings->data)
                && method_exists($jet->listings->data,'get_current_object_id'))
                $jet_id=(int)$jet->listings->data->get_current_object_id();
        }
        $lang=(string)apply_filters('wpml_current_language','');
        if (!self::$memo_document || !$post_id || !$jet_id
            || !preg_match('/^[a-z]{2,5}$/D',$lang))return [];
        return [get_current_blog_id(),self::$memo_document,$post_id,$jet_id,
            (int)get_queried_object_id(),$lang];
    }

    /**
     * Compile WPML's own converter predicates (not a parallel translator).
     * WooProduct::getAll builds 108 predicates that can ONLY match
     * settings.__dynamic__ strings containing "woocommerce-product-".
     * For a document with no such token, retain every other WPML converter
     * in original order. Unknown vendor definitions always run native.
     */
    private static array $prune_stats = [
        'calls'=>0,'optimized'=>0,'native'=>0,'audit'=>0,
        'mismatches'=>0,'saved_predicates'=>0,'native_ms'=>0,'optimized_ms'=>0
    ];
    private static function prune_requested(): bool {
        $mode=(string)get_option('mad4b_wpml_woocommerce_predicate_prune_mode','off');
        $qa=(string)($_SERVER['HTTP_X_MAD4B_WPML_PRUNE_QA']??'');
        if($qa==='native-v1')return false;
        if($mode==='active')return true;
        return $mode==='canary' && $qa==='v1'
            && ($_SERVER['HTTP_X_MAD4B_BYPASS_CACHE']??'')==='1';
    }
    private static function prune_compiled() {
        static $compiled=null;
        if($compiled!==null)return $compiled;
        $compiled=false;
        if(has_filter('wpml_pb_elementor_widget_dynamic_id_converters')!==false)return false;
        $native='WPML\PB\Elementor\Hooks\DynamicElements';
        $provider='WPML\PB\Elementor\Config\DynamicElements\Provider';
        $woo='WPML\PB\Elementor\Config\DynamicElements\WooProduct';
        if(!class_exists($native)||!class_exists($provider)||!class_exists($woo))return false;
        try {
            $verify=[
                $native=>'b519ddeb269cd2ba1753fabb000b6c32aa94a82617b8ab8a27b3083e9a62aecc',
                $provider=>'430023758d831fff10612cf98f9f459802695d1511f87156ce2eb9172aaca386',
                $woo=>'45eae64fcbb3a664aa2ab764e6740cec3a89e0d26bf7a20ef05cf17da8235b9f'
            ];
            foreach($verify as $class=>$sha) {
                $file=(new ReflectionClass($class))->getFileName();
                if(!is_string($file) || !is_file($file)
                    || !hash_equals($sha,(string)hash_file('sha256',$file)))return false;
            }
            $definitions=$provider::get();
            if(!is_array($definitions) || count($definitions)!==122)return false;
            $convertTag=\WPML\FP\curryN(3,[$native,'convertTag']);
            $base=[];$excluded=0;
            foreach($definitions as $def) {
                if(!is_array($def) || count($def)<2 || !is_callable($def[0])
                    || !is_callable($def[1]))return false;
                $predRef=new ReflectionFunction(Closure::fromCallable($def[0]));
                $src=(string)$predRef->getFileName();
                $vars=$predRef->getStaticVariables();
                $fromWoo=is_string($src) && basename($src)==='WooProduct.php';
                if($fromWoo){
                    $path=$vars['dynamicPath']??null;
                    $tag=$vars['tagName']??null;
                    if(!is_array($path) || count($path)!==3
                        || $path[0]!=='settings' || $path[1]!=='__dynamic__'
                        || !is_string($path[2]) || !is_string($tag)
                        || !str_starts_with($tag,'woocommerce-product-')
                        || ($def[2]??null)!==$tag
                        || ($def[3]??null)!=='product_id')return false;
                    ++$excluded;
                    continue;
                }
                $convert=!empty($def[2]) && !empty($def[3])
                    ? $convertTag($def[2],$def[3])
                    : [$native,'convertId'];
                $base[]=[$def[0],\WPML\FP\Obj::over($def[1],$convert)];
            }
            if($excluded!==108 || count($base)!==14)return false;
            $method=new ReflectionMethod($native,'applyConverters');
            if($method->getNumberOfRequiredParameters()!==2)return false;
            $compiled=['base'=>$base,'method'=>$method,'excluded'=>$excluded];
        }catch(Throwable $e){return false;}
        return $compiled;
    }
    private static function prune_convert($data, callable $native){
        ++self::$prune_stats['calls'];
        if(!is_array($data) || has_filter('wpml_pb_elementor_widget_dynamic_id_converters')!==false) {
            ++self::$prune_stats['native'];return $native($data);
        }
        try {
            $serialized=serialize($data);
            // This is a conservative superset of every Woo Product converter's
            // tag condition. Never prune any Woo tag present in a document.
            if(str_contains($serialized,'woocommerce-product-')) {
                ++self::$prune_stats['native'];return $native($data);
            }
            $compiled=self::prune_compiled();
            if(!$compiled || !is_array($native) || !isset($native[0])
                || get_class($native[0])!=='WPML\PB\Elementor\Hooks\DynamicElements') {
                ++self::$prune_stats['native'];return $native($data);
            }
            $start=hrtime(true);
            $out=$compiled['method']->invoke($native[0],$data,$compiled['base']);
            self::$prune_stats['optimized_ms']+=(hrtime(true)-$start)/1e6;
            $audit=(string)($_SERVER['HTTP_X_MAD4B_WPML_PRUNE_AUDIT']??'')==='v1'
                && (string)($_SERVER['HTTP_X_MAD4B_WPML_PRUNE_QA']??'')==='v1';
            if($audit){
                $start=hrtime(true);$expected=$native($data);
                self::$prune_stats['native_ms']+=(hrtime(true)-$start)/1e6;
                self::$prune_stats['audit']++;
                if(serialize($out)!==serialize($expected)) {
                    ++self::$prune_stats['mismatches'];
                }
                // Audit returns original WPML output and never changes presentation.
                return $expected;
            }
            ++self::$prune_stats['optimized'];
            self::$prune_stats['saved_predicates']+=$compiled['excluded'];
            return $out;
        } catch(Throwable $e){
            ++self::$prune_stats['native'];
            return $native($data);
        }
    }
    private static function prune_profile_enable():void {
        if(($_SERVER['HTTP_X_MAD4B_WPML_PRUNE_PROFILE']??'')!=='v1')return;
        add_action('shutdown',static function():void {
            $lang=(string)apply_filters('wpml_current_language','');
            if(!preg_match('/^[a-z]{2,5}$/D',$lang))return;
            file_put_contents('/tmp/mad4b-wpml-prune-'.$lang.'.json',
                wp_json_encode(self::$prune_stats,JSON_PRETTY_PRINT),LOCK_EX);
        },PHP_INT_MAX);
    }

    public static function install_memo():void {
        // Reuse the verified company/locale/session/market/route admission.
        if (!class_exists('MAD4B_Elementor_Condition_Tree_Gate',false)
            || !MAD4B_Elementor_Condition_Tree_Gate::request())return;
        if(!is_singular('tours-and-activities'))return;
        $mode=(string)get_option('mad4b_wpml_converter_request_memo_v1','off');
        $qa=(string)($_SERVER['HTTP_X_MAD4B_WPML_MEMO_QA']??'');
        $canary=in_array(wp_get_environment_type(),['staging','production'],true) && $qa==='v1'
            && ($_SERVER['HTTP_X_MAD4B_BYPASS_CACHE']??'')==='1';
        $memo=$qa!=='native-v1' && ($mode==='active' || $canary);
        $prune=self::prune_requested();
        if(!$memo && !$prune)return;
        // Third-party converter providers may be context-sensitive: fail native.
        if (has_filter('wpml_pb_elementor_widget_dynamic_id_converters')!==false)return;
        global $wp_filter;
        $tag='elementor/frontend/builder_content_data';
        $hook=$wp_filter[$tag]??null;
        if (!($hook instanceof WP_Hook))return;
        $matched=[];
        foreach ($hook->callbacks[10]??[] as $id=>$entry) {
            $cb=$entry['function']??null;
            if (is_array($cb) && count($cb)===2 && is_object($cb[0])
                && get_class($cb[0])===\WPML\PB\Elementor\Hooks\DynamicElements::class
                && $cb[1]==='convert')$matched[$id]=$cb;
        }
        if (count($matched)!==1)return;
        $key=array_key_first($matched);
        $native=$matched[$key];
        if (!is_callable($native) || !isset($hook->callbacks[10][$key])
            || $hook->callbacks[10][$key]['function']!==$native)return;
        if($prune){
            self::prune_profile_enable();
            $original=$native;
            $native=static fn($data)=>self::prune_convert($data,$original);
        }
        if(!$memo){
            $hook->callbacks[10][$key]['function']=$native;
            return;
        }
        add_filter($tag,[self::class,'memo_document_context'],9,2);
        $hook->callbacks[10][$key]['function']=static function($data)use($native) {
            self::$memo_stats['calls']++;
            if (!is_array($data)) {
                self::$memo_stats['rejected']++;self::$memo_stats['native']++;
                return $native($data);
            }
            $context=self::memo_context();
            if (!$context) {
                self::$memo_stats['rejected']++;self::$memo_stats['native']++;
                return $native($data);
            }
            $start=hrtime(true);
            try {$raw=serialize($data);}
            catch (Throwable $e) {
                self::$memo_stats['rejected']++;self::$memo_stats['native']++;
                return $native($data);
            }
            $bytes=strlen($raw);
            if ($bytes<2 || $bytes>600000) {
                self::$memo_stats['rejected']++;self::$memo_stats['native']++;
                return $native($data);
            }
            $key=hash('sha256',serialize($context).'|'.$raw);
            self::$memo_stats['hash_ms']+=(hrtime(true)-$start)/1e6;
            if (isset(self::$memo[$key])) {
                self::$memo_stats['hits']++;
                self::$memo_stats['hit_bytes']+=$bytes;
                return self::$memo[$key];
            }
            $start=hrtime(true);
            self::$memo_stats['native']++;
            $result=$native($data);
            self::$memo_stats['native_ms']+=(hrtime(true)-$start)/1e6;
            if (is_array($result) && count(self::$memo)<64
                && self::$memo_bytes+$bytes<=4194304) {
                self::$memo[$key]=$result;
                self::$memo_bytes+=$bytes;
            }
            return $result;
        };
        if ($canary && ($_SERVER['HTTP_X_MAD4B_WPML_MEMO_PROFILE']??'')==='1') {
            add_action('shutdown',static function():void {
                error_log('MAD4B_WPML_REQUEST_MEMO '.wp_json_encode(
                   self::$memo_stats+['entries'=>count(self::$memo),
                   'input_bytes'=>self::$memo_bytes,
                   'site'=>(string)wp_parse_url(home_url('/'),PHP_URL_HOST)]));
            },9999);
        }
    }
}
add_filter('jet-engine/meta-boxes/taxonomy-options/terms-args',
    ['MAD4B_Elementor_WPML_Schema_Guard_V1','jetengine_taxonomy_prefetch'],8,3);
add_action('shutdown',['MAD4B_Elementor_WPML_Schema_Guard_V1','jetengine_tax_prefetch_qa'],99999);
add_filter('elementor/frontend/builder_content_data',
    ['MAD4B_Elementor_WPML_Schema_Guard_V1','normalized'],1,2);
add_action('wp',['MAD4B_Elementor_WPML_Schema_Guard_V1','install_memo'],30);
