<?php
if (!defined('ABSPATH')) exit;

/** Exact site-bound measurement resource selection, without Google secrets. */
final class MAD4B_Google_Measurement_Admin {
    const OPTION = 'mad4b_google_measurement_exact_binding_v1';
    public static function boot() {
        add_action('admin_menu',array(__CLASS__,'menu'));
        add_action('admin_post_mad4b_google_measurement_bind',array(__CLASS__,'save'));
    }
    public static function menu() {
        add_management_page('Google Measurement','Google Measurement','manage_options',
            'mad4b-google-measurement',array(__CLASS__,'page'));
    }
    private static function site() {
        if (!class_exists('MAD4B_SCP_Site_Profile')) return false;
        $p=MAD4B_SCP_Site_Profile::status();
        return is_array($p) && !empty($p['origin_match']) && !empty($p['environment_match']) &&
            !empty($p['site_uuid']) && !empty($p['profile_digest']) ? $p : false;
    }
    public static function binding() {
        $profile=self::site();
        $stored=get_option(self::OPTION,array());
        if (!$profile || !is_array($stored)) return array('ready'=>false,'reason'=>'site_profile_required');
        $same=isset($stored['site_uuid'],$stored['profile_digest']) &&
            hash_equals((string)$profile['site_uuid'],(string)$stored['site_uuid']) &&
            hash_equals((string)$profile['profile_digest'],(string)$stored['profile_digest']);
        if (!$same) return array('ready'=>false,'reason'=>'site_profile_changed');
        return array('ready'=>true,'ga4_property'=>$stored['ga4_property'],
            'gtm_container'=>$stored['gtm_container'],'site_uuid'=>$stored['site_uuid']);
    }
    public static function page() {
        if (!current_user_can('manage_options')) wp_die('Permission denied.');
        $p=self::site();
        $b=self::binding();
        echo '<div class="wrap"><h1>Google Measurement — Brand Authority</h1>';
        echo '<p>Connect your Google account through the reviewed measurement broker first. This form only binds resource identifiers to the enrolled site. It never stores OAuth tokens or changes Google resources.</p>';
        if (!$p) { echo '<p>Exact MAD4B Site Profile is not ready.</p></div>'; return; }
        echo '<p>Site UUID: <code>'.esc_html((string)$p['site_uuid']).'</code></p>';
        echo '<p>Broker connection and OAuth consent must be independently verified before using API abilities.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="mad4b_google_measurement_bind"/>';
        wp_nonce_field('mad4b_google_measurement_bind');
        echo '<p><label>GA4 Property (properties/123) <input name="ga4_property" required pattern="properties/[0-9]+" value="'.esc_attr(!empty($b['ready'])?$b['ga4_property']:'').'" size="36"/></label></p>';
        echo '<p><label>GTM Container (accounts/123/containers/456) <input name="gtm_container" required pattern="accounts/[0-9]+/containers/[0-9]+" value="'.esc_attr(!empty($b['ready'])?$b['gtm_container']:'').'" size="55"/></label></p>';
        submit_button('Bind selected resources (no Google mutation)');
        echo '</form>';
        $ads=MAD4B_Google_Measurement_Ads::binding();
        echo '<hr/><h2>Google Ads Account</h2>';
        echo '<p>Bind the customer ID returned by an authorized Google Ads account listing. This does not verify account ownership, change an ad, or require a developer token.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="mad4b_google_ads_bind"/>';
        wp_nonce_field('mad4b_google_ads_bind');
        echo '<p><label>Customer ID (10 digits) <input name="customer_id" required pattern="[0-9]{10}" value="'.esc_attr(!empty($ads['ready'])?$ads['customer_id']:'').'" size="20"/></label></p>';
        echo '<p><label>MCC login customer ID (optional) <input name="login_customer_id" pattern="[0-9]{10}" value="'.esc_attr(!empty($ads['ready'])?$ads['login_customer_id']:'').'" size="20"/></label></p>';
        submit_button('Bind Google Ads customer (no Ads mutation)');
        echo '</form>';
        echo '<hr/><h2>Keyword Research Projects</h2>';
        echo '<p>Save an owner-scoped draft keyword list; this never creates Ads campaigns or publishes WordPress content.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="mad4b_keyword_project_save"/>';
        wp_nonce_field('mad4b_keyword_project_save');
        echo '<p><label>Research project title <input name="title" required maxlength="120" size="55"/></label></p>';
        echo '<p><label>Keywords (one per line) <textarea name="keywords" rows="7" cols="70" required></textarea></label></p>';
        submit_button('Save Keyword Research draft');
        echo '</form>';
        echo '<hr/><h2>Custom GA4 Reports</h2>';
        echo '<p>Definitions are saved in WordPress for this site and GA4 property. They do not create reports inside the Google Analytics interface.</p>';
        $defs=MAD4B_Google_Measurement_Reports::saved();
        if ($defs) {
            echo '<table class="widefat striped"><thead><tr><th>Report</th><th>Dimensions</th><th>Metrics</th></tr></thead><tbody>';
            foreach ($defs as $key=>$def) {
                echo '<tr><td>'.esc_html((string)$key).'</td><td>'.esc_html(implode(', ',(array)($def['dimensions']??array()))).'</td><td>'.esc_html(implode(', ',(array)($def['metrics']??array()))).'</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="mad4b_measurement_report_save"/>';
        wp_nonce_field('mad4b_measurement_report_save');
        echo '<p><label>Report key <input name="report_name" required pattern="[a-z][a-z0-9_]*" value="tour_performance"/></label></p>';
        echo '<p><label>Dimensions (comma-separated) <input name="dimensions" required value="eventName" size="65"/></label></p>';
        echo '<p><label>Metrics (comma-separated) <input name="metrics" required value="eventCount,totalUsers" size="65"/></label></p>';
        echo '<p><label>Days <input name="days" type="number" min="1" max="365" value="28"/></label></p>';
        echo '<p><label>Rows <input name="limit" type="number" min="1" max="500" value="100"/></label></p>';
        submit_button('Save report definition (no Google write)');
        echo '</form></div>';
    }
    public static function save() {
        if (!current_user_can('manage_options') || !check_admin_referer('mad4b_google_measurement_bind'))
            wp_die('Permission denied.');
        $p=self::site();
        if (!$p) wp_die('Enrolled Site Profile required.');
        $ga=isset($_POST['ga4_property'])?sanitize_text_field(wp_unslash($_POST['ga4_property'])):'';
        $gtm=isset($_POST['gtm_container'])?sanitize_text_field(wp_unslash($_POST['gtm_container'])):'';
        if (!preg_match('~^properties/[0-9]+$~D',$ga) ||
            !preg_match('~^accounts/[0-9]+/containers/[0-9]+$~D',$gtm))
            wp_die('Invalid Google resource path.');
        update_option(self::OPTION,array(
            'site_uuid'=>(string)$p['site_uuid'],
            'profile_digest'=>(string)$p['profile_digest'],
            'ga4_property'=>$ga,'gtm_container'=>$gtm,
            'bound_by'=>(int)get_current_user_id(),'bound_at'=>time(),
        ),false);
        wp_safe_redirect(admin_url('tools.php?page=mad4b-google-measurement&bound=1'));
        exit;
    }
}
