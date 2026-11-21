<?php
/** Plugin Name: ETG Lab Attachment Request Memo */
if (!defined('ABSPATH')) exit;
// Only cache exact URL lookups during one request; no persistent cross-language keys.
$GLOBALS['etg_lab_attachment_memo'] = [];
add_filter('pre_attachment_url_to_postid', static function ($pre, $url) {
    if (null !== $pre || !is_string($url) || '' === $url) return $pre;
    return array_key_exists($url, $GLOBALS['etg_lab_attachment_memo']) ? $GLOBALS['etg_lab_attachment_memo'][$url] : null;
}, 50, 2);
add_filter('attachment_url_to_postid', static function ($id, $url) {
    if (is_string($url) && '' !== $url) $GLOBALS['etg_lab_attachment_memo'][$url] = (int) $id;
    return $id;
}, PHP_INT_MAX, 2);
