#!/usr/bin/env python3
"""Root-cycle guard: site Post/Readback and Production independent observation."""
from pathlib import Path
root=Path("wp-content/plugins/mad4b-site-control-plane")
native=(root/"includes/class-mad4b-scp-deployment-binding-native.php").read_text()
view=(root/"includes/class-mad4b-scp-operational-remediation.php").read_text()
for s in (
    "const PRODUCTION_STATUS = 'mad4b/deployment-binding-production-status'",
    "public static function production_status( $input = array() )",
    "'production' !== (string) ( $site['configured_environment']",
    "'production' !== (string) ( $site['wordpress_environment']",
    "production_mutation_authorized",
    "'PRODUCTION_HOST_BINDING_VERIFIED'",
    "'PRODUCTION_HOST_BINDING_MISMATCH'",
    "'PRODUCTION_HOST_BINDING_NOT_ENROLLED'",
    "'POSTCOMMIT_RECONCILIATION_REQUIRED' === $result_state",
    "$state = 'reconciliation_required'",
    "$state = 'blocked'",
    "$code = 'unexpected_setup_result'",
    "'BOUND_NEW_REQUEST_READBACK_REQUIRED' === $result_state",
    "'mad4b_native_host_setup' => $state",
    "'page' => 'mad4b-operational-remediation'",
    "new_request_readback_input",
    "separate_http_request_required",
    "return self::commit_new_binding( $snap, $audit_action, true )",
):
    assert s in native, "missing fail-closed postcommit/production state: "+s
assert "admin_url( 'plugins.php' )" not in native[native.index("public static function handle_wordpress_setup()"):native.index("private static function wordpress_update_bootstrap()")], "obsolete redirect hides independent readback"
prod=native[native.index("public static function production_status("):native.index("Explicit second-request acceptance")]
for s in ("self::provision(", "self::commit_new_binding(", "update_option(", "MAD4B_SCP_Site_Profile::commit_record_with_audit("):
    assert s not in prod, "Production status cannot mutate host or database: "+s
for s in (
    "MAD4B_SCP_Deployment_Binding_Native::readback( array(",
    "'WORDPRESS_BINDING_READBACK_PASS' === $proof_state",
    "mad4b/staging-write-authority-convergence-handshake",
    "'mad4b_native_host_setup'",
    "'PRODUCTION_HOST_BINDING_VERIFIED'",
):
    assert s in view or s in native, "operational UI lacks truthful state: "+s
assert "Legacy wp-config Host secret is not configured" in view
assert "one verified local setup" in view
assert "class-mad4b-scp-deployment-binding-native.php" not in str(root/"tests")
print("POST_SETUP_PRODUCTION_READ_ONLY_LIFECYCLE_CONTRACT: PASS")
