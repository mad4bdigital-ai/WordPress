<?php
declare(strict_types=1);
/** Isolated never-mutating gate tests, no WordPress database. */
define('ABSPATH','/isolated-lab/');
class WP_Error {
    public function __construct(public string $code, public string $message) {}
    public function get_error_code() { return $this->code; }
}
function is_wp_error($v): bool { return $v instanceof WP_Error; }
function sanitize_key($x): string { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$x)); }
function absint($x): int { return abs((int)$x); }
function current_user_can($cap): bool { return $cap === 'manage_options'; }
function get_post_type_object($name) { return null; }
class MAD4B_SCP_Site_Profile {
    public static function status() { return ['environment'=>'local']; }
    public static function write_enabled() { return false; }
    public static function origin_enrolled() { return true; }
}
class MAD4B_SCP_Policy {
    public static function mutation_gate_status() { return ['effective'=>false]; }
}
class MAD4B_SCP_Provider_Contracts {
    public static function get($provider) { return false; }
}
class MAD4B_SCP_Provider_Compatibility_Certification {
    public static function supports_provider($provider) { return false; }
}
class MAD4B_SCP_Audit {
    public static function storage_status() { return ['ready'=>true]; }
}
require dirname(__DIR__) . '/includes/adapters/class-mad4b-scp-chatbotx-write-preflight.php';
$cases = [
    ['mad4b/chatbotx-support-create-ticket', ['customer_id'=>11,'mailbox_id'=>12]],
    ['mad4b/chatbotx-support-add-note', ['ticket_id'=>16]],
    ['mad4b/chatbotx-cpt-create-draft', ['post_type'=>'tours-and-activities']],
];
foreach ($cases as [$ability,$args]) {
    $result = MAD4B_SCP_ChatbotX_Write_Preflight::assess(['ability'=>$ability]+$args);
    if (!is_array($result) || !in_array('site_profile_write_feature_disabled',$result['blockers'],true)
      || !in_array('central_mutation_authority_not_effective',$result['blockers'],true)
      || !in_array('provider_write_certification_missing',$result['blockers'],true)
      || !in_array('enroll_exact_staging_or_production_origin_before_write_enable',$result['blockers'],true)
      || $result['approved_for_execution'] || !$result['no_mutation_performed']) {
        fwrite(STDERR,"FAIL: {$ability} did not fail closed
");exit(1);
    }
    echo "PASS: {$ability} resolves exact immutable local authority blockers
";
}
$invalid = MAD4B_SCP_ChatbotX_Write_Preflight::assess(['ability'=>'mad4b/other-write']);
if (!is_wp_error($invalid) || $invalid->get_error_code() !== 'cmb_write_preflight_invalid_ability') {
    fwrite(STDERR,"FAIL: unrecognized operation not denied
");exit(1);
}
echo "PASS: unknown ability rejected and no mutator called
";
