<?php
/**
 * Listing AJAX query signatures.
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'Jet_Engine_Listings_Signatures' ) ) {

	class Jet_Engine_Listings_Signatures {

		/**
		 * Request key that carries a signature for server-rendered widget settings.
		 *
		 * @var string
		 */
		const SETTINGS_SIGNATURE_KEY = '_jet_engine_settings_signature';

		/**
		 * Check if query has valid base signature.
		 *
		 * @param array $query Query to validate.
		 * @return bool
		 */
		public function query_has_valid_signature( $query ) {

			/**
			 * Allow to disable signature checking from the code.
			 * Please note - this can expose your site to security risks.
			 */
			$force_valid = apply_filters( 'jet-engine/listings/ajax/force-valid-query', false, $query );

			if ( $force_valid ) {
				return true;
			}

			$signature = ! empty( $query['signature'] ) ? $query['signature'] : false;
			unset( $query['signature'] );

			$generated_signature = $this->generate_signature( $query );

			if ( ! $signature || $signature !== $generated_signature ) {
				return false;
			}

			return true;
		}

		/**
		 * Generate signature for the query.
		 *
		 * @param array $query Query to generate signature for.
		 * @return string
		 */
		public function generate_signature( $query ) {

			// Remove parameters allowed to set from the JS and not affecting query security.
			$js_allowed = array(
				'front_store__in',
				'is_front_store',
				'order',
				'jet_ajax_search_settings',
				// `filtered_query` is intentionally client-mutated for front-end filters.
				// Query-specific handlers must sanitize it before using it in SQL.
				'filtered_query',
				'geo_query',
				'filtered_query_signature',
				'signature',
			);

			foreach ( $js_allowed as $param ) {
				if ( isset( $query[ $param ] ) ) {
					unset( $query[ $param ] );
				}
			}

			$normalized = $this->normalize( $query );

			// Remove empty arrays.
			$normalized = array_filter( $normalized, function( $value ) {
				return ! ( is_array( $value ) && empty( $value ) );
			} );

			$serialized = json_encode( $normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			return hash_hmac( 'sha256', $serialized, $this->get_signature_secret() );
		}

		/**
		 * Add a signature to widget settings emitted into a public listing render.
		 *
		 * Widget settings are configuration, not client-controlled query input. This
		 * lets AJAX-only integrations replay the rendered configuration without
		 * allowing it to be modified in transit.
		 *
		 * @param array $settings Widget settings.
		 * @return array
		 */
		public function maybe_add_settings_signature( $settings ) {

			if ( ! is_array( $settings ) ) {
				return $settings;
			}

			$settings[ self::SETTINGS_SIGNATURE_KEY ] = $this->generate_settings_signature( $settings );

			return $settings;
		}

		/**
		 * Verify that widget settings were emitted by a server-side listing render.
		 *
		 * @param array $settings Request widget settings.
		 * @return bool
		 */
		public function settings_have_valid_signature( $settings ) {

			if ( ! is_array( $settings ) || empty( $settings[ self::SETTINGS_SIGNATURE_KEY ] ) || ! is_scalar( $settings[ self::SETTINGS_SIGNATURE_KEY ] ) ) {
				return false;
			}

			$signature = (string) $settings[ self::SETTINGS_SIGNATURE_KEY ];
			unset( $settings[ self::SETTINGS_SIGNATURE_KEY ] );
			$settings = wp_unslash( $settings );

			if ( hash_equals( $this->generate_settings_signature( $settings ), $signature ) ) {
				return true;
			}

			return $this->has_legacy_null_settings_signature( $settings, $signature );
		}

		/**
		 * Generate a signature for the complete widget settings payload.
		 *
		 * Do not use normalize() here: it deliberately omits query `relation`,
		 * while every widget setting must be protected from client-side changes.
		 *
		 * @param array $settings Widget settings.
		 * @return string
		 */
		public function generate_settings_signature( $settings ) {

			if ( ! is_array( $settings ) ) {
				return '';
			}

			unset( $settings[ self::SETTINGS_SIGNATURE_KEY ] );

			$serialized = wp_json_encode( $this->normalize_settings( $settings ) );

			return hash_hmac( 'sha256', $serialized, $this->get_signature_secret() );
		}

		/**
		 * Validate a signature emitted before null request values were normalized
		 * as empty strings. Only load_more_offset used null in navigation data.
		 *
		 * @param array  $settings Request widget settings.
		 * @param string $signature Request signature.
		 * @return bool
		 */
		protected function has_legacy_null_settings_signature( $settings, $signature ) {

			if ( ! array_key_exists( 'load_more_offset', $settings ) || '' !== $settings['load_more_offset'] ) {
				return false;
			}

			// The old normalizer converted null to the literal "null" string.
			$settings['load_more_offset'] = 'null';

			return hash_equals( $this->generate_settings_signature( $settings ), $signature );
		}

		/**
		 * Add a server-side signature for mutable listing query state.
		 * @param array $query Query arguments.
		 * @param array $settings Listing widget settings.
		 * @return array
		 */
		public function maybe_add_mutable_query_signature( $query, $settings = array() ) {

			$payload = $this->get_mutable_query_payload( $query );

			if ( empty( $payload ) ) {
				return $query;
			}

			$query['filtered_query_signature'] = $this->create_mutable_query_signature(
				$payload,
				$this->get_mutable_query_context( $query, $settings )
			);

			return $query;
		}

		/**
		 * Create signature for mutable listing query state.
		 *
		 * @param array $payload Mutable query payload.
		 * @param array $context Stable listing/query context.
		 * @return string
		 */
		public function create_mutable_query_signature( $payload, $context = array() ) {

			$normalized_payload = $this->normalize_mutable_query_payload( $payload );

			if ( empty( $normalized_payload ) ) {
				return '';
			}

			$serialized = wp_json_encode( array(
				'context' => $this->normalize( $context ),
				'payload' => $normalized_payload,
			) );

			return hash_hmac( 'sha256', $serialized, $this->get_signature_secret() );
		}

		/**
		 * Verify mutable listing query state signature.
		 *
		 * @param array $query Request query.
		 * @param array $settings Listing widget settings.
		 * @return bool
		 */
		public function query_has_valid_mutable_signature( $query, $settings = array() ) {

			$payload = $this->get_mutable_query_payload( $query );

			if ( empty( $payload ) ) {
				return true;
			}

			$require_signature = apply_filters(
				'jet-engine/listings/ajax/require-filtered-query-signature',
				true,
				$payload,
				$query,
				$settings
			);

			if ( ! $require_signature ) {
				return true;
			}

			$signature = ! empty( $query['filtered_query_signature'] ) && is_scalar( $query['filtered_query_signature'] )
				? (string) $query['filtered_query_signature']
				: '';

			if ( ! $signature ) {
				return false;
			}

			$expected = $this->create_mutable_query_signature(
				$payload,
				$this->get_mutable_query_context( $query, $settings )
			);

			return $expected && hash_equals( $expected, $signature );
		}

		/**
		 * Extract mutable query data from listing query args.
		 *
		 * @param array $query Listing query args.
		 * @return array
		 */
		protected function get_mutable_query_payload( $query ) {

			$payload = array();

			if ( ! empty( $query['filtered_query'] ) && is_array( $query['filtered_query'] ) ) {
				$payload['filtered_query'] = $query['filtered_query'];
			}

			if ( ! empty( $query['geo_query'] ) && is_array( $query['geo_query'] ) ) {
				$payload['geo_query'] = $query['geo_query'];
			}

			return $payload;
		}

		/**
		 * Build stable mutable-query context. Pagination is intentionally excluded.
		 *
		 * @param array $query Listing query args.
		 * @param array $settings Listing widget settings.
		 * @return array
		 */
		protected function get_mutable_query_context( $query = array(), $settings = array() ) {

			$context = array(
				'provider'        => 'jet-engine',
				'query_id'        => ! empty( $query['query_id'] ) ? absint( $query['query_id'] ) : 0,
				'custom_query_id' => ! empty( $query['custom_query_id'] ) ? absint( $query['custom_query_id'] ) : 0,
				'listing_id'      => ! empty( $settings['lisitng_id'] ) ? absint( $settings['lisitng_id'] ) : 0,
				'element_id'      => ! empty( $settings['_element_id'] ) && is_scalar( $settings['_element_id'] ) ? (string) $settings['_element_id'] : '',
				'user_id'         => get_current_user_id(),
			);

			return apply_filters( 'jet-engine/listings/ajax/mutable-query-context', $context, $query, $settings );
		}

		/**
		 * Normalize mutable query data for deterministic signing.
		 *
		 * @param array $payload Mutable query payload.
		 * @return array
		 */
		protected function normalize_mutable_query_payload( $payload ) {

			if ( ! is_array( $payload ) ) {
				return array();
			}

			unset( $payload['filtered_query_signature'] );
			unset( $payload['signature'] );

			$normalized = $this->normalize_mutable_value( $payload );

			return array_filter( $normalized, function( $value ) {
				return ! ( is_array( $value ) && empty( $value ) );
			} );
		}

		/**
		 * Normalize mutable payload without dropping semantic keys like `relation`.
		 *
		 * @param mixed $data Data to normalize.
		 * @return mixed
		 */
		protected function normalize_mutable_value( $data ) {

			if ( is_array( $data ) ) {
				ksort( $data );

				foreach ( $data as $key => $value ) {
					$data[ $key ] = $this->normalize_mutable_value( $value );
				}

				return $data;
			}

			return $this->normalize_scalar( $data );
		}

		/**
		 * Normalize array for consistent serialization.
		 *
		 * @param mixed $data Data to normalize.
		 * @return mixed
		 */
		public function normalize( $data ) {

			if ( is_array( $data ) ) {

				// Do not use 'relation' key in the base signature.
				// It does not affect query security, but can cause consistency issues.
				if ( isset( $data['relation'] ) ) {
					unset( $data['relation'] );
				}

				ksort( $data );

				foreach ( $data as $key => $value ) {
					$data[ $key ] = $this->normalize( $value );
				}

				return $data;
			}

			return $this->normalize_scalar( $data );
		}

		/**
		 * Normalize settings without removing any semantically relevant key.
		 *
		 * @param mixed $data Settings data.
		 * @return mixed
		 */
		protected function normalize_settings( $data ) {

			if ( is_array( $data ) ) {
				ksort( $data );

				foreach ( $data as $key => $value ) {
					$data[ $key ] = $this->normalize_settings( $value );

					// jQuery form serialization omits empty arrays. Treat a missing
					// empty setting the same way as the server-rendered empty array so
					// signed settings-only reloads remain valid.
					if ( is_array( $data[ $key ] ) && empty( $data[ $key ] ) ) {
						unset( $data[ $key ] );
					}
				}

				return $data;
			}

			// jQuery serializes null request values as an empty string. Treat both
			// representations alike so a server-rendered navigation payload can be
			// verified after the normal AJAX form serialization round trip.
			if ( is_null( $data ) ) {
				return '';
			}

			return $this->normalize_scalar( $data );
		}

		/**
		 * Normalize scalar values for signatures.
		 *
		 * @param mixed $data Value to normalize.
		 * @return string
		 */
		protected function normalize_scalar( $data ) {

			if ( is_bool( $data ) ) {
				return $data ? 'true' : 'false';
			}

			if ( is_null( $data ) ) {
				return 'null';
			}

			return (string) $data;
		}

		/**
		 * Get signing secret.
		 *
		 * @return string
		 */
		protected function get_signature_secret() {

			$key_parts = array(
				defined( 'AUTH_KEY' ) ? AUTH_KEY : '',
				defined( 'NONCE_KEY' ) ? NONCE_KEY : '',
				defined( 'AUTH_SALT' ) ? AUTH_SALT : '',
				defined( 'NONCE_SALT' ) ? NONCE_SALT : '',
			);

			return implode( '.', $key_parts );
		}

	}

}
