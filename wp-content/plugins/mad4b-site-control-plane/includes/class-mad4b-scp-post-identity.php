<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Minimal read-only post identity primitive for governed preflight checks.
 *
 * This intentionally exposes no content, meta, terms, author data or raw DB
 * values. It creates no authority and performs no mutation.
 */
final class MAD4B_SCP_Post_Identity {
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		if ( function_exists( 'add_action' ) ) add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 25 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/post-identity' ) ) return;

		wp_register_ability(
			'mad4b/post-identity',
			array(
				'label' => 'Get Post Identity',
				'description' => 'Return only the minimal WordPress identity of one post for governed read-only preflight checks.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'input_schema' => array(
					'type' => 'object',
					'required' => array( 'post_id' ),
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					),
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type' => 'object',
					'required' => array( 'exists' ),
					'properties' => array(
						'exists' => array( 'type' => 'boolean' ),
						'ID' => array( 'type' => 'integer', 'minimum' => 1 ),
						'post_type' => array( 'type' => 'string' ),
						'post_status' => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
					'annotations' => array(
						'readonly' => true,
						'destructive' => false,
						'idempotent' => true,
					),
				),
			)
		);
	}

	public static function can_read( $input = null ) {
		if ( ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::can_read() ) return false;
		$id = is_array( $input ) && isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		if ( $id < 1 ) return false;
		$post = get_post( $id );
		if ( ! $post ) return true;
		return current_user_can( 'read_post', $post->ID );
	}

	public static function execute( $input ) {
		$id = is_array( $input ) && isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		if ( $id < 1 ) return new WP_Error( 'mad4b_post_identity_invalid_id', 'A positive post_id is required.' );
		$post = get_post( $id );
		if ( ! $post ) return array( 'exists' => false, 'ID' => $id );
		return array(
			'exists' => true,
			'ID' => (int) $post->ID,
			'post_type' => (string) $post->post_type,
			'post_status' => (string) $post->post_status,
		);
	}
}

MAD4B_SCP_Post_Identity::boot();
