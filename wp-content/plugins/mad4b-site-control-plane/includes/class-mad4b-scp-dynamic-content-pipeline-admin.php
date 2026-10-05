<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Dynamic_Content_Pipeline_Admin {
	const PAGE='mad4b-control-plane-content-pipeline';
	const ACTION='mad4b_save_dynamic_content_pipeline';
	private static $booted=false;

	public static function boot(){
		if(self::$booted)return;self::$booted=true;
		add_action('admin_menu',array(__CLASS__,'menu'));
		add_action('admin_post_'.self::ACTION,array(__CLASS__,'save'));
	}

	public static function menu(){
		add_submenu_page(
			MAD4B_SCP_Admin_UI::PAGE_SLUG,
			__('Content Pipeline','mad4b-site-control-plane'),
			__('Content Pipeline','mad4b-site-control-plane'),
			'manage_options',self::PAGE,array(__CLASS__,'render')
		);
	}

	public static function save(){
		if(!current_user_can('manage_options'))wp_die(esc_html__('Administrator capability is required.','mad4b-site-control-plane'));
		check_admin_referer(self::ACTION);
		$json=isset($_POST['pipeline_json'])?wp_unslash((string)$_POST['pipeline_json']):'';
		$decoded=json_decode($json,true);
		if(!is_array($decoded)){
			wp_safe_redirect(add_query_arg(array('page'=>self::PAGE,'mad4b_pipeline_error'=>'invalid_json'),admin_url('admin.php')));exit;
		}
		$result=MAD4B_SCP_Dynamic_Content_Pipeline::persist($decoded);
		if(is_wp_error($result)){
			wp_safe_redirect(add_query_arg(array('page'=>self::PAGE,'mad4b_pipeline_error'=>sanitize_key($result->get_error_code())),admin_url('admin.php')));exit;
		}
		if(class_exists('MAD4B_SCP_Audit')) MAD4B_SCP_Audit::record('mad4b/dynamic-content-pipeline-settings-updated',array(
			'settings_sha256'=>isset($result['settings_sha256'])?(string)$result['settings_sha256']:'',
			'stage_count'=>isset($result['stages'])&&is_array($result['stages'])?count($result['stages']):0,
			'revision'=>isset($result['revision'])?(int)$result['revision']:0,
		),'ok');
		wp_safe_redirect(add_query_arg(array('page'=>self::PAGE,'mad4b_pipeline_saved'=>'1'),admin_url('admin.php')));exit;
	}

	public static function render(){
		if(!current_user_can('manage_options'))wp_die(esc_html__('Administrator capability is required.','mad4b-site-control-plane'));
		$cfg=MAD4B_SCP_Dynamic_Content_Pipeline::effective();
		echo '<div class="wrap"><h1>'.esc_html__('Dynamic Content Pipeline','mad4b-site-control-plane').'</h1>';
		echo '<p>'.esc_html__('Stages are registered by trusted code. Settings control stage order, enablement, conditions and bounded policy values; settings cannot execute arbitrary PHP callbacks.','mad4b-site-control-plane').'</p>';
		if(!empty($_GET['mad4b_pipeline_saved']))echo '<div class="notice notice-success"><p>'.esc_html__('Pipeline settings saved and verified.','mad4b-site-control-plane').'</p></div>';
		if(!empty($_GET['mad4b_pipeline_error']))echo '<div class="notice notice-error"><p>'.esc_html__('Pipeline settings were not saved: ','mad4b-site-control-plane').esc_html(sanitize_key(wp_unslash($_GET['mad4b_pipeline_error']))).'</p></div>';

		echo '<h2>'.esc_html__('Registered stages','mad4b-site-control-plane').'</h2><table class="widefat striped"><thead><tr><th>ID</th><th>Phase</th><th>Description</th></tr></thead><tbody>';
		foreach(isset($cfg['registry'])?(array)$cfg['registry']:array() as $stage)echo '<tr><td><code>'.esc_html($stage['id']).'</code></td><td>'.esc_html($stage['phase']).'</td><td>'.esc_html($stage['description']).'</td></tr>';
		echo '</tbody></table>';

		echo '<h2>'.esc_html__('Registered conditions','mad4b-site-control-plane').'</h2><table class="widefat striped"><thead><tr><th>ID</th><th>Description</th></tr></thead><tbody>';
		foreach(isset($cfg['condition_registry'])?(array)$cfg['condition_registry']:array() as $condition) echo '<tr><td><code>'.esc_html($condition['id']).'</code></td><td>'.esc_html($condition['description']).'</td></tr>';
		echo '</tbody></table>';

		$editable=array(
			'expected_revision'=>isset($cfg['revision'])?(int)$cfg['revision']:0,
			'max_iterations'=>$cfg['max_iterations'],
			'stop_on_no_progress'=>$cfg['stop_on_no_progress'],
			'no_progress_limit'=>$cfg['no_progress_limit'],
			'default_repair_mode'=>$cfg['default_repair_mode'],
			'stages'=>$cfg['stages'],
		);
		echo '<h2>'.esc_html__('Pipeline policy','mad4b-site-control-plane').'</h2>';
		echo '<p>'.esc_html__('Stages and conditions are registry-driven. New trusted stage or condition IDs become selectable automatically without changing the core pipeline.','mad4b-site-control-plane').'</p>';
		echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
		wp_nonce_field(self::ACTION);
		echo '<input type="hidden" name="action" value="'.esc_attr(self::ACTION).'">';
		echo '<textarea name="pipeline_json" rows="28" class="large-text code">'.esc_textarea(wp_json_encode($editable,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)).'</textarea>';
		submit_button(__('Save and verify pipeline','mad4b-site-control-plane'));
		echo '</form>';
		echo '<p><strong>'.esc_html__('Settings digest:','mad4b-site-control-plane').'</strong> <code>'.esc_html(isset($cfg['settings_sha256'])?$cfg['settings_sha256']:'').'</code></p></div>';
	}
}
MAD4B_SCP_Dynamic_Content_Pipeline_Admin::boot();

// Routes are declared without booting menus or provider lifecycle on frontend requests.
if ( class_exists( 'MAD4B_SCP_Admin_Route_Registry', false ) ) MAD4B_SCP_Admin_Route_Registry::register( MAD4B_SCP_Dynamic_Content_Pipeline_Admin::PAGE, 'manage_options' );
