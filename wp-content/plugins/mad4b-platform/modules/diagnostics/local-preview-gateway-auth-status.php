<?php
/** Authenticated Local Lab preview gateway capability check.
 * This adds no login, roles, tokens, user mutation or remote action.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type()!=='local') return;
add_action('wp_ajax_preview_gateway_auth_status',static function():void {
 if(!is_user_logged_in()||!current_user_can('manage_options')) {
  wp_send_json_error(['authenticated'=>false],403);
 }
 wp_send_json_success(['authenticated'=>true,'can_manage'=>true],200);
},1);
