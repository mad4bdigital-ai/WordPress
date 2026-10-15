<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Provider serialization owns node types and controls; the shared engine never rewrites trees. */
final class MAD4B_SCP_Domain_Builders {
	public static function validate( $profile, array $desired, array $facts ) {
		if ( 'builder_tree' !== $profile ) return MAD4B_SCP_Domain_Contracts::error( 'builder_profile' );
		$check = MAD4B_SCP_Domain_Contracts::keys( $desired, array( 'native_format','mode','nodes' ), array( 'native_format','mode','nodes' ) );
		if ( is_wp_error( $check ) ) return $check;
		if ( ! is_string( $desired['native_format'] ) || $desired['native_format'] !== ( $facts['native_format'] ?? '' ) || true !== ( $facts['serialization_contract'] ?? null ) || true !== ( $facts['revision_current'] ?? null ) || false !== ( $facts['editor_locked'] ?? null ) || true !== ( $facts['template_scope_authorized'] ?? null ) ) return MAD4B_SCP_Domain_Contracts::error( 'builder_format_lock_or_scope' );
		if ( ! in_array( $desired['mode'], array( 'outline','update','clone' ), true ) || ! is_array( $desired['nodes'] ) ) return MAD4B_SCP_Domain_Contracts::error( 'builder_shape' );
		$check = MAD4B_SCP_Domain_Contracts::hierarchy( $desired['nodes'] );
		if ( is_wp_error( $check ) ) return $check;
		foreach ( $desired['nodes'] as $node ) {
			$check = MAD4B_SCP_Domain_Contracts::keys( $node, array( 'id','parent','type','settings' ), array( 'id','parent','type','settings' ) );
			if ( is_wp_error( $check ) ) return $check;
			if ( ! is_string( $node['type'] ) || ! in_array( $node['type'], $facts['allowed_node_types'] ?? array(), true ) || ! is_array( $node['settings'] ) ) return MAD4B_SCP_Domain_Contracts::error( 'builder_node_type' );
			$current_ids = $facts['current_ids'] ?? array();
			if ( 'clone' === $desired['mode'] ) {
				if ( in_array( $node['id'], $current_ids, true ) || ! in_array( $node['id'], $facts['allocated_clone_ids'] ?? array(), true ) ) return MAD4B_SCP_Domain_Contracts::error( 'builder_id_collision' );
			} elseif ( ! in_array( $node['id'], $current_ids, true ) ) return MAD4B_SCP_Domain_Contracts::error( 'builder_foreign_node' );
			if ( $node['settings'] ) {
				$fields = array( 'field_contracts'=>$facts['control_contracts'][ $node['type'] ] ?? array() );
				$check = MAD4B_SCP_Domain_Contracts::fields( $node['settings'], $fields, array( 'local_content' ) );
				if ( is_wp_error( $check ) ) return $check;
			}
		}
		$check = MAD4B_SCP_Domain_Contracts::no_secrets( $desired );
		if ( is_wp_error( $check ) ) return $check;
		$result = MAD4B_SCP_Domain_Contracts::result( 'builder_tree', $desired, array( 'native_format_exact','bounded_parent_graph','fresh_clone_ids','editor_revision_and_lock','provider_controls','template_scope' ), 'outline' === $desired['mode'] ? 'read' : 'governed_structural_write' );
		$result['rendered_readback_required'] = 'outline' !== $desired['mode'];
		$result['exact_rollback_acceptance_required'] = 'outline' !== $desired['mode'];
		return $result;
	}
}
