<?php
defined('ABSPATH') || exit;
/**
 * MAD4B modern skin for JetEngine-owned CPT meta forms.
 * Presentation only: never moves inputs, never intercepts post save.
 */
final class MAD4B_JetEngine_Modern_Meta_Editor_V1 {
 public static function boot(): void {
  if (!is_admin()) return;
  add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue'], 90);
 }
 public static function enqueue($hook): void {
  if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;
  $screen = get_current_screen();
  if (!$screen || $screen->base !== 'post' || !post_type_exists($screen->post_type)) return;
  if (!current_user_can('edit_posts') || !class_exists('Jet_Engine')) return;
  // All live WordPress CPT editors that actually render JetEngine meta
  // receive the same skin; JS aborts where there are no native wrappers.
  $dir = __DIR__ . '/assets/';
  $url = plugins_url('mad4b-jetengine/modules/functional-modes/assets/');
  wp_enqueue_style('mad4b-jetengine-modern-meta-editor',
    $url . 'modern-meta-editor.css', ['jet-engine-meta-boxes'],
    (string)filemtime($dir . 'modern-meta-editor.css'));
  wp_enqueue_style('mad4b-jetengine-cpt-field-recipe',
    $url . 'jetengine-field-recipe.css',
    ['mad4b-jetengine-modern-meta-editor'],
    (string)filemtime($dir . 'jetengine-field-recipe.css'));
  MAD4B_JetEngine_Recipe_Registry_V1::enqueue('cpt', (string)$screen->post_type);
  wp_enqueue_script('mad4b-jetengine-modern-meta-editor',
    $url . 'modern-meta-editor.js', [],
    (string)filemtime($dir . 'modern-meta-editor.js'), true);
 }
}
MAD4B_JetEngine_Modern_Meta_Editor_V1::boot();
