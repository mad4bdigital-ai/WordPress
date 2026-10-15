<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Catalog, private commerce and monetary effects never inherit each other's authority. */
final class MAD4B_SCP_Domain_Commerce {
	public static function validate( $profile, array $desired, array $facts ) {
		if ( 'commerce_catalog' === $profile ) return self::catalog( $desired, $facts );
		if ( 'commerce_private' === $profile ) return self::private_read( $desired, $facts );
		if ( 'commerce_financial' !== $profile ) return MAD4B_SCP_Domain_Contracts::error( 'commerce_profile' );
		$check = MAD4B_SCP_Domain_Contracts::keys( $desired, array( 'operation','object_id' ), array( 'operation','object_id' ) );
		if ( is_wp_error( $check ) ) return $check;
		if ( ! in_array( $desired['operation'], array( 'shipping','tax','gateway','webhook','notification','refund','payment' ), true ) || ! MAD4B_SCP_Domain_Contracts::identifier( $desired['object_id'] ) || true !== ( $facts['object_access'] ?? null ) ) return MAD4B_SCP_Domain_Contracts::error( 'commerce_financial_scope' );
		$result = MAD4B_SCP_Domain_Contracts::result( $profile, $desired, array( 'independent_financial_gate','no_secret_configuration','no_undo_claim' ), 'high_risk_explicit_gate' );
		$result['execution_supported'] = false;
		$result['required_gate'] = 'exact_financial_or_external_effect_review';
		return $result;
	}

	private static function catalog( array $desired, array $facts ) {
		$check = MAD4B_SCP_Domain_Contracts::keys( $desired, array( 'items' ), array( 'items' ) );
		if ( is_wp_error( $check ) ) return $check;
		if ( true !== ( $facts['runtime_compatible'] ?? null ) || true !== ( $facts['hpos_compatible'] ?? null ) || true !== ( $facts['hooks_bounded'] ?? null ) ) return MAD4B_SCP_Domain_Contracts::error( 'commerce_runtime_or_hooks' );
		if ( ! is_array( $desired['items'] ) || ! MAD4B_SCP_Domain_Contracts::is_list( $desired['items'] ) || ! $desired['items'] || count( $desired['items'] ) > 20 ) return MAD4B_SCP_Domain_Contracts::error( 'commerce_batch_bound' );
		$ids = array(); $inventory_effect = false;
		foreach ( $desired['items'] as $item ) {
			if ( ! is_array( $item ) ) return MAD4B_SCP_Domain_Contracts::error( 'commerce_item' );
			$check = MAD4B_SCP_Domain_Contracts::keys( $item, array( 'id','parent_id','expected_revision','fields' ), array( 'id','parent_id','expected_revision','fields' ) );
			if ( is_wp_error( $check ) ) return $check;
			if ( ! MAD4B_SCP_Domain_Contracts::identifier( $item['id'] ) || isset( $ids[ $item['id'] ] ) || ! is_array( $item['fields'] ) ) return MAD4B_SCP_Domain_Contracts::error( 'commerce_duplicate_or_identity' );
			$ids[ $item['id'] ] = true;
			$object = $facts['objects'][ $item['id'] ] ?? array();
			if ( true !== ( $object['authorized'] ?? null ) || ! is_string( $item['parent_id'] ) || $item['parent_id'] !== ( $object['parent_id'] ?? null ) || ! MAD4B_SCP_Domain_Contracts::sha( $item['expected_revision'] ) || $item['expected_revision'] !== ( $object['revision'] ?? null ) ) return MAD4B_SCP_Domain_Contracts::error( 'commerce_parent_or_revision' );
			$valid = MAD4B_SCP_Domain_Contracts::fields( $item['fields'], $object, array( 'local_catalog','inventory' ) );
			if ( is_wp_error( $valid ) ) return $valid;
			foreach ( array_keys( $item['fields'] ) as $field ) {
				if ( 'inventory' === $object['field_contracts'][ $field ]['effect'] ) {
					$inventory_effect = true;
					if ( true !== ( $object['stock_concurrency_guard'] ?? null ) ) return MAD4B_SCP_Domain_Contracts::error( 'commerce_stock_race' );
				}
			}
		}
		return MAD4B_SCP_Domain_Contracts::result( 'commerce_catalog', $desired, array( 'exact_object_parent','independent_runtime_hpos','unique_bounded_batch','stock_concurrency','per_field_effects' ), $inventory_effect ? 'high_risk_inventory' : 'governed_catalog' );
	}

	private static function private_read( array $desired, array $facts ) {
		$check = MAD4B_SCP_Domain_Contracts::keys( $desired, array( 'kind','object_ids','fields','limit','masked' ), array( 'kind','object_ids','fields','limit','masked' ) );
		if ( is_wp_error( $check ) ) return $check;
		if ( ! in_array( $desired['kind'], array( 'orders','customers','coupons','reports','fulfillment' ), true ) || ! is_array( $desired['object_ids'] ) || ! MAD4B_SCP_Domain_Contracts::is_list( $desired['object_ids'] ) || ! $desired['object_ids'] || count( $desired['object_ids'] ) > 20 || ! is_array( $desired['fields'] ) || ! MAD4B_SCP_Domain_Contracts::is_list( $desired['fields'] ) || ! $desired['fields'] || count( $desired['fields'] ) > 32 || true !== $desired['masked'] || ! is_int( $desired['limit'] ) || $desired['limit'] < 1 || $desired['limit'] > 50 ) return MAD4B_SCP_Domain_Contracts::error( 'commerce_private_bound' );
		$seen = array();
		foreach ( $desired['object_ids'] as $id ) {
			if ( ! MAD4B_SCP_Domain_Contracts::identifier( $id ) || isset( $seen[ $id ] ) || true !== ( $facts['object_access'][ $id ] ?? null ) ) return MAD4B_SCP_Domain_Contracts::error( 'commerce_private_object' );
			$seen[ $id ] = true;
			foreach ( $desired['fields'] as $field ) if ( ! is_string( $field ) || true !== ( $facts['field_masks'][ $id ][ $field ] ?? null ) ) return MAD4B_SCP_Domain_Contracts::error( 'commerce_pii_mask' );
		}
		return MAD4B_SCP_Domain_Contracts::result( 'commerce_private', $desired, array( 'private_object_authority','field_masks','bounded_pagination','no_payment_effect' ), 'private_read' );
	}
}
