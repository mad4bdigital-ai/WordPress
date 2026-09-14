<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Site-profile-bound governed write authority for the existing ChatGPT Plugin.
 *
 * OAuth remains an authentication layer. Mutation authority is created only by
 * an enabled site-local NHI, exact mad4b-write grants, the global mutation gate,
 * provider/runtime checks, budgets and one-time exact approval tickets.
 *
 * Unknown sites and origin drift fail closed. Production writes require an
 * explicit profile confirmation. Breakglass is never included.
 */
final class MAD4B_SCP_Staging_Write_Authority {
	const CONTRACT = 'mad4b.governed-write-authority.v2';
	const OPTION = 'mad4b_scp_staging_write_authority_v1';
	const VERSION = 1;
	const APPROVAL_INPUT_KEY = '_mad4b_approval_ticket_id';

	private static $booted = false;
	private static $reconciling = false;
	private static $status = array();

	public static function bootstrap() {
		$status = self::base_status();
		if ( ! $status['eligible'] ) { self::$status = $status; return $status; }

		if ( defined( 'MAD4B_MCP_MUTATION_ENABLED' ) && true !== constant( 'MAD4B_MCP_MUTATION_ENABLED' ) ) {
			$status['blocker'] = 'explicit_mutation_disabled';
			self::$status = $status;
			return $status;
		}
		if ( ! defined( 'MAD4B_MCP_MUTATION_ENABLED' ) ) define( 'MAD4B_MCP_MUTATION_ENABLED', true );
		$status['mutation_gate_configured'] = true;
		$status['configuration_source'] = 'site_profile';
		self::$status = $status;
		return $status;
	}

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		self::bootstrap();

		add_filter( 'wp_register_ability_args', array( __CLASS__, 'augment_write_ability' ), 70, 2 );
		add_filter( 'mad4b_scp_low_impact_requires_approval', array( __CLASS__, 'force_remote_write_approval' ), 100, 4 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_status_ability' ), 35 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'reconcile' ), 95 );
		add_action( 'admin_init', array( __CLASS__, 'reconcile' ), 20 );
	}

	public static function eligible() {
		$status = self::base_status();
		return ! empty( $status['eligible'] );
	}

	public static function effective() {
		$status = self::status();
		return ! empty( $status['ready'] );
	}

	public static function status() {
		if ( ! empty( self::$status ) && isset( self::$status['contract'] ) ) return self::$status;
		$stored = get_option( self::OPTION, array() );
		if ( is_array( $stored ) && isset( $stored['contract'] ) && self::CONTRACT === $stored['contract'] ) return $stored;
		return self::base_status();
	}

	public static function write_tools() {
		if ( ! class_exists( 'MAD4B_SCP_Servers' ) ) return array();
		$tools = MAD4B_SCP_Servers::write_tools();
		$tools = array_values( array_unique( array_filter( array_map( 'strval', is_array( $tools ) ? $tools : array() ) ) ) );
		return array_values( array_diff( $tools, array( 'mad4b/database-raw-query' ) ) );
	}

	public static function is_write_ability( $ability_name ) {
		return in_array( (string) $ability_name, self::write_tools(), true );
	}

	public static function approval_ticket_from_input( $input ) {
		if ( ! is_array( $input ) || ! isset( $input[ self::APPROVAL_INPUT_KEY ] ) ) return '';
		$value = strtolower( trim( (string) $input[ self::APPROVAL_INPUT_KEY ] ) );
		return preg_match( '/^[a-f0-9-]{36}$/', $value ) ? $value : '';
	}

	public static function authorization_input( $input ) {
		if ( ! is_array( $input ) ) return $input;
		$clean = $input;
		unset( $clean[ self::APPROVAL_INPUT_KEY ] );
		return $clean;
	}

	public static function remote_scope_delegation_allowed( array $identity, $server_id, $ability_name, $input ) {
		if ( ! self::effective() || 'mad4b-write' !== sanitize_key( (string) $server_id ) || ! self::is_write_ability( $ability_name ) ) return false;
		if ( empty( $identity['authenticated'] ) || 'oauth2_bearer' !== ( isset( $identity['auth_method'] ) ? (string) $identity['auth_method'] : '' ) ) return false;
		$scopes = isset( $identity['token_scopes'] ) && is_array( $identity['token_scopes'] ) ? $identity['token_scopes'] : array();
		if ( ! in_array( 'mad4b:read', $scopes, true ) ) return false;
		return '' !== self::approval_ticket_from_input( $input );
	}

	public static function force_remote_write_approval( $required, $ability_name, $provider, $input ) {
		if ( $required ) return true;
		if ( ! self::effective() || ! self::is_write_ability( $ability_name ) ) return $required;
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return $required;
		$current = class_exists( 'MAD4B_SCP_Transport_Context' ) ? MAD4B_SCP_Transport_Context::current_server_id() : '';
		return in_array( $current, array( 'mad4b-chatgpt', 'mad4b-write' ), true ) ? true : $required;
	}

	public static function augment_write_ability( $args, $name ) {
		if ( ! is_array( $args ) || 'mad4b/approval-plan' === (string) $name ) return $args;
		$meta = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		if ( ! array_key_exists( 'readonly', $annotations ) || false !== $annotations['readonly'] ) return $args;

		if ( isset( $args['input_schema'] ) && is_array( $args['input_schema'] ) ) {
			if ( ! isset( $args['input_schema']['properties'] ) || ! is_array( $args['input_schema']['properties'] ) ) $args['input_schema']['properties'] = array();
			$args['input_schema']['properties'][ы[▌▌░T⌠у░SрS■UряVHHH\°≤^J┌BBBIщ\IхO┬	эщ [≥ик┌BBBIшZ[⌠[≥щ	хO┬м▀┌BBBIшX^[≥щ	хO┬м▀┌BBBIэ]\⌡┴хO┬	в√пKQ≤KY▄NKW^лм÷I	к┌BBBIы\ьэ \[ш┴хO┬	сш≥K][YH^XщPQ┬\⌡щ≤[Xзы]≥\]Z\≥Y⌡э┬≥[[щHшщ≥\⌡≥Yэ ]\хш┬\х[°⌡шYз]K┴к┌BBJNб┌B_B┌┌BZY┬
\эы]
	\≥эжиы^Xщ]Wьь[≤XзивH
H	┴┬\вьь[X⌡J	\≥эжиы^Xщ]Wьь[≤XзивH
H
Hб┌BBIэ Yз[≤[H	\≥эжиы^Xщ]Wьь[≤XзивNб┌BBI\≥эжиы^Xщ]Wьь[≤XзивHHщ]Xх²[≤щ[ш┬
	[°]H²[
H\ыH
	э Yз[≤[
Hб┌BBBIшX[┬HPQ≈тпттщYз[≥вуэ ]Wп]]э ]N▌≤]]э ^≤][ш≈з[°]
	[°]
Nб┌BBB\≥]\⌡┬ь[щ\ы\≈ы²[≤й	э Yз[≤[	шX[┬
Nб┌BB_Nб┌B_B┌BZY┬
H\эы]
	\≥эжишY]IвVишXэ	вH
HH\вь\°≤^J	\≥эжишY]IвVишXэ	вH
H
H	\≥эжишY]IвVишXэ	вHH\°≤^J
Nб┌BI\≥эжишY]IвVишXэ	вVишXY≈ышщ≥\⌡≥Yщэ ]Wь]]э ]IвHHы[▌▌░сс∙░Pуб┌BI\≥эжишY]IвVишXэ	вVишXY≈э≥[[щWщэ ]Wь\⌡щ≤[э≥\]Z\≥Y	вHH²YNб┌B\≥]\⌡┬	\≥энб┌_B┌┌\X⌡Xхщ]Xх²[≤щ[ш┬≥Xшш≤з[J
Hб┌BZY┬
ы[▌▌┴≥Xшш≤з[[≥х
H≥]\⌡┬ы[▌▌°щ]\й
Nб┌B\ы[▌▌┴≥Xшш≤з[[≥хH²YNб┌BIщ]\хHы[▌▌≤≤\ыWэщ]\й
Nб┌BIщ]\жиш]]][ш≈ыь]Wьшш≥ Yщ\≥Y	вHHY [≥Y
	сPQ≈сPтсUUUSс≈яS░P⌠Q	х
H	┴┬²YHOOHшш°щ[²
	сPQ≈сPтсUUUSс≈яS░P⌠Q	х
Nб┌BZY┬
H	щ]\жиы[YзX⌡IвHH	щ]\жиш]]][ш≈ыь]Wьшш≥ Yщ\≥Y	вH
Hб┌BBIщ]\жиь⌡ьзы\┴вHH	щ]\жиы[YзX⌡IвHх	ш]]][ш≈ыь]Wы\ьX⌡Y	х┬	щ]\жиь⌡ьзы\┴вNб┌BBIXXщ]≤]YHы[▌▌≥XXщ]≤]WшX[≤YыYь]]э ]J	щ]\жиь⌡ьзы\┴вH
Nб┌BBZY┬
\вь\°≤^J	XXщ]≤]Y
H
H	щ]\жиыXXщ]≤][ш┴вHH	XXщ]≤]Yб┌BB\ы[▌▌┴щ]\хH	щ]\нб┌BB\ы[▌▌┴≥Xшш≤з[[≥хH≤[ыNб┌BB\≥]\⌡┬	щ]\нб┌B_B┌BZY┬
Hш\эвы^\щй	сPQ≈тпттьз[XIх
HHPQ≈тпттьз[XN▌ \вэ≥XYJ
H
Hб┌BBIщ]\жиь⌡ьзы\┴вHH	ышщ≥\⌡≤[≤ыWэьз[XWщ[≤]≤Z[X⌡Iнб┌BB\ы[▌▌┴щ]\хH	щ]\нхы[▌▌┴≥Xшш≤з[[≥хH≤[ыNх≥]\⌡┬	щ]\нб┌B_B┌BI]Y]Hш\эвы^\щй	сPQ≈тптп]Y]	х
HхPQ≈тптп]Y]▌°щэ≤YыWэщ]\й
H┬\°≤^J	э≥XYIхO┬≤[ыH
Nб┌BZY┬
[\J	]Y]иэ≥XYIвH
H
Hх	щ]\жиь⌡ьзы\┴вHH	ь]Y]щ[≤]≤Z[X⌡Iнхы[▌▌┴щ]\хH	щ]\нхы[▌▌┴≥Xшш≤з[[≥хH≤[ыNх≥]\⌡┬	щ]\нхB┌BZY┬
H²[≤щ[ш≈ы^\щй	щэыы]ьX []Iх
HHш\эвы^\щй	сPQ≈тптты\²≥\°их
H
Hх	щ]\жиь⌡ьзы\┴вHH	ьX []Y\вэ²[²[YWщ[≤]≤Z[X⌡Iнхы[▌▌┴щ]\хH	щ]\нхы[▌▌┴≥Xшш≤з[[≥хH≤[ыNх≥]\⌡┬	щ]\нхB┌┌BIь]]Hш\эвы^\щй	сPQ≈тпттщYз[≥всп]]п]]ьшш≥ Yих
HхPQ≈тпттщYз[≥всп]]п]]ьшш≥ Yн▌°щ]\й
H┬\°≤^J
Nб┌BI\ы\≈зYхHш\эвы^\щй	сPQ≈тпттз]Wт⌡ы [Iх
HхPQ≈тпттз]Wт⌡ы [N▌⌡ь]]щ\ы\≈зYй
H┬\°≤^J
Nб┌BZY┬
[\J	\ы\≈зYх
H	┴┬H[\J	ь]]шZ\эз[≥вH
H
H	\ы\≈зYхH\°≤^J
HX°з[²
	ь]]шZ\эз[≥вH
H
Nб┌BI\ы\≈зYхH\°≤^Wщ≤[Y\й\°≤^Wщ[ \]YJ\°≤^Wы [\┼\°≤^WшX\
	ьX°з[²	к	\ы\≈зYх
H
H
H
Nб┌BI\ы\≈зYHH[\J	\ы\≈зYх
Hх
[²
H	\ы\≈зYжлH┬б┌BI\эщY\┬Hш\эвы^\щй	сPQ≈тптсьь[сп]]ты\²≥\┴х
Hх² [J
щ [≥йHPQ≈тптсьь[сп]]ты\²≥\▌▌ \эщY\┼
K	ких
H┬	инб┌BZY┬
[\J	ь]]иьшш≥ Yщ\≥Y	вH
H	\ы\≈зYH	ихOOH	\эщY\┬
Hх	щ]\жиь⌡ьзы\┴вHH	шь]]эщX ≥Xщщ[≤]≤Z[X⌡Iнхы[▌▌┴щ]\хH	щ]\нхы[▌▌┴≥Xшш≤з[[≥хH≤[ыNх≥]\⌡┬	щ]\нхB┌BY⌡э≥XXз
	\ы\≈зYх\х	ь[≥Y]Wщ\ы\≈зY
Hб┌BBI\ы\┬Hы]щ\ы\≥]J	ь[≥Y]Wщ\ы\≈зY
Nб┌BBIшш⌡≥Xщь[щыYHш\эвы^\щй	сPQ≈тпттшXчIх
H	┴┬Y]ыы^\щй	сPQ≈тпттшXчIк	ьь[≈ьшш⌡≥Xщщ\ы\┴х
B┌BBBOхPQ≈тпттшXчN▌≤ь[≈ьшш⌡≥Xщщ\ы\┼	ь[≥Y]Wщ\ы\≈зY
B┌BBBN┬
	\ы\┬	┴┬\ы\≈ьь[┼	\ы\▀	шX[≤YыWшэ[ш°их
H
Nб┌BBZY┬
H	\ы\┬H	шш⌡≥Xщь[щыY
Hх	щ]\жиь⌡ьзы\┴вHH	шь]]щ\ы\≈ш⌡щь]]э ^≥Y	нхы[▌▌┴щ]\хH	щ]\нхы[▌▌┴≥Xшш≤з[[≥хH≤[ыNх≥]\⌡┬	щ]\нхB┌B_B┌┌BI[² \⌡ш⌡Y[²Hш\эвы^\щй	сPQ≈тпттз]Wт⌡ы [Iх
HхPQ≈тпттз]Wт⌡ы [N▌≤щ\°≥[²ы[² \⌡ш⌡Y[²

H┬	щ[ ш⌡щш┴нб┌BIYы[²Hы[▌▌≤Yы[²ь·WэшYй
Nб┌BZY┬
H	Yы[²
Hб┌BBIYы[²HPQ≈тптпYы[²т≥Yз\щ·N▌≤э≥X]WьYы[²
\°≤^J┌BBBIэшYихO┬ы[▌▌≤Yы[²эшYй
K┌BBBIшX≥[	хO┬	пз]тшщ≥\⌡≥Yэ ]H8═%	х┬
ш\эвы^\щй	сPQ≈тпттз]Wт⌡ы [Iх
HхPQ≈тпттз]Wт⌡ы [N▌≥\э^Wш≤[YJ
H┬ы[▌▌ шYWзэщ

H
K┌BBBIэщ]\ихO┬	ы[≤X⌡Y	к┌BBBIщэщ\ы\≈зY	хO┬	\ы\≈зY┌BBBIы[² \⌡ш⌡Y[²	хO┬	[² \⌡ш⌡Y[²┌BBJH
Nб┌BBZY┬
\вщэы\°⌡э┼	Yы[²
H
Hх	щ]\жиь⌡ьзы\┴вHH	Yы[²O≥ы]ы\°⌡э≈ьшыJ
Nхы[▌▌┴щ]\хH	щ]\нхы[▌▌┴≥Xшш≤з[[≥хH≤[ыNх≥]\⌡┬	щ]\нхB┌B_H[ыHб┌BIз[≥ы\хH\°≤^J
Nб┌BZY┬
	ы[≤X⌡Y	хOOH	Yы[²иэщ]\их
H	з[≥ы\жиэщ]\ивHH	ы[≤X⌡Y	нб┌BZY┬
	[² \⌡ш⌡Y[²OOH	Yы[²иы[² \⌡ш⌡Y[²	вH
H	з[≥ы\жиы[² \⌡ш⌡Y[²	вHH	[² \⌡ш⌡Y[²б┌BZY┬

[²
H	Yы[²ищэщ\ы\≈зY	вHOOH	\ы\≈зY
H	з[≥ы\жищэщ\ы\≈зY	вHH	\ы\≈зYб┌BZY┬
	з[≥ы\х
Hб┌BBI\]YHPQ≈тптпYы[²т≥Yз\щ·N▌²\]WьYы[²
	Yы[²иэX⌡XвзY	вK	з[≥ы\к
[²
H	Yы[²иэ≥] \з[ш┴вH
Nб┌BBZY┬
\вщэы\°⌡э┼	\]Y
H
Hх	щ]\жиь⌡ьзы\┴вHH	\]YO≥ы]ы\°⌡э≈ьшыJ
Nхы[▌▌┴щ]\хH	щ]\нхы[▌▌┴≥Xшш≤з[[≥хH≤[ыNх≥]\⌡┬	щ]\нхB┌BBIYы[²H	\]Yб┌B_B┌_B┌┌BI [≥ы\° [²хH\°≤^J
Nб┌BY⌡э≥XXз
	\ы\≈зYх\х	щX ≥Xщщ\ы\≈зY
Hб┌BBI [≥ы\° [²H\з
	эзL█M┴к	шь]]	х┬≈┬┬	\эщY\┬┬≈┬┬	щ\ы\▌┴х┬	щX ≥Xщщ\ы\≈зY
Nб┌BBI [≥ы\° [²жвHH	 [≥ы\° [²б┌BBIY[²]HH\°≤^J	ь]][²Xь]Y	хO┬²YK	эщX ≥Xщщ\IхO┬	шь]]	к	эщX ≥Xщы [≥ы\° [²	хO┬	 [≥ы\° [²
Nб┌BBI⌡щ[≥HPQ≈тптпYы[²т≥Yз\щ·N▌°≥\шш≥WьYы[²
	Y[²]H
Nб┌BBZY┬
\вщэы\°⌡э┼	⌡щ[≥
H
Hб┌BBBZY┬
	шXY≈ш WэщX ≥Xщщ[≤⌡щ[≥	хOOH	⌡щ[≥O≥ы]ы\°⌡э≈ьшыJ
H
Hх	щ]\жиь⌡ьзы\┴вHH	⌡щ[≥O≥ы]ы\°⌡э≈ьшыJ
Nхы[▌▌┴щ]\хH	щ]\нхы[▌▌┴≥Xшш≤з[[≥хH≤[ыNх≥]\⌡┬	щ]\нхB┌BBBI [≥[≥хHPQ≈тптпYы[²т≥Yз\щ·N▌≤ [≥эщX ≥Xщ
	Yы[²иэX⌡XвзY	вK	шь]]	к	 [≥ы\° [²	сьь[п]]\ы\▌┴х┬	щX ≥Xщщ\ы\≈зY┬	х	х┬ы[▌▌⌡X≥[шэ Yз[┼
H
Nб┌BBBZY┬
\вщэы\°⌡э┼	 [≥[≥х
H
Hх	щ]\жиь⌡ьзы\┴вHH	 [≥[≥кO≥ы]ы\°⌡э≈ьшыJ
Nхы[▌▌┴щ]\хH	щ]\нхы[▌▌┴≥Xшш≤з[[≥хH≤[ыNх≥]\⌡┬	щ]\нхB┌BB_H[ыZY┬

[²
H	⌡щ[≥изY	вHOOH
[²
H	Yы[²изY	вH
Hб┌BBBIщ]\жиь⌡ьзы\┴вHH	шь]]эщX ≥Xщь⌡щ[≥щвшщ\≈ьYы[²	нхы[▌▌┴щ]\хH	щ]\нхы[▌▌┴≥Xшш≤з[[≥хH≤[ыNх≥]\⌡┬	щ]\нб┌BB_B┌B_B┌┌BIщ[WэщX ≥Xщвы\ьX⌡YHб┌BY⌡э≥XXз
PQ≈тптпYы[²т≥Yз\щ·N▌°щX ≥Xщвы⌡э≈ьYы[²
	Yы[²изY	вK	шь]]	х
H\х	щX ≥Xщэ⌡щх
Hб┌BBZY┬
H[≈ь\°≤^J
щ [≥йH	щX ≥Xщэ⌡щжиэщX ≥Xщы [≥ы\° [²	вK	 [≥ы\° [²к²YH
H	┴┬	ы[≤X⌡Y	хOOH
щ [≥йH	щX ≥Xщэ⌡щжиэщ]\ивH
Hб┌BBBI\ьX⌡YHPQ≈тптпYы[²т≥Yз\щ·N▌°ы]эщX ≥Xщэщ]\й	Yы[²иэX⌡XвзY	вK	шь]]	к
щ [≥йH	щX ≥Xщэ⌡щжиэщX ≥Xщы [≥ы\° [²	к	ы\ьX⌡Y	х
Nб┌BBBZY┬
\щэы\°⌡э┼	\ьX⌡Y
H
H	щ]\жиь⌡ьзы\┴вHH	\ьX⌡YO≥ы]ы\°⌡э≈ьшыJ
Nб┌BBBY[ыH
йищ[WэщX ≥Xщвы\ьX⌡Yб┌BB_B┌B_B┌┌BIшшхHы[▌▌²э ]Wщшшй
Nб┌BZY┬
[\J	шшх
H
Hх	щ]\жиь⌡ьзы\┴вHH	щэ ]Wщшшз[²≥[²э·Wы[\Iнхы[▌▌┴щ]\хH	щ]\нхы[▌▌┴≥Xшш≤з[[≥хH≤[ыNх≥]\⌡┬	щ]\нхB┌BIэ≤[²YHб┌BI^\щ[≥хHб┌BIэ≤[²ь⌡ьзы\°хH\°≤^J
Nб┌BI[²≥[²э·Wэ⌡щэхH\°≤^J
Nб┌BI^XщYыэ≤[²хH\°≤^J
Nб┌BY⌡э≥XXз
	шшх\х	X []H
Hб┌BBZY┬
	шXY▀ы]X≤\ыK\≤]к\]Y\·IхOOH	X []H
Hх	э≤[²ь⌡ьзы\°жвHH	ь°≥XZыш\эвшXZинхшш²[²YNхB┌BBI⌡щ Y\┬HPQ≈тптты\²≥\°н▌°⌡щ Y\≈ы⌡э≈ьX []J	шXY▀]э ]Iк	X []H
Nб┌BBZY┬
²[OOH	⌡щ Y\┬
Hх	э≤[²ь⌡ьзы\°жвHH	щ[⌡[щ[²Y┴х┬	X []Nхшш²[²YNхB┌BBI[²≥[²э·Wэ⌡щэжвHH\°≤^J	ьX []IхO┬
щ [≥йH	X []K	э⌡щ Y\┴хO┬
щ [≥йH	⌡щ Y\┬
Nб┌BBI^XщYыэ≤[²жх
щ [≥йH	X []H┬≈┬┬ь[ ]^≥Wзы^J
щ [≥йH	⌡щ Y\┬
HHH\°≤^J	ьX []IхO┬
щ [≥йH	X []K	э⌡щ Y\┴хO┬ь[ ]^≥Wзы^J
щ [≥йH	⌡щ Y\┬
H
Nб┌B_B┌BIщ[Wыэ≤[²вэ≥]⌡зыYHб┌BIэ≤[²эьшэWшZYэ≤][ш°хHб┌BY⌡э≥XXз
PQ≈тптпYы[²т≥Yз\щ·N▌≥э≤[²вы⌡э≈ьYы[²
	Yы[²изY	вK	шXY▀]э ]Iх
H\х	X[≤YыYыэ≤[²
Hб┌BBZY┬
	ь[щихOOH
щ [≥йH	X[≤YыYыэ≤[²иыY≥≥Xщ	вH
Hшш²[²YNб┌BBIы^HH
щ [≥йH	X[≤YыYыэ≤[²иьX []Wш≤[YIвH┬≈┬┬ь[ ]^≥Wзы^J
щ [≥йH	X[≤YыYыэ≤[²иэ⌡щ Y\┴вH
Nб┌BBZY┬
\эы]
	^XщYыэ≤[²жх	ы^HH
H
Hб┌BBBZY┬
	ь[	хOOH
ь[ ]^≥Wзы^J
щ [≥йH	X[≤YыYыэ≤[²иы[² \⌡ш⌡Y[²	х
H
H
H	ихOOH [J
щ [≥йH	X[≤YыYыэ≤[²иы[² \⌡ш⌡Y[²	х
H
H
Hб┌BBBBIшш°щ≤Z[²хH\°≤^J
Nб┌BBBBZY┬
H[\J	X[≤YыYыэ≤[²иэ≥\шщ\≤ыWьшш°щ≤Z[²ивH
H
Hб┌BBBBBIXшыYH°шш≈ыXшыJ
щ [≥йH	X[≤YыYыэ≤[²иэ≥\шщ\≤ыWьшш°щ≤Z[²ивK²YH
Nб┌BBBBBZY┬
\вь\°≤^J	XшыY
H
H	шш°щ≤Z[²хH	XшыYб┌BBBB_B┌BBBBIэ≥X]YHPQ≈тптпYы[²т≥Yз\щ·N▌≥э≤[²ьX []J	Yы[²иэX⌡XвзY	к	шXY▀]э ]Iк
щ [≥йH	X[≤YыYыэ≤[²иьX []Wш≤[YIвK

щ [≥йH	X[≤YыYыэ≤[²иэ⌡щ Y\┴вH
K	шш°щ≤Z[²к	ь[щик	[² \⌡ш⌡Y[²
Nб┌BBBBZY┬
\вщэы\°⌡э┼	э≥X]Y
H
H	э≤[²ь⌡ьзы\°жвHH	э≥X]YO≥ы]ы\°⌡э≈ьшыJ
H┬	н⌡ZYэ≤]N┴х┬
щ [≥йH	X[≤YыYыэ≤[²иьX []Wш≤[YIвNб┌BBBBY[ыHб┌BBBBBI≥]⌡зыYHPQ≈тптпYы[²т≥Yз\щ·N▌°≥]⌡зыWь[щвыэ≤[²ь·WзY
	Yы[²иэX⌡XвзY	вK
[²
H	X[≤YыYыэ≤[²изY	вK	шXY▀]э ]Iх
Nб┌BBBBBZY┬
\вщэы\°⌡э┼	≥]⌡зыY
H
H	э≤[²ь⌡ьзы\°жвHH	≥]⌡зыYO≥ы]ы\°⌡э≈ьшыJ
H┬	н⌡ZYэ≤]K\≥]⌡зыN┴х┬
щ [≥йH	X[≤YыYыэ≤[²иьX []Wш≤[YIвNб┌BBBBBY[ыH
йиэ≤[²эьшэWшZYэ≤][ш°нб┌BBBB_B┌BBB_B┌BBBXшш²[²YNб┌BB_B┌BBI≥]⌡зыYHPQ≈тптпYы[²т≥Yз\щ·N▌°≥]⌡зыWь[щвыэ≤[²ь·WзY
	Yы[²иэX⌡XвзY	к
[²
H	X[≤YыYыэ≤[²изY	вK	шXY▀]э ]Iх
Nб┌BBZY┬
\щэы\°⌡э┼	≥]⌡зыY
H
H	э≤[²ь⌡ьзы\°жвHH	≥]⌡зыYO≥ы]ы\°⌡э≈ьшыJ
H┬	н┴х┬
щ [≥йH	X[≤YыYыэ≤[²иьX []Wш≤[YIвNб┌BBY[ыH
йищ[Wыэ≤[²вэ≥]⌡зыYб┌B_B┌┌Y⌡э≥XXз
	^XщYыэ≤[²х\х	⌡щх
Hб┌BBIX []HH	⌡щжиьX []Iнб┌BBI⌡щ Y\┬H	⌡щжиэ⌡щ Y\┴вNб┌BBIэ≤[²HPQ≈тптпYы[²т≥Yз\щ·N▌≥^Xщыэ≤[²
	Yы[²изY	вK	шXY▀]э ]Iк	X []K	⌡щ Y\┬
Nб┌BBZY┬
H\вщэы\°⌡э┼	э≤[²
H
Hх
йи^\щ[≥нхшш²[²YNхB┌BBZY┬
	шXY≈ш Wыэ≤[²шZ\эз[≥ихOOH	э≤[²O≥ы]ы\°⌡э≈ьшыJ
H
Hх	э≤[²ь⌡ьзы\°жвHH	э≤[²O≥ы]ы\°⌡э≈ьшыJ
H┬	н┴х┬	X []Nхшш²[²YNхB┌BBIэ≥X]YHPQ≈тптпYы[²т≥Yз\щ·N▌≥э≤[²ьX []J	Yы[²иэX⌡XвзY	к	шXY▀]э ]Iк	X []K	⌡щ Y\▀\°≤^J
K	ь[щик	[² \⌡ш⌡Y[²
Nб┌BBZY┬
\вщэы\°⌡э┼	э≥X]Y
H
H	э≤[²ь⌡ьзы\°жвHH	э≥X]YO≥ы]ы\°⌡э≈ьшыJ
H┬	н┴х┬	X []Nб┌BBY[ыH
йиэ≤[²Yб┌B_B┌B]\шэ²
	[²≥[²э·Wэ⌡щэкщ]Xх²[≤щ[ш┬
	K	┬
Hх≥]\⌡┬щ≤ш\
	VиьX []IвH┬≈┬┬	Vиэ⌡щ Y\┴вK	√иьX []IвH┬≈┬┬	√иэ⌡щ Y\┴вH
NхH
Nб┌┌BIщ]\жиьYы[²эX⌡XвзY	хO┬
щ [≥йH	Yы[²иэX⌡XвзY	вNб┌BIщ]\жиэщX ≥Xщы [≥ы\° [²э≥Y ^	вHO┬H[\J	 [≥ы\° [²х
HхщX°щ┼	 [≥ы\° [²жлKM┬
H┬	инб┌BIщ]\жишь]]эщX ≥Xщьшщ[²	вHO┬шщ[²
	 [≥ы\° [²х
Nб┌BIщ]\жищэ ]Wщшшьшщ[²	вHO┬шщ[²
	шшх
Nб┌BIщ]\жищэ ]Wз[²≥[²э·Wы [≥ы\° [²	вHO┬\з
	эзL█M┴кэз°шш≈ы[≤шыJ	[²≥[²э·Wэ⌡щэк■сс≈уS▒TппTQтсTрTх■сс≈уS▒TппTQуS▓PсяH
H
Nб┌BIщ]\жиы^Xщыэ≤[²вы^\щ[≥ивHO┬	^\щ[≥нб┌BIщ]\жиы^Xщыэ≤[²вьэ≥X]Y	вHO┬	э≤[²Yб┌BIщ]\жиэщ[WэщX ≥Xщвы\ьX⌡Y	хO┬	щ[WэщX ≥Xщвы\ьX⌡Yб┌BIщ]\жиэщ[Wыэ≤[²вэ≥]⌡зыY	вHO┬	щ[Wыэ≤[²вэ≥]⌡зыYб┌BIщ]\жиыэ≤[²эьшэWшZYэ≤][ш°ивHO┬	э≤[²эьшэWшZYэ≤][ш°нб┌BIщ]\жиыэ≤[²ь⌡ьзы\°ивHO┬\°≤^Wщ≤[Y\й\°≤^Wщ[ \]YJ	э≤[²ь⌡ьзы\°х
H
Nб┌BIщ]\жиь[э≥[[щWщэ ]\вэ≥\]Z\≥Wы^Xщь\⌡щ≤[	вHO┬²YNб┌BIщ]\жиь°≥XZыш\эвз[≤шYY	вHO┬[≈ь\°≤^J	шXY▀ы]X≤\ыK\≤]к\]Y\·Iк	шшк²YH
Nб┌BIщ]\жиэ≥XYIвHO┬[\J	э≤[²ь⌡ьзы\°х
H	┴┬H	щ]\жиь°≥XZыш\эвз[≤шYY	нб┌BIщ]\жиэщ]IвHO┬	щ]\жиэ≥XYIхх	э≥XYIх┬	ь⌡ьзыY	нб┌BIщ]\жиь⌡ьзы\┴вHO┬	щ]\жиэ≥XYIхх	их┬
H[\J	э≤[²ь⌡ьзы\°х
Hх	ыэ≤[²э≥Xшш≤з[X][ш≈з[≤шш\]Iх┬	ь°≥XZыш\эвшXZих
Nб┌BIщ]\жищ\]Yь]	вHO┬шY]J	ьих
Nб┌BIщ]\жиэ≥[[щWщ≤[°ээ²	вHO┬	шXY▀Xз]э	нб┌BIщ]\жиь]]э ]Wэы\²≥\┴вHO┬	шXY▀]э ]Iнб┌BIщ]\жишь]]э⌡шIвHO┬	зY[²]Wшш⌡Iнб┌BIщ]\жищэ ]Wь]]э ]Wьшш\ш≥[²ихO┬\°≤^J	ы^Xщшэ Yз[┴к	шь]]зY[²]Iк	ш WэщX ≥Xщь [≥[≥ик	ы^XщшXY≈щэ ]Wыэ≤[²	к	э⌡щ Y\≈э²[²[YIк	ышь≤[ш]]][ш≈ыь]Iк	ь²Yы]э≥\ы\²≤][ш┴к	шш≥Wщ[YWы^Xщь\⌡щ≤[	к	ь]Y]	х
Nб┌BZY┬
	щ]\жиэ≥XYIвH
Hб┌BBIщэ≥YHы]шэ[ш┼ы[▌▌⌠тSс▀\°≤^J
H
Nб┌BBIз[≥ыYHH\вь\°≤^J	щэ≥Y
H[\J	щэ≥Yиэ≥XYIвH
HH[\J	щэ≥Yиь⌡ьзы\┴вH
HH\эы]
	щэ≥YиьYы[²эX⌡XвзY	вH
HH\зы\]X[й
щ [≥йH	щ]\жиьYы[²эX⌡XвзY	вK
щ [≥йH	щэ≥YиьYы[²эX⌡XвзY	вH
HH\эы]
	щэ≥Yищэ ]Wщшшьшщ[²	вH
H
[²
H	щэ≥Yищэ ]Wщшшьшщ[²	вHOOH
[²
H	щ]\жищэ ]Wщшшьшщ[²	хH\эы]
	щэ≥Yищэ ]Wз[²≥[²э·Wы [≥ы\° [²	вH
HH\зы\]X[й
щ [≥йH	щ]\жищэ ]Wз[²≥[²э·Wы [≥ы\° [²	вK
щ [≥йH	щэ≥Yищэ ]Wз[²≥[²э·Wы [≥ы\° [²	вH
HH[\J	щэ≥Yиь°≥XZыш\эвз[≤шYY	вH
Nб┌BBZY┬
	з[≥ыY
Hб┌BBBSPQ≈тптп]Y]▌°≥Xшэ≥
	шXY▀ышщ≥\⌡≥Y]э ]KX]]э ]K\≥Xшш≤з[Y	к\°≤^J	ьYы[²эX⌡XвзY	хO┬	щ]\жиьYы[²эX⌡XвзY	вK	щэ ]Wщшшьшщ[²	хO┬	щ]\жищэ ]Wщшшьшщ[²	вK	щэ ]Wз[²≥[²э·Wы [≥ы\° [²	хO┬	щ]\жищэ ]Wз[²≥[²э·Wы [≥ы\° [²	к	ы^Xщыэ≤[²вьэ≥X]Y	хO┬	э≤[²Y	ы^Xщыэ≤[²вы^\щ[≥ихO┬	^\щ[≥к	эщ[WэщX ≥Xщвы\ьX⌡Y	хO┬	щ[WэщX ≥Xщвы\ьX⌡Y	эщ[Wыэ≤[²вэ≥]⌡зыY	хO┬	щ[Wыэ≤[²вэ≥]⌡зыY	ыэ≤[²эьшэWшZYэ≤][ш°ихO┬	э≤[²эьшэWшZYэ≤][ш°к	э≥[[щWщ≤[°ээ²	хO┬	шXY▀Xз]э	к	ь]]э ]Wэы\²≥\┴хO┬	шXY▀]э ]Iк	ь°≥XZыш\эвз[≤шYY	хO┬≤[ыH
K	шзих
Nб┌BBB]\]Wшэ[ш┼ы[▌▌⌠тSс▀	щ]\к≤[ыH
Nб┌BBBIщ]\жиэ\°з\щ[≤ыIвHH	э≥Xшэ≥Y	нб┌BB_H[ыHх	щ]\жиэ\°з\щ[≤ыIвHH	щ[≤з[≥ыY	нх	щ]\жиэ\°з\щYщ\]Yь]	вHH\эы]
	щэ≥Yищ\]Yь]	вH
Hх
щ [≥йH	щэ≥Yищ\]Yь]	вH┬	инхB┌B_B┌B\ы[▌▌┴щ]\хH	щ]\нб┌B\ы[▌▌┴≥Xшш≤з[[≥хH≤[ыNб┌B\≥]\⌡┬	щ]\нб┌_B┌┌\X⌡Xхщ]Xх²[≤щ[ш┬≥Yз\щ\≈эщ]\вьX []J
Hб┌BZY┬
H²[≤щ[ш≈ы^\щй	щээ≥Yз\щ\≈ьX []Iх
Hэз\вьX []J	шXY▀щэ ]KX]]э ]K\щ]\их
H
H≥]\⌡▌б┌B]ээ≥Yз\щ\≈ьX []J	шXY▀щэ ]KX]]э ]K\щ]\ик\°≤^J	шX≥[	хO┬	яы]шщ≥\⌡≥Yэ ]H]]э ]Hщ]\ик	ы\ьэ \[ш┴хO┬	т≥XYH^Xщ[э Yз[┬▓Kыэ≤[²ь\⌡щ≤[щ]\х⌡э┬Hшщ≥\⌡≥Yэ ]H]]э ]Hш┬\х[°⌡шYз]K┴к	ьь]Yшэ·IхO┬	шXY▀\≥XY	к	ы^Xщ]Wьь[≤XзихO┬\°≤^JвпсTтввк	эщ]\их
K	э\⌡Z\эз[ш≈ьь[≤XзихO┬\°≤^J	сPQ≈тпттшXчIк	ьь[≈э≥XY	х
K	шщ]]эьз[XIхO┬\°≤^J	щ\IхO┬	шь ≥Xщ	к	ьY][ш≤[⌡э\²Y\ихO┬²YH
K	шY]IхO┬\°≤^J	эX⌡XихO┬≤[ыK	эзщвз[≈э≥\щ	хO┬≤[ыK	шXэ	хO┬\°≤^J	эX⌡XихO┬≤[ыK	щ\IхO┬	щшш	к	эщ\≥≤XыIхO┬	э≥XY	х
K	ь[⌡⌡щ][ш°ихO┬\°≤^J	э≥XYш⌡IхO┬²YK	ы\щ²Xщ]≥IхO┬≤[ыK	зY[\щ[²	хO┬²YH
H
H
H
Nб┌_B┌┌\ ]≤]Hщ]Xх²[≤щ[ш┬XXщ]≤]WшX[≤YыYь]]э ]J	≥X\шш┬
Hб┌BI≥\щ[H\°≤^J	ьYы[²ы\ьX⌡Y	хO┬≤[ыK	эщX ≥Xщвы\ьX⌡Y	хO┬	ь[щвыэ≤[²вэ≥]⌡зыY	хO┬	ь⌡ьзы\°ихO┬\°≤^J
H
Nб┌BZY┬
Hш\эвы^\щй	сPQ≈тпттьз[XIх
HHPQ≈тпттьз[XN▌ \вэ≥XYJ
HHш\эвы^\щй	сPQ≈тптпYы[²т≥Yз\щ·Iх
H
H≥]\⌡┬	≥\щ[б┌BIYы[²Hы[▌▌≤Yы[²ь·WэшYй
Nб┌BZY┬
H\вь\°≤^J	Yы[²
H
H≥]\⌡┬	≥\щ[б┌BZY┬
	ы[≤X⌡Y	хOOH
щ [≥йH	Yы[²иэщ]\ивH
Hб┌BBI\ьX⌡YHPQ≈тптпYы[²т≥Yз\щ·N▌≥\ьX⌡WьYы[²
	Yы[²иэX⌡XвзY	вK
[²
H	Yы[²иэ≥] \з[ш┴вH
Nб┌BBZY┬
\вщэы\°⌡э┼	\ьX⌡Y
H
H	≥\щ[иь⌡ьзы\°ивVвHH	\ьX⌡YO≥ы]ы\°⌡э≈ьшыJ
Nб┌BBY[ыHх	Yы[²H	\ьX⌡Yх	≥\щ[иьYы[²ы\ьX⌡Y	вHH²YNхB┌B_B┌BY⌡э≥XXз
PQ≈тптпYы[²т≥Yз\щ·N▌°щX ≥Xщвы⌡э≈ьYы[²
	Yы[²изY	вK	шь]]	х
H\х	щX ≥Xщ
Hб┌BBZY┬
	ы[≤X⌡Y	хOOH
щ [≥йH	щX ≥Xщиэщ]\ивH
Hшш²[²YNб┌BBI\ьX⌡YHPQ≈тптпYы[²т≥Yз\щ·N▌°ы]эщX ≥Xщэщ]\й	Yы[²иэX⌡XвзY	к	шь]]	к
щ [≥йH	щX ≥XщиэщX ≥Xщы [≥ы\° [²	вK	ы\ьX⌡Y	х
Nб┌BBZY┬
\вщэы\°⌡э┼	\ьX⌡Y
H
H	≥\щ[иь⌡ьзы\°ивVвHH	\ьX⌡YO≥ы]ы\°⌡э≈ьшыJ
Nб┌BBY[ыH
йи≥\щ[иэщX ≥Xщвы\ьX⌡Y	вNб┌B_B┌BY⌡э≥XXз
PQ≈тптпYы[²т≥Yз\щ·N▌≥э≤[²вы⌡э≈ьYы[²
	Yы[²изY	вK	шXY▀]э ]Iх
H\х	э≤[²
Hб┌BBZY┬
	ь[щихOOH
щ [≥йH	э≤[²иыY≥≥Xщ	вH
Hшш²[²YNб┌BBI≥]⌡зыYHPQ≈тптпYы[²т≥Yз\щ·N▌°≥]⌡зыWь[щвыэ≤[²ь·WзY
	Yы[²иэX⌡XвзY	к
[²
H	э≤[²изY	вK	шXY▀]э ]Iх
Nб┌BBZY┬
\вщэы\°⌡э┼	≥]⌡зыY
H
H	≥\щ[иь⌡ьзы\°ивVвHH	≥]⌡зыYO≥ы]ы\°⌡э≈ьшыJ
Nб┌BBY[ыH
йи≥\щ[иь[щвыэ≤[²вэ≥]⌡зыY	вNб┌B_B┌BI≥\щ[иь⌡ьзы\°ивHH\°≤^Wщ≤[Y\й\°≤^Wщ[ \]YJ	≥\щ[иь⌡ьзы\°ивH
H
Nб┌BZY┬

	≥\щ[иьYы[²ы\ьX⌡Y	вH	≥\щ[иэщX ≥Xщвы\ьX⌡Y	вH	≥\щ[иь[щвыэ≤[²вэ≥]⌡зыY	вH
H	┴┬ш\эвы^\щй	сPQ≈тптп]Y]	х
H
Hб┌BBSPQ≈тптп]Y]▌°≥Xшэ≥
	шXY▀ышщ≥\⌡≥Y]э ]KX]]э ]KY\ьX⌡Y	к\°≤^J	э≥X\шш┴хO┬ь[ ]^≥Wзы^J
щ [≥йH	≥X\шш┬
K	ьYы[²эX⌡XвзY	хO┬
щ [≥йH	Yы[²иэX⌡XвзY	вK	эщX ≥Xщвы\ьX⌡Y	хO┬	≥\щ[иэщX ≥Xщвы\ьX⌡Y	вK	ь[щвыэ≤[²вэ≥]⌡зыY	хO┬	≥\щ[иь[щвыэ≤[²вэ≥]⌡зыY	х
K[\J	≥\щ[иь⌡ьзы\°ивH
Hх	шзих┬	ь⌡ьзыY	х
Nб┌B_B┌B\≥]\⌡┬	≥\щ[б┌_B┌┌\ ]≤]Hщ]Xх²[≤щ[ш┬Yы[²ь·WэшYй
Hб┌BYшь≤[	э▌б┌BZY┬
Hш\эвы^\щй	сPQ≈тпттьз[XIх
H
H≥]\⌡┬²[б┌BIHPQ≈тпттьз[XN▌²X⌡\й
Nб┌BI⌡щхH	э▀O≥ы]э⌡щй	э▀O°≥\\≥J■яSPу
┬■⌠сH	ииьYы[²ив_HрT▒HшYхH	\хSRUH▀ы[▌▌≤Yы[²эшYй
H
KT■░VWпH
Nхкхэн Yш⌡э≥Hшэ≥≥\эк▒▀▒\≥Xщ]X≤\ыT]Y\·K▒\≥Xщ]Y\·B┌B\≥]\⌡┬	⌡щхх	⌡щх┬²[б┌_B┌┌\X⌡Xхщ]Xх²[≤щ[ш┬Yы[²эшYй
Hб┌B\≥]\⌡┬ш\эвы^\щй	сPQ≈тпттз]Wт⌡ы [Iх
HхPQ≈тпттз]Wт⌡ы [N▌≤Yы[²эшYй
H┬	ьз]эYшщ≥\⌡≥Y]э ]Iнб┌_B┌┌\ ]≤]Hщ]Xх²[≤щ[ш┬≤\ыWэщ]\й
Hб┌BI[² \⌡ш⌡Y[²Hш\эвы^\щй	сPQ≈тпттз]Wт⌡ы [Iх
HхPQ≈тпттз]Wт⌡ы [N▌≤щ\°≥[²ы[² \⌡ш⌡Y[²

H┬
²[≤щ[ш≈ы^\щй	щэыы]ы[² \⌡ш⌡Y[²щ\Iх
Hхь[ ]^≥Wзы^J
щ [≥йHэыы]ы[² \⌡ш⌡Y[²щ\J
H
H┬	щ[ ш⌡щш┴х
Nб┌BIэщHы[▌▌ шYWзэщ

Nб┌BI⌡ы [HHш\эвы^\щй	сPQ≈тпттз]Wт⌡ы [Iх
HхPQ≈тпттз]Wт⌡ы [N▌°щ]\й
H┬\°≤^J
Nб┌BI[YзX⌡HHH[\J	⌡ы [Vиьшш≥ Yщ\≥Y	вH
H	┴┬H[\J	⌡ы [Vишэ Yз[≈шX]з	вH
H	┴┬H[\J	⌡ы [Vиы[² \⌡ш⌡Y[²шX]з	вH
H	┴┬H[\J	⌡ы [Vищэ ]Wы[≤X⌡Y	вH
Nб┌BI⌡ьзы\┬H	инб┌BZY┬
H	[YзX⌡H
Hб┌BBZY┬
[\J	⌡ы [Vиьшш≥ Yщ\≥Y	вH
H
H	⌡ьзы\┬H	эз]Wэ⌡ы [Wщ[≤шш≥ Yщ\≥Y	нб┌BBY[ыZY┬
[\J	⌡ы [Vиы[² \⌡ш⌡Y[²шX]з	вH
H
H	⌡ьзы\┬H	эз]Wэ⌡ы [Wы[² \⌡ш⌡Y[²ы Y²	нб┌BBY[ыZY┬
[\J	⌡ы [Vишэ Yз[≈шX]з	вH
H
H	⌡ьзы\┬H	эз]Wэ⌡ы [Wшэ Yз[≈ы Y²	нб┌BBY[ыH	⌡ьзы\┬H	ышщ≥\⌡≥Yщэ ]Wш⌡щы[≤X⌡Y	нб┌B_B┌B\≥]\⌡┬\°≤^J┌BBIьшш²≤Xщ	хO┬ы[▌▌░сс∙░Pу┌BBIщ≥\°з[ш┴хO┬ы[▌▌∙▒T■рSс▀┌BBIы[² \⌡ш⌡Y[²	хO┬	[² \⌡ш⌡Y[²┌BBIзэщ	хO┬	эщ┌BBIэз]Wщ]ZY	хO┬\эы]
	⌡ы [Vиэз]Wщ]ZY	вH
Hх
щ [≥йH	⌡ы [Vиэз]Wщ]ZY	вH┬	ик┌BBIэз]Wэ⌡ы [Wэ≥] \з[ш┴хO┬\эы]
	⌡ы [Vиэ≥] \з[ш┴вH
HхX°з[²
	⌡ы [Vиэ≥] \з[ш┴вH
H┬┌BBIэз]Wэ⌡ы [WыYы\щ	хO┬\эы]
	⌡ы [Vиэ⌡ы [WыYы\щ	вH
Hх
щ [≥йH	⌡ы [Vиэ⌡ы [WыYы\щ	вH┬	ик┌BBIы[YзX⌡IхO┬	[YзX⌡K┌BBIэ≥XYIхO┬≤[ыK┌BBIэщ]IхO┬	[YзX⌡Hх	э[≥[≥их┬	з[≥[YзX⌡Iк┌BBIь⌡ьзы\┴хO┬	⌡ьзы\▀┌BBIш]]][ш≈ыь]Wьшш≥ Yщ\≥Y	хO┬≤[ыK┌BBIьшш≥ Yщ\≤][ш≈эшщ\≤ыIхO┬	ш⌡ш≥Iк┌BBIэ⌡ыXщ[ш≈ь]]вы[≤X⌡IхO┬≤[ыK┌BBIь°≥XZыш\эвь]]вы[≤X⌡IхO┬≤[ыK┌BBIь°≥XZыш\эвз[≤шYY	хO┬≤[ыK┌BBIь[э≥[[щWщэ ]\вэ≥\]Z\≥Wы^Xщь\⌡щ≤[	хO┬²YK┌BBIэ≥[[щWщ≤[°ээ²	хO┬	шXY▀Xз]э	к┌BBIь]]э ]Wэы\²≥\┴хO┬	шXY▀]э ]Iк┌BBIшь]]э⌡шIхO┬	зY[²]Wшш⌡Iк┌BJNб┌_B┌┌\ ]≤]Hщ]Xх²[≤щ[ш┬шYWзэщ

Hб┌BI\²хHээ\°ыWщ\⌡
шYWщ\⌡
	ких
H
Nб┌B\≥]\⌡┬\вь\°≤^J	\²х
H	┴┬H[\J	\²жизэщ	вH
Hхщ²шщы\┼² [J
щ [≥йH	\²жизэщ	вK	к┴х
H
H┬	инб┌_B÷