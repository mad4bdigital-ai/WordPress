<?php
/**
 * Plugin Name: MAD4B Published Content Scoped Cache Policy
 * Description: Published content defaults to stable with selective query cache epochs. Dynamic/ambiguous dependencies keep global invalidation.
 * Version: 1.1.1
 */
defined('ABSPATH') || exit;

final class MAD4B_Published_Content_Scope_Cache {
    public const VERSION='1.1.1';
    public const OPTION='mad4b_published_content_scope_cache_v1';
    private static array $pending=[];
    private static bool $registered=false;
    private static bool $itinerary_repeater_dirty=false;
    private static bool $itinerary_repeater_hooked=false;

    public static function default_policy(): array {
        return ['status'=>'active','strategy'=>'stable_scoped','published_content'=>'stable_until_edited',
            'fallback'=>'global_for_dynamic_or_unknown','revision'=>1];
    }
    private static function enabled(): bool {
        $config=get_option(self::OPTION,[]);
        // In Production the legacy global cache is the default authority
        // until company-local opt-in proves schema, query and FastCGI parity.
        if(function_exists('wp_get_environment_type')
           && wp_get_environment_type()==='production'
           && (!is_array($config) || ($config['status']??'paused')!=='active'))
            return false;
        if (is_array($config) && (($config['status']??'active')==='paused' || ($config['strategy']??'')==='global'))return false;
        if (class_exists('MAD4B_Company_Policy_Registry_V2',false)) {
            $result=MAD4B_Company_Policy_Registry_V2::resolve('content.cache.scoped',
                ['component'=>'published_content'],['strategy'=>'stable_scoped']);
            if (($result['values']['strategy']??'stable_scoped')!=='stable_scoped')return false;
        }
        return true;
    }
    public static function providers($providers): array {
        if (!is_array($providers))$providers=[];
        $providers['mad4b-published-content-cache']=[
            'label'=>'Published Content / Scoped Cache Invalidation',
            'policy_id'=>'content.cache.scoped',
            'fields'=>['strategy'=>['type'=>'enum','options'=>[
                'stable_scoped'=>'Published content stable; invalidate affected content family',
                'global'=>'Legacy global cache invalidation']]],
            'allowed_scopes'=>['company']];
        return $providers;
    }
    private static function safe_type(string $type): bool {
        if ($type==='' || !preg_match('/^[a-z][a-z0-9_-]{1,55}$/D',$type)
            || preg_match('/(?:elementor|jet-engine|template|attachment|product|order|booking|checkout|payment|customer|reservation|invoice|rate|pricing|price|quote|supplier|stock|inventory|variation|revision|nav_menu)/i',$type))return false;
        $obj=get_post_type_object($type);
        return (bool)($obj && !empty($obj->public));
    }
    private static function safe_taxonomy(string $taxonomy): bool {
        if ($taxonomy==='' || !preg_match('/^[a-z][a-z0-9_-]{1,55}$/D',$taxonomy)
            || preg_match('/(?:price|rate|cost|booking|reservation|stock|availability|product|market|currency|supplier)/i',$taxonomy))return false;
        $obj=get_taxonomy($taxonomy);
        return (bool)($obj && !empty($obj->public));
    }
    private static function token(string $kind,string $id): string {
        return 'mad4b_pub_cache_'.($kind==='post_type'?'pt_':'tax_').sanitize_key($id).'_v1';
    }
    private static function queue(string $kind,string $id,array $source): void {
        if(count(self::$pending)>=48 && !isset(self::$pending[$kind.':'.$id]))return;
        self::$pending[$kind.':'.$id]=[$kind,$id,$source];
        if(!self::$registered){self::$registered=true;add_action('shutdown',[self::class,'commit'],95);}
    }
    /** Returns true only when it safely owns selective scope invalidation. */
    public static function global_required($required,$origin) {
        if(!$required || !self::enabled() || !is_array($origin))return $required;
        $kind=(string)($origin['kind']??'');
        if(in_array($kind,['taxonomy','taxonomy_links'],true)) {
            $taxonomy=(string)($origin['taxonomy']??'');
            if(!self::safe_taxonomy($taxonomy))return true;
            self::queue('taxonomy',$taxonomy,['kind'=>$kind,'term_id'=>absint($origin['term_id']??0)]);
            return false;
        }
        if($kind==='object_terms') {
            $id=absint($origin['post_id']??0);$type=$id?(string)get_post_type($id):'';
            $taxonomy=(string)($origin['taxonomy']??'');
            if(!self::safe_type($type)||!self::safe_taxonomy($taxonomy))return true;
            self::queue('post_type',$type,['kind'=>$kind,'post_id'=>$id]);
            self::queue('taxonomy',$taxonomy,['kind'=>$kind,'post_id'=>$id]);
            return false;
        }
        if(!in_array($kind,['public_post_change','post_meta'],true))return true;
        $id=absint($origin['post_id']??0);
        $type=$id?(string)get_post_type($id):'';
        if(!self::safe_type($type))return true;
        if($kind==='post_meta'){
            $sensitive=(int)($origin['meta_sensitive']??1);
            // Shared templates, hidden cross-entity relations and price inputs
            // cannot be proven isolated; preserve legacy global invalidation.
            if($sensitive!==0)return true;
        }
        self::queue('post_type',$type,['kind'=>$kind,'post_id'=>$id]);
        return false;
    }
    public static function hybrid_eligible(): bool {
        return self::enabled();
    }
    public static function query_version($generation,$query,$decision): string {
        if(!self::enabled() || !is_object($query) || empty($decision['cache']))return (string)$generation;
        // For a fully proven, live reverse-index query the graph is the
        // exclusive query generation owner; legacy type epochs continue to
        // protect everything else and are still updated for other callers.
        if(class_exists('MAD4B_Page_Dependency_Cache',false)
            && MAD4B_Page_Dependency_Cache::authority($query,(array)$decision)==='dependency')
            return (string)$generation;
        $args=(array)($query->final_query??[]);
        $type=(string)($query->query_type??'');
        $depend=[];
        if($type==='posts') {
            foreach((array)($args['post_type']??[]) as $pt){
                if(!is_string($pt)||!self::safe_type($pt))return (string)$generation;
                $depend[]=self::token('post_type',$pt);
            }
            foreach((array)($args['tax_query']??[]) as $tax){
                if(!is_array($tax)||!isset($tax['taxonomy']))continue;
                if(!self::safe_taxonomy((string)$tax['taxonomy']))return (string)$generation;
                $depend[]=self::token('taxonomy',(string)$tax['taxonomy']);
            }
        } elseif($type==='terms') {
            foreach((array)($args['taxonomy']??[]) as $tax){
                if(!is_string($tax)||!self::safe_taxonomy($tax))return (string)$generation;
                $depend[]=self::token('taxonomy',$tax);
            }
        } elseif($type==='repeater') {
            // Content repeaters depend on their parent; until a per-parent
            // dependency manifest is proven, they retain global generation.
            return (string)$generation;
        } else return (string)$generation;
        $depend=array_unique($depend);sort($depend,SORT_STRING);
        if(!$depend)return (string)$generation;
        $values=[];foreach($depend as $key)$values[$key]=(string)get_option($key,'0');
        return (string)$generation.':scope:'.substr(hash('sha256',wp_json_encode($values)),0,20);
    }
    public static function commit(): void {
        foreach(self::$pending as [$kind,$id,$source]){
            $key=self::token($kind,$id);
            $revision=substr(hash('sha256',(string)get_option($key,'0').'|'.(string)hrtime(true).'|'.(string)wp_rand(100000,999999)),0,24);
            update_option($key,$revision,false);
            $receipt=['contract'=>'mad4b.content-cache.scope-update.v1','kind'=>$kind,
                'scope'=>$id,'epoch'=>$revision,'source'=>$source,'time'=>gmdate('c')];
            do_action('mad4b/content_cache/scope_updated',$receipt);
        }
        self::$pending=[];
    }
    /**
     * Native JetEngine itinerary_item is a public *repeater* Query Builder
     * source; unlike posts/terms queries it has no verified parent-scoped
     * generation. It retains the shared fragment generation. A source edit
     * must therefore rotate that generation once AFTER the original WordPress
     * request finishes its entire localized repeater batch.
     *
     * Do not rotate on unrelated post meta, generated Elementor artifacts,
     * price fields, merely viewing pages, or JetEngine reads. No object-cache
     * flush or general FastCGI purge is performed here.
     */
    public static function on_original_itinerary_meta_change($meta_id,$post_id,$meta_key): void {
        if((string)$meta_key!=='itinerary_item' ||
           !self::enabled() || get_post_type((int)$post_id)!=='tours-and-activities' ||
           get_post_status((int)$post_id)!=='publish')return;
        self::$itinerary_repeater_dirty=true;
        if(!self::$itinerary_repeater_hooked){
            self::$itinerary_repeater_hooked=true;
            // After all original post_meta writes, before the scoped epoch
            // receipt is committed at shutdown priority 95.
            add_action('shutdown',[self::class,'commit_original_itinerary_repeater_generation'],94);
        }
    }
    public static function commit_original_itinerary_repeater_generation(): void {
        if(!self::$itinerary_repeater_dirty || !self::enabled())return;
        self::$itinerary_repeater_dirty=false;
        $key='mad4b_render_query_data_generation';
        $value=sprintf('%d-%d',time(),wp_rand(100000,999999));
        $GLOBALS['mad4b_render_query_invalidation_origin']=[
            'kind'=>'original_published_itinerary_repeater_batch',
            'post_type'=>'tours-and-activities',
            'scope'=>'shared_repeater_fallback_only'
        ];
        try {
            update_option($key,$value,false);
        } finally {
            unset($GLOBALS['mad4b_render_query_invalidation_origin']);
        }
        if((string)get_option($key,'')!==$value)
            error_log('MAD4B_ORIGINAL_ITINERARY_REPEATER_EPOCH_READBACK_FAILED');
    }
    public static function term_created($id,$ttid,$taxonomy): void {
        $tax=(string)$taxonomy;
        if(self::enabled()&&self::safe_taxonomy($tax))self::queue('taxonomy',$tax,['kind'=>'created_term','term_id'=>(int)$id]);
    }
    public static function boot(): void {
        add_filter('mad4b/policy_registry/providers',[self::class,'providers'],20);
        add_filter('mad4b/render_query/global_invalidation_required',[self::class,'global_required'],20,2);
        add_filter('mad4b/render_query/query_version',[self::class,'query_version'],20,3);
        foreach(['added_post_meta','updated_post_meta','deleted_post_meta'] as $itineraryHook)
            add_action($itineraryHook,[self::class,'on_original_itinerary_meta_change'],90,3);
        add_action('created_term',[self::class,'term_created'],35,3);
    }
}
MAD4B_Published_Content_Scope_Cache::boot();
