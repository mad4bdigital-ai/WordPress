<?php
/**
 * Plugin Name: JE Meta ID → Label Dynamic Tag
 * Description: Convert meta value (Post ID / Term ID) into Post Title / Term Name for Elementor Dynamic Tags.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'jet-engine/elementor-views/dynamic-tags/register', function( $dynamic_tags ) {

	// تأكد إن Elementor موجود
	if ( ! class_exists( '\Elementor\Core\DynamicTags\Tag' ) ) {
		return;
	}

	if ( ! class_exists( 'JE_Meta_Id_Label_Tag' ) ) {

	class JE_Meta_Id_Label_Tag extends \Elementor\Core\DynamicTags\Tag {

		public function get_name() {
			return 'je-meta-id-to-label';
		}

		public function get_title() {
			return __( 'Meta ID → Label (Post/Term)', 'jet-engine' );
		}

		public function get_group() {
			return \Jet_Engine_Dynamic_Tags_Module::JET_GROUP;
		}

		public function get_categories() {
			return [ \Jet_Engine_Dynamic_Tags_Module::TEXT_CATEGORY ];
		}

		public function is_settings_required() {
			return true;
		}

		protected function register_controls() {

			// Meta field selector (JetEngine meta boxes list)
			$this->add_control(
				'meta_field',
				[
					'label'  => __( 'Meta Field', 'jet-engine' ),
					'type'   => \Elementor\Controls_Manager::SELECT,
					'groups' => ( jet_engine()->meta_boxes ) ? jet_engine()->meta_boxes->get_fields_for_select( 'plain' ) : [],
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

			// Taxonomy selector (only for term mode)
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
					'label'   => __( 'If meta has multiple IDs', 'jet-engine' ),
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

			$meta_field = $this->get_settings( 'meta_field' );
			if ( ! $meta_field ) {
				return;
			}

			$raw = jet_engine()->listings->data->get_meta( $meta_field );
			if ( empty( $raw ) ) {
				return;
			}

			$ids = $this->normalize_ids( $raw );
			if ( empty( $ids ) ) {
				return;
			}

			$kind     = $this->get_settings( 'id_kind' );
			$taxonomy = $this->get_settings( 'taxonomy' );
			$multi    = $this->get_settings( 'multi_mode' );

			$labels = [];
			foreach ( $ids as $id ) {
				$label = $this->id_to_label( $id, $kind, $taxonomy );
				if ( $label ) {
					$labels[] = $label;
					if ( 'first' === $multi ) {
						break;
					}
				}
			}

			if ( empty( $labels ) ) {
				return;
			}

			echo esc_html( implode( ', ', $labels ) );
		}

		private function normalize_ids( $raw ) {

			// Array (checkbox, repeater, etc.)
			if ( is_array( $raw ) ) {
				$out = [];
				foreach ( $raw as $v ) {
					$v = is_scalar( $v ) ? trim( (string) $v ) : '';
					if ( $v !== '' && ctype_digit( $v ) ) {
						$out[] = (int) $v;
					}
				}
				return array_values( array_unique( $out ) );
			}

			// String / number: may be "123" or "123,456"
			$str = trim( (string) $raw );
			if ( $str === '' ) { return []; }

			$parts = preg_split( '/[,\s|]+/', $str );
			$out = [];
			foreach ( $parts as $p ) {
				$p = trim( $p );
				if ( $p !== '' && ctype_digit( $p ) ) {
					$out[] = (int) $p;
				}
			}
			return array_values( array_unique( $out ) );
		}

		private function id_to_label( $id, $kind, $taxonomy ) {

			static $cache = [
				'post' => [],
				'term' => [],
			];

			$id = (int) $id;
			if ( $id <= 0 ) { return ''; }

			// Post
			if ( 'post' === $kind || 'auto' === $kind ) {

				if ( isset( $cache['post'][ $id ] ) ) {
					return $cache['post'][ $id ];
				}

				$title = get_the_title( $id );
				if ( $title && ! is_wp_error( $title ) ) {
					$cache['post'][ $id ] = $title;
					return $title;
				}

				$cache['post'][ $id ] = '';
				if ( 'post' === $kind ) {
					return '';
				}
			}

			// Term
			if ( 'term' === $kind || 'auto' === $kind ) {

				$key = $taxonomy ? ( $taxonomy . ':' . $id ) : (string) $id;

				if ( isset( $cache['term'][ $key ] ) ) {
					return $cache['term'][ $key ];
				}

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

	// Register tag
	if ( ! class_exists( 'JE_Meta_Id_Label_Tag' ) ) {
		return;
	}

	if ( method_exists( $dynamic_tags, 'register' ) ) {
		$dynamic_tags->register( new JE_Meta_Id_Label_Tag() );
	} else {
		$dynamic_tags->register_tag( new JE_Meta_Id_Label_Tag() );
	}

}, 20, 1 );