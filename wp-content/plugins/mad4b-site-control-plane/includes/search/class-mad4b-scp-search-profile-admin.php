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
		if ( ! preg_match( '/^(0|[1-9][0-9]{0,8})$/D', $input['expected_revision'] ) || ! MAD4B_SCP_Search_Contracts::id( $input['profile_id'] ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
		$revision = (int) $input['expected_revision'];
		if ( 'create' === $input['operation'] ) {
			if ( 0 !== $revision ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
			foreach ( array( 'brand_id', 'market_id', 'market_country', 'languages', 'engines' ) as $field ) if ( ! isset( $input[ $field ] ) || ! is_string( $input[ $field ] ) || strlen( $input[ $field ] ) > 2048 ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
			if ( ! MAD4B_SCP_Search_Contracts::id( $input['market_id'] ) || ! preg_match( '/^[A-Z]{2}$/D', $input['market_country'] ) || ( '' !== $input['brand_id'] && ! MAD4B_SCP_Search_Contracts::id( $input['brand_id'] ) ) ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
			$providers = isset( $input['providers'] ) ? $input['providers'] : array();
			if ( ! is_array( $providers ) || count( $providers ) > 64 ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
			$adapters = MAD4B_SCP_Search_Providers::adapters();
			foreach ( $providers as $provider ) if ( ! is_string( $provider ) || ! isset( $adapters[ $provider ] ) ) return MAD4B_SCP_Search_Contracts::error( 'provider_id_invalid' );
			$languages = self::csv( $input['languages'] ); $engines = self::csv( $input['engines'] );
			if ( ! $languages || ! $engines ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
			$raw = array( 'profile_id' => $input['profile_id'], 'brand_id' => $input['brand_id'], 'enabled' => false, 'markets' => array( array( 'id' => $input['market_id'], 'country' => $input['market_country'] ) ), 'language_policy' => array( 'desired' => $languages ), 'provider_policy' => array( 'allowed' => array_values( array_unique( $providers ) ), 'engines' => $engines, 'freeze_spend' => true ) );
		} elseif ( 'edit' === $input['operation'] ) {
			if ( 0 === $revision || ! isset( $input['profile_json'] ) || ! is_string( $input['profile_json'] ) || strlen( $input['profile_json'] ) > 65536 ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
			$raw = json_decode( $input['profile_json'], true, 32 );
			if ( ! is_array( $raw ) || ! isset( $raw['profile_id'] ) || $raw['profile_id'] !== $input['profile_id'] ) return MAD4B_SCP_Search_Contracts::error( 'profile_form_invalid' );
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

	private static function csv( $value ) { return array_values( array_unique( array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' ) ) ); }

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
			$policy = MAD4B_SCP_Search_Context::policy(); $raw = array_intersect_key( $p, array_flip( $policy['profile_fields'] ) );
			echo '<details><summary>Edit selected profile policy</summary><p>Markets, provider location mappings, language and budget policies are independent of Site Profile. Editing policy does not certify a provider or allocate paid allowance.</p>';
			self::form_start( 'edit', $id, $p['revision'] );
			echo '<label for="mad4b-search-profile-json">Profile policy JSON</label><textarea id="mad4b-search-profile-json" name="profile_json" rows="20" class="large-text code">' . esc_textarea( wp_json_encode( $raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</textarea><button class="button button-primary">Save and verify profile</button></form></details>';
		}
		echo '<details' . ( $profiles ? '' : ' open' ) . '><summary>Create a search profile</summary><p>New profiles start paused with spend frozen. Configure provider locations and certified allowance before resuming observations.</p>';
		self::form_start( 'create', '', 0 );
		foreach ( array( 'profile_id' => 'Profile ID', 'brand_id' => 'Brand ID (optional)', 'market_id' => 'Market ID', 'market_country' => 'Country code (ISO two letters)', 'languages' => 'Desired language codes (comma separated)', 'engines' => 'Search engine IDs (comma separated)' ) as $key => $label ) echo '<p><label for="mad4b-search-new-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><br><input id="mad4b-search-new-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" maxlength="' . ( in_array( $key, array( 'languages', 'engines' ), true ) ? '2048' : ( 'market_country' === $key ? '2' : '96' ) ) . '"' . ( 'brand_id' === $key ? '' : ' required' ) . '></p>';
		echo '<fieldset><legend>Allowed providers</legend>';
		foreach ( MAD4B_SCP_Search_Providers::adapters() as $key => $adapter ) echo '<label style="margin-right:16px"><input type="checkbox" name="providers[]" value="' . esc_attr( $key ) . '"> ' . esc_html( $key ) . '</label>';
		echo '</fieldset><p><button class="button button-primary">Create paused profile</button></p></form></details></section>';
	}

	private static function form_start( $operation, $id, $revision ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '"><input type="hidden" name="expected_revision" value="' . esc_attr( (string) $revision ) . '">';
		if ( 'edit' === $operation ) echo '<input type="hidden" name="profile_id" value="' . esc_attr( $id ) . '">';
		wp_nonce_field( self::ACTION );
	}
}
