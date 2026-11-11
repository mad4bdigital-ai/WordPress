<?php
/**
 * Plugin Name: Elementor Dynamic Tag - JetEngine Term Select Label
 */

if ( ! defined('ABSPATH') ) exit;

add_action('elementor/dynamic_tags/register', function( $dynamic_tags ){

  if ( ! class_exists('\Elementor\Core\DynamicTags\Tag') ) return;

  if ( ! class_exists( 'JE_Term_Select_Label_Tag' ) ) {

  class JE_Term_Select_Label_Tag extends \Elementor\Core\DynamicTags\Tag {

    public function get_name() { return 'je_term_select_label'; }
    public function get_title() { return 'JE: Term Select Label'; }
    public function get_group() { return 'site'; } // appears under "Site" group
    public function get_categories() { return [ \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY ]; }

    protected function register_controls() {

      $this->add_control('meta_key', [
        'label' => 'Term Meta Key',
        'type'  => \Elementor\Controls_Manager::TEXT,
        'default' => '',
        'description' => 'Example: destination_type',
      ]);

      $this->add_control('taxonomy', [
        'label' => 'Taxonomy Slug (optional)',
        'type'  => \Elementor\Controls_Manager::TEXT,
        'default' => '',
        'description' => 'Leave empty to auto-detect from current term (recommended).',
      ]);

      $this->add_control('delimiter', [
        'label' => 'Delimiter',
        'type'  => \Elementor\Controls_Manager::TEXT,
        'default' => ', ',
      ]);
    }

    public function render() {

      $meta_key  = trim((string) $this->get_settings('meta_key'));
      $taxonomy  = trim((string) $this->get_settings('taxonomy'));
      $delimiter = (string) $this->get_settings('delimiter');

      if (!$meta_key) return;

      $qo = get_queried_object();
      if ( ! $qo || ! isset($qo->term_id) ) return;

      $term_id = (int) $qo->term_id;

      if (!$taxonomy && isset($qo->taxonomy)) {
        $taxonomy = (string) $qo->taxonomy;
      }

      $value = get_term_meta($term_id, $meta_key, true);
      if ($value === '' || $value === null || (is_array($value) && empty($value))) return;

      // ✅ JetEngine native labels resolver (best)
      if ( function_exists('jet_engine_get_field_options_labels') && $taxonomy ) {
        $label = jet_engine_get_field_options_labels($value, $taxonomy, $meta_key, $delimiter);
        if ($label !== '' && $label !== null) {
          echo esc_html($label);
          return;
        }
      }

      // Fallback: raw value
      if (is_array($value)) echo esc_html(implode($delimiter, $value));
      else echo esc_html((string)$value);
    }
  }

  }

  if ( ! class_exists( 'JE_Term_Select_Label_Tag' ) ) {
    return;
  }

  if ( method_exists( $dynamic_tags, 'register' ) ) {
    $dynamic_tags->register( new JE_Term_Select_Label_Tag() );
  } elseif ( method_exists( $dynamic_tags, 'register_tag' ) ) {
    $dynamic_tags->register_tag( new JE_Term_Select_Label_Tag() );
  }

}, 20);
