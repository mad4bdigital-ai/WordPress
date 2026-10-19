<?php
/**
 * Register post meta field for Rest API
 */

namespace Jet_Engine\REST_API_Fields;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

class Repeater extends Base {
	protected function init() {
		switch ( $this->object_type ) {
			case 'post':
				$this->add_post_hooks();
				break;
			case 'term':
				$this->add_term_hooks();
				break;
			case 'user':
				$this->add_user_hooks();
				break;
		}
	}

	protected function add_post_hooks() {
		if ( ! empty( $this->field['save_separate'] ) ) {
			add_action( 'rest_after_insert_' . $this->object_subtype, array( $this, 'save_separate_post_fields' ) );
		}
	}

	protected function add_term_hooks() {
		if ( ! empty( $this->field['save_separate'] ) ) {
			add_action( 'rest_after_insert_' . $this->object_subtype, array( $this, 'save_separate_term_fields' ) );
		}
	}

	protected function add_user_hooks() {
		if ( ! empty( $this->field['save_separate'] ) ) {
			add_action( 'rest_after_insert_user', array( $this, 'save_separate_user_fields' ) );
		}
	}

	public function save_separate_post_fields( $post ) {
		if ( ! $this->is_rest() ) {
			return;
		}

		$name  = $this->field['name'];
		$value = get_post_meta( $post->ID, $name, true );

		\Cherry_X_Post_Meta::_save_repeater_separate_fields(
			$post->ID,
			$name,
			$value,
			$this->field
		);
	}

	public function save_separate_term_fields( $term ) {
		if ( ! $this->is_rest() ) {
			return;
		}

		$name  = $this->field['name'];
		$value = get_term_meta( $term->term_id, $name, true );

		\Cherry_X_Term_Meta::_save_repeater_separate_fields(
			$term->term_id,
			$name,
			$value,
			$this->field
		);
	}

	public function save_separate_user_fields( $user ) {
		if ( ! $this->is_rest() ) {
			return;
		}
		
		$name  = $this->field['name'];
		$value = get_user_meta( $user->ID, $name, true );

		\Jet_Engine_CPT_User_Meta::_save_repeater_separate_fields(
			$user->ID,
			$name,
			$value,
			$this->field
		);
	}
}
