<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Exact-site ACI01 recipe scope normalization, separated from the public
 * Content Experience Profile registry. No WordPress mutation or authority.
 */
final class MAD4B_SCP_Content_Experience_Recipe_Scope {
	public static function normalize( $rows ) {
		if ( ! is_array( $rows ) || count( $rows ) > 24 )
			return new WP_Error( 'mad4b_aci01_recipe_registry_invalid', 'Content recipe variants must be a bounded array.' );
		if ( empty( $rows ) ) return array();
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) )
			return new WP_Error( 'mad4b_aci01_recipe_site_unavailable', 'Recipe scope needs the enrolled site identity.' );
		$site_uuid = strtolower( (string) MAD4B_SCP_Site_Profile::site_uuid() );
		if ( ! preg_match( '/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $site_uuid ) )
			return new WP_Error( 'mad4b_aci01_recipe_site_invalid', 'Current site identity is not bound.' );
		$out = array();
		$seen = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || array_diff( array_keys( $row ), array(
					'site_uuid', 'brand_id', 'locale', 'market', 'requirements' ) ) )
				return new WP_Error( 'mad4b_aci01_recipe_fields_invalid', 'Recipe contains unrecognized or malformed fields.' );
			foreach ( array( 'site_uuid', 'brand_id', 'locale', 'market' ) as $key )
				if ( ! isset( $row[$key] ) || ! is_string( $row[$key] ) )
					return new WP_Error( 'mad4b_aci01_recipe_scope_invalid', 'Exact recipe dimensions are required.' );
			$uuid = strtolower( $row['site_uuid'] );
			$brand = $row['brand_id'];
			$locale = $row['locale'];
			$market = strtoupper( $row['market'] );
			if ( $uuid !== $site_uuid ||
				! preg_match( '/^[A-Za-z0-9_.:-]{1,80}$/D', $brand ) ||
				! preg_match( '/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8}){0,3}$/D', $locale ) ||
				! preg_match( '/^[A-Z]{2}$/D', $market ) ||
				! isset( $row['requirements'] ) || ! is_array( $row['requirements'] ) ||
				count( $row['requirements'] ) < 1 || count( $row['requirements'] ) > 40 )
				return new WP_Error( 'mad4b_aci01_recipe_scope_invalid', 'Recipe scope or bounded requirements invalid.' );
			$key = $uuid . '|' . $brand . '|' . $locale . '|' . $market;
			if ( isset( $seen[$key] ) )
				return new WP_Error( 'mad4b_aci01_recipe_duplicate_scope', 'Only one recipe is permitted for an exact site, brand, locale and market.' );
			$seen[$key] = true;
			$req = array();
			foreach ( $row['requirements'] as $requirement ) {
				if ( ! is_string( $requirement ) ||
					! preg_match( '/^[a-z][a-z0-9_.-]{1,79}$/D', $requirement ) ||
					isset( $req[$requirement] ) )
					return new WP_Error( 'mad4b_aci01_recipe_requirement_invalid', 'Requirements must be distinct bounded identifiers.' );
				$req[$requirement] = true;
			}
			$keys = array_keys( $req );
			sort( $keys, SORT_STRING );
			$out[$key] = array( 'site_uuid' => $uuid, 'brand_id' => $brand,
				'locale' => $locale, 'market' => $market, 'requirements' => $keys );
		}
		ksort( $out, SORT_STRING );
		return array_values( $out );
	}

}
