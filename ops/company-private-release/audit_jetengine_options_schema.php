<?php
/**
 * Inspects authoritative JetEngine Options Page definitions.
 * Never exports saved option values, field defaults, help text containing
 * tenant values, API credentials, media URLs, or option-page content.
 * WordPress CLI only. No database mutation.
 */
if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('WP-CLI only');
}
global $wpdb;
$domain=strtolower((string)getenv('MAD4B_EXPECTED_TENANT_DOMAIN'));
if(!in_array($domain,['allroyalegypt.com','egypttourgates.com'],true)) {
    throw new RuntimeException('Unknown target tenant');
}
$table=$wpdb->prefix.'jet_post_types';
$exists=$wpdb->get_var($wpdb->prepare(
    'SELECT table_name FROM information_schema.tables WHERE table_schema=%s AND table_name=%s',
    DB_NAME,$table
));
if($exists!==$table) throw new RuntimeException('JetEngine schema table absent');
$rows=$wpdb->get_results(
    "SELECT id, slug, status, labels, args, meta_fields FROM {$table} WHERE status='page' ORDER BY slug",
    ARRAY_A
);
if($wpdb->last_error) throw new RuntimeException('Schema query failed');
$out=[
    'contract'=>'mad4b.jetengine.options-page-schema-audit.v1',
    'tenant'=>$domain,
    'read_only'=>true,
    'schema_source_table'=>$table,
    'stored_option_values_exported'=>false,
    'field_defaults_exported'=>false,
    'pages'=>[]
];
foreach($rows as $row){
    $slug=(string)$row['slug'];
    $args=maybe_unserialize($row['args']??'');
    $labels=maybe_unserialize($row['labels']??'');
    $raw=maybe_unserialize($row['meta_fields']??'');
    $fields=[];
    if(is_array($raw))foreach($raw as $f){
        if(!is_array($f))continue;
        $key=(string)($f['name']??'');
        if($key==='' || !preg_match('/^[A-Za-z0-9_-]{1,128}$/',$key)) {
            throw new RuntimeException('Unsafe/unknown field key in schema');
        }
        $fields[]=[
            'name'=>$key,
            'type'=>(string)($f['type']??'unknown'),
            'has_conditional_logic'=>isset($f['conditional_logic']),
            'has_repeater_fields'=>isset($f['repeater-fields']) && is_array($f['repeater-fields']),
            'default_value_exported'=>false
        ];
    }
    $out['pages'][$slug]=[
        'local_id_present'=>(int)$row['id']>0,
        'label'=>is_array($labels)?(string)($labels['name']??$slug):$slug,
        'field_count'=>count($fields),
        'fields'=>$fields,
        'storage_type'=>is_array($args)?($args['storage_type']??null):null,
        'definition_located'=>true,
        'saved_content_ownership'=>'tenant_only',
        'auto_copy_donor_values'=>false
    ];
}
$out['mutation_performed']=false;
$out['release_allowed']=false;
echo wp_json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),PHP_EOL;
