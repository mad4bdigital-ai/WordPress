<?php
/** Source-backed media rights regressions: no WordPress, network or production writes. */
define('ABSPATH', '/');
class WP_Error {
    private $code;
    public function __construct($code, $message = '', $data = null) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
class MAD4B_SCP_Adapter_Base {}
class MAD4B_SCP_Content_Experience_Media_Storage {
    public static function reference_ids($value, $spec) {
        return array_values(array_map('intval', (array) $value));
    }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function get_option($key, $fallback = false) { return 'mad4b_remote_media_rights_mode' === $key ? 'enforced' : $fallback; }
function apply_filters($hook, $value) { return $value; }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string) $value)); }
function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_textarea_field($value) { return trim((string) $value); }
function sanitize_file_name($value) { return basename((string) $value); }
function wp_kses_post($value) { return (string) $value; }
function wp_date($format) { return '2026-11-16'; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function esc_url_raw($url, $protocols = null) { return $url; }
function untrailingslashit($url) { return rtrim($url, '/'); }
function absint($value) { return abs((int) $value); }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function get_post_type($id) { return 17 === (int) $id ? 'attachment' : false; }
function get_post_mime_type($id) { return 17 === (int) $id ? 'image/jpeg' : false; }
function get_attached_file($id) { return 17 === (int) $id ? $GLOBALS['mock_image_file'] : false; }
function get_post_meta($id, $key, $single = false) {
    $values = $GLOBALS['mock_meta'][(int) $id][$key] ?? array();
    return $single ? (isset($values[0]) ? $values[0] : '') : $values;
}
function check_case($label, $ok) {
    if (!$ok) {
        fwrite(STDERR, 'FAIL ' . $label . PHP_EOL);
        exit(1);
    }
    $GLOBALS['passed'] = ($GLOBALS['passed'] ?? 0) + 1;
}
$root = dirname(__DIR__, 2);
$includes = $root . '/wp-content/plugins/mad4b-site-control-plane/includes';
$GLOBALS['mock_image_file'] = tempnam(sys_get_temp_dir(), 'mad4b-rights-');
try {
    require $includes . '/class-mad4b-scp-content-experience-media-rights.php';
    require $includes . '/class-mad4b-scp-remote-media-rights.php';
    require $includes . '/adapters/class-mad4b-scp-remote-media-adapter.php';
    $local = 'MAD4B_SCP_Content_Experience_Media_Rights';
    $specs = array(
        'images' => array('kind' => 'image_gallery', 'storage' => 'ids'),
        'image_usage' => array(
            'kind' => 'image_gallery_usage',
            'references_field' => 'images',
            'publish_rights_policy' => 'require_valid',
            'expiry_required_licenses' => array('cc-by-4.0'),
        ),
    );
    $r = $local::publish_guard($specs, array('images' => array(17)), '2026-11-16');
    check_case('real_library_missing_license_warning_in_enforced_mode',
        is_array($r) && ($r['license_warning_count'] ?? 0) > 0
        && ($r['legal_reuse_permission'] ?? '') === 'not_determined_by_system');
    $r = $local::publish_guard($specs, array('images' => array(99999999)), '2026-11-16');
    check_case('unresolved_image_still_blocked',
        is_wp_error($r) && $r->get_error_code() === 'mad4b_content_experience_media_rights_usage_required');
    $r = $local::publish_guard($specs, array('images' => array(17),
        'image_usage' => array(array('attachment_id' => 17, 'license' => 'cc-by-4.0'))), '2026-11-16');
    check_case('dotted_license_expiry_warning_without_fabricated_expiry',
        is_array($r) && $r['license_warning_count'] > 0);
    $r = $local::publish_guard($specs, array('images' => array(17),
        'image_usage' => array(array('attachment_id' => 17, 'license' => 'restricted'))), '2026-11-16');
    check_case('explicit_restriction_always_blocks',
        is_wp_error($r) && $r->get_error_code() === 'mad4b_content_experience_media_rights_explicit_restriction');
    $r = $local::publish_guard($specs, array('images' => array(17),
        'image_usage' => array(array('attachment_id' => 17, 'license' => '',
            'license_expires_on' => '2026-02-30'))), '2026-11-16');
    check_case('invalid_calendar_expiry_blocks',
        is_wp_error($r) && $r->get_error_code() === 'mad4b_content_experience_media_rights_expired');

    $url = 'https://upload.wikimedia.org/wikipedia/commons/example.jpg';
    $source = hash('sha256', $url);
    $bytes = str_repeat('a', 64);
    $GLOBALS['mock_meta'][17] = array(
        '_mad4b_remote_media_source_sha256' => array($source),
        '_mad4b_remote_media_content_sha256' => array($bytes),
        '_mad4b_remote_media_provenance' => array(array(
            'contract' => 'mad4b.remote-media-provenance.v1',
            'source_url_sha256' => $source, 'content_sha256' => $bytes,
            'rights_basis' => 'unknown', 'rights_note' => '',
            'rights_reference' => '', 'license_expires_on' => '',
        )),
    );
    $remote = 'MAD4B_SCP_Remote_Media_Rights';
    $r = $remote::publish_guard(17, array(), array(), '2026-11-16');
    check_case('remote_existing_library_image_missing_rights_advisory',
        is_array($r) && $r['license_warning_count'] > 0
        && ($r['legal_reuse_permission'] ?? '') === 'not_determined_by_system');
    $GLOBALS['mock_meta'][17]['_mad4b_remote_media_content_sha256'] = array(str_repeat('b', 64));
    $r = $remote::publish_guard(17, array(), array(), '2026-11-16');
    check_case('remote_content_sha_drift_denied',
        is_wp_error($r) && $r->get_error_code() === 'mad4b_remote_media_publish_provenance_drift');
    $GLOBALS['mock_meta'][17]['_mad4b_remote_media_content_sha256'] = array($bytes);
    check_case('cc_by_is_not_public_domain',
        $remote::conflicts_with_explicit_cc_license('public_domain',
            'https://creativecommons.org/licenses/by/4.0/', ''));
    check_case('actual_public_domain_mark_not_cc_by',
        !$remote::conflicts_with_explicit_cc_license('public_domain',
            'https://creativecommons.org/publicdomain/mark/1.0/', ''));

    $ref = new ReflectionClass('MAD4B_SCP_Remote_Media_Adapter');
    $adapter = $ref->newInstanceWithoutConstructor();
    $method = $ref->getMethod('normalize_remote_import_input');
    if (PHP_VERSION_ID < 80100) $method->setAccessible(true);
    $data = array('source_url' => $url,
        'rights_reference' => 'https://creativecommons.org/licenses/by/4.0/');
    $bad = $method->invoke($adapter, $data + array('rights_basis' => 'public_domain'));
    check_case('remote_import_cannot_mislabel_cc_by_as_public_domain',
        is_wp_error($bad) && $bad->get_error_code() === 'mad4b_remote_media_cc_license_not_public_domain');
    $good = $method->invoke($adapter, $data + array('rights_basis' => 'creative_commons'));
    check_case('remote_import_retains_explicit_cc_basis',
        is_array($good) && $good['rights_basis'] === 'creative_commons'
        && untrailingslashit($good['rights_reference']) === untrailingslashit($data['rights_reference']));
    echo 'PASS: MEDIA_RIGHTS_SOURCE_CONVERGENCE ' . $GLOBALS['passed'] . ' real source-backed hermetic cases' . PHP_EOL;
} finally {
    if (is_string($GLOBALS['mock_image_file']) && is_file($GLOBALS['mock_image_file'])) {
        unlink($GLOBALS['mock_image_file']);
    }
}
