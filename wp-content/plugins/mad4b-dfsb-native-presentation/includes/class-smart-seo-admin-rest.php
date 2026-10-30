<?php
namespace MAD4B\DFSB\NativePresentation;

if (!defined('ABSPATH')) { exit; }

/**
 * Native WordPress REST authorization: REST nonce or Application Password,
 * plus manage_options. No extra release certificates, custom bearer secrets,
 * public draft data, or unapproved SEO publishing.
 */
final class SmartSeoAdminRest {
    const NS = 'mad4b/v1';

    public static function register(): void {
        $admin=array(__CLASS__,'authorized');
        register_rest_route(self::NS,'/seo-intelligence/status',array(
            'methods'=>'GET','permission_callback'=>$admin,
            'callback'=>array(__CLASS__,'status'),
        ));
        register_rest_route(self::NS,'/seo-intelligence/discover',array(
            'methods'=>'GET','permission_callback'=>$admin,
            'callback'=>array(__CLASS__,'discover'),
            'args'=>array('probe'=>array('type'=>'boolean','default'=>false)),
        ));
        register_rest_route(self::NS,'/seo-intelligence/registry',array(
            'methods'=>'GET','permission_callback'=>$admin,
            'callback'=>array(__CLASS__,'registry'),
        ));
        register_rest_route(self::NS,'/seo-intelligence/policies',array(
            array('methods'=>'GET','permission_callback'=>$admin,
                'callback'=>array(__CLASS__,'policies')),
            array('methods'=>'PUT','permission_callback'=>$admin,
                'callback'=>array(__CLASS__,'updatePolicies')),
        ));
        register_rest_route(self::NS,'/seo-intelligence/proposals',array(
            'methods'=>'GET','permission_callback'=>$admin,
            'callback'=>array(__CLASS__,'proposals'),
        ));
        register_rest_route(self::NS,'/seo-intelligence/proposals/generate',array(
            'methods'=>'POST','permission_callback'=>$admin,
            'callback'=>array(__CLASS__,'generate'),
        ));
        register_rest_route(self::NS,'/seo-intelligence/proposals/(?P<id>[0-9a-f]{40})/stage',array(
            'methods'=>'POST','permission_callback'=>$admin,
            'callback'=>array(__CLASS__,'stage'),
        ));
    }

    public static function authorized(): bool {
        return current_user_can('manage_options');
    }
    public static function status(): \WP_REST_Response {
        $jsf=function_exists('jet_smart_filters')?jet_smart_filters():null;
        $services=array();
        if (function_exists('product_navigation_registry')) {
            foreach ((array)product_navigation_registry() as $service=>$definition) {
                $postType=(string)($definition['post_type']??'');
                if ($postType==='' || !post_type_exists($postType)) continue;
                $services[$service]=DynamicQualification::companyPolicy($postType);
            }
        }
        return rest_ensure_response(array(
            'status'=>'ok','contract'=>SmartSeoRules::CONTRACT,
            'company_service_channels'=>$services,
            'site'=>site_url('/'),'generation_mode'=>'admin_rest_draft',
            'native_seo_enabled'=>$jsf?(bool)$jsf->settings->is_seo_enabled:false,
            'native_rule_count'=>$jsf?count((array)$jsf->settings->get('seo_sitemap_rules',array())):0,
            'draft_count'=>count(SmartSeoRules::drafts()),
            'policy_revision'=>SmartSeoPolicyRegistry::revision(),
            'policy_mode'=>'company_scoped_configurable',
            'auto_indexing'=>false,'requires_release_certificate'=>false,
            'routes'=>array('discover','proposals','proposals/generate','proposals/{id}/stage'),
        ));
    }
    public static function discover(\WP_REST_Request $req): \WP_REST_Response {
        // GET with probe=true fetches only WordPress-generated same-origin public URLs.
        $probe=filter_var($req->get_param('probe'),FILTER_VALIDATE_BOOLEAN);
        $report=SmartSeoRules::recommend($probe);
        return rest_ensure_response($report);
    }
    public static function registry(): \WP_REST_Response {
        return rest_ensure_response(SmartSeoPolicyRegistry::discovered());
    }

    public static function policies(): \WP_REST_Response {
        return rest_ensure_response(array(
            'revision'=>SmartSeoPolicyRegistry::revision(),
            'stored'=>SmartSeoPolicyRegistry::stored(),
            'defaults'=>SmartSeoPolicyRegistry::defaults(),
            'site'=>home_url('/'), 'indexing_enabled_by_policy'=>false,
        ));
    }

    public static function updatePolicies(\WP_REST_Request $req) {
        $payload=$req->get_json_params();
        if (!is_array($payload) || !isset($payload['expected_revision'],$payload['policy'])
            || !is_string($payload['expected_revision']))
            return new \WP_Error('policy_request_invalid','Expected revision and policy object required',array('status'=>400));
        $validated=SmartSeoPolicyRegistry::validate($payload['policy']);
        if (is_wp_error($validated)) return $validated;
        // Never allow writes to arbitrary source IDs not discovered in this company.
        $known=SmartSeoPolicyRegistry::discovered()['sources'];
        foreach ($validated['sources'] as $source=>$settings) {
            if (!isset($known[$source]))
                return new \WP_Error('policy_source_not_discovered',
                    'Source not discovered in current company',array('status'=>400));
        }
        $lock='mad4b_smart_seo_policy_update_lock_v1';
        // add_option is atomic on the unique option_name index.
        if (!add_option($lock,wp_generate_uuid4(),'','no'))
            return new \WP_Error('policy_update_busy','Policy update already in progress',array('status'=>409));
        try {
            if (!hash_equals(SmartSeoPolicyRegistry::revision(),$payload['expected_revision']))
                return new \WP_Error('policy_revision_conflict','Policy changed; refresh and retry',array('status'=>409));
            $ok=update_option(SmartSeoPolicyRegistry::OPTION,$validated,false);
            if (!$ok && SmartSeoPolicyRegistry::stored()!==$validated)
                return new \WP_Error('policy_update_failed','Unable to save site policy',array('status'=>500));
            return rest_ensure_response(array(
                'status'=>'saved','revision'=>SmartSeoPolicyRegistry::revision(),
                'policy'=>SmartSeoPolicyRegistry::stored(),
                'seo_indexing_activated'=>false,
            ));
        } finally {
            delete_option($lock);
        }
    }

    public static function proposals(): \WP_REST_Response {
        return rest_ensure_response(array(
            'status'=>'ok','drafts'=>SmartSeoRules::drafts(),
            'native_rules_unchanged'=>true,
        ));
    }
    public static function generate(): \WP_REST_Response {
        $report=SmartSeoRules::recommend(true);
        $drafts=SmartSeoRules::saveDrafts($report);
        return rest_ensure_response(array(
            'status'=>'generated','qualified'=>$report['qualified'],
            'draft_count'=>count($drafts),'drafts'=>$drafts,
            'native_rules_modified'=>false,'indexing_enabled'=>false,
        ));
    }

    /**
     * Explicit administrator action only. Stores generated proposal as native
     * JSF SEO rule while JSF SEO remains OFF. This is NOT a public activation.
     */
    public static function stage(\WP_REST_Request $req) {
        $id=(string)$req->get_param('id');
        $drafts=SmartSeoRules::drafts();$record=null;
        foreach($drafts as $draft) {
            if (($draft['id']??'')===$id) { $record=$draft;break; }
        }
        if (!$record || ($record['status']??'')!=='draft')
            return new \WP_Error('mad4b_seo_draft_missing','Unknown or previously staged draft.',array('status'=>404));
        $jsf=function_exists('jet_smart_filters')?jet_smart_filters():null;
        if (!$jsf || !empty($jsf->settings->is_seo_enabled))
            return new \WP_Error('mad4b_seo_staging_requires_disabled_native_rules',
                'Native JetSmartFilters SEO must remain disabled while staging proposals.',array('status'=>409));
        $report=SmartSeoRules::recommend(true);
        $live=null;
        foreach ($report['recommendations'] as $item) {
            if ($item['id']===$id && $item['status']==='qualified_draft') {$live=$item;break;}
        }
        if (!$live || ($live['proposed_native_rule']??null)!==($record['native_rule']??null))
            return new \WP_Error('mad4b_seo_draft_stale','Discovery changed; regenerate the draft.',array('status'=>409));
        // Draft staging never enables SEO; multilingual editorial QA remains pending.
        $new=$record['native_rule'];
        if (count($new['filters']??array())<1 || count($new['filters'])>3)
            return new \WP_Error('mad4b_seo_filter_limit','Native SEO Rule needs 1–3 verified filters.',array('status'=>400));
        $effective=SmartSeoPolicyRegistry::effective((string)$new['provider'],(string)$new['query_id']);
        if (!$effective['enabled'])
            return new \WP_Error('policy_source_disabled','Source disabled by company SEO policy',array('status'=>409));
        // A configured policy change makes the old draft stale, even if HTML is identical.
        if (isset($record['effective_policy']) && $record['effective_policy']!==$effective)
            return new \WP_Error('policy_revision_stale','Draft policy changed; regenerate first',array('status'=>409));
        $store=SmartSeoNativeStore::appendDisabled($new);
        if (is_wp_error($store)) return $store;
        $stored=get_option(SmartSeoRules::OPTION,array());
        if (isset($stored[$id])) {
            $stored[$id]['status']='staged_disabled';
            $stored[$id]['staged_at']=gmdate('c');
            update_option(SmartSeoRules::OPTION,$stored,false);
        }
        return rest_ensure_response(array(
            'status'=>'staged_disabled','id'=>$id,
            'native_rule'=>$new,'native_rule_count'=>$store['rule_count'],
            'native_seo_enabled'=>false,'indexing_enabled'=>false,
            'next'=>'Review localized copy and filtered URL QA in native JetSmartFilters before enabling.',
        ));
    }
}
