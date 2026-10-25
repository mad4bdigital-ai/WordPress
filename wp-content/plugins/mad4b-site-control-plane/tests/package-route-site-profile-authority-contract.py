#!/usr/bin/env python3
"""Regression: package route planning cannot trust independent environment discovery."""
from pathlib import Path
source=(Path(__file__).resolve().parents[1]/"includes"/"class-mad4b-scp-plugin-package.php").read_text()
assert "MAD4B_SCP_Site_Profile::environment_resolution()" in source
assert "'profile_environment_authoritative'" in source
assert "'wordpress_profile_mismatch'" in source
assert "MAD4B_SCP_Package_Route_Resolver::resolve( $provider, $environment, $inventory )" in source
assert "$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';" not in source
assert "$environment = 'unknown';" in source
print("PACKAGE_ROUTE_SITE_PROFILE_AUTHORITY: PASS")
