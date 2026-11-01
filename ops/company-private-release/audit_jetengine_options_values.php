<?php
/**
 * Read-only, fail-closed inspection of JetEngine Options Page VALUE ownership.
 * This does not export or import option-page schema, media or business content.
 * Use only via authenticated WP-CLI with --skip-plugins --skip-themes.
 */
if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('WP-CLI required');
}
$target = strtolower(trim((string)getenv('MAD4B_EXPECTED_TENANT_DOMAIN')));
$allowed = ['allroyalegypt.com', 'egypttourgates.com'];
if (!in_array($target, $allowed, true)) {
    throw new RuntimeException('Unknown tenant: fail closed');
}
$other = $target === $allowed[0] ? $allowed[1] : $allowed[0];
$keys = ['look-and-feel', 'brand'];
$result = [
  'contract' => 'mad4b.jetengine-options-values-isolation.v1',
  'tenant' => $target,
  'mode' => 'READ_ONLY',
  'definitions_shared' => true,
  'values_tenant_owned' => true,
  'schema_registration_verified' => false,
  'automatic_donor_value_import_allowed' => false,
  'pages' => [],
  'mutation_performed' => false,
];
foreach ($keys as $option) {
    $value = get_option($option, null);
    $row = [
      'option_name' => $option,
      'stored_value_exists' => $value !== null,
      'value_type' => gettype($value),
      'field_count' => is_array($value) ? count($value) : null,
      'field_keys' => is_array($value) ? array_map('strval', array_keys($value)) : [],
      'url_references' => ['target_domain' => 0, 'other_company' => 0, 'external_unverified' => 0],
      'foreign_string_fields' => [],
      'gallery_entries' => null,
      'donor_field_values_exported' => false
    ];
    $walk = function($node, $path = '$', $depth = 0) use (&$walk, &$row, $target, $other): void {
        if($depth > 12) return;
        if (is_array($node)) {
            foreach ($node as $key=>$child) {
                $keyString = (string)$key;
                // Names only; never emit raw values or secrets.
                $safeKey = preg_replace('/[^a-z0-9_:-]/i', '_', substr($keyString, 0, 80));
                if (preg_match('/(?:token|secret|password|nonce|authorization|private|credential)/i',$keyString)) {
                    continue;
                }
                $walk($child, $path.'.'.$safeKey, $depth+1);
            }
        } elseif(is_string($node)) {
            if(stripos($node, $other) !== false){
                $row['foreign_string_fields'][] = $path;
            }
            preg_match_all('~https?://[^\s,<>"\x27()]+~i',$node,$matches);
            foreach($matches[0] as $url) {
                $url = rtrim($url, '.,;]');
                $host = strtolower((string)parse_url($url, PHP_URL_HOST));
                if($host===$target || $host==='www.'.$target) {
                    $row['url_references']['target_domain']++;
                } elseif($host===$other || $host==='www.'.$other) {
                    $row['url_references']['other_company']++;
                } else {
                    $row['url_references']['external_unverified']++;
                }
            }
        }
    };
    $walk($value);
    if ($option === 'look-and-feel' && is_array($value) &&
        is_string($value['review_gellery_slider'] ?? null)) {
        $row['gallery_entries'] = count(array_filter(
            array_map('trim', explode(',', $value['review_gellery_slider'])),
            static fn($v)=>$v!==''
        ));
    }
    $row['foreign_string_fields'] = array_values(array_unique($row['foreign_string_fields']));
    $row['tenant_content_sources_require_review'] =
        count($row['foreign_string_fields']) > 0 ||
        $row['url_references']['other_company'] > 0 ||
        $row['url_references']['external_unverified'] > 0;
    $result['pages'][$option] = $row;
}
$result['release_allowed'] = false;
$result['reason'] = 'Schema registration and tenant-owned data binding require independent release QA';
echo wp_json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT), PHP_EOL;
