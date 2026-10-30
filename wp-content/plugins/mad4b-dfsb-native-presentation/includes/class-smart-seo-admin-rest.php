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
        return rest_ensure_response(array(
            'status'=>'ok','contract'=>SmartSeoRules::CONTRACT,
            'site'=>site_url('/'),'generation_mode'=>'admin_rest_draft',
            'native_seo_enabled'=>$jsf?(bool)$jsf->settings->is_seo_enabled:false,
            'native_rule_count'=>$jsf?count((array)$jsf->settings->get('seo_sitemap_rules',array())):0,
            'draft_count'=>count(SmartSeoRules::drafts()),
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
        // Draft staging never enables SEO; multilingual editorial QA remains pending.\n        $rules=$jsf->settings->get('seo_sitemap_rules',array());
        if (!is_array($rules)) $rules=array();
        $new=$record['native_rule'];
        if (count($new['filters']??array())<1 || count($new['filters'])>3)
            return new \WP_Error('mad4b_seo_filter_limit','Native SEO Rule needs 1–3 verified filters.',array('status'=>400));
        foreach ($rules as $old) {
            if (($old['url']??'')===$new['url']
                && ($old['provider']??'')===$new['provider']
                && ($old['query_id']??'')===$new['query_id'])
                return new \WP_Error('mad4b_seo_rule_conflict',
                    'A native SEO Rule already exists for this page/provider/query.',array('status'=>409));
        }
        if (count($rules)>=50)
            return new \WP_Error('mad4b_seo_rule_limit','Maximum 50 manually staged rules.',array('status'=>409));
        // Snapshot previous native settings; activate only through normal operator action.
        $backup=get_option('mad4b_jsf_smart_seo_native_backup_v1',array());
        if (!is_array($backup) || empty($backup)) {
            update_option('mad4b_jsf_smart_seo_native_backup_v1',array(
                'rules'=>$rules,'created_at'=>gmdate('c'),
                'native_seo_enabled'=>false,
            ),false);
        }
        $rules[]=$new;
        $jsf->settings->update('seo_sitemap_rules',$rules);
        // Guard against unexpected side effects; native SEO is deliberately left OFF.
        if (filter_var($jsf->settings->get('use_seo_sitemap',false),FILTER_VALIDATE_BOOLEAN)) {
            $jsf->settings->update('seo_sitemap_rules',array_slice($rules,0,-1));
            return new \WP_Error('mad4b_seo_activation_guard',
                'SEO was unexpectedly activated. Changes rolled back.',array('status'=>409));
        }
        $stored=get_option(SmartSeoRules::OPTION,array());
        if (isset($stored[$id])) {
            $stored[$id]['status']='staged_disabled';
            $stored[$id]['staged_at']=gmdate('c');
            update_option(SmartSeoRules::OPTION,$stored,false);
        }
        return rest_ensure_response(array(
            'status'=>'staged_disabled','id'=>$id,
            'native_rule'=>$new,'native_rule_count'=>count($rules),
            'native_seo_enabled'=>false,'indexing_enabled'=>false,
            'next'=>'Review localized copy and filtered URL QA in native JetSmartFilters before enabling.',
        ));
    }
}
