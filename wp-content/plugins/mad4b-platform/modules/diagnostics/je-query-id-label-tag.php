<?php
/**
 * Plugin Name: JE Query Field ID → Label Dynamic Tag
 * Description: Convert IDs stored in JetEngine Query item fields into Post Title / Term Name.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'jet-engine/elementor-views/dynamic-tags/register', function( $dynamic_tags ) {

	if ( ! class_exists( '\Elementor\Core\DynamicTags\Tag' ) ) return;

	if ( ! class_exists( 'JE_Query_Field_Id_Label_Tag' ) ) {

	class JE_Query_Field_Id_Label_Tag extends \Elementor\Core\DynamicTags\Tag {

		public function get_name() { return 'je-query-field-id-to-label'; }
		public function get_title() { return __( 'Query Field ID → Label (Post/Term)', 'jet-engine' ); }
		public function get_group() { return \Jet_Engine_Dynamic_Tags_Module::JET_GROUP; }
		public function get_categories() { return [ \Jet_Engine_Dynamic_Tags_Module::TEXT_CATEGORY ]; }
		public function is_settings_required() { return true; }

		protected function register_controls() {

			$this->add_control(
				'query_field',
				[
					'label'       => __( 'Query Field Key (column/alias)', 'jet-engine' ),
					'type'        => \Elementor\Controls_Manager::TEXT,
					'placeholder' => 'e.g. trans_from_station_id / train_line_ids',
				]
			);

			$this->add_control(
				'id_kind',
				[
					'label'   => __( 'ID Type', 'jet-engine' ),
					'type'    => \Elementor\Controls_Manager::SELECT,
					'default' => 'auto',
					'options' => [
						'auto' => 'Auto (Post then Term)',
						'post' => 'Post ID',
						'term' => 'Term ID',
					],
				]
			);

			$tax_options = [ '' => __( '— Select —', 'jet-engine' ) ];
			$taxes = get_taxonomies( [ 'public' => true ], 'objects' );
			foreach ( $taxes as $tax ) {
				$tax_options[ $tax->name ] = $tax->labels->singular_name . ' (' . $tax->name . ')';
			}

			$this->add_control(
				'taxonomy',
				[
					'label'     => __( 'Taxonomy (for Term ID)', 'jet-engine' ),
					'type'      => \Elementor\Controls_Manager::SELECT,
					'default'   => '',
					'options'   => $tax_options,
					'condition' => [ 'id_kind' => 'term' ],
				]
			);

			$this->add_control(
				'multi_mode',
				[
					'label'   => __( 'If field has multiple IDs', 'jet-engine' ),
					'type'    => \Elementor\Controls_Manager::SELECT,
					'default' => 'first',
					'options' => [
						'first' => 'Use first only',
						'all'   => 'Join all (comma separated)',
					],
				]
			);
		}

		public function render() {

			$field = trim( (string) $this->get_settings( 'query_field' ) );
			if ( ! $field ) return;

			// أهم سطر: قراءة قيمة من الـ current query item
			$raw = jet_engine()->listings->data->get_prop( $field );

			// fallback (بعض السياقات بتخزنها كـ array داخل object)
			if ( empty( $raw ) ) {
				$obj = jet_engine()->listings->data->get_current_object();
				if ( is_array( $obj ) && isset( $obj[ $field ] ) ) $raw = $obj[ $field ];
				if ( is_object( $obj ) && isset( $obj->{$field} ) ) $raw = $obj->{$field};
			}

			if ( empty( $raw ) ) return;

			$ids = $this->normalize_ids( $raw );
			if ( empty( $ids ) ) return;

			$kind     = $this->get_settings( 'id_kind' );
			$taxonomy = $this->get_settings( 'taxonomy' );
			$multi    = $this->get_settings( 'multi_mode' );

			$labels = [];
			foreach ( $ids as $id ) {
				$label = $this->id_to_label( $id, $kind, $taxonomy );
				if ( $label ) {
					$labels[] = $label;
					if ( 'first' === $multi ) break;
				}
			}

			if ( $labels ) echo esc_html( implode( ', ', $labels ) );
		}

		private function normalize_ids( $raw ) {

			// لو جاية JSON
			if ( is_string( $raw ) ) {
				$maybe = json_decode( $raw, true );
				if ( json_last_error() === JSON_ERROR_NONE && is_array( $maybe ) ) {
					$raw = $maybe;
				}
			}

			if ( is_array( $raw ) ) {
				$out = [];
				foreach ( $raw as $v ) {
					$v = is_scalar( $v ) ? trim( (string) $v ) : '';
					if ( $v !== '' && ctype_digit( $v ) ) $out[] = (int) $v;
				}
				return array_values( array_unique( $out ) );
			}

			$str = trim( (string) $raw );
			if ( $str === '' ) return [];

			$parts = preg_split( '/[,\s|]+/', $str );
			$out = [];
			foreach ( $parts as $p ) {
				$p = trim( $p );
				if ( $p !== '' && ctype_digit( $p ) ) $out[] = (int) $p;
			}
			return array_values( array_unique( $out ) );
		}

		private function id_to_label( $id, $kind, $taxonomy ) {

			static $cache = [ 'post' => [], 'term' => [] ];

			$id = (int) $id;
			if ( $id <= 0 ) return '';

			if ( 'post' === $kind || 'auto' === $kind ) {
				if ( isset( $cache['post'][ $id ] ) ) return $cache['post'][ $id ];

				$title = get_the_title( $id );
				if ( $title && ! is_wp_error( $title ) ) {
					$cache['post'][ $id ] = $title;
					return $title;
				}
				$cache['post'][ $id ] = '';
				if ( 'post' === $kind ) return '';
			}

			if ( 'term' === $kind || 'auto' === $kind ) {
				$key = $taxonomy ? ( $taxonomy . ':' . $id ) : (string) $id;
				if ( isset( $cache['term'][ $key ] ) ) return $cache['term'][ $key ];

				$term = $taxonomy ? get_term( $id, $taxonomy ) : get_term( $id );
				if ( $term && ! is_wp_error( $term ) && ! empty( $term->name ) ) {
					$cache['term'][ $key ] = $term->name;
					return $term->name;
				}
				$cache['term'][ $key ] = '';
			}

			return '';
		}
	}

	}

	if ( ! class_exists( 'JE_Query_Field_Id_Label_Tag' ) ) {
		return;
	}

	if ( method_exists( $dynamic_tags, 'register' ) ) {
		$dynamic_tags->register( new JE_Query_Field_Id_Label_Tag() );
	} else {
		$dynamic_tags->register_tag( new JE_Query_Field_Id_Label_Tag() );
	}

}, 20, 1 );