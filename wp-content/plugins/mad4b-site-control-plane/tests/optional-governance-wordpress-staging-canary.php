<?php
/**
 * Staging-only WordPress-native Abilities canary. Run from WP-CLI, as an
 * enrolled Staging administrator, after installing exact candidate code:
 * wp eval-file tests/optional-governance-wordpress-staging-canary.php --url=STAGING_URL --user=ID
 *
 * The canary is intentionally read-only: it inspects the live category
 * registry and Plan/Verify, and passes a deliberately invalid approval token
 * to ensure live WP_Ability::execute refuses a mutation.
 * An actual separately approved MCP Apply is not exercised by this canary.
 */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'wp_get_ability' ) )
    throw new RuntimeException('WordPress Abilities runtime is required.');
$prefix='mad4b/optional-governance-';
if ( ! class_exists('MAD4B_SCP_Site_Profile',false) ||
    'staging' !== (string) MAD4B_SCP_Site_Profile::current_environment() )
    throw new RuntimeException('Canary denied outside Staging.');
$required=array( 'status'=>'mad4b-read', 'plan'=>'mad4b-read',
    'verify'=>'mad4b-read', 'apply'=>'mad4b-write' );
foreach($required as $name=>$category) {
    $ability=wp_get_ability($prefix.$name);
    if(!$ability || $ability->get_category()!==$category)
        throw new RuntimeException('Missing live Ability or wrong category: '.$name);
}
$status=wp_get_ability($prefix.'status')->execute(array());
if(is_wp_error($status) || empty($status['valid']))
    throw new RuntimeException('Cannot read valid policy options from WordPress Ability.');
$policy=$status['gates'][0]['id']??'';
$current=$status['gates'][0]['configured_mode']??'';
if(!is_string($policy) || ''===$policy || !is_string($current))
    throw new RuntimeException('Valid optional policy binding is missing.');
$before=get_option('mad4b_scp_optional_governance_options_v1',null);
$plan=wp_get_ability($prefix.'plan')->execute(array('policy_id'=>$policy,'mode'=>$current));
$verify=wp_get_ability($prefix.'verify')->execute(array('policy_id'=>$policy,'mode'=>$current));
if(is_wp_error($plan) || is_wp_error($verify) || empty($verify['verified']))
    throw new RuntimeException('Plan/Verify live WordPress Abilities did not return read-only evidence.');
$invalid=array(
    'policy_id'=>$policy, 'mode'=>'off', 'expected_plan_sha256'=>str_repeat('0',64),
    'confirmation'=>'INVALID APPROVAL'
);
$result=wp_get_ability($prefix.'apply')->execute($invalid);
$after=get_option('mad4b_scp_optional_governance_options_v1',null);
if(!is_wp_error($result) || $before!==$after)
    throw new RuntimeException('Unapproved WP_Ability::execute mutated Staging option.');
echo "OPTIONAL_GOVERNANCE_STAGING_CANARY PASS: real WordPress registration/read and denied write.\n";
