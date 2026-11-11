<?php
/** Plugin Name: MAD4B — Taxonomy FAQs Settings */
defined('ABSPATH') || exit;
add_action('admin_init',static function():void {
    register_setting('mad4b_term_faqs','mad4b_taxonomy_faq_enabled_taxonomies',array(
        'type'=>'array','default'=>array('destinations','tour-types','tour-styles','season','good-for','duration-options','attractions-types','tour_categories'),
        'sanitize_callback'=>static function($value):array{
            if(!is_array($value))$value=array();
            return array_values(array_intersect(MAD4B_Taxonomy_FAQ_Source::taxonomies(),array_map('sanitize_key',$value)));
        }
    ));
});
add_action('admin_menu',static function():void {
 add_options_page('MAD4B Taxonomy FAQs','MAD4B Taxonomy FAQs','manage_options','mad4b-taxonomy-faqs',static function():void {
  if(!current_user_can('manage_options'))wp_die(esc_html__('Access denied.'));
  $enabled=MAD4B_Taxonomy_FAQ_Source::enabled_taxonomies();
  echo '<div class="wrap"><h1>MAD4B — Dynamic Taxonomy FAQs</h1><p>JetEngine term Repeater, one Query Builder source, one shared Elementor template. Enabled taxonomies affect publishing, not the editorial data stored on terms.</p>';
  echo '<form method="post" action="options.php">';settings_fields('mad4b_term_faqs');
  echo '<input type="hidden" name="mad4b_taxonomy_faq_enabled_taxonomies[]" value="">';
  echo '<table class="widefat striped"><thead><tr><th scope="col">Active</th><th scope="col">Taxonomy</th><th scope="col">Slug</th></tr></thead><tbody>';
  foreach(MAD4B_Taxonomy_FAQ_Source::taxonomies() as $tax){$obj=get_taxonomy($tax);if(!$obj)continue;
   echo '<tr><td><input type="checkbox" name="mad4b_taxonomy_faq_enabled_taxonomies[]" value="'.esc_attr($tax).'" '.checked(in_array($tax,$enabled,true),true,false).'></td><td>'.esc_html($obj->labels->name??$tax).'</td><td><code>'.esc_html($tax).'</code></td></tr>';
  }
  echo '</tbody></table>';
  echo '<p>Content source: <code>mad4b_term_faq_items</code> &nbsp; Query: <code>'.esc_html((string)get_option('mad4b_taxonomy_faq_query_id')).'</code> &nbsp; Listing: <code>'.esc_html((string)get_option('mad4b_taxonomy_faq_listing_id')).'</code> &nbsp; Elementor template: <code>'.esc_html((string)get_option('mad4b_taxonomy_faq_template_id')).'</code></p>';
  submit_button('Save taxonomy activation');echo '</form></div>';
 });
});
