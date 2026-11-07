<?php
/**
 * Plugin Name: MAD4B Google Things to do Readiness
 * Version: 1.1.0
 * Description: Company-local pricing issues for the existing Content Readiness/Fluent Boards workflow.
 * Never starts an external feed, creates tasks or changes pricing on GET/scan.
 */
if (!defined('ABSPATH')) exit;
final class MAD4B_GTTD_Readiness_V1 {
    public const SOURCE = 'mad4b-google-ttd-pricing';
    public const CODE = 'gttd_requirements_unverified';
    public const MAX_SCAN = 1000;

    public static function checklist(int $id): array {
        if (!class_exists('MAD4B_Tour_Price_Distribution_V1')) {
            return ['status'=>'source_unavailable', 'missing'=>['pricing_provider'], 'items'=>[]];
        }
        $snapshot = MAD4B_Tour_Price_Distribution_V1::snapshot($id);
        return (array)($snapshot['google_things_to_do'] ?? []);
    }

    public static function issue(int $id, array $snapshot): ?array {
        if (get_post_type($id)!=='tours-and-activities' || get_post_status($id)!=='publish') return null;
        if (in_array((string)get_post_meta($id,'tour_public_status',true),['0','false','stop_sale'],true)) return null;
        $checklist=(array)($snapshot['google_things_to_do']??[]);
        if (($checklist['status']??'')!=='action_required') return null;
        $missing=array_values(array_intersect(['price','fees_and_taxes','occupancy','availability','checkout_match'],
            (array)($checklist['missing']??[])));
        if (!$missing) return null;
        $label='Google Things to do NOT READY: '.
            (($snapshot['state']??'')==='price_on_request'?'price_on_request; ':'').
            'verify '.implode(', ',$missing).' before channel submission. Content distribution remains enabled.';
        return [
            'id'=>hash('sha256',get_current_blog_id().'|tours-and-activities|'.$id.'|'.self::CODE),
            'post_type'=>'tours-and-activities','object_id'=>$id,
            'source'=>self::SOURCE,'code'=>self::CODE,'severity'=>'high',
            'label'=>substr($label,0,240),
            'source_evidence'=>[
                'contract'=>'mad4b.gttd-readiness-checklist.v1',
                'checklist'=>$checklist['items']??[],
                'missing'=>$missing,
                'distribution_only'=>true,
                'price_state'=>(string)($snapshot['state']??''),
            ],
        ];
    }

    /** Called only by the existing explicit/authorized Content Readiness scan. */
    public static function external($previous,$config=[]) {
        if (!is_array($previous)) return $previous;
        if (!class_exists('MAD4B_Tour_Price_Distribution_V1')) return $previous;
        $ids=get_posts([
            'post_type'=>'tours-and-activities','post_status'=>'publish',
            'fields'=>'ids','posts_per_page'=>self::MAX_SCAN+1,
            'orderby'=>'ID','order'=>'ASC','suppress_filters'=>true,
            'no_found_rows'=>true,
        ]);
        // Never mark existing alerts resolved if a site grows beyond scan capacity.
        if (!is_array($ids) || count($ids)>self::MAX_SCAN) return new WP_Error('gttd_readiness_scan_incomplete');
        $default=apply_filters('wpml_default_language',null);
        foreach ($ids as $id) {
            $id=(int)$id;
            if (is_string($default) && $default && has_filter('wpml_object_id')) {
                $primary=apply_filters('wpml_object_id',$id,'tours-and-activities',false,$default);
                if ($primary && (int)$primary!==$id) continue;
            }
            $snapshot=MAD4B_Tour_Price_Distribution_V1::snapshot($id);
            $issue=self::issue($id,$snapshot);
            if ($issue) $previous[]=$issue;
        }
        return $previous;
    }

    /** Pure, provider-scoped reconciliation; all other readiness issues are preserved. */
    public static function merge_issues(array $existing, array $fresh, string $now) {
        $seen=[];$next=$existing;
        foreach ($fresh as $row) {
            if (!is_array($row) || ($row['source']??'')!==self::SOURCE
                || ($row['code']??'')!==self::CODE
                || ($row['post_type']??'')!=='tours-and-activities'
                || ($row['severity']??'')!=='high') return new WP_Error('gttd_issue_invalid');
            $id=absint($row['object_id']??0);
            $key=hash('sha256',get_current_blog_id().'|tours-and-activities|'.$id.'|'.self::CODE);
            if (!$id || !hash_equals($key,(string)($row['id']??''))
                || !is_array($row['source_evidence']['checklist']??null)
                || count($row['source_evidence']['checklist'])!==5
                || isset($seen[$key])) return new WP_Error('gttd_issue_fingerprint_invalid');
            $seen[$key]=true;
            $old=$next[$key]??[];
            $newlyReopened=($old['status']??'')==='resolved';
            $next[$key]=array_merge($old,$row,[
                'status'=>'open','first_seen'=>$old['first_seen']??$now,
                'last_seen'=>$now,'resolved_at'=>null,
                'reopens'=>(int)($old['reopens']??0)+($newlyReopened?1:0),
            ]);
        }
        foreach ($next as $key=>$row) {
            if (($row['source']??'')!==self::SOURCE || ($row['code']??'')!==self::CODE
                || isset($seen[$key]) || ($row['status']??'')==='resolved') continue;
            $next[$key]['status']='resolved';
            $next[$key]['resolved_at']=$now;
        }
        if (count($next)>3000) return new WP_Error('gttd_issue_capacity_exceeded');
        return $next;
    }

    /** Native admin-only scan. Does not create tasks or send external notifications. */
    public static function sync_issues() {
        if (!current_user_can('manage_options')) return new WP_Error('gttd_admin_permission_required');
        if (!class_exists('MAD4B_SCP_Content_Readiness_Alerts'))
            return new WP_Error('gttd_native_readiness_unavailable');
        $fresh=self::external([],[]);
        if (is_wp_error($fresh) || !is_array($fresh))
            return new WP_Error('gttd_scan_incomplete');
        global $wpdb;
        $lock='mad4b_wpml_url_jobs_'.get_current_blog_id();
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)
            return new WP_Error('gttd_readiness_ledger_busy');
        try {
            $store=MAD4B_SCP_Content_Readiness_Alerts::STORE;
            $old=(array)get_option($store,[]);
            $next=self::merge_issues($old,$fresh,gmdate('c'));
            if (is_wp_error($next)) return $next;
            if ($next!==$old) update_option($store,$next,false);
            if ((array)get_option($store,[])!==$next)
                return new WP_Error('gttd_readiness_readback_failed');
            if (class_exists('MAD4B_SCP_Readiness_Integrations') && $next!==$old)
                MAD4B_SCP_Readiness_Integrations::record_changes($old,$next);
            return ['contract'=>'mad4b.gttd-native-readiness-sync.v1',
                'scanned_issues'=>count($fresh),'issues_open'=>count($fresh),
                'other_sources_preserved'=>true,'board_tasks_created'=>false,
                'google_feed_submitted'=>false];
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }

    public static function admin_post_scan() {
        if (!current_user_can('manage_options')) wp_die('Forbidden',403);
        check_admin_referer('mad4b_gttd_scan');
        $result=self::sync_issues();
        set_transient('mad4b_gttd_scan_'.get_current_user_id(),
            is_wp_error($result)?['error'=>$result->get_error_code()]:$result,120);
        wp_safe_redirect(admin_url('admin.php?page=mad4b-content-readiness-alerts'));
        exit;
    }

    public static function admin_notice() {
        if (!current_user_can('manage_options') ||
            sanitize_key((string)($_GET['page']??''))!=='mad4b-content-readiness-alerts') return;
        $last=get_transient('mad4b_gttd_scan_'.get_current_user_id());
        if ($last) {
            delete_transient('mad4b_gttd_scan_'.get_current_user_id());
            echo '<div class="notice notice-info"><p>Google Things to do readiness: '.
                esc_html(wp_json_encode($last)).'</p></div>';
        }
        echo '<div class="notice notice-warning"><p><strong>Google Things to do — Commercial Readiness</strong></p>';
        echo '<p>Scan weighted price, mandatory fees, adult/group occupancy, real booking availability and checkout price parity. Incomplete criteria create high-severity company issues; other content distribution continues.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('mad4b_gttd_scan');
        echo '<input type="hidden" name="action" value="mad4b_gttd_scan">';
        echo '<button class="button button-secondary">Scan Google Things to do only</button></form></div>';
    }

    public static function init() {
        add_filter('mad4b/content_readiness/external_issues',[__CLASS__,'external'],68,2);
        add_action('admin_post_mad4b_gttd_scan',[__CLASS__,'admin_post_scan']);
        add_action('admin_notices',[__CLASS__,'admin_notice']);
    }
}
MAD4B_GTTD_Readiness_V1::init();
