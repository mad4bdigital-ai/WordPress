<?php
/**
 * Plugin Name: MAD4B Service Public Entry Guard
 * Description: Site-neutral public entry-point policy. Reads JetEngine Service Activation; never grants a service or modifies stored settings.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

if (!class_exists('MAD4B_Service_Public_Entry_Guard_V1', false)) {
final class MAD4B_Service_Public_Entry_Guard_V1 {
    private const INTERNAL_TYPES = ['tour-rates','property-rates','transport-rates'];
    private const FAMILY_TYPES = [
        'tours-and-activities'=>'tours', 'properties'=>'properties','units'=>'properties',
        'transportations'=>'transportations','travel-visa'=>'visas'
    ];
    private const FAMILY_ROUTES = [
        'properties'=>'properties', 'transportations'=>'transportations',
        'travel-visa'=>'visas','visas'=>'visas'
    ];
    public static function family(string $type):string {
        return self::FAMILY_TYPES[$type] ?? '';
    }
    public static function standaloneAllowed(string $type):bool {
        if(in_array($type,self::INTERNAL_TYPES,true))return false;
        $family=self::family($type);
        if($family==='')return true; // Never interfere with unrelated content.
        return function_exists('service_channel_allowed')
            && service_channel_allowed($family,'standalone');
    }
    public static function publiclyRestricted(string $type):bool {
        return in_array($type,self::INTERNAL_TYPES,true)
            || (self::family($type)!=='' && !self::standaloneAllowed($type));
    }
    public static function servicePageRestricted($post):bool {
        if(!($post instanceof WP_Post) || $post->post_type!=='page')return false;
        $slug=(string)$post->post_name;
        $family=self::FAMILY_ROUTES[$slug]??null;
        if($family===null){
            $url=get_permalink($post);
            $path=trim((string)wp_parse_url($url,PHP_URL_PATH),'/');
            $segments=explode('/',$path);
            if(in_array($segments[0]??'',['en','ar','fr','de','es','it'],true))array_shift($segments);
            $family=self::FAMILY_ROUTES[(string)($segments[0]??'')]??null;
        }
        return $family!==null && !(function_exists('service_channel_allowed')
            && service_channel_allowed($family,'standalone'));
    }
    public static function publicGet():bool {
        return !current_user_can('edit_posts');
    }
    public static function restGuard($result,$server,$request){
        if($result!==null || !($request instanceof WP_REST_Request)
            || !self::publicGet() || !in_array($request->get_method(),['GET','HEAD'],true))return $result;
        $route='/'.ltrim($request->get_route(),'/');
        foreach(get_post_types(['show_in_rest'=>true],'objects') as $type=>$object){
            if(!self::publiclyRestricted((string)$type))continue;
            $namespace=trim((string)($object->rest_namespace?:'wp/v2'),'/');
            $base=trim((string)($object->rest_base?:$type),'/');
            $prefix='/'.$namespace.'/'.$base;
            if($route===$prefix || str_starts_with($route,$prefix.'/'))
                return new WP_Error('rest_no_route',
                    'No route was found matching the URL and request method.',['status'=>404]);
        }
        // Pages which are service-specific standalone landings must obey the
        // same site policy as the public HTML render, including WPML editions.
        if(preg_match('~^/wp/v2/pages/([0-9]+)(?:/|$)~',$route,$m)){
            $post=get_post((int)$m[1]);
            if(self::servicePageRestricted($post))
                return new WP_Error('rest_no_route',
                    'No route was found matching the URL and request method.',['status'=>404]);
        }
        if($route==='/wp/v2/search'){
            $subtype=$request->get_param('subtype');
            if(is_string($subtype)&&self::publiclyRestricted($subtype))
                return new WP_Error('rest_no_route',
                    'No route was found matching the URL and request method.',['status'=>404]);
        }
        return $result;
    }
    public static function frontendGate():void {
        if(is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('WP_CLI') && WP_CLI) || current_user_can('edit_posts'))return;
        global $wp_query;
        if(!($wp_query instanceof WP_Query))return;
        $deny=false;
        if(is_singular()){
            $post=get_queried_object();
            if($post instanceof WP_Post)
                $deny=self::publiclyRestricted($post->post_type)||self::servicePageRestricted($post);
        }elseif(is_post_type_archive()){
            $types=(array)$wp_query->get('post_type');
            if($types){
                $deny=true;
                foreach($types as $type)if(!self::publiclyRestricted((string)$type)){$deny=false;break;}
            }
        }
        if(!$deny)return;
        $wp_query->set_404();
        $GLOBALS['mad4b_service_public_denied']=true;
        status_header(404);
        nocache_headers();
    }
    public static function rankmathType($excluded,$postType):bool {
        return (bool)$excluded || self::publiclyRestricted((string)$postType);
    }
    public static function rankmathEntry($entry,$type,$object){
        if(!$entry)return $entry;
        if($type==='post' && $object instanceof WP_Post
          && (self::publiclyRestricted($object->post_type)||self::servicePageRestricted($object)))
            return false;
        return $entry;
    }
    public static function searchPolicy($query):void {
        if(!($query instanceof WP_Query) || (is_admin()&&!wp_doing_ajax())
            || !$query->is_main_query() || !$query->is_search())return;
        // Source already owns a more specific registry-driven general search.
        if(function_exists('registry_search_public_types'))return;
        $option=get_option('service-activation',[]);
        $option=is_array($option)?$option:[];
        $types=['post'];
        if(($option['discovery_enabled']??'disabled')==='enabled'){
            $surfaces=(array)($option['discovery_surfaces']??[]);
            $services=['tours-and-activities'=>'tour','properties'=>'property',
                'transportations'=>'transportation','travel-visa'=>'visa'];
            foreach($services as $type=>$surface)
                if(in_array($surface,$surfaces,true)&&post_type_exists($type)
                    &&self::standaloneAllowed($type))$types[]=$type;
        }
        $query->set('post_type',array_values(array_unique($types)));
        $query->set('post_status','publish');
        $query->set('has_password',false);
    }
    public static function boot():void {
        add_filter('rest_pre_dispatch',[__CLASS__,'restGuard'],950,3);
        add_action('template_redirect',[__CLASS__,'frontendGate'],0);
        add_filter('redirect_canonical',static fn($redirect)=>
            !empty($GLOBALS['mad4b_service_public_denied'])?false:$redirect,1000);
        add_filter('rank_math/sitemap/exclude_post_type',[__CLASS__,'rankmathType'],1000,2);
        add_filter('rank_math/sitemap/entry',[__CLASS__,'rankmathEntry'],1000,3);
        add_action('pre_get_posts',[__CLASS__,'searchPolicy'],998);
    }
}
MAD4B_Service_Public_Entry_Guard_V1::boot();
}
