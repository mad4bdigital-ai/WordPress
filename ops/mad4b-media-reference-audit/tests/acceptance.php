<?php
/**
 * Execute from an authorized WordPress container:
 * wp --path=/var/www/html eval-file - < tests/acceptance.php
 */
if (!class_exists('MAD4B_Media_Reference_Audit')) throw new RuntimeException('Ability class not loaded');
$p = MAD4B_Media_Reference_Audit::policy();
foreach (['delete_allowed','trash_allowed','quarantine_move_allowed','auto_publish_allowed','auto_media_catalog_approval'] as $flag) {
    if (!array_key_exists($flag, $p) || $p[$flag] !== false) throw new RuntimeException('Unsafe media policy: '.$flag);
}
if (!function_exists('wp_has_ability') || !wp_has_ability(MAD4B_Media_Reference_Audit::ABILITY)) throw new RuntimeException('Ability not registered');
if (!wp_next_scheduled(MAD4B_Media_Reference_Audit::HOOK)) throw new RuntimeException('Missing recurring WordPress event');
$old_user = get_current_user_id();
wp_set_current_user(0);
if (MAD4B_Media_Reference_Audit::can_read()) throw new RuntimeException('Unauthorized read allowed');
wp_set_current_user($old_user);

global $wpdb;
$before = [
    'attachments'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment'"),
    'attached_file_meta'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key=%s",'_wp_attached_file'))
];
$scan = MAD4B_Media_Reference_Audit::scan(0,3);
if (is_wp_error($scan)) throw new RuntimeException($scan->get_error_code());
if ($scan['batch_size'] > 3 || $scan['mutation_performed']) throw new RuntimeException('Unexpected scan behavior');
$review = MAD4B_Media_Reference_Audit::review(0,3,'all');
if (is_wp_error($review) || $review['count'] > 3 || $review['mutation_performed']) throw new RuntimeException('Unexpected review behavior');
$after = [
    'attachments'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment'"),
    'attached_file_meta'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key=%s",'_wp_attached_file'))
];
if ($before !== $after) throw new RuntimeException('Attachment row count changed');
echo wp_json_encode([
    'contract'=>'mad4b.media-reference-audit.acceptance.v1',
    'status'=>'PASS',
    'policy_no_delete'=>true,
    'ability_registered'=>true,
    'unauthenticated_denied'=>true,
    'cron_registered'=>true,
    'quick_scan_count'=>$scan['batch_size'],
    'stored_review_count'=>$review['count'],
    'source_media_counts_unchanged'=>$before === $after
], JSON_PRETTY_PRINT);
