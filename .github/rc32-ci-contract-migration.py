#!/usr/bin/env python3
from pathlib import Path

ROOT = Path('.')


def text(path):
    return (ROOT / path).read_text(encoding='utf-8')


def write(path, value):
    (ROOT / path).write_text(value, encoding='utf-8')


def replace(path, old, new, expected=1):
    s = text(path)
    found = s.count(old)
    if found != expected:
        raise SystemExit(f'{path}: expected {expected} occurrence(s), found {found}: {old!r}')
    write(path, s.replace(old, new, expected))


def between(path, start, end, replacement):
    s = text(path)
    a = s.find(start)
    if a < 0:
        raise SystemExit(f'{path}: start marker not found: {start!r}')
    b = s.find(end, a + len(start))
    if b < 0:
        raise SystemExit(f'{path}: end marker not found: {end!r}')
    write(path, s[:a] + replacement + s[b:])


# Control Plane package becomes a tenant-neutral General Distribution artifact.
p = '.github/workflows/mad4b-control-plane-package.yml'
replace(
    p,
    "          # The distributable binary is tenant-neutral. This ETG deployment kit is\n          # selected by an explicit reviewed Site Profile preset, not by a hardcoded\n          # runtime-host authority check inside the write authority implementation.\n",
    "          # The distributable binary and public package are tenant-neutral.\n          # Fresh installation must carry no tenant preset and no implicit authority.\n",
)
between(
    p,
    "          python3 - \"$presets\" <<'PY'\n",
    "\n      - name: Build provenance-bound control-plane ZIP and manifest",
    """          python3 - \"$presets\" <<'PY'\n          import json, sys\n          p = json.load(open(sys.argv[1], encoding='utf-8'))\n          if p.get('contract') != 'mad4b.site-profile-presets.v2':\n              raise SystemExit('site profile preset contract mismatch')\n          profiles = p.get('profiles', [])\n          if not isinstance(profiles, list) or profiles:\n              raise SystemExit('public General Distribution package must not bundle tenant presets')\n          print('tenant-neutral empty public preset catalog: PASS')\n          PY\n""",
)
replace(p, "artifact_identity = f'mad4b-site-control-plane-staging-kit-{source}'", "artifact_identity = f'mad4b-site-control-plane-general-distribution-kit-{source}'")
replace(p, "'contract': 'mad4b.site-control-plane.staging-install-kit.v5'", "'contract': 'mad4b.site-control-plane.general-distribution-kit.v1'")
replace(p, "'release_class': 'staging-governed-write-release-candidate'", "'release_class': 'general-distribution-release-candidate'")
replace(p, "'governed_origin': 'staging.egypttourgates.com',\n              'tenant_binding_source': 'reviewed_site_profile_preset',", "'tenant_binding_required': True,\n              'tenant_binding_source': 'explicit_site_profile_enrollment',")
replace(p, "'governed_staging_write_authority': True,", "'zero_authority_fresh_install': True,\n                  'empty_tenant_presets': True,\n                  'explicit_site_profile_enrollment_required': True,")
replace(p, "'staging_exact_origin_mutation_autoconfig': True,", "'install_grants_authority': False,")
replace(p, "              'required_post_install_checks': [\n                  'mad4b/runtime-self-test',", "              'required_post_install_checks': [\n                  'mad4b/site-profile-status',\n                  'mad4b/runtime-self-test',")
between(
    p,
    "          cat > \"$stage/INSTALL-STAGING.md\" <<'EOF'\n",
    "\n      - name: Verify package boundaries, provenance and checksums",
    """          cat > \"$stage/INSTALL-GENERAL-DISTRIBUTION.md\" <<'EOF'\n          # MAD4B Site Control Plane — General Distribution installation kit\n\n          1. Install and activate the exact `mcp-adapter-0.6.1.zip` first.\n          2. Install and activate the provenance-bound MAD4B Site Control Plane ZIP. Fresh installation is deliberately zero-authority: installation does not enroll a tenant, enable OAuth, enable Skills, grant write authority, or enable Production write.\n          3. Read `mad4b/build-provenance-status` and require `manifest_present=true`, `manifest_valid=true`, `runtime_manifest_match=true`, and `stale=false`.\n          4. Explicitly enroll the current WordPress site through Site Profile v2. The enrolled canonical origin and environment must match the live site. Public package presets are empty.\n          5. Enable only the features required by this tenant. OAuth identity, Skills, provider isolation, managed runtime, acceptance, and governed write remain separate profile features.\n          6. Production write remains disabled unless the Production Site Profile is explicitly enrolled for write and the separate `ENABLE GOVERNED PRODUCTION WRITE` confirmation is supplied.\n          7. Governed writes still require the normal NHI, exact grant, provider certification where applicable, mutation budget, audit availability, exact one-time approval, and execution fence. Breakglass and Raw SQL are never part of the normal MCP write surface.\n          8. Read `mad4b/write-authority-status` and `mad4b/write-runtime-certification` after enrollment. Provider-blocked writes must remain unmounted until their exact capability certification passes.\n          9. Deployment-specific live acceptance, including external WPML compatibility where required, is performed against that deployment's explicitly enrolled origin. ETG is one deployment target, not Core identity.\n          10. Compare the portable ChatGPT package `MAD4B-SNAPSHOT-ID.txt` with `mad4b/skills-export-status` before external snapshot acceptance, then Refresh/Scan Tools in ChatGPT.\n\n          Production mutation, Breakglass, Raw SQL, Ready for Review, and merge are not authorized by this package.\n          EOF\n""",
)
replace(p, "print('provenance-bound staging kit: PASS')", "print('provenance-bound General Distribution kit: PASS')")
replace(p, "grep -Fq '\"contract\": \"mad4b.site-control-plane.staging-install-kit.v5\"' \"$stage/install-manifest.json\"", "grep -Fq '\"contract\": \"mad4b.site-control-plane.general-distribution-kit.v1\"' \"$stage/install-manifest.json\"")
replace(p, "grep -Fq '\"release_class\": \"staging-governed-write-release-candidate\"' \"$stage/install-manifest.json\"", "grep -Fq '\"release_class\": \"general-distribution-release-candidate\"' \"$stage/install-manifest.json\"")
replace(p, "grep -Fq '\"tenant_binding_source\": \"reviewed_site_profile_preset\"' \"$stage/install-manifest.json\"", "grep -Fq '\"tenant_binding_source\": \"explicit_site_profile_enrollment\"' \"$stage/install-manifest.json\"")
replace(p, "grep -Fq '\"governed_staging_write_authority\": true' \"$stage/install-manifest.json\"", "grep -Fq '\"zero_authority_fresh_install\": true' \"$stage/install-manifest.json\"\n          grep -Fq '\"empty_tenant_presets\": true' \"$stage/install-manifest.json\"\n          grep -Fq '\"explicit_site_profile_enrollment_required\": true' \"$stage/install-manifest.json\"")
replace(p, "      - name: Upload reviewed staging installation kit", "      - name: Upload reviewed General Distribution installation kit")
replace(p, "name: mad4b-site-control-plane-staging-kit-${{ env.SOURCE_SHA }}", "name: mad4b-site-control-plane-general-distribution-kit-${{ env.SOURCE_SHA }}")
replace(p, "/tmp/mad4b-install-kit/INSTALL-STAGING.md", "/tmp/mad4b-install-kit/INSTALL-GENERAL-DISTRIBUTION.md")

# Pure/static contracts assert Site Profile v2/profile-driven semantics.
replace(
    'wp-content/plugins/mad4b-site-control-plane/tests/spec-consistency-contract.py',
    "    'const CONTRACT = \\'mad4b.site-profile.v1\\'',",
    "    'const CONTRACT = \\'mad4b.site-profile.v2\\'',",
)
replace(
    '.github/workflows/mad4b-admin-performance.yml',
    '              "const STAGING_HOST = \'staging.egypttourgates.com\'",',
    '              "MAD4B_SCP_Site_Profile::nonproduction_governed( \'managed_runtime\' )",',
)

ds = 'wp-content/plugins/mad4b-site-control-plane/tests/dynamic-skills-contract.py'
replace(ds, '    "\'staging\' !== $environment",', '    "! in_array( $environment, array( \'local\', \'development\', \'staging\', \'production\' ), true )",', 1)
replace(ds, '    "\'staging\' !== $environment",', '    "MAD4B_SCP_Site_Profile::origin_enrolled()",', 1)
replace(ds, '    "\'staging\' !== $environment",', '    "MAD4B_SCP_Site_Profile::origin_enrolled()",', 1)

replace(
    'wp-content/plugins/mad4b-site-control-plane/tests/live-acceptance-evidence-contract.py',
    "    \"const STAGING_HOST = 'staging.egypttourgates.com'\",",
    "    \"MAD4B_SCP_Site_Profile::nonproduction_governed( 'acceptance' )\",\n    \"MAD4B_SCP_Site_Profile::site_urls_match_enrollment()\",",
)
replace(
    'wp-content/plugins/mad4b-site-control-plane/tests/live-acceptance-reconciler-contract.py',
    "    \"const STAGING_HOST = 'staging.egypttourgates.com'\",",
    "    \"MAD4B_SCP_Site_Profile::site_urls_match_enrollment()\",\n    \"MAD4B_SCP_Site_Profile::skills_enabled()\",",
)

iso = 'wp-content/plugins/mad4b-site-control-plane/tests/mcp-provider-isolation-contract.py'
replace(iso, "require(isolation, \"const STAGING_HOST = 'staging.egypttourgates.com'\", 'exact-staging-origin')", "require(isolation, 'MAD4B_SCP_Site_Profile::origin_enrolled()', 'explicit-site-profile-enrollment')")
replace(iso, "require(isolation, \"'staging' !== $environment || self::STAGING_HOST !== $host\", 'exact-origin-auto-config-guard')", "require(isolation, 'MAD4B_SCP_Site_Profile::provider_isolation_enabled()', 'profile-provider-isolation-gate')\nrequire(isolation, 'MAD4B_SCP_Site_Profile::current_environment()', 'profile-environment-binding')")

# Standalone provider fixtures explicitly model governed non-production write.
behavioral = 'wp-content/plugins/mad4b-site-control-plane/tests/provider-behavioral-recertification-runtime.php'
replace(
    behavioral,
    "class MAD4B_SCP_Policy { public static function can_mutate() { return true; } }\nclass MAD4B_SCP_Staging_Write_Authority {",
    "class MAD4B_SCP_Policy { public static function can_mutate() { return true; } }\nclass MAD4B_SCP_Site_Profile { public static function nonproduction_governed( $feature = '' ) { return 'write' === (string) $feature; } }\nclass MAD4B_SCP_Staging_Write_Authority {",
)

canary = 'wp-content/plugins/mad4b-site-control-plane/tests/provider-canary-execution-contract.php'
replace(canary, "function home_url( $path = '/' ) { return 'https://staging.egypttourgates.com' . $path; }", "function home_url( $path = '/' ) { return 'https://provider-canary.test' . $path; }")
replace(
    canary,
    "final class MAD4B_SCP_Policy {\n\tpublic static function can_mutate() { return ! empty( $GLOBALS['mad4b_policy_mutate'] ); }\n}\nfinal class MAD4B_SCP_Staging_Write_Authority {",
    "final class MAD4B_SCP_Policy {\n\tpublic static function can_mutate() { return ! empty( $GLOBALS['mad4b_policy_mutate'] ); }\n}\nfinal class MAD4B_SCP_Site_Profile {\n\tpublic static function nonproduction_governed( $feature = '' ) { return 'write' === (string) $feature; }\n}\nfinal class MAD4B_SCP_Staging_Write_Authority {",
)

# Durable acceptance is tenant-neutral and bound to current Site Profile.
fence = 'wp-content/plugins/mad4b-site-control-plane/tests/runtime-mcp-governed-execution-fence-reconciler.php'
start = "$acceptance = MAD4B_SCP_Live_Acceptance_Reconciler::mutation_acceptance_status();\n$current_origin ="
end = "\n\n// The real MCP cycle above proves"
s = text(fence)
a = s.find(start)
b = s.find(end, a)
if a < 0 or b < 0:
    raise SystemExit(f'{fence}: stale tenant-specific acceptance branch not found')
replacement = """$acceptance = MAD4B_SCP_Live_Acceptance_Reconciler::mutation_acceptance_status();
$check( is_array( $acceptance ) && ! empty( $acceptance['ready'] ), 'generic enrolled Site Profile did not reconstruct mutation_acceptance.ready=true: ' . wp_json_encode( $acceptance ) );
$check( 'durable_authoritative_reconstruction' === ( isset( $acceptance['evidence_source'] ) ? (string) $acceptance['evidence_source'] : '' ), 'generic Site Profile acceptance did not use durable authoritative reconstruction: ' . wp_json_encode( $acceptance ) );
$check( hash_equals( $mutation_id, strtolower( (string) ( isset( $acceptance['mutation_id'] ) ? $acceptance['mutation_id'] : '' ) ) ), 'reconstructed acceptance is not bound to the exact real MCP mutation' );
$check( hash_equals( strtolower( (string) $ticket['ticket_id'] ), strtolower( (string) ( isset( $acceptance['approval_ticket_id'] ) ? $acceptance['approval_ticket_id'] : '' ) ) ), 'reconstructed acceptance is not bound to the exact execution ticket' );
$check( hash_equals( strtolower( (string) $undo_ticket['ticket_id'] ), strtolower( (string) ( isset( $acceptance['undo_approval_ticket_id'] ) ? $acceptance['undo_approval_ticket_id'] : '' ) ) ), 'reconstructed acceptance is not bound to the exact undo ticket' );
$check( empty( $acceptance['first_reconstruction_failure'] ), 'ready durable reconstruction retained a failure marker: ' . wp_json_encode( $acceptance ) );"""
write(fence, s[:a] + replacement + s[b:])

observer_runtime = 'wp-content/plugins/mad4b-site-control-plane/tests/live-acceptance-observer-runtime.php'
replace(
    observer_runtime,
    "\tclass MAD4B_SCP_Servers {",
    "\tclass MAD4B_SCP_Site_Profile {\n\t\tpublic static function current_environment() { return $GLOBALS['mad4b_test_env']; }\n\t\tpublic static function site_origin() { return rtrim( $GLOBALS['mad4b_test_home'], '/' ); }\n\t\tpublic static function site_host() { return (string) parse_url( self::site_origin(), PHP_URL_HOST ); }\n\t\tpublic static function nonproduction_governed( $feature = '' ) { return in_array( self::current_environment(), array( 'local', 'development', 'staging' ), true ) && ( '' === $feature || 'acceptance' === $feature ); }\n\t\tpublic static function site_urls_match_enrollment() { return true; }\n\t}\n\tclass MAD4B_SCP_Servers {",
)
replace(observer_runtime, "'environment' => 'staging', 'origin' => MAD4B_SCP_Live_Acceptance_Finalizer::STAGING_ORIGIN,", "'environment' => 'staging', 'origin' => MAD4B_SCP_Site_Profile::site_origin(),")

# Browser canary tests follow governed non-production semantics.
oauth_contract = 'wp-content/plugins/mad4b-site-control-plane/tests/local-oauth-browser-canary-contract.py'
replace(oauth_contract, "    'mad4b-staging-browser-canary',", "    'mad4b-governed-browser-canary',")
replace(oauth_contract, "    'mad4b-staging-canary',", "    'mad4b-governed-canary',")
replace(oauth_contract, "    \"'staging_only' => true\",", "    \"'governed_nonproduction_only' => true\",\n    \"MAD4B_SCP_Site_Profile::nonproduction_governed( 'oauth' )\",\n    \"MAD4B_SCP_Site_Profile::site_urls_match_enrollment()\",")
replace(oauth_contract, "    'Canary configuration is intentionally unavailable outside Staging.',", "    'Canary configuration is available only on an explicitly enrolled local, development, or staging site with OAuth enabled.',")
replace(oauth_contract, "    \"if ( 'staging' !== $status['environment'] ) return;\",\n", "")
replace(oauth_contract, "    \"[string]$ClientId = 'mad4b-staging-canary'\",", "    \"[string]$ClientId = 'mad4b-governed-canary'\",")

oauth_smoke = 'wp-content/plugins/mad4b-site-control-plane/tests/runtime-local-oauth-browser-canary-smoke.php'
replace(oauth_smoke, "'client_name' => 'MAD4B Staging Browser Canary'", "'client_name' => 'MAD4B Governed Browser Canary'")
replace(oauth_smoke, "if ( 'staging' !== $status['environment'] || empty( $status['staging_only'] ) ) exit( 1 );", "if ( 'staging' !== $status['environment'] || empty( $status['governed_nonproduction_only'] ) || empty( $status['target_eligible'] ) ) exit( 1 );")
replace(oauth_smoke, "'mad4b-staging-browser-canary'", "'mad4b-governed-browser-canary'")

oauth_prod = 'wp-content/plugins/mad4b-site-control-plane/tests/runtime-local-oauth-browser-canary-production-smoke.php'
replace(oauth_prod, "if ( empty( $status['staging_only'] ) || ! empty( $status['can_run'] ) ) exit( 1 );", "if ( empty( $status['governed_nonproduction_only'] ) || ! empty( $status['target_eligible'] ) || ! empty( $status['can_run'] ) ) exit( 1 );")
replace(oauth_prod, "if ( false === strpos( $html, 'Canary configuration is intentionally unavailable outside Staging.' ) ) exit( 1 );", "if ( false === strpos( $html, 'Canary configuration is available only on an explicitly enrolled local, development, or staging site with OAuth enabled.' ) ) exit( 1 );")
replace(oauth_prod, "'mad4b-staging-browser-canary', 'mad4b-staging-canary'", "'mad4b-governed-browser-canary', 'mad4b-governed-canary'")

# Runtime workflows explicitly enroll disposable generic sites.
web = '.github/workflows/mad4b-web-mcp-lifecycle.yml'
replace(web, "          php /tmp/wp-cli.phar core install --path=\"$WP_PATH\" --url='http://mad4b-web.test' --title='MAD4B Web MCP Lifecycle' --admin_user='mad4b-ci-admin' --admin_password='ci-only-not-a-secret' --admin_email='ci@example.invalid' --skip-email", "          php /tmp/wp-cli.phar core install --path=\"$WP_PATH\" --url='https://mad4b-web.test' --title='MAD4B Web MCP Lifecycle' --admin_user='mad4b-ci-admin' --admin_password='ci-only-not-a-secret' --admin_email='ci@example.invalid' --skip-email\n          php /tmp/wp-cli.phar config set WP_ENVIRONMENT_TYPE staging --type=constant --path=\"$WP_PATH\"")
replace(web, "          php /tmp/wp-cli.phar plugin activate mad4b-site-control-plane --path=\"$WP_PATH\"\n", "          php /tmp/wp-cli.phar plugin activate mad4b-site-control-plane --path=\"$WP_PATH\"\n          php /tmp/wp-cli.phar eval '\n          $result = MAD4B_SCP_Site_Profile::save_current_site( array(\n              \"expected_revision\" => 0,\n              \"display_name\" => \"MAD4B Web MCP Lifecycle\",\n              \"oauth_user_ids\" => array( get_current_user_id() ),\n              \"oauth_enabled\" => false, \"skills_enabled\" => false, \"write_enabled\" => false,\n              \"production_write_confirmed\" => false, \"provider_isolation_enabled\" => false,\n              \"managed_runtime_enabled\" => true, \"acceptance_enabled\" => false,\n          ) );\n          if ( is_wp_error( $result ) ) throw new RuntimeException( $result->get_error_code() . \":\" . $result->get_error_message() );\n          ' --path=\"$WP_PATH\" --user=mad4b-ci-admin\n", 1)
between(web, "          # Set the environment in wp-config without loading WordPress.\n", "\n      - name: Prove first web request refreshes stale managed MU bytes", "")

meta = '.github/workflows/mad4b-mcp-adapter-metadata-bridge.yml'
replace(meta, "      - name: Provision exact governed Staging fixture", "      - name: Provision explicitly enrolled generic staging fixture")
replace(meta, "--url='https://staging.egypttourgates.com'", "--url='https://mad4b-metadata.test'")
replace(meta, "          php /tmp/wp-cli.phar plugin activate mad4b-site-control-plane --path=\"$WP_PATH\"\n", "          php /tmp/wp-cli.phar plugin activate mad4b-site-control-plane --path=\"$WP_PATH\"\n          php /tmp/wp-cli.phar eval '\n          $result = MAD4B_SCP_Site_Profile::save_current_site( array(\n              \"expected_revision\" => 0, \"display_name\" => \"MAD4B Metadata Bridge\",\n              \"oauth_user_ids\" => array( get_current_user_id() ),\n              \"oauth_enabled\" => false, \"skills_enabled\" => false, \"write_enabled\" => false,\n              \"production_write_confirmed\" => false, \"provider_isolation_enabled\" => false,\n              \"managed_runtime_enabled\" => true, \"acceptance_enabled\" => false,\n          ) );\n          if ( is_wp_error( $result ) ) throw new RuntimeException( $result->get_error_code() . \":\" . $result->get_error_message() );\n          ' --path=\"$WP_PATH\" --user=mad4b-ci-admin\n", 1)
replace(meta, '              "const STAGING_HOST = \'staging.egypttourgates.com\'",', '              "MAD4B_SCP_Site_Profile::nonproduction_governed( \'managed_runtime\' )",')

conn = '.github/workflows/mad4b-connection-governance.yml'
replace(conn, "      - uses: actions/checkout@v4", "      - uses: actions/checkout@v4\n        with:\n          ref: ${{ github.event.pull_request.head.sha || github.sha }}", 2)
replace(conn, "--url='http://mad4b-connection.test'", "--url='https://mad4b-connection.test'")
old_block = """          # Move the disposable site onto the exact Staging origin without a
          # WordPress bootstrap. This guarantees the next eval-file is the
          # first governed Staging request after the preloader exists.
          mysql --protocol=tcp -h127.0.0.1 -P3306 -uwordpress -pwordpress wordpress \
            -e \"UPDATE wp_options SET option_value='https://staging.egypttourgates.com' WHERE option_name IN ('home','siteurl');\"
          staging_count=\"$(mysql --protocol=tcp -N -B -h127.0.0.1 -P3306 -uwordpress -pwordpress wordpress -e \"SELECT COUNT(*) FROM wp_options WHERE option_name IN ('home','siteurl') AND option_value='https://staging.egypttourgates.com';\")\"
          test \"$staging_count\" = \"2\"

"""
new_block = """          # Enroll the generic HTTPS fixture after the foreign preloader exists.
          # This request observes the unconfigured profile before save; the next
          # request remains the first governed repair opportunity.
          php /tmp/wp-cli.phar eval '
          $result = MAD4B_SCP_Site_Profile::save_current_site( array(
              \"expected_revision\" => 0, \"display_name\" => \"MAD4B Connection Governance\",
              \"oauth_user_ids\" => array( get_current_user_id() ),
              \"oauth_enabled\" => false, \"skills_enabled\" => false, \"write_enabled\" => false,
              \"production_write_confirmed\" => false, \"provider_isolation_enabled\" => false,
              \"managed_runtime_enabled\" => true, \"acceptance_enabled\" => false,
          ) );
          if ( is_wp_error( $result ) ) throw new RuntimeException( $result->get_error_code() . \":\" . $result->get_error_message() );
          ' --path=\"$WP_PATH\" --user=mad4b-ci-admin

"""
replace(conn, old_block, new_block)
replace(conn, "Prove Hostinger pre-active-plugin Adapter collision self-heals on exact Staging origin", "Prove Hostinger pre-active-plugin Adapter collision self-heals on enrolled generic staging origin")

replace('.github/workflows/mad4b-governed-execution-fence.yml', "artifact_identity': f'mad4b-site-control-plane-staging-kit-{source}'", "artifact_identity': f'mad4b-site-control-plane-general-distribution-kit-{source}'")

# Temporary migration machinery must not survive final tree.
Path('.github/workflows/rc32-ci-contract-migration.yml').unlink()
Path('.github/rc32-ci-contract-migration.py').unlink()
