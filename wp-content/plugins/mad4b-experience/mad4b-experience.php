<?php
/**
 * Plugin Name: MAD4B Experience
 * Description: Modular presentation, performance and visitor UX for all registered company environments.
 * Version: 1.2.2
 * Requires PHP: 8.0
 * Text Domain: mad4b-experience
 */
defined('ABSPATH')||exit;
final class MAD4B_Experience {
 const VERSION='1.2.2';
 const MODULE_GROUPS=['presentation'=>9,'performance'=>5,'visitor'=>2];
 public static function boot():void {
  require_once __DIR__.'/src/presentation/bootstrap.php';
  require_once __DIR__.'/src/visitor/bootstrap.php';
  add_action('admin_menu',[__CLASS__,'menu']);
 }
 public static function menu():void {
  if(!current_user_can('manage_options'))return;
  add_options_page('MAD4B Experience','MAD4B Experience','manage_options',
    'mad4b-experience',[__CLASS__,'render']);
 }
 public static function render():void {
  if(!current_user_can('manage_options'))return;
  echo '<div class="wrap"><h1>MAD4B Experience</h1>';
  echo '<p>One WordPress plugin with independently controlled modules. Site-owned settings are preserved.</p>';
  echo '<table class="widefat striped"><thead><tr><th>Module</th><th>Components</th></tr></thead><tbody>';
  foreach(self::MODULE_GROUPS as $domain=>$number){
   echo '<tr><td>'.esc_html(ucwords($domain)).'</td><td>'.esc_html((string)$number).'</td></tr>';
  }
  echo '</tbody></table><p>Feature controls are available under Settings: Presentation Module and Visitor Module.</p></div>';
 }
}
MAD4B_Experience::boot();
