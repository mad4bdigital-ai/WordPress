from pathlib import Path
import sys
root=Path(sys.argv[1]);api=(root/'wp-content/plugins/mad4b-platform/modules/integrations/visitor-market-passport-api.php').read_text();head=(root/'wp-content/plugins/mad4b-platform/legacy-mu-tree/assets/header-context-controls.js').read_text();prof=(root/'wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-site-profile.php').read_text();doc=(root/'ops/visitor-session-continuity-20261117/README.md').read_text()
checks={
 'existing server-owned country/passport save handler':"MAD4B_Country_Switcher::set_country($code)" in api and "Travel_Booking_Traveller_Nationality_Switcher::choose($code)" in api,
 'uses one audited REST state route':"register_rest_route('visitor-preferences/v1','/state'" in api,
 'uses audited REST apply route':"register_rest_route('visitor-preferences/v1','/apply'" in api,
 'nonce from current WP identity':'wp_create_nonce' in api and 'wp_verify_nonce' in api,
 'POST strictly same-origin':"$_SERVER['HTTP_ORIGIN']" in api and "invalid_origin" in api,
 'POST must be JSON':"'invalid_content_type'" in api,
 'REST responses are not shared cached':"private, no-store, max-age=0" in api,
 'JS uses native server-authoritative API':"fetch(endpoint('apply')" in head,
 'JS fetches fresh nonce state':"fetch(endpoint('state')" in head,
 'no native form reload on JS select change':'nativeForm.requestSubmit' not in head and 'form.submit()' not in head,
 'sync both native desktop/mobile controls':"roots().forEach(root =>" in head and "['market_country',data.country]" in head,
 'native no-JS forms remain server-owned':"form.action" not in head,
 'no duplicated market pricing engine':"visitor-market:revalidate" in head and 'market_profile_id' in api,
 'no country or passport stored in sessionStorage':'sessionStorage.setItem' not in head,
 'browser journey handles only fingerprint reuse':"MAD4BBrowserJourney?.getNonces" in head,
 'first view native Elementor panel accessibility':'if (!panel.id) panel.id' in head,
 'WPML home canonical host anchored':'$stored = self::normalize_origin' in prof and "self::normalize_origin( site_url( '/' ) )" in prof,
 'WPML path suffix anchored to canonical origin':'$prefix = $stored .' in prof and 'str_starts_with( $observed, $prefix )' in prof,
 'only site active languages admitted':'active_languages' in prof and 'hidden_languages' in prof and 'in_array( $localized, $active, true )' in prof,
 'only REST/AJAX locale mismatch admitted':'wp_doing_ajax()' in prof and "defined( 'REST_REQUEST' )" in prof,
 'different host is rejected':'! hash_equals( $expected_host, $request_host )' in prof,
 'deployment remains independently bound':'deployment_binding_match' in prof,
 'company ownership documented':'manifest' in doc and 'Wayfatours' in doc,
 'preimage/reversal documented':'rollback' in doc.lower(),
}
for k,valid in checks.items():
 if not valid:raise SystemExit('FAIL '+k)
print('PASS',len(checks),'/',len(checks),'source-owned no-refresh WPML session contract')
