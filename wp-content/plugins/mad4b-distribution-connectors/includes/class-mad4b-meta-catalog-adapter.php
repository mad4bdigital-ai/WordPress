<?php
/**
 * Plugin Name: MAD4B Meta Catalog Distribution Adapter
 * Version: 1.0.0
 * Description: Connects Meta Catalog Feed Mapper to MAD4B weighted prices, destination data and readiness checks. No external channel sending.
 */
if (!defined('ABSPATH')) exit;

final class MAD4B_Meta_Catalog_Adapter_V1 {
    const PROFILE_OPTION = 'mad4b_distribution_catalog_profiles_v1';
    const CONTRACT = 'mad4b.catalog.adapter.v1';
    private static array $price_cache = [];
    private static array $place_cache = [];

    public static function init(): void {
        add_filter('mad4b/mcfm/computed_options',[__CLASS__,'options'],10,2);
        add_filter('mad4b/mcfm/computed_value',[__CLASS__,'value'],10,4);
        add_filter('mad4b/mcfm/include_row',[__CLASS__,'include_row'],10,5);
        add_filter('mad4b/mcfm/can_export_feed',[__CLASS__,'can_export'],10,3);
        add_action('rest_api_init',[__CLASS__,'register_rest']);
    }

    private static function is_managed($feed): bool {
        return is_array($feed) && str_starts_with((string)($feed['slug']??''),'mad4b-managed-');
    }

    public static function options($options,$feed) {
        if (!is_array($options) || !is_array($feed)) return $options;
        if (($feed['object_type']??'')==='post_type' && ($feed['object_key']??'')==='tours-and-activities') {
            return $options + [
                'mad4b_weighted_price'=>'MAD4B weighted price (public indicative, not guaranteed checkout)',
                'mad4b_price_currency'=>'MAD4B selected price currency',
                'mad4b_price_state'=>'MAD4B price state / price_on_request',
                'mad4b_price_source'=>'MAD4B winning weighted source',
                'mad4b_rate_id'=>'MAD4B selected Rate ID',
                'mad4b_price_basis'=>'MAD4B price basis',
                'mad4b_gttd_checks'=>'Google Things to do missing checks (comma-separated)',
                'mad4b_gttd_price_ready'=>'Google Things to do price checks passed (1 or 0)',
                'mad4b_gttd_submission_ready'=>'Google Things to do delivery authorized (1 or 0)',
            ];
        }
        if (($feed['object_type']??'')==='taxonomy' && ($feed['object_key']??'')==='destinations') {
            return $options + [
                'mad4b_destination_schema_type'=>'MAD4B TouristAttraction / TouristDestination',
                'mad4b_destination_schema_id'=>'MAD4B destination canonical schema ID',
                'mad4b_destination_image'=>'MAD4B destination primary image',
                'mad4b_destination_latitude'=>'MAD4B validated latitude',
                'mad4b_destination_longitude'=>'MAD4B validated longitude',
            ];
        }
        return $options;
    }

    public static function price_snapshot(int $id): array {
        $lang=(string)apply_filters('wpml_current_language', null);
        $key=get_current_blog_id().'|'.$id.'|'.$lang.'|'.(function_exists('get_woocommerce_currency')?get_woocommerce_currency():'');
        if (!isset(self::$price_cache[$key])) {
            self::$price_cache[$key] = class_exists('MAD4B_Tour_Price_Distribution_V1')
                ? MAD4B_Tour_Price_Distribution_V1::snapshot($id)
                : ['state'=>'price_on_request','quote'=>null,'reason'=>'distribution_engine_missing'];
        }
        return self::$price_cache[$key];
    }

    public static function destination_snapshot(int $id): ?array {
        if (array_key_exists($id,self::$place_cache)) return self::$place_cache[$id];
        $term=get_term($id,'destinations');
        return self::$place_cache[$id] = ($term instanceof WP_Term
            && class_exists('MAD4B_Tour_Destination_Structured_Data'))
            ? MAD4B_Tour_Destination_Structured_Data::destination_node($term) : null;
    }

    public static function value($value,$feed,$object,$source) {
        $opts=self::options([], $feed);
        if (!is_array($opts) || !array_key_exists((string)$source,$opts)) return $value;
        if (($feed['object_type']??'')==='post_type' && $object instanceof WP_Post
            && $object->post_type==='tours-and-activities' && $object->post_status==='publish') {
            $snapshot=self::price_snapshot((int)$object->ID);
            $quote=(array)($snapshot['quote']??[]);
            $checks=(array)($snapshot['google_things_to_do']??[]);
            $distrib=(array)($snapshot['distribution']??[]);
            $amount=$quote['amount']??null;
            $source_values=[
                'mad4b_weighted_price'=>is_numeric($amount)&&$amount>0 ? number_format((float)$amount,2,'.','') : '',
                'mad4b_price_currency'=>(string)($quote['currency']??''),
                'mad4b_price_state'=>(string)($snapshot['state']??'price_on_request'),
                'mad4b_price_source'=>(string)($quote['source']??''),
                'mad4b_rate_id'=>isset($quote['rate']['id'])?(string)(int)$quote['rate']['id']:'',
                'mad4b_price_basis'=>(string)($quote['price_basis']??''),
                'mad4b_gttd_checks'=>implode(',',array_map('sanitize_key',(array)($checks['missing']??[]))),
                'mad4b_gttd_price_ready'=>!empty($distrib['google_things_to_do_price_ready'])?'1':'0',
                'mad4b_gttd_submission_ready'=>!empty($distrib['google_things_to_do_submission_ready'])?'1':'0',
            ];
            return $source_values[(string)$source]??'';
        }
        if (($feed['object_type']??'')==='taxonomy' && $object instanceof WP_Term
            && $object->taxonomy==='destinations') {
            $node=self::destination_snapshot((int)$object->term_id);
            if (!$node) return '';
            $geo=(array)($node['geo']??[]);
            $source_values=[
                'mad4b_destination_schema_type'=>(string)($node['@type']??''),
                'mad4b_destination_schema_id'=>(string)($node['@id']??''),
                'mad4b_destination_image'=>(string)($node['image']??''),
                'mad4b_destination_latitude'=>isset($geo['latitude'])?(string)$geo['latitude']:'',
                'mad4b_destination_longitude'=>isset($geo['longitude'])?(string)$geo['longitude']:'',
            ];
            return $source_values[(string)$source]??'';
        }
        return '';
    }

    public static function can_export($allowed,$feed,$lang=null): bool {
        if (!$allowed) return false;
        if (!self::is_managed($feed)) return true;
        // Website URL is a local authenticated preview, never a partner feed endpoint.
        return current_user_can('manage_options');
    }

    public static function include_row($included,$feed,$object,$row,$lang=null): bool {
        if (!$included) return false;
        if (!self::is_managed($feed)) return true;
        $slug=(string)$feed['slug'];
        if (str_contains($slug,'google-ttd')) {
            if (!$object instanceof WP_Post || $object->post_type!=='tours-and-activities') return false;
            $snapshot=self::price_snapshot((int)$object->ID);
            return ($snapshot['distribution']['google_things_to_do_price_ready']??false)===true
                && ($snapshot['distribution']['google_things_to_do_submission_ready']??false)===true;
        }
        return true;
    }

    public static function default_profiles(): array {
        $tours=[
            'label'=>'MAD4B | Tours — Private Distribution Preview',
            'slug'=>'mad4b-managed-tours-internal',
            'object_type'=>'post_type','object_key'=>'tours-and-activities',
            'default_format'=>'csv','xml_variant'=>'rss','language_mode'=>'all',
            'status'=>'publish','mapping'=>[],
        ];
        $tour_cols=[
            ['id','object_field','id'],['title','object_field','title'],
            ['url','object_field','permalink'],['image_url','featured_image','featured_image'],
            ['destinations','taxonomy_terms','destinations'],
            ['tour_types','taxonomy_terms','tour-types'],
            ['tourist_type','taxonomy_terms','good-for'],
            ['weighted_price','computed','mad4b_weighted_price'],
            ['price_currency','computed','mad4b_price_currency'],
            ['price_state','computed','mad4b_price_state'],
            ['price_source','computed','mad4b_price_source'],
            ['rate_id','computed','mad4b_rate_id'],
            ['price_basis','computed','mad4b_price_basis'],
            ['google_checks_missing','computed','mad4b_gttd_checks'],
            ['google_price_ready','computed','mad4b_gttd_price_ready'],
            ['google_feed_connected','computed','mad4b_gttd_submission_ready'],
        ];
        foreach ($tour_cols as $col) {
            $tours['mapping'][]=['column'=>$col[0],'source_type'=>$col[1],
                'source'=>$col[2],'extra'=>'', 'required_non_empty'=>in_array($col[0],['id','url'],true)?1:0];
        }
        $dest=[
            'label'=>'MAD4B | Destinations — Private Distribution Preview',
            'slug'=>'mad4b-managed-destinations-internal',
            'object_type'=>'taxonomy','object_key'=>'destinations',
            'default_format'=>'csv','xml_variant'=>'rss','language_mode'=>'all',
            'status'=>'publish','hide_empty_terms'=>0,'mapping'=>[],
        ];
        $dest_cols=[
            ['id','object_field','term_id'],['name','object_field','name'],
            ['url','object_field','link'],['description','object_field','description'],
            ['parent_id','object_field','parent'],
            ['image_url','computed','smart_destination_image_url'],
            ['schema_type','computed','mad4b_destination_schema_type'],
            ['schema_id','computed','mad4b_destination_schema_id'],
            ['latitude','computed','mad4b_destination_latitude'],
            ['longitude','computed','mad4b_destination_longitude'],
            ['google_place_types','computed','smart_google_place_types'],
        ];
        foreach ($dest_cols as $col) {
            $dest['mapping'][]=['column'=>$col[0],'source_type'=>$col[1],
                'source'=>$col[2],'extra'=>'', 'required_non_empty'=>in_array($col[0],['id','url'],true)?1:0];
        }
        return [$tours['slug']=>$tours,$dest['slug']=>$dest];
    }

    /** Explicit, repeatable company provisioning. Never replaces a company's custom mapping. */
    public static function provision(): array|WP_Error {
        if (!function_exists('mcfm_get_feeds')||!function_exists('mcfm_update_feeds'))
            return new WP_Error('catalog_mapper_missing','Mapper plugin unavailable');
        $existing=mcfm_get_feeds();
        $profiles=self::profiles();
        $new=[];$unchanged=[];
        foreach (self::default_profiles() as $slug=>$feed) {
            $matches=array_values(array_filter($existing,static fn($v)=>is_array($v)&&($v['slug']??null)===$slug));
            if (count($matches)>1) return new WP_Error('catalog_duplicate_slug','Duplicate managed feed slug: '.$slug);
            if ($matches) {
                if (($matches[0]['object_type']??null)!==$feed['object_type']
                    || ($matches[0]['object_key']??null)!==$feed['object_key'])
                    return new WP_Error('catalog_profile_collision','Feed slug already belongs to a different object');
                $unchanged[]=$slug;
            } else {
                $existing[]=$feed;
                $new[]=$slug;
            }
            if (!isset($profiles[$slug])) {
                $profiles[$slug]=[
                    'channel'=>'internal_catalog', 'access'=>'admin_only',
                    'format'=>'csv','languages'=>'wpml', 'delivery'=>'not_connected',
                    'company_home'=>home_url('/'),
                    'external_send_enabled'=>false,
                    'pricing_source'=>'mad4b_weighted_resolver',
                ];
            }
        }
        if ($new) mcfm_update_feeds($existing);
        if (self::profiles()!==$profiles) update_option(self::PROFILE_OPTION,$profiles,false);
        foreach (self::default_profiles() as $slug=>$unused) {
            if (!function_exists('mcfm_get_feed_by_slug') || !mcfm_get_feed_by_slug($slug))
                return new WP_Error('catalog_provision_readback_failed','Feed was not stored: '.$slug);
        }
        return ['contract'=>self::CONTRACT,'created'=>$new,'existing'=>$unchanged,
            'profile_count'=>count($profiles),'external_send_enabled'=>false];
    }

    public static function rest_provision() {
        $result=self::provision();
        if (is_wp_error($result)) return $result;
        $response=rest_ensure_response($result);
        $response->header('Cache-Control','private, no-store');
        return $response;
    }

    public static function register_rest(): void {
        register_rest_route('mad4b-distribution/v1','/catalog-profiles/provision',[
            'methods'=>'POST',
            'permission_callback'=>static fn()=>current_user_can('manage_options'),
            'callback'=>[__CLASS__,'rest_provision'],
        ]);
        register_rest_route('mad4b-distribution/v1','/catalog-profiles',[
            'methods'=>'GET',
            'permission_callback'=>static fn()=>current_user_can('manage_woocommerce'),
            'callback'=>[__CLASS__,'list_profiles'],
        ]);
        register_rest_route('mad4b-distribution/v1','/catalog-preview/(?P<slug>[a-z0-9-]+)',[
            'methods'=>'GET',
            'permission_callback'=>static fn()=>current_user_can('manage_woocommerce'),
            'callback'=>[__CLASS__,'preview'],
            'args'=>['limit'=>['default'=>5,'sanitize_callback'=>'absint']],
        ]);
    }

    public static function profiles(): array {
        $result=get_option(self::PROFILE_OPTION,[]);
        return is_array($result)?$result:[];
    }

    public static function list_profiles() {
        return rest_ensure_response(['contract'=>self::CONTRACT,'profiles'=>self::profiles(),
            'external_deliveries_enabled'=>false,'source'=>'site_control_plane']);
    }

    public static function preview(WP_REST_Request $request) {
        $slug=sanitize_title((string)$request['slug']);
        $profiles=self::profiles();
        if (!isset($profiles[$slug]) || !self::is_managed(['slug'=>$slug])) {
            return new WP_Error('catalog_profile_not_found','Unknown managed catalog profile',['status'=>404]);
        }
        if (!function_exists('mcfm_get_feed_by_slug') || !function_exists('mcfm_iterate_feed_rows'))
            return new WP_Error('catalog_mapper_unavailable','Feed Mapper unavailable',['status'=>503]);
        $found=mcfm_get_feed_by_slug($slug);
        if (!$found) return new WP_Error('catalog_mapping_not_found','Missing catalog mapping',['status'=>404]);
        $limit=max(1,min(20,absint($request->get_param('limit'))));
        $lang=sanitize_key((string)($request->get_param('lang')?:''));
        if ($lang && !preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/',$lang))
            return new WP_Error('catalog_invalid_language','Invalid language',['status'=>400]);
        $previous=apply_filters('wpml_current_language',null);
        try {
            if ($lang && function_exists('mcfm_switch_language')) mcfm_switch_language($lang);
            $rows=[];
            mcfm_iterate_feed_rows($found['feed'],$lang,static function($row)use(&$rows,$limit){
                $rows[]=$row;
                return count($rows)<$limit;
            });
        } finally {
            if ($lang && function_exists('mcfm_switch_language') && $previous) mcfm_switch_language($previous);
        }
        $response=rest_ensure_response(['contract'=>self::CONTRACT,'slug'=>$slug,
            'rows'=>$rows,'sample_count'=>count($rows),'truncated_or_more_possible'=>count($rows)===$limit,
            'external_deliveries_enabled'=>false]);
        $response->header('Cache-Control','private, no-store');
        return $response;
    }
}
MAD4B_Meta_Catalog_Adapter_V1::init();
