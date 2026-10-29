<?php
/**
 * MAD4B Company Performance REST, read-only and staging-only.
 *
 * Contract: mad4b.company-performance-rest.v1
 * REST namespace is shared with Control Plane, but no dynamic ability dispatch,
 * write endpoints, stored customer data, paths or credential/owner manifest body.
 */
defined('ABSPATH') || exit;

final class MAD4B_Company_Performance_REST {
    const NAMESPACE = 'mad4b/v1';
    const CONTRACT = 'mad4b.company-performance-rest.v1';
    const SCAN_ABILITY = 'mad4b/company-performance-scan-plan';

    public static function boot() {
        add_action('rest_api_init', [__CLASS__, 'register_routes'], 25);
    }

    private static function authorized($request = null) {
        if (wp_get_environment_type() !== 'staging') {
            return new WP_Error('mad4b_performance_environment_denied',
                'The performance REST surface is not enabled here.', ['status'=>403]);
        }
        if (!is_user_logged_in()) {
            return new WP_Error('mad4b_performance_auth_required',
                'Authentication is required.', ['status'=>401]);
        }
        if (!current_user_can('manage_options') ||
            !class_exists('MAD4B_SCP_Policy') ||
            !MAD4B_SCP_Policy::can_read()) {
            return new WP_Error('mad4b_performance_forbidden',
                'Company read authority and administrator capability are required.', ['status'=>403]);
        }
        return true;
    }

    public static function permission($request) {
        return self::authorized($request);
    }

    public static function register_routes() {
        $common=[
            'methods'=>WP_REST_Server::READABLE,
            'permission_callback'=>[__CLASS__,'permission'],
        ];
        register_rest_route(self::NAMESPACE,'/company-performance/status',array_merge($common,[
            'callback'=>[__CLASS__,'status'],
        ]));
        register_rest_route(self::NAMESPACE,'/company-performance/scan',array_merge($common,[
            'callback'=>[__CLASS__,'scan'],
            'args'=>[
                'previous_snapshot_sha256'=>[
                    'type'=>'string','required'=>false,
                    'pattern'=>'^[a-f0-9]{64}$',
                    'description'=>'Optional digest from a previous scan, for drift detection.',
                ],
            ],
        ]));
        register_rest_route(self::NAMESPACE,'/company-performance/plan',array_merge($common,[
            'callback'=>[__CLASS__,'plan'],
            'args'=>[
                'previous_snapshot_sha256'=>[
                    'type'=>'string','required'=>false,
                    'pattern'=>'^[a-f0-9]{64}$',
                ],
            ],
        ]));
        register_rest_route(self::NAMESPACE,'/native-abilities',array_merge($common,[
            'callback'=>[__CLASS__,'native_abilities'],
            'args'=>[
                'prefix'=>['type'=>'string','required'=>false,'maxLength'=>64,
                    'pattern'=>'^[a-z0-9_.-]+(?:/[a-z0-9_.-]*)?$'],
                'limit'=>['type'=>'integer','required'=>false,'minimum'=>1,'maximum'=>96],
                'offset'=>['type'=>'integer','required'=>false,'minimum'=>0,'maximum'=>10000],
            ],
        ]));
    }

    private static function response($value) {
        if (is_wp_error($value)) return $value;
        $r=rest_ensure_response($value);
        if ($r instanceof WP_REST_Response) {
            $r->header('Cache-Control','private, no-store, no-cache, must-revalidate');
            $r->header('Pragma','no-cache');
            $r->header('Vary','Authorization, Cookie');
            $r->header('X-Content-Type-Options','nosniff');
        }
        return $r;
    }

    private static function read_only_scan($request) {
        if (!class_exists('MAD4B_SCP_Adapter_Registry') ||
            !class_exists('MAD4B_Company_Performance_Intelligence')) {
            return new WP_Error('mad4b_performance_unavailable',
                'The approved Staging scanner is unavailable.', ['status'=>503]);
        }
        $catalog=MAD4B_SCP_Adapter_Registry::instance()->native_capability_registry([
            'prefix'=>self::SCAN_ABILITY,'limit'=>10,'offset'=>0
        ]);
        if (is_wp_error($catalog)) return new WP_Error(
            'mad4b_performance_trust_unavailable','Cannot inspect native source trust.', ['status'=>503]);
        $bound=null;
        foreach (($catalog['items']??[]) as $item) {
            if (($item['ability']??null)===self::SCAN_ABILITY) {$bound=$item;break;}
        }
        if (($catalog['manifest_state']??null)!=='OWNER_PIN_VERIFIED' ||
            ($bound['state']??null)!=='OWNER_PINNED_READ_SOURCE_VERIFIED' ||
            ($bound['read_source_eligible']??false)!==true ||
            ($catalog['execution_authorized_by_catalog']??true)!==false ||
            ($catalog['write_authority_created']??true)!==false) {
            return new WP_Error('mad4b_performance_source_unverified',
                'The Staging scanner source requires independent verification.', ['status'=>503]);
        }

        $ability=function_exists('wp_get_ability') ? wp_get_ability(self::SCAN_ABILITY) : null;
        if (!$ability || !method_exists($ability,'execute')) {
            return new WP_Error('mad4b_performance_ability_missing',
                'Approved scanner ability is not available.', ['status'=>503]);
        }
        $previous=$request->get_param('previous_snapshot_sha256');
        $input=[];
        if ($previous!==null) $input['previous_snapshot_sha256']=$previous;
        // Retain the original WordPress Ability permission callback and
        // Control Plane execution fence; do not call the implementation directly.
        $result=$ability->execute($input);
        if (is_wp_error($result)) return $result;
        if (!is_array($result) || ($result['contract']??'')!==
            MAD4B_Company_Performance_Intelligence::CONTRACT ||
            ($result['readonly']??false)!==true ||
            ($result['mutation_performed']??true)!==false) {
            return new WP_Error('mad4b_performance_result_invalid',
                'Scanner returned an invalid read-only contract.', ['status'=>503]);
        }
        return $result;
    }

    public static function status($request) {
        $reg=class_exists('MAD4B_SCP_Adapter_Registry')
            ? MAD4B_SCP_Adapter_Registry::instance()->native_capability_registry([
                'prefix'=>self::SCAN_ABILITY,'limit'=>10,'offset'=>0
            ]) : null;
        if (is_wp_error($reg)) $reg=null;
        $item=null;
        foreach (($reg['items']??[]) as $candidate) {
            if (($candidate['ability']??'')===self::SCAN_ABILITY) {$item=$candidate;break;}
        }
        return self::response([
            'contract'=>self::CONTRACT,'site_host'=>wp_parse_url(home_url('/'),PHP_URL_HOST),
            'environment'=>wp_get_environment_type(),
            'ability'=>self::SCAN_ABILITY,
            'manifest_state'=>$reg['manifest_state']??'unavailable',
            'read_source_state'=>$item['state']??'unavailable',
            'read_source_eligible'=>$item['read_source_eligible']??false,
            'auto_apply_allowed'=>false,'write_authorized'=>false,
            'scanner_available'=>class_exists('MAD4B_Company_Performance_Intelligence'),
            'mutation_performed'=>false,
        ]);
    }

    public static function scan($request) {
        $result=self::read_only_scan($request);
        if (is_wp_error($result)) return $result;
        return self::response($result);
    }

    public static function plan($request) {
        $result=self::read_only_scan($request);
        if (is_wp_error($result)) return $result;
        return self::response([
            'contract'=>self::CONTRACT,
            'site_host'=>$result['inventory']['host'],
            'scan_snapshot_sha256'=>$result['scan_snapshot_sha256'],
            'summary'=>$result['summary'],
            'previous_snapshot_state'=>$result['previous_snapshot_state'],
            'remediation_plan'=>$result['remediation_plan'],
            'auto_apply_allowed'=>false,
            'mutation_performed'=>false,
        ]);
    }

    public static function native_abilities($request) {
        if (!class_exists('MAD4B_SCP_Adapter_Registry')) {
            return new WP_Error('mad4b_native_registry_unavailable',
                'Native ability registry is unavailable.', ['status'=>503]);
        }
        $input=[];
        foreach (['prefix','limit','offset'] as $name) {
            if ($request->get_param($name)!==null) $input[$name]=$request->get_param($name);
        }
        $result=MAD4B_SCP_Adapter_Registry::instance()->native_capability_registry($input);
        if (is_wp_error($result)) return $result;
        // Catalog deliberately never dispatches an Ability; statuses only.
        return self::response($result);
    }
}

MAD4B_Company_Performance_REST::boot();
