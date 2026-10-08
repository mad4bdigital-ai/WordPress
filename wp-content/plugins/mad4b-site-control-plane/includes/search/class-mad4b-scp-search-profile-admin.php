<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Local business configuration; saving never captures evidence or grants spend. */
final class MAD4B_SCP_Search_Profile_Admin {
	const ACTION = 'mad4b_search_profile_save';

	public static function boot() { add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'post' ) ); }

	public static function profiles() {
		$row = MAD4B_SCP_Search_Store::read( 'registry', 'profiles' );
		if ( is_wp_error( $row ) ) return $row;
		$out = array();
		foreach ( is_array( $row ) && isset( $row['ids'] ) ? array_slice( $row['ids'], 0, 64 ) : array() as $id ) {
			$p = MAD4B_SCP_Search_Context::profile( $id );
			if ( ! is_wp_error( $p ) ) $out[ $id ] = $p;
		}
		return $out;
	}

	/** Keep invalid explicit selections visible; only an omitted selection defaults. */
	public static function selected_id() {
		$id = MAD4B_SCP_Admin_Experience::query_string( 'profile_id', '', 96 );
		if ( isset( $_GET['profile_id'] ) ) return MAD4B_SCP_Search_Contracts::id( $id ) ? $id : '';
		$profiles = self::profiles();
		return is_array( $profiles ) && $profiles ? (string) key( $profiles ) : '';
	}

	public static function save( array $input ) {
		if ( ! MAD4B_SCP_Search_Runtime::can_configure() ) return MAD4B_SCP_Search_Contracts::error( 'configuration_unauthorized' );
		foreach ( array( 'operation', 'profile_id', 'expected_revision' ) as $key ) if ( ! isset( $input[ $key ] ) || ! is_string( $input[ $key ] ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
		if ( ! preg_match( '/^(0|[1-9][0-9]{0,8})$/D', $input['expected_revision'] ) || ( '' !== $input['profile_id'] && ! MAD4B_SCP_Search_Contracts::id( $input['profile_id'] ) ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
		$revision = (int) $input['expected_revision'];
		if ( 'create' === $input['operation'] ) {
			if ( 0 !== $revision ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
			foreach ( array( 'brand_id', 'market_id', 'market_country' ) as $field ) if ( ! isset( $input[ $field ] ) || ! is_string( $input[ $field ] ) || strlen( $input[ $field ] ) > 2048 ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
			if ( ! array_key_exists( 'engines', $input ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
			$market_country = strtoupper( trim( $input['market_country'] ) );
			if ( ! preg_match( '/^[A-Z]{2}$/D', $market_country ) || ( '' !== $input['brand_id'] && ! MAD4B_SCP_Search_Contracts::id( $input['brand_id'] ) ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
			// The operator's audience country, not the WordPress timezone, owns these identifiers.
			$profile_id = $input['profile_id']; $market_id = $input['market_id'];
			if ( '' === $profile_id ) {
				$existing = self::profiles();
				if ( is_wp_error( $existing ) ) return $existing;
				$base = 'search-' . strtolower( $market_country );
				for ( $i = 1; $i <= 100; $i++ ) {
					$option = 1 === $i ? $base : $base . '-' . $i;
					if ( ! isset( $existing[ $option ] ) ) { $profile_id = $option; break; }
				}
				if ( '' === $profile_id ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
			}
			if ( '' === $market_id ) $market_id = 'market-' . strtolower( $market_country );
			if ( ! MAD4B_SCP_Search_Contracts::id( $profile_id ) || ! MAD4B_SCP_Search_Contracts::id( $market_id ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
			$providers = isset( $input['providers'] ) ? $input['providers'] : array();
			if ( ! is_array( $providers ) || count( $providers ) > 64 ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
			$adapters = MAD4B_SCP_Search_Providers::adapters();
			foreach ( $providers as $provider ) if ( ! is_string( $provider ) || ! isset( $adapters[ $provider ] ) ) return MAD4B_SCP_Search_Contracts::error( 'provider_id_invalid' );
			$languages = self::list_input( isset( $input['languages'] ) ? $input['languages'] : array() );
			$extra = self::list_input( isset( $input['additional_languages'] ) ? $input['additional_languages'] : '' );
			$engines = self::list_input( $input['engines'] );
			if ( is_wp_error( $languages ) || is_wp_error( $extra ) || is_wp_error( $engines ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
			$languages = array_values( array_unique( array_merge( $languages, $extra ) ) );
			if ( ! $languages || ! $engines || count( $languages ) > 64 ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
			$raw = array( 'profile_id' => $profile_id, 'brand_id' => $input['brand_id'], 'enabled' => false, 'markets' => array( array( 'id' => $market_id, 'country' => $market_country ) ), 'language_policy' => array( 'desired' => $languages ), 'provider_policy' => array( 'allowed' => array_values( array_unique( $providers ) ), 'engines' => $engines, 'freeze_spend' => true ) );
		} elseif ( 'edit' === $input['operation'] ) {
			if ( 0 === $revision || '' === $input['profile_id'] || ! isset( $input['profile_json'] ) || ! is_string( $input['profile_json'] ) || strlen( $input['profile_json'] ) > 65536 ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
			$raw = json_decode( $input['profile_json'], true, 32 );
			if ( ! is_array( $raw ) || ! isset( $raw['profile_id'] ) || $raw['profile_id'] !== $input['profile_id'] ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
			$current = MAD4B_SCP_Search_Context::profile( $input['profile_id'] );
			if ( is_wp_error( $current ) ) return $current;
			$current_enabled = ! empty( $current['enabled'] );
			$current_freeze_spend = ! empty( $current['provider_policy']['freeze_spend'] );
			if ( array_key_exists( 'enabled', $raw ) && (bool) $raw['enabled'] !== $current_enabled ) return MAD4B_SCP_Search_Contracts::error( 'profile_state_requires_explicit_control' );
			if ( ! isset( $raw['provider_policy'] ) || ! is_array( $raw['provider_policy'] ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
			if ( array_key_exists( 'freeze_spend', $raw['provider_policy'] ) && (bool) $raw['provider_policy']['freeze_spend'] !== $current_freeze_spend ) return MAD4B_SCP_Search_Contracts::error( 'profile_state_requires_explicit_control' );
			// Generic policy JSON cannot resume observations or unfreeze spend. Those
			// state transitions use dedicated controls with their own operator intent
			// and exact post-apply readback.
			$raw['enabled'] = $current_enabled;
			$raw['provider_policy']['freeze_spend'] = $current_freeze_spend;
		} elseif ( 'edit_guided' === $input['operation'] ) {
			if ( 0 === $revision || '' === $input['profile_id'] ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
			foreach ( array( 'audience_country', 'audience_languages', 'audience_devices', 'objective' ) as $field ) {
				if ( ! isset( $input[ $field ] ) || ! is_string( $input[ $field ] ) || strlen( $input[ $field ] ) > 2048 ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
			}
			$current = MAD4B_SCP_Search_Context::profile( $input['profile_id'] );
			if ( is_wp_error( $current ) ) return $current;
			$policy = MAD4B_SCP_Search_Context::policy();
			if ( is_wp_error( $policy ) ) return $policy;
			$raw = array_intersect_key( $current, array_flip( $policy['profile_fields'] ) );
			if ( ! isset( $raw['markets'][0] ) || ! is_array( $raw['markets'][0] ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
			$country = strtoupper( trim( $input['audience_country'] ) );
			if ( ! preg_match( '/^[A-Z]{2}$/D', $country ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
			// A country change must not carry stale provider-specific geo mappings.
			if ( $country !== $raw['markets'][0]['country'] && ! empty( $raw['markets'][0]['provider_locations'] ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_market_location_review_required' );
			$languages = self::list_input( $input['audience_languages'] );
			$devices = self::list_input( $input['audience_devices'] );
			if ( is_wp_error( $languages ) || is_wp_error( $devices ) || ! $languages || ! $devices || count( $languages ) > 64 || array_diff( $devices, array( 'desktop', 'mobile', 'tablet' ) ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
			$objective = trim( $input['objective'] );
			if ( '' === $objective || strlen( $objective ) > 256 ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
			$raw['markets'][0]['country'] = $country;
			$raw['language_policy']['desired'] = $languages;
			$raw['provider_policy']['devices'] = $devices;
			$raw['objective'] = $objective;
			$raw['enabled'] = ! empty( $current['enabled'] );
			$raw['provider_policy']['freeze_spend'] = ! empty( $current['provider_policy']['freeze_spend'] );
		} else return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
		$args = array( 'profile' => $raw, 'expected_revision' => $revision );
		$plan = MAD4B_SCP_Search_Runtime::profile_plan( $args );
		if ( is_wp_error( $plan ) ) return $plan;
		$result = MAD4B_SCP_Search_Runtime::profile_apply( array_merge( $args, array( 'plan_sha256' => $plan['plan_sha256'] ) ) );
		if ( is_wp_error( $result ) ) return $result;
		$verify = MAD4B_SCP_Search_Runtime::profile_verify( array( 'profile_id' => $raw['profile_id'] ) );
		if ( is_wp_error( $verify ) || empty( $verify['valid'] ) || $verify['profile_sha256'] !== $plan['profile']['profile_sha256'] ) return MAD4B_SCP_Search_Contracts::error( 'profile_readback_failed' );
		return $result;
	}

	private static function list_input( $value ) {
		if ( is_string( $value ) ) {
			if ( strlen( $value ) > 2048 ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
			$items = explode( ',', $value );
		} elseif ( is_array( $value ) ) {
			if ( count( $value ) > 64 ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
			$items = $value;
		} else return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
		$out = array();
		foreach ( $items as $item ) {
			if ( ! is_string( $item ) || strlen( $item ) > 96 ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
			$item = trim( $item );
			if ( '' === $item ) continue;
			if ( ! preg_match( '/^[A-Za-z0-9._-]{1,64}$/D', $item ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
			$out[] = $item;
		}
		return array_values( array_unique( $out ) );
	}

	public static function post() {
		check_admin_referer( self::ACTION );
		$input = wp_unslash( $_POST );
		$result = self::save( $input );
		if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 422 ) );
		$p = $result['profile'];
		$args = array( 'page' => MAD4B_SCP_Search_Experience::PAGE_SLUG, 'profile_id' => $p['profile_id'], 'mad4b_notice_receipt' => MAD4B_SCP_Admin_Experience::notice_receipt( MAD4B_SCP_Search_Experience::PAGE_SLUG, 'profile_saved', $p['profile_sha256'] ) );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) ); exit;
	}

	public static function render( $id ) {
		$profiles = self::profiles();
		echo '<section aria-label="Search profile setup"><h2>Search profiles</h2>';
		if ( is_wp_error( $profiles ) ) { echo '<p>' . esc_html( $profiles->get_error_message() ) . '</p></section>'; return; }
		if ( $profiles ) {
			echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( MAD4B_SCP_Search_Experience::PAGE_SLUG ) . '"><label for="mad4b-search-profile-picker">Selected profile</label> <select id="mad4b-search-profile-picker" name="profile_id">';
			foreach ( $profiles as $key => $p ) echo '<option value="' . esc_attr( $key ) . '"' . ( $id === $key ? ' selected' : '' ) . '>' . esc_html( $key . ' · revision ' . $p['revision'] ) . '</option>';
			echo '</select> <button class="button">Open profile</button></form>';
		}
		if ( ! MAD4B_SCP_Search_Runtime::can_configure() ) { echo '<p>Profile editing requires an enrolled nonproduction site and an administrator.</p></section>'; return; }
		if ( isset( $profiles[ $id ] ) ) {
			$p = $profiles[ $id ];
			if ( MAD4B_SCP_Admin_Experience::notice_verified( MAD4B_SCP_Search_Experience::PAGE_SLUG, 'profile_saved', $p['profile_sha256'] ) ) echo '<div class="notice notice-success"><p>Search profile saved and verified.</p></div>';
			echo '<div class="mad4b-scp-panel"><h3>Profile runtime controls</h3><p>Observation state and provider spend state are intentionally separate from advanced policy JSON. These controls do not certify a provider, allocate allowance or create write authority.</p>';
			echo '<p><strong>Observations:</strong> ' . esc_html( ! empty( $p['enabled'] ) ? 'Enabled' : 'Paused' ) . ' &nbsp; <strong>Provider spend:</strong> ' . esc_html( ! empty( $p['provider_policy']['freeze_spend'] ) ? 'Frozen' : 'Unfrozen' ) . '</p>';
			self::runtime_control_form( $id, $p['revision'], ! empty( $p['enabled'] ) ? 'pause' : 'resume', ! empty( $p['enabled'] ) ? 'Pause observations' : 'Resume observations' );
			self::runtime_control_form( $id, $p['revision'], ! empty( $p['provider_policy']['freeze_spend'] ) ? 'unfreeze_spend' : 'freeze_spend', ! empty( $p['provider_policy']['freeze_spend'] ) ? 'Unfreeze provider spend' : 'Freeze provider spend' );
			echo '</div>';
			$policy = MAD4B_SCP_Search_Context::policy(); $raw = array_intersect_key( $p, array_flip( $policy['profile_fields'] ) );
			self::render_guided_edit_form( $p );
			echo '<details><summary>Edit selected profile policy</summary><p>Markets, provider location mappings, language and budget policies are independent of Site Profile. Observation enablement and spend freeze state cannot be changed through this JSON editor.</p>';
			self::form_start( 'edit', $id, $p['revision'] );
			echo '<label for="mad4b-search-profile-json">Profile policy JSON</label><textarea id="mad4b-search-profile-json" name="profile_json" rows="20" class="large-text code">' . esc_textarea( wp_json_encode( $raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</textarea><button class="button button-primary">Save and verify profile</button></form></details>';
		}
		self::render_guided_create_form( $profiles );
		echo '</section>';
	}

	/** Business-safe edit; advanced profile policies remain unchanged. */
	private static function render_guided_edit_form( array $p ) {
		if ( empty( $p['markets'][0]['country'] ) || empty( $p['profile_id'] ) ) return;
		echo '<details><summary>Edit audience settings (guided)</summary>';
		echo '<p class="description">Update the first audience market, languages, devices and objective. Other markets, budget and runtime states remain unchanged. Existing geographic provider mappings require separate review before country changes.</p>';
		self::form_start( 'edit_guided', $p['profile_id'], $p['revision'] );
		echo '<p><label for="mad4b-search-edit-country">Audience country</label><br><input id="mad4b-search-edit-country" name="audience_country" value="' . esc_attr( $p['markets'][0]['country'] ) . '" maxlength="2" pattern="[A-Za-z]{2}" required></p>';
		echo '<p><label for="mad4b-search-edit-languages">Audience languages (comma-separated)</label><br><input id="mad4b-search-edit-languages" name="audience_languages" value="' . esc_attr( implode( ', ', $p['language_policy']['desired'] ) ) . '" maxlength="2048" required></p>';
		echo '<p><label for="mad4b-search-edit-devices">Devices (desktop, mobile, tablet)</label><br><input id="mad4b-search-edit-devices" name="audience_devices" value="' . esc_attr( implode( ', ', $p['provider_policy']['devices'] ) ) . '" maxlength="128" required></p>';
		echo '<p><label for="mad4b-search-edit-objective">Business objective</label><br><input id="mad4b-search-edit-objective" name="objective" value="' . esc_attr( $p['objective'] ) . '" maxlength="256" required></p>';
		echo '<p><button class="button button-primary">Save audience settings and verify</button></p></form></details>';
	}

	private static function render_guided_create_form( array $profiles ) {
		$setup = self::guided_setup( $profiles );
		echo '<details' . ( $profiles ? '' : ' open' ) . '><summary>' . esc_html__( 'Create a search profile', 'mad4b-site-control-plane' ) . '</summary>';
		echo '<div class="mad4b-scp-panel"><h3>' . esc_html__( 'Guided setup', 'mad4b-site-control-plane' ) . '</h3><p>' . esc_html__( 'Choose your audience market and languages explicitly. Hosting timezone and installed site languages never define your target market. Profiles remain paused with spend frozen; no paid search is performed.', 'mad4b-site-control-plane' ) . '</p></div>';
		self::form_start( 'create', '', 0 );

		echo '<fieldset><legend><strong>' . esc_html__( '1. Target market', 'mad4b-site-control-plane' ) . '</strong></legend>';
		echo '<p><label for="mad4b-search-new-market_country">' . esc_html__( 'Country code', 'mad4b-site-control-plane' ) . '</label><br><input id="mad4b-search-new-market_country" name="market_country" value="" maxlength="2" pattern="[A-Za-z]{2}" placeholder="US" required aria-describedby="mad4b-search-country-help"></p>';
		echo '<p id="mad4b-search-country-help" class="description">' . esc_html( $setup['country_help'] ) . '</p></fieldset>';

		echo '<fieldset><legend><strong>' . esc_html__( '2. Audience languages', 'mad4b-site-control-plane' ) . '</strong></legend><p class="description">' . esc_html( $setup['language_help'] ) . '</p>';
		foreach ( $setup['languages'] as $code => $label ) echo '<label style="display:inline-block;margin:0 18px 8px 0"><input type="checkbox" name="languages[]" value="' . esc_attr( $code ) . '"> ' . esc_html( $label . ' (' . $code . ')' ) . '</label>';
		echo '<p><label for="mad4b-search-additional-languages">Additional audience language codes (optional)</label><br><input id="mad4b-search-additional-languages" name="additional_languages" type="text" maxlength="2048" placeholder="es, fr"></p>';
		echo '</fieldset>';

		echo '<fieldset><legend><strong>' . esc_html__( '3. Search engines', 'mad4b-site-control-plane' ) . '</strong></legend>';
		if ( $setup['engines'] ) foreach ( $setup['engines'] as $engine ) echo '<label style="display:inline-block;margin:0 18px 8px 0"><input type="checkbox" name="engines[]" value="' . esc_attr( $engine ) . '" checked> ' . esc_html( ucfirst( $engine ) ) . '</label>';
		else echo '<p role="alert">' . esc_html__( 'No connected search adapter currently advertises an engine. Connect a provider before creating the profile.', 'mad4b-site-control-plane' ) . '</p>';
		echo '</fieldset>';

		echo '<fieldset><legend><strong>' . esc_html__( '4. Provider accounts', 'mad4b-site-control-plane' ) . '</strong></legend><p class="description">' . esc_html__( 'Verified accounts are selected automatically. Provider certification, location mapping and governed budget remain separate gates before observations can resume.', 'mad4b-site-control-plane' ) . '</p>';
		foreach ( $setup['providers'] as $provider ) {
			$checked = ! empty( $provider['recommended'] ) ? ' checked' : '';
			echo '<label style="display:block;margin:0 0 8px"><input type="checkbox" name="providers[]" value="' . esc_attr( $provider['id'] ) . '"' . $checked . '> <strong>' . esc_html( $provider['label'] ) . '</strong> — ' . esc_html( $provider['summary'] ) . '</label>';
		}
		if ( ! $setup['providers'] ) echo '<p role="alert">' . esc_html__( 'No search provider adapter is registered.', 'mad4b-site-control-plane' ) . '</p>';
		echo '</fieldset>';

		echo '<details><summary>' . esc_html__( 'Technical identifiers (auto-filled)', 'mad4b-site-control-plane' ) . '</summary><p class="description">' . esc_html__( 'Leave these blank to generate stable identifiers from the target country (for example search-us and market-us). You may specify valid custom IDs.', 'mad4b-site-control-plane' ) . '</p>';
		echo '<p><label for="mad4b-search-new-profile_id">' . esc_html__( 'Profile ID', 'mad4b-site-control-plane' ) . '</label><br><input id="mad4b-search-new-profile_id" name="profile_id" value="" maxlength="96" placeholder="Auto: search-us"></p>';
		echo '<p><label for="mad4b-search-new-market_id">' . esc_html__( 'Market ID', 'mad4b-site-control-plane' ) . '</label><br><input id="mad4b-search-new-market_id" name="market_id" value="" maxlength="96" placeholder="Auto: market-us"></p>';
		echo '<p><label for="mad4b-search-new-brand_id">' . esc_html__( 'Brand ID (optional)', 'mad4b-site-control-plane' ) . '</label><br><input id="mad4b-search-new-brand_id" name="brand_id" value="" maxlength="96"></p></details>';
		echo '<p><button class="button button-primary"' . ( $setup['engines'] ? '' : ' disabled' ) . '>' . esc_html__( 'Create paused profile', 'mad4b-site-control-plane' ) . '</button></p></form></details>';
	}

	private static function guided_setup( array $profiles ) {
		$languages = self::site_language_options();
		$providers = array(); $all_engines = array(); $verified_engines = array();

		foreach ( MAD4B_SCP_Search_Providers::adapters() as $id => $adapter ) {
			$descriptor = $adapter->descriptor();
			$engines = is_array( $descriptor ) && isset( $descriptor['capabilities']['engines'] ) && is_array( $descriptor['capabilities']['engines'] ) ? array_values( array_filter( $descriptor['capabilities']['engines'], 'is_string' ) ) : array();
			$all_engines = array_merge( $all_engines, $engines );
			$status = class_exists( 'MAD4B_SCP_Search_Provider_Connections' ) ? MAD4B_SCP_Search_Provider_Connections::status( $id ) : null;
			$verified = is_array( $status ) && isset( $status['connection_state'] ) && 'VERIFIED' === $status['connection_state'];
			if ( $verified ) $verified_engines = array_merge( $verified_engines, $engines );
			$label = $id;
			if ( method_exists( $adapter, 'enrollment' ) ) {
				$enrollment = $adapter->enrollment();
				if ( is_array( $enrollment ) && ! empty( $enrollment['label'] ) ) $label = (string) $enrollment['label'];
			}
			$summary = $verified ? 'Verified' : ( is_array( $status ) && isset( $status['connection_state'] ) ? str_replace( '_', ' ', strtolower( (string) $status['connection_state'] ) ) : 'Configuration available' );
			if ( $verified && isset( $status['quota']['remaining'] ) ) $summary .= ' · ' . (int) $status['quota']['remaining'] . ' searches remaining';
			$providers[] = array( 'id' => $id, 'label' => $label, 'summary' => $summary, 'recommended' => $verified );
		}
		$engines = array_values( array_unique( $verified_engines ? $verified_engines : $all_engines ) );
		sort( $engines, SORT_STRING );

		return array(
			'profile_id' => '',
			'market_id' => '',
			'market_country' => '',
			'country_help' => 'Choose the audience country (ISO code: US, GB, EG, etc.). Hosting location and WordPress timezone are intentionally ignored.',
			'languages' => $languages['items'],
			'language_help' => $languages['help'],
			'engines' => $engines,
			'providers' => $providers,
		);
	}

	private static function site_language_options() {
		$items = array(); $source = '';
		if ( function_exists( 'apply_filters' ) ) {
			$wpml = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0, 'orderby' => 'code' ) );
			if ( is_array( $wpml ) ) {
				foreach ( $wpml as $code => $row ) {
					if ( ! is_string( $code ) || ! preg_match( '/^[A-Za-z0-9._-]{1,32}$/D', $code ) ) continue;
					$label = is_array( $row ) ? ( ! empty( $row['native_name'] ) ? $row['native_name'] : ( ! empty( $row['translated_name'] ) ? $row['translated_name'] : ( ! empty( $row['english_name'] ) ? $row['english_name'] : $code ) ) ) : $code;
					$items[ $code ] = (string) $label;
				}
				if ( $items ) $source = 'WPML active languages';
			}
		}
		if ( ! $items && function_exists( 'pll_languages_list' ) ) {
			$codes = pll_languages_list( array( 'fields' => 'slug' ) );
			if ( is_array( $codes ) ) foreach ( $codes as $code ) if ( is_string( $code ) && preg_match( '/^[A-Za-z0-9._-]{1,32}$/D', $code ) ) $items[ $code ] = strtoupper( $code );
			if ( $items ) $source = 'Polylang active languages';
		}
		if ( ! $items ) {
			$locale = function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';
			$parts = preg_split( '/[_-]/', $locale ); $code = strtolower( isset( $parts[0] ) ? $parts[0] : 'en' );
			if ( ! preg_match( '/^[a-z]{2,3}$/D', $code ) ) $code = 'en';
			$items[ $code ] = strtoupper( $code );
			$source = 'WordPress locale ' . $locale;
		}
		if ( ! isset( $items['en'] ) ) $items['en'] = 'English';
		return array( 'items' => $items, 'help' => 'Site languages from ' . $source . ' are only suggestions. Select audience languages explicitly or add other language codes below.' );
	}

	private static function runtime_control_form( $profile_id, $revision, $control, $label ) {
		$confirmation = 'resume' === $control ? 'RESUME SEARCH OBSERVATIONS' : ( 'unfreeze_spend' === $control ? 'UNFREEZE SEARCH SPEND' : '' );
		echo '<form method="post" style="display:inline-block;margin:0 12px 8px 0" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="mad4b_search_control"><input type="hidden" name="profile_id" value="' . esc_attr( $profile_id ) . '"><input type="hidden" name="control" value="' . esc_attr( $control ) . '"><input type="hidden" name="expected_revision" value="' . esc_attr( (string) $revision ) . '">';
		wp_nonce_field( 'mad4b_search_control' );
		if ( '' !== $confirmation ) echo '<button class="button button-secondary" name="confirmation" value="' . esc_attr( $confirmation ) . '">' . esc_html( $label ) . '</button>';
		else echo '<button class="button button-secondary">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	private static function form_start( $operation, $id, $revision ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '"><input type="hidden" name="expected_revision" value="' . esc_attr( (string) $revision ) . '">';
		if ( 'edit' === $operation || 'edit_guided' === $operation ) echo '<input type="hidden" name="profile_id" value="' . esc_attr( $id ) . '">';
		wp_nonce_field( self::ACTION );
	}
}
