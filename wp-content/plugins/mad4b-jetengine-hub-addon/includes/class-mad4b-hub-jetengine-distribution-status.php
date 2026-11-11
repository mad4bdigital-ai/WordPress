<?php
/**
 * Read-only provider inventory for native JetEngine Content Hub consumption.
 * Site identity, signed remote transport and content release are separate gates.
 * Never creates wp_posts, CCT rows, WooCommerce products or pricing overrides.
 */
defined('ABSPATH') || exit;
final class MAD4B_Hub_JetEngine_Distribution_Status_V1 {
    const CONTRACT = 'mad4b.hub.jetengine-native-distribution-status.v1';
    const REGISTRY = 'mad4b_hub_jetengine_consumers_v2';
    const ROUTES = [
        'research_observation' => ['default'=>'rest_listing','local_post'=>'none','cct'=>'not_required','seo'=>'no_permalink'],
        'article' => ['default'=>'rest_listing','local_post'=>'only_approved_seo_permalink','cct'=>'optional','seo'=>'optional_local_post'],
        'tour' => ['default'=>'rest_listing','local_post'=>'use_existing_tours_and_activities','cct'=>'optional_search_index','seo'=>'existing_native_cpt'],
        'property' => ['default'=>'rest_listing','local_post'=>'use_existing_properties','cct'=>'optional_search_index','seo'=>'existing_native_cpt'],
        'transport' => ['default'=>'rest_listing','local_post'=>'use_existing_transportations','cct'=>'optional_search_index','seo'=>'existing_native_cpt'],
    ];
    public static function report() {
        $r = get_option(self::REGISTRY, []);
        $registry = is_array($r) ? $r : [];
        $central = strtolower((string)wp_parse_url(home_url('/'), PHP_URL_HOST)) === 'tours.dev.mad4b.com';
        $engine = class_exists('Jet_Engine', false) || function_exists('jet_engine');
        $native = class_exists('MAD4B_Hub_JetEngine_Consumer_Runtime', false)
            && MAD4B_Hub_JetEngine_Consumer_Runtime::native_ready();
        $cctTable = [];
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb) && isset($wpdb->prefix)) {
            $like = $wpdb->esc_like($wpdb->prefix . 'jet_cct_') . '%';
            $result = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
            foreach ((array)$result as $table) {
                if (is_string($table) && preg_match('/^[a-zA-Z0-9_]{3,128}$/D',$table)) {
                    $cctTable[] = $table;
                }
            }
        }
        $items = [];
        $remote = 0;
        $local = 0;
        foreach ($registry as $key=>$item) {
            if (!is_string($key) || !is_array($item)) continue;
            $mode = $item['transport']??'unknown';
            $kind = $item['content_kind']??'unknown';
            if ($mode === 'local' && ($item['state']??'')==='active') $local++;
            if ($mode === 'signed_rest' && ($item['state']??'')==='active') $remote++;
            $items[] = [
                'consumer_key_sha256' => hash('sha256', $key),
                'company_id' => sanitize_key((string)($item['company_id']??'')),
                'kind' => sanitize_key((string)$kind),
                'language' => sanitize_key((string)($item['language']??'')),
                'transport' => sanitize_key((string)$mode),
                'state' => sanitize_key((string)($item['state']??'')),
                'native_endpoint_id' => absint($item['jet_endpoint_id']??0),
                'requires_new_wp_post' => false,
                'source_release_verified' => false,
                'external_peer_validated_for_content' => false
            ];
        }
        return [
            'contract'=>self::CONTRACT, 'site_kind'=>$central?'central_source':'tenant',
            'jetengine_available'=>$engine, 'native_rest_listing_available'=>$native,
            'native_local_active'=>$local, 'configured_remote_active'=>$remote,
            'remote_distribution_certified'=>false,
            'registered_cct_tables'=>$cctTable,
            'content_routes'=>self::ROUTES, 'consumers'=>$items,
            'default_rendering'=>'jetengine_rest_api_listings',
            'cct_materialization_default'=>false,
            'wordpress_post_creation_default'=>false,
            'remote_connector'=>'hold_until_existing_p256_mutual_peer_and_signed_feed_are_verified',
            'commercial_prices'=>'native_company_pricing_provider_only',
            'required_evidence'=>[
                'signed_company_peer', 'source_release_approved_and_rights_verified',
                'target_site_uuid_language_market_verified',
                'jetengine_native_endpoint_readback', 'wpml_and_filter_parity',
                'company_site_render_and_commerce_readback'
            ],
            'publication_authorized'=>false, 'operation'=>'read_only',
            'source_mutated'=>false, 'tenant_mutated'=>false,
        ];
    }
    public static function route() {
        register_rest_route('mad4b-hub/v1', '/jetengine-native-distribution/status', [
            'methods'=>'GET',
            'permission_callback'=>static function(){return current_user_can('manage_options');},
            'callback'=>static function(){
                $response=rest_ensure_response(self::report());
                if ($response instanceof WP_REST_Response) {
                    $response->header('Cache-Control','private, no-store');
                }
                return $response;
            }
        ]);
    }
    public static function boot(){add_action('rest_api_init',[__CLASS__,'route'],50);}
}
