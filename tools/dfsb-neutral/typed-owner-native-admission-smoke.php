<?php
/**
 * Non-mutating staging acceptance for exact original typed-owner admission.
 * Uses a previously created private, first-party QA Media canary as a target.
 * No test creates rights certificates, grants, ContentJobs or meta fields.
 */
if (!defined('ABSPATH') || !class_exists('MAD4B_SCP_Relationship_Owner_Write_Adapter')
    || !class_exists('MAD4B_SCP_Mutation_Principal')) {
    throw new RuntimeException('original_typed_owner_runtime_missing');
}
if (wp_get_environment_type() !== 'staging' || !current_user_can('manage_options')) {
    throw new RuntimeException('staging_original_admin_required');
}
$qa = get_option('mad4b_cwa_qa_media_canary_20261102v3', []);
$post = (int)($qa['created']['post_fr'] ?? 0);
$source = (int)($qa['created']['post_en'] ?? 0);
if (!$post || !$source || get_post_status($post) !== 'private'
    || get_post_type($post) !== 'elementor_library'
    || get_post_status($source) !== 'private') {
    throw new RuntimeException('original_private_qa_canary_missing');
}
$field = 'mad4b_qa_typed_reference';
$args = [
    'post_id' => $post, 'field' => $field,
    'value' => $source, 'allow_create' => true
];
$jet = MAD4B_SCP_Adapter_Registry::instance()->get('jetengine');
if (!$jet || !$jet->is_available()) {
    throw new RuntimeException('original_jetengine_provider_missing');
}
$wasPresent = metadata_exists('post', $post, $field);
if ($wasPresent) {
    throw new RuntimeException('original_qa_field_must_be_absent');
}
$identity = MAD4B_SCP_Identity_Context::current();
$principal = is_wp_error($identity) ? $identity :
    MAD4B_SCP_Mutation_Principal::resolve($identity, 'jetengine/update-post-meta', $args);
$ability = wp_get_ability('jetengine/update-post-meta');
$abilityPermission = $ability ? $ability->check_permissions($args) : null;
$reversibleState = $jet->capture_reversible_state('jetengine/update-post-meta', $args);
$certificate = MAD4B_SCP_Relationship_Owner_Write_Adapter::certificateValid(
    null,
    [
        'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
        'job_id' => (string)($qa['created']['job_id'] ?? ''),
        'source_post_id' => $source, 'target_post_id' => $post,
        'provider_id' => 'jetengine',
        'original_ability' => 'jetengine/update-post-meta',
        'native_source_sha256' => str_repeat('0', 64),
        'binding_sha256' => str_repeat('1', 64)
    ]
);
$checks = [
    'ordinary_wordpress_actor_does_not_gain_jetengine_principal' =>
        is_wp_error($principal) && $principal->get_error_code() ===
            'mad4b_principal_native_ability_unmapped',
    'uncertified_ability_is_denied' =>
        is_wp_error($abilityPermission),
    'missing_explicit_jetengine_create_policy_is_denied' =>
        is_wp_error($reversibleState) &&
        $reversibleState->get_error_code() === 'mad4b_jetengine_create_policy_denied',
    'missing_independent_owner_certificate_is_denied' => $certificate === false,
    'private_qa_target_meta_remains_unwritten' =>
        !metadata_exists('post', $post, $field),
    'source_and_target_remain_private' =>
        get_post_status($post) === 'private' &&
        get_post_status($source) === 'private'
];
$failed = array_keys(array_filter($checks, static fn($value) => !$value));
echo wp_json_encode([
    'contract' => 'mad4b.typed-owner-native-admission-smoke.v1',
    'checks' => count($checks), 'pass' => !$failed,
    'failed' => $failed, 'cms_mutations' => 0,
    'jetengine_actor_error' => is_wp_error($principal) ?
        $principal->get_error_code() : null,
    'jetengine_policy_error' => is_wp_error($reversibleState) ?
        $reversibleState->get_error_code() : null
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
if ($failed) {
    throw new RuntimeException('typed_owner_native_admission_smoke_failed');
}
