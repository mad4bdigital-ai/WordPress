<?php
/**
 * Plugin Name: MAD4B Company Content Job Intake
 * Description: Strict-review ContentJob intake for company operators; never grants generic MCP/REST mutation authority.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Content_Job_Draft_V1 {
    public const SLUG='mad4b-company-content-job-draft';
    public const ACTION='mad4b_company_content_job_draft_create';
    public const REASON='Company-approved strict-review intake';
    private const TYPES=['article','guide','destination','tour'];
    private const LANGS=['en','es','ar','fr','de','it'];

    public static function caps():bool {
        return class_exists('MAD4B_Admin_Surface_Registry_V1',false)
            && MAD4B_Admin_Surface_Registry_V1::allowed(self::SLUG,'draft');
    }

    /** Fail-closed exact request fence for one existing ContentJob create path. */
    public static function admitted($input,$scope):bool {
        if(!is_array($input)||!is_array($scope)
            || !self::caps()
            || !is_user_logged_in()
            || get_current_user_id()<1
            || !is_admin()
            || (defined('REST_REQUEST')&&REST_REQUEST)
            || (defined('WP_CLI')&&WP_CLI)
            || strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='POST'
            || sanitize_key((string)($_POST['action']??''))!==self::ACTION)return false;
        $nonce=isset($_POST['_wpnonce'])&&is_string($_POST['_wpnonce'])
            ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])):'';
        if($nonce===''||!wp_verify_nonce($nonce,self::ACTION))return false;
        // An exact company intake may narrow a globally enabled write gate,
        // but must never turn a disabled Staging/Production gate back on.
        if(!class_exists('MAD4B_SCP_Policy',false)
            || !method_exists('MAD4B_SCP_Policy','mutation_gate_status'))return false;
        $gate=MAD4B_SCP_Policy::mutation_gate_status();
        if(!is_array($gate)||empty($gate['effective']))return false;
        if(!class_exists('MAD4B_SCP_Site_Profile',false))return false;
        $status=MAD4B_SCP_Site_Profile::status();
        if(!is_array($status)||empty($status['configured'])
            ||empty($status['origin_match'])
            ||empty($status['deployment_binding_match'])
            ||!hash_equals((string)($status['site_uuid']??''),(string)($scope['site_uuid']??'')))
            return false;
        foreach(['site_uuid','tenant_ref','brand_ref'] as $k)
            if(empty($scope[$k])||!is_string($scope[$k]))return false;
        if(!hash_equals((string)$scope['brand_ref'],(string)($input['brand_id']??'')))
            return false;
        if((string)($input['reason']??'')!==self::REASON
           ||(string)($input['automation_level']??'')!=='strict'
           ||!empty($input['desired_publish_at'])||!empty($input['target_post_id'])
           ||!empty($input['writer_profile_id'])||!empty($input['writer_profile_version']))
            return false;
        $post=isset($_POST['subject'])&&is_string($_POST['subject'])
            ? trim(sanitize_text_field(wp_unslash($_POST['subject']))):'';
        if($post===''||strlen($post)>250||!hash_equals($post,(string)($input['subject']??'')))
            return false;
        $lang=sanitize_key((string)($input['language']??''));
        $type=sanitize_key((string)($input['content_type']??''));
        if(!in_array($lang,self::LANGS,true)||!in_array($type,self::TYPES,true)
           ||$lang!==sanitize_key((string)($_POST['language']??''))
           ||$type!==sanitize_key((string)($_POST['content_type']??'')))
            return false;
        if((string)($input['country']??'')!=='eg'
           ||(string)($input['research_depth']??'')!=='standard')return false;
        return true;
    }

    public static function menu():void {
        if(!self::caps() || !class_exists('MAD4B_Company_Workspaces_V1',false))return;
        add_submenu_page(MAD4B_Company_Workspaces_V1::menu_parent(),
            'Create Draft Content Job','Create Draft Job','mad4b_manage_company_operations',
            self::SLUG,[__CLASS__,'page']);
    }
    public static function page():void {
        if(!self::caps())wp_die('Company content jobs access denied.');
        echo '<div class="wrap"><h1>Create Content Job — strict review</h1>';
        echo '<p>Creates NEW/INTAKE only, under the current verified site, brand and tenant. Publication, approvals, job execution and cross-company distribution are not granted.</p>';
        if(isset($_GET['job_status']) && is_string($_GET['job_status'])){
            $code=sanitize_key(wp_unslash($_GET['job_status']));
            echo '<p><strong>Result:</strong> <code>'.esc_html($code).'</code></p>';
        }
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field(self::ACTION);
        echo '<input type="hidden" name="action" value="'.esc_attr(self::ACTION).'">';
        echo '<p><label>Subject <input class="regular-text" required maxlength="250" name="subject"></label></p>';
        echo '<p><label>Language <select name="language">';
        foreach(self::LANGS as $lang)echo '<option value="'.esc_attr($lang).'">'.esc_html(strtoupper($lang)).'</option>';
        echo '</select></label> <label>Content type <select name="content_type">';
        foreach(self::TYPES as $type)echo '<option value="'.esc_attr($type).'">'.esc_html(ucfirst($type)).'</option>';
        echo '</select></label></p>';
        echo '<button type="submit" class="button button-primary">Create strict-review job</button></form></div>';
    }

    public static function handle():void {
        if(!self::caps())wp_die('Company content jobs permission denied.', '',['response'=>403]);
        if(strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='POST')wp_die('POST required.','',['response'=>405]);
        check_admin_referer(self::ACTION);
        if(!class_exists('MAD4B_SCP_Operational_Integrity',false)
            ||!class_exists('MAD4B_SCP_Content_Jobs',false))
            wp_die('Content job service unavailable.','',['response'=>503]);
        $checkpoint=MAD4B_SCP_Operational_Integrity::capture();
        if(is_wp_error($checkpoint))wp_die('Site operation integrity unavailable.','',['response'=>403]);
        $scope=$checkpoint['scope']??[];
        $input=[
            'brand_id'=>$scope['brand_ref']??'',
            'subject'=>trim(sanitize_text_field(wp_unslash($_POST['subject']??''))),
            'language'=>sanitize_key(wp_unslash($_POST['language']??'')),
            'country'=>'eg',
            'content_type'=>sanitize_key(wp_unslash($_POST['content_type']??'')),
            'research_depth'=>'standard','automation_level'=>'strict',
            'reason'=>self::REASON,
            'desired_publish_at'=>'',
        ];
        if(!self::admitted($input,$scope))
            wp_die('Company intake scope or nonce invalid.','',['response'=>403]);
        $result=MAD4B_SCP_Content_Jobs::create_job($input);
        $status=is_wp_error($result)?$result->get_error_code():'created_verified';
        wp_safe_redirect(add_query_arg([
            'page'=>self::SLUG,'job_status'=>sanitize_key($status)
        ],admin_url('admin.php')));
        exit;
    }

    public static function boot():void {
        add_action('admin_menu',[__CLASS__,'menu'],830);
        add_action('admin_post_'.self::ACTION,[__CLASS__,'handle']);
    }
}
MAD4B_Company_Content_Job_Draft_V1::boot();
