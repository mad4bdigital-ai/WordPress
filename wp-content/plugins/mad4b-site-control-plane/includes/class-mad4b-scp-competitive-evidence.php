<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Packaged read-only projection of the reproducible Competitive Experience evidence snapshot. */
final class MAD4B_SCP_Competitive_Evidence {
	const CONTRACT = 'mad4b.competitive-evidence-summary.v1';
	const FILE = 'config/competitive-evidence-summary.json';

	public static function boot() {
		if ( function_exists( 'add_action' ) ) add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 41 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) || ( function_exists('wp_has_ability') && wp_has_ability('mad4b/competitive-evidence-status') ) ) return;
		wp_register_ability('mad4b/competitive-evidence-status',array(
			'label'=>'Competitive Evidence Status',
			'description'=>'Read-only packaged comparison evidence with capability-local task and evidence links.',
			'category'=>'mad4b-read',
			'execute_callback'=>array(__CLASS__,'summary'),
			'permission_callback'=>array('MAD4B_SCP_Policy','can_read'),
			'input_schema'=>array('type'=>'object','properties'=>array(),'additionalProperties'=>false),
			'output_schema'=>array('type'=>'object','additionalProperties'=>true),
			'meta'=>array(
				'public'=>false,'show_in_rest'=>false,
				'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
				'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true),
			),
		));
	}

	public static function summary( $input = array() ) {
		$path=defined('MAD4B_SCP_DIR')?MAD4B_SCP_DIR.self::FILE:'';
		if(''===$path||!is_readable($path)) return new WP_Error('mad4b_competitive_evidence_missing','Competitive evidence summary is unavailable.');
		$raw=file_get_contents($path);
		$data=false===$raw?null:json_decode($raw,true);
		if(!is_array($data)||self::CONTRACT!==(isset($data['contract'])?(string)$data['contract']:'')||!empty($data['authorizing'])) {
			return new WP_Error('mad4b_competitive_evidence_invalid','Competitive evidence summary contract is invalid.');
		}
		$rows=isset($data['capabilities'])&&is_array($data['capabilities'])?$data['capabilities']:array();
		if(count($rows)>128) return new WP_Error('mad4b_competitive_evidence_unbounded','Competitive evidence summary exceeds the bounded capability limit.');
		return $data;
	}

	public static function render_operator_view() {
		if(!function_exists('current_user_can')||!current_user_can('manage_options')) return;
		$data=self::summary();
		if(is_wp_error($data)) return;
		echo '<h2>' . esc_html__( 'Competitive evidence', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Reproducible archive and capability evidence. Static or marketed evidence never proves runtime parity or creates access.', 'mad4b-site-control-plane' ) . '</p>';
		$cards=array(
			array('label'=>__('Reference packages','mad4b-site-control-plane'),'value'=>(string)(isset($data['package_count'])?(int)$data['package_count']:0),'help'=>__('Immutable uploaded archive identities.','mad4b-site-control-plane'),'state'=>'complete'),
			array('label'=>__('Mapped capabilities','mad4b-site-control-plane'),'value'=>(string)(isset($data['capability_count'])?(int)$data['capability_count']:0),'help'=>__('Each row links evidence and task ownership.','mad4b-site-control-plane'),'state'=>'complete'),
			array('label'=>__('Evidence generation','mad4b-site-control-plane'),'value'=>substr((string)(isset($data['snapshot_generation_sha256'])?$data['snapshot_generation_sha256']:''),0,12),'help'=>__('Immutable snapshot fingerprint; not runtime certification.','mad4b-site-control-plane'),'state'=>'pending'),
		);
		if(class_exists('MAD4B_SCP_Admin_Experience')) MAD4B_SCP_Admin_Experience::cards($cards);
		$rows=isset($data['capabilities'])&&is_array($data['capabilities'])?$data['capabilities']:array();
		echo '<div class="mad4b-workspace-table" tabindex="0" role="region" aria-label="' . esc_attr__( 'Competitive capability evidence', 'mad4b-site-control-plane' ) . '"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Capability', 'mad4b-site-control-plane' ) . '</th><th scope="col">' . esc_html__( 'Status', 'mad4b-site-control-plane' ) . '</th><th scope="col">' . esc_html__( 'Tasks', 'mad4b-site-control-plane' ) . '</th><th scope="col">' . esc_html__( 'Evidence', 'mad4b-site-control-plane' ) . '</th></tr></thead><tbody>';
		foreach(array_slice($rows,0,59) as $row){
			if(!is_array($row)) continue;
			$tasks=isset($row['task_ids'])&&is_array($row['task_ids'])?implode(', ',array_map('strval',$row['task_ids'])):'';
			$evidence=isset($row['evidence_ids'])&&is_array($row['evidence_ids'])?implode(', ',array_map('strval',$row['evidence_ids'])):'';
			echo '<tr><th scope="row"><bdi>' . esc_html((string)(isset($row['id'])?$row['id']:'')) . '</bdi> — ' . esc_html((string)(isset($row['title'])?$row['title']:'')) . '</th><td>' . esc_html((string)(isset($row['status'])?$row['status']:'OPEN')) . '</td><td><code><bdi>' . esc_html($tasks) . '</bdi></code></td><td><code><bdi>' . esc_html($evidence) . '</bdi></code></td></tr>';
		}
		echo '</tbody></table></div>';
	}
}

MAD4B_SCP_Competitive_Evidence::boot();
