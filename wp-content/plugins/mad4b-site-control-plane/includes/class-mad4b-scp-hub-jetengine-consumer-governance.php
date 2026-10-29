<?php
/**
 * MAD4B Control Plane — governed native JetEngine REST consumer admission.
 *
 * A plan is NOT approval. No REST endpoint, Listing, Query, taxonomy, option,
 * media, brand or price is ever written by this component.
 *
 * PHP 7.4 compatible. No credentials or payloads in logs/receipts.
 */
defined('ABSPATH') || exit;

final class MAD4B_SCP_Hub_JetEngine_Consumer_Governance {
    const CONTRACT = 'mad4b.hub.jetengine-native-consumer.v1';
    const NAMESPACE_NAME = 'mad4b/v1';
    const CENTRAL_HOST = 'tours.dev.mad4b.com';
    const ALLOWED_COLLECTIONS = array('catalog','categories');
    private static $admitted_request_paths = array();
    const DATA_FIELDS = array(
        'source_company','source_id','source_kind','title','summary','language',
        'source_term_id','canonical_term_id','taxonomy','parent_term_id',
        'itinerary','duration','source_version','source_updated_at','media_reference'
    );

    private static function denial($code) {
        return new WP_Error('mad4b_hub_jetengine_' . $code, $code,
            array('status'=>403,'contract'=>self::CONTRACT));
    }

    private static function boolean_false(array $plan, $key) {
        return array_key_exists($key, $plan) && $plan[$key] === false;
    }

    public static function status() {
        $modules = get_option('jet_engine_modules', array());
        $enabled = is_array($modules) &&
            in_array('rest-api-listings', $modules, true) &&
            class_exists('Jet_Engine_Module_Rest_Api_Listings');
        return array(
            'contract'=>self::CONTRACT,
            'status'=>'planning_only',
            'provider'=>'jet-engine',
            'native_rest_api_listings_enabled'=>$enabled,
            'jetengine_hub_addon_loaded'=>class_exists('MAD4B_JetEngine_Hub_Addon'),
            'hub_capability_discovery_loaded'=>class_exists('MAD4B_Hub_Discovery_Planner'),
            'discovery_selection_contract'=>'mad4b.hub.capability-selection.v1',
            'market_policy_contract'=>'mad4b.hub.market-policy.v1',
            'market_scope_required_for_distribution'=>true,
            'market_source_proof_server_filter'=>'mad4b_scp_hub_consumer_market_authority',
            'inheritance_policy'=>'deny_wins_without_self_attested_source_grants',
            'assisted_mapping_mode'=>'deterministic_advisory_no_llm_no_auto_apply',
            'native_hub_mapping_contract'=>'mad4b.jetengine-hub-native-addon.v1',
            'canonical_consumers'=>array('existing_jetengine_listing','jetengine_rest_query'),
            'central_host'=>self::CENTRAL_HOST,
            'native_layout_preservation_required'=>true,
            'company_brand_and_media_local'=>true,
            'private_signed_rest_required'=>true,
            'independent_shortcodes_forbidden'=>true,
            'wordpress_bulk_import_forbidden'=>true,
            'historical_rates_sale_authority'=>false,
            'manual_or_automatic_rest_activation_performed'=>false,
            'runtime_admission_default'=>'deny',
        );
    }

    /**
     * Read-only specification check.
     * This never authorizes fetch, publishing, public REST, or checkout.
     */
    public static function validate_plan($plan) {
        if (!is_array($plan) || ($plan['contract'] ?? '') !== self::CONTRACT) {
            return self::denial('contract_invalid');
        }
        $fixed = array(
            'data_owner'=>'source_company',
            'transport'=>'control_plane_signed_rest',
            'consumer_provider'=>'jet-engine',
            'listing_provider'=>'existing_jetengine_listing',
            'query_provider'=>'jetengine_rest_query',
            'content_model'=>'remote_reference',
            'brand_authority'=>'local_company',
            'media_authority'=>'source_company',
            'taxonomy_strategy'=>'reuse_verified_canonical_terms',
            'language_strategy'=>'source_target_wpml_verified',
            'pricing_strategy'=>'commercial_review_required',
        );
        foreach ($fixed as $key=>$value) {
            if (!isset($plan[$key]) || $plan[$key] !== $value) {
                return self::denial('required_field_' . $key);
            }
        }
        foreach (array(
            'create_shortcode','create_parallel_section','copy_source_database',
            'overwrite_native_posts','create_duplicate_taxonomy',
            'overwrite_brand_kit','import_private_media','enable_historical_checkout',
            'expose_private_source_cpt'
        ) as $key) {
            if (!self::boolean_false($plan, $key)) {
                return self::denial('side_effect_' . $key);
            }
        }
        $company = isset($plan['source_company']) ? (string)$plan['source_company'] : '';
        $sourceUuid = isset($plan['source_site_uuid']) ? (string)$plan['source_site_uuid'] : '';
        $consumerUuid = isset($plan['consumer_site_uuid']) ? (string)$plan['consumer_site_uuid'] : '';
        if (!preg_match('/^[a-z0-9][a-z0-9-]{2,78}$/D', $company) ||
            !preg_match('/^[a-f0-9-]{16,64}$/Di', $sourceUuid) ||
            !preg_match('/^[a-f0-9-]{16,64}$/Di', $consumerUuid) ||
            hash_equals($sourceUuid, $consumerUuid)) {
            return self::denial('site_identity_unverified');
        }
        $collection = $plan['collection'] ?? null;
        if (!in_array($collection, self::ALLOWED_COLLECTIONS, true)) {
            return self::denial('collection_not_allowlisted');
        }
        $url = isset($plan['endpoint_url']) ? (string)$plan['endpoint_url'] : '';
        $parts = wp_parse_url($url);
        $expectedPath = '/wp-json/mad4b-central/v1/hub-distribution/' . $company . '/' . $collection;
        if (!is_array($parts) ||
            ($parts['scheme'] ?? '') !== 'https' ||
            strtolower((string)($parts['host'] ?? '')) !== self::CENTRAL_HOST ||
            ($parts['path'] ?? '') !== $expectedPath ||
            isset($parts['port']) || isset($parts['user']) ||
            isset($parts['pass']) || isset($parts['fragment']) ||
            isset($parts['query'])) {
            return self::denial('endpoint_origin_or_route_invalid');
        }

        if (!isset($plan['listing_id'], $plan['query_id']) ||
            !is_numeric($plan['listing_id']) || !is_numeric($plan['query_id']) ||
            (int)$plan['listing_id'] <= 0 || (int)$plan['query_id'] <= 0) {
            return self::denial('native_provider_binding_missing');
        }

        $fields = $plan['fields'] ?? null;
        if (!is_array($fields) || !$fields || count($fields) > count(self::DATA_FIELDS)) {
            return self::denial('fields_invalid');
        }
        $unique = array();
        foreach ($fields as $field) {
            if (!is_string($field) || !in_array($field, self::DATA_FIELDS, true) ||
                isset($unique[$field])) {
                return self::denial('field_not_permitted');
            }
            $unique[$field] = true;
        }
        foreach (array('source_company','source_id','title','language') as $required) {
            if (!isset($unique[$required])) {
                return self::denial('identity_field_missing');
            }
        }

        $bindings = $plan['taxonomy_bindings'] ?? null;
        if (!is_array($bindings) || count($bindings) > 100 ||
            ($collection === 'categories' && !$bindings)) {
            return self::denial('taxonomy_bindings_invalid');
        }
        $seen = array();
        foreach ($bindings as $binding) {
            if (!is_array($binding)) return self::denial('taxonomy_binding_malformed');
            $sourceId = $binding['source_term_id'] ?? null;
            $targetId = $binding['canonical_term_id'] ?? null;
            $lang = $binding['language'] ?? null;
            $tax = $binding['taxonomy'] ?? null;
            $sourceTax = $binding['source_taxonomy'] ?? null;
            $parent = $binding['canonical_parent_id'] ?? null;
            $trid = $binding['target_wpml_trid'] ?? null;
            if (!is_numeric($sourceId) || (int)$sourceId < 1 ||
                !is_numeric($targetId) || (int)$targetId < 1 ||
                !in_array($lang, array('ar','en','fr','de','es','it'), true) ||
                $tax !== 'tour-types' || !is_string($sourceTax) ||
                $sourceTax === '' || !is_numeric($parent) || (int)$parent < 0 ||
                !is_numeric($trid) || (int)$trid < 1) {
                return self::denial('taxonomy_proof_missing');
            }
            $key = (string)$sourceId . ':' . $lang;
            if (isset($seen[$key])) return self::denial('duplicated_term_mapping');
            $seen[$key] = true;
            // A submitted numeric ID is never equivalent to verified local term ownership.
            $term = get_term((int)$targetId, 'tour-types');
            if (!$term || is_wp_error($term) ||
                (int)$term->parent !== (int)$parent) {
                return self::denial('canonical_term_not_found');
            }
            if (defined('ICL_SITEPRESS_VERSION')) {
                global $wpdb;
                $record = $wpdb->get_row($wpdb->prepare(
                    "SELECT language_code,trid FROM {$wpdb->prefix}icl_translations
                     WHERE element_type=%s AND element_id=%d LIMIT 1",
                    'tax_tour-types', (int)$targetId), ARRAY_A);
                if (!$record || $record['language_code'] !== $lang ||
                    (int)$record['trid'] !== (int)$trid) {
                    return self::denial('wpml_term_authority_mismatch');
                }
            } else {
                return self::denial('wpml_required');
            }
        }

        return array(
            'contract'=>self::CONTRACT,
            'plan_valid'=>true,
            'runtime_approved'=>false,
            'requires'=>array(
                'mutual_auth_signed_company_identity',
                'read_only_catalog_scope',
                'verified_source_target_wpml_pairs',
                'existing_jetengine_endpoint_listing_query',
                'provider_response_and_cache_tests',
                'explicit_release_authority'
            ),
            'company'=>$company,
            'collection'=>$collection,
            'taxonomies_reused'=>count($bindings),
            'native_query_id'=>(int)$plan['query_id'],
            'native_listing_id'=>(int)$plan['listing_id'],
            'activation_performed'=>false,
        );
    }

    /**
     * The ONLY admission entrypoint for a future Hub REST-to-JetEngine bridge.
     * A client-supplied "signature_verified" flag is never considered proof.
     * Both proofs must be provided by trusted server-side Control Plane hooks.
     */
    public static function admit_runtime($plan) {
        $result = self::validate_plan($plan);
        if (is_wp_error($result)) return $result;
        $module = self::status();
        if (!$module['native_rest_api_listings_enabled']) {
            return self::denial('jetengine_module_missing');
        }
        if (!$module['jetengine_hub_addon_loaded'] ||
            !MAD4B_JetEngine_Hub_Addon::dependencies_ready()) {
            return self::denial('jetengine_hub_addon_missing');
        }
        // A signed feed cannot bypass capability/policy discovery prerequisites.
        if (!$module['hub_capability_discovery_loaded']) {
            return self::denial('capability_discovery_module_missing');
        }
        // The native endpoint cannot run based on a broad company grant:
        // an exact selection manifest and server-proven market scope are required.
        $marketManifest=$plan['selection_manifest']??null;
        if(!is_array($marketManifest) ||
            ($marketManifest['contract']??'')!=='mad4b.hub.capability-selection.v1' ||
            ($marketManifest['source_company']??'')!==($plan['source_company']??null) ||
            ($marketManifest['source_site_uuid']??'')!==($plan['source_site_uuid']??null) ||
            ($marketManifest['consumer_site_uuid']??'')!==($plan['consumer_site_uuid']??null)) {
            return self::denial('market_selection_manifest_required');
        }
        $scoped=MAD4B_Hub_Discovery_Planner::validate($marketManifest);
        if(is_wp_error($scoped) || empty($scoped['market_scoped']) ||
            !empty($scoped['query_plan']['policy_blocks_read']) ||
            empty($scoped['query_plan']['market_slices'])) {
            return self::denial('market_selection_scope_invalid');
        }
        $sourceVerified = apply_filters('mad4b_scp_hub_consumer_signed_authority', false, $plan);
        $bindingVerified = apply_filters('mad4b_scp_hub_consumer_native_binding_authority', false, $plan);
        $marketVerified = apply_filters('mad4b_scp_hub_consumer_market_authority', false, $plan, $scoped);
        if ($sourceVerified !== true || $bindingVerified !== true || $marketVerified !== true) {
            return self::denial('server_proof_missing');
        }
        // The runtime grant lasts only for this PHP request and exact company/path.
        $route = (string)wp_parse_url((string)$plan['endpoint_url'], PHP_URL_PATH);
        self::$admitted_request_paths[$route] = true;
        $result['runtime_approved']=true;
        $result['server_side_proofs_verified']=true;
        return $result;
    }

    /** Protect the same-source remote REST path even from manual JetEngine configuration.
     * This does not affect other WordPress REST consumers or different endpoints.
     */
    public static function fence_hub_http($preempt, $args, $url) {
        if ($preempt !== false || !is_string($url)) return $preempt;
        $parts = wp_parse_url($url);
        if (!is_array($parts) ||
            strtolower((string)($parts['host'] ?? '')) !== self::CENTRAL_HOST) {
            return $preempt;
        }
        $path = (string)($parts['path'] ?? '');
        if (strpos($path, '/wp-json/mad4b-central/v1/hub-distribution/') !== 0) {
            return $preempt;
        }
        if (!isset(self::$admitted_request_paths[$path]) ||
            strtoupper((string)($args['method'] ?? 'GET')) !== 'GET') {
            return self::denial('unapproved_remote_fetch');
        }
        return $preempt;
    }

    public static function permission() {
        return current_user_can('manage_options');
    }

    public static function register_routes() {
        register_rest_route(self::NAMESPACE_NAME, '/hub-jetengine-consumer/policy', array(
            'methods'=>'GET',
            'callback'=>array(__CLASS__, 'status'),
            'permission_callback'=>array(__CLASS__, 'permission'),
        ));
        register_rest_route(self::NAMESPACE_NAME, '/hub-jetengine-consumer/validate', array(
            'methods'=>'POST',
            'callback'=>array(__CLASS__, 'rest_validate'),
            'permission_callback'=>array(__CLASS__, 'permission'),
        ));
    }

    public static function rest_validate($request) {
        // REST is plan-only: this never invokes admit_runtime.
        $plan = $request->get_json_params();
        return self::validate_plan($plan);
    }

    public static function boot() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'), 29);
        add_filter('pre_http_request', array(__CLASS__, 'fence_hub_http'), 9, 3);
    }
}
