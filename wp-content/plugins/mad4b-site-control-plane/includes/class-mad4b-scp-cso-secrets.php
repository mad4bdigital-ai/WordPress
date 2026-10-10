<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * A reviewed adapter's private native service, never an MCP plaintext schema.
 * Its real metadata-only native Ability callbacks delegate to dispatch_native().
 * Staging and each transition must atomically compare context.expected_revision;
 * staging preserves the old
 * active credential. context.idempotency_ref identifies the consumed handoff.
 * None of these callbacks supplies proof; independent native readback does.
 */
interface MAD4B_SCP_CSO_Secret_Provider {
	public function secret_fields();
	public function secret_stage( $field_ref, $plaintext, array $context );
	public function secret_transition( $phase, $field_ref, $candidate_ref, array $context );
}

/** First-party credential handoff. No built-in provider is automatically admitted. */
final class MAD4B_SCP_CSO_Secrets {
	const CONTRACT = 'mad4b.cso01.secret-handoff.v1';
	const PURPOSE = 'mad4b.cso01.secret-handoff';
	const ACTION = 'mad4b_cso_secret_handoff';
	const TTL_MAX = 300;
	const SLOT_COUNT = 64;
	const USER_SLOT_COUNT = 8;
	const CLEANUP_MAX = 16;
	const RECEIPT_RETENTION = 86400;
	private static $booted = false;
	private static $pending = null;

	public static function boot() {
		if ( self::$booted || ! class_exists( 'MAD4B_SCP_CSO_Scope' ) || true !== MAD4B_SCP_CSO_Scope::enabled( 'secrets' ) ) return;
		self::$booted = true;
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handoff' ) );
	}

	/** Metadata only. It cannot convert an OAuth session into a browser identity. */
	public static function session( $input ) {
		$valid = self::metadata( $input, array( 'provider_id', 'field_ref', 'mode', 'ttl', 'consent' ) );
		if ( is_wp_error( $valid ) ) return $valid;
		$scope = self::scope(); if ( is_wp_error( $scope ) ) return $scope;
		$mode = $input['mode'] ?? 'configure'; $ttl = $input['ttl'] ?? self::TTL_MAX;
		if ( ! in_array( $mode, array( 'configure', 'rotate' ), true ) || true !== ( $input['consent'] ?? null ) ) return self::error( 'explicit_consent_required' );
		if ( ! is_int( $ttl ) || $ttl < 30 || $ttl > self::TTL_MAX ) return self::error( 'ttl_invalid' );
		$provider = self::provider( $input, $scope, $mode );
		if ( is_wp_error( $provider ) ) return self::unsupported( $input, $provider->get_error_code() );
		$identity = self::identity( $scope ); if ( is_wp_error( $identity ) ) return $identity;
		$endpoint = self::endpoint( $scope ); if ( is_wp_error( $endpoint ) ) return $endpoint;
		$guard = self::authorize( $provider, 'store', self::target( $input['field_ref'], '', 'store' ), $scope ); if ( is_wp_error( $guard ) ) return $guard;
		$initial = self::inspect( $provider, $input['field_ref'], '', 'current', $scope ); if ( is_wp_error( $initial ) ) return $initial;
		if ( $initial['configured'] !== ( 'rotate' === $mode ) ) return self::error( 'lifecycle_mode_conflict' );
		$cleanup = self::cleanup( $scope ); if ( is_wp_error( $cleanup ) ) return $cleanup;
		$slot = self::allocation( $identity['user_id'] ); if ( is_wp_error( $slot ) ) return $slot;
		try { $ref = 'csoh.' . sprintf( '%02x', $slot ) . bin2hex( random_bytes( 31 ) ); $token = bin2hex( random_bytes( 32 ) ); }
		catch ( Throwable $error ) { return self::error( 'randomness_unavailable' ); }
		$record = array(
			'contract'=>self::CONTRACT, 'session_ref'=>$ref, 'state'=>'PREPARED', 'phase'=>'prepare',
			'provider_id'=>$input['provider_id'], 'field_ref'=>$input['field_ref'], 'mode'=>$mode,
			'scope'=>$scope, 'identity'=>$identity, 'provider_binding'=>$provider['binding'],
			'expires_at'=>time()+$ttl, 'created_at'=>time(), 'open_token_sha256'=>hash('sha256',$token),
			'initial_revision'=>$initial['revision'], 'initial_configured'=>$initial['configured'],
			'configured'=>$initial['configured'], 'verified'=>false, 'active_new'=>false, 'old_revoked'=>false,
			'owner_recovery_required'=>false,
		);
		$saved = self::persist( $ref, null, $record ); if ( is_wp_error( $saved ) ) return $saved;
		$out = self::projection( $record );
		$out['handoff_url'] = add_query_arg( array('action'=>self::ACTION,'session_ref'=>$ref,'open_token'=>$token,'_wpnonce'=>wp_create_nonce(self::ACTION.':open:'.$ref)), $endpoint );
		$out['user_initiated_open_required'] = true;
		return $out;
	}

	public static function status( $input ) {
		$valid = self::metadata( $input, array( 'session_ref' ), false ); if ( is_wp_error( $valid ) ) return $valid;
		if ( ! self::ref( $input['session_ref'] ?? null ) ) return self::error( 'session_ref_invalid' );
		$scope = self::scope(); if ( is_wp_error( $scope ) ) return $scope;
		$loaded = self::load( $input['session_ref'] ); if ( is_wp_error( $loaded ) ) return $loaded;
		$record = $loaded['record']; $bound = self::bound( $record, $scope ); if ( is_wp_error( $bound ) ) return $bound;
		if ( $record['expires_at'] <= time() && in_array( $record['state'], array('PREPARED','OPENED'), true ) ) {
			$record['state']='EXPIRED'; $record['phase']='expired';
		}
		return self::projection( $record );
	}

	/** No secret or provider execution. Missing native lifecycle remains unsupported. */
	public static function rotation_plan( $input ) {
		$valid=self::metadata($input,array('provider_id','field_ref')); if(is_wp_error($valid)) return $valid;
		$scope=self::scope(); if(is_wp_error($scope)) return $scope;
		$provider=self::provider($input,$scope,'rotate'); $ready=!is_wp_error($provider);
		$out=array('contract'=>'mad4b.cso01.secret-rotation-plan.v1','state'=>$ready?'PLANNED':'UNSUPPORTED','site_uuid'=>$scope['site_uuid'],'steps'=>array('stage_without_replacing_active','verify_new_with_provider','independent_candidate_readback','cutover','independent_active_readback','revoke_old','independent_revocation_readback'),'blockers'=>$ready?array():array($provider->get_error_code()),'collection_allowed'=>false,'execution_allowed'=>false,'authorizing'=>false,'plaintext_in_chat'=>false,'automatic_environment_transfer'=>false);
		if($ready) {$out['provider_id']=$provider['provider_id'];$out['field_ref']=$input['field_ref'];}
		return $out;
	}

	/**
	 * Only the actual certified native Ability callback may take this one-use value.
	 * It is absent in ordinary Ability calls and never enters their input/receipt.
	 */
	public static function dispatch_native( $adapter, $input ) {
		$p=self::$pending;
		if(!is_array($p)||!is_array($input)||$p['used']||$adapter!==$p['provider']['adapter']||$input!==$p['target']) return self::error('private_callback_unavailable');
		if(!class_exists('MAD4B_SCP_Authorization')||!MAD4B_SCP_Authorization::execution_callback_started($p['ability'])||!MAD4B_SCP_Execution_Fence::has_active_frame()) return self::error('native_execution_entry_required');
		self::$pending['used']=true;
		$guard=MAD4B_SCP_CSO_Scope::assert_current($p['scope']); if(is_wp_error($guard)) return $guard;
		$fresh=self::provider(array('provider_id'=>$p['provider']['provider_id'],'field_ref'=>$input['field_ref']),$p['scope'],$p['provider']['mode']);
		if(is_wp_error($fresh)||!hash_equals($p['provider']['binding'],$fresh['binding'])||!MAD4B_SCP_Policy::can_mutate()) return self::error('native_authority_changed');
		try {
			if('store'===$input['phase']) {
				$result=$adapter->secret_stage($input['field_ref'],$p['plaintext'],$p['context']);
				if(!is_string($result)||1!==preg_match('/^cso-secret\.[a-f0-9]{32,64}$/D',$result)||hash_equals($p['plaintext'],$result)) return self::error('native_store_result_unconfirmed');
				return $result;
			}
			$result=$adapter->secret_transition($input['phase'],$input['field_ref'],$input['candidate_ref'],$p['context']);
			return true===$result?true:self::error('native_transition_unconfirmed');
		} catch(Throwable $error) { return self::error('native_exception_unconfirmed'); }
		finally { unset($p['plaintext']); }
	}

	/** Standalone HTML with no external resources, admin analytics or inline script. */
	public static function handoff() {
		self::headers();
		if(!function_exists('current_action')||'admin_post_'.self::ACTION!==current_action()||(defined('REST_REQUEST')&&REST_REQUEST)||(defined('DOING_CRON')&&DOING_CRON)||(defined('XMLRPC_REQUEST')&&XMLRPC_REQUEST)) self::finish(self::error('first_party_handler_required'));
		$method=$_SERVER['REQUEST_METHOD']??'';
		if('GET'===$method) {
			if('status'===($_GET['view']??null)) {
				$input=wp_unslash($_GET);
				if(array_diff(array_keys($input),array('action','session_ref','view','_wpnonce'))||self::ACTION!==($input['action']??null)||!self::ref($input['session_ref']??null)||!is_string($input['_wpnonce']??null)||!wp_verify_nonce($input['_wpnonce'],self::ACTION.':status:'.$input['session_ref'])) self::finish(self::error('status_nonce_invalid'));
				$scope=self::scope(); if(is_wp_error($scope)) self::finish($scope);
				$http=self::http_origin($scope,$_SERVER,false); if(is_wp_error($http)) self::finish($http);
				self::finish(self::status(array('session_ref'=>$input['session_ref'])));
			}
			$opened=self::open(wp_unslash($_GET),$_SERVER); if(is_wp_error($opened)) self::finish($opened);
			$r=$opened['record']; $endpoint=self::endpoint($r['scope']); if(is_wp_error($endpoint)) self::finish($endpoint);
			if(headers_sent()||!setcookie(self::cookie_name($r['session_ref']),$opened['cookie'],array('expires'=>$r['expires_at'],'path'=>(string)wp_parse_url($endpoint,PHP_URL_PATH),'secure'=>true,'httponly'=>true,'samesite'=>'Strict'))) self::finish(self::error('same_site_cookie_unavailable'));
			echo '<!doctype html><html><head><meta charset="utf-8"><meta name="referrer" content="no-referrer"><title>Secure credential handoff</title></head><body><main><h1>Secure credential handoff</h1><p>This value goes directly to the certified native store on this site. It is never sent to the conversation.</p><p>Provider: '.esc_html($r['provider_id']).'; field: '.esc_html($r['field_ref']).'</p><form method="post" action="'.esc_url($endpoint).'" autocomplete="off"><input type="hidden" name="action" value="'.esc_attr(self::ACTION).'"><input type="hidden" name="session_ref" value="'.esc_attr($r['session_ref']).'"><input type="hidden" name="_wpnonce" value="'.esc_attr(wp_create_nonce(self::ACTION.':submit:'.$r['session_ref'])).'"><label for="mad4b-private-secret">Secret value</label><input id="mad4b-private-secret" name="secret" type="password" required maxlength="'.esc_attr((string)$opened['max_bytes']).'" autocomplete="new-password" autocapitalize="none" spellcheck="false"><button type="submit">Store and verify</button></form></main></body></html>';
			exit;
		}
		if('POST'!==$method) self::finish(self::error('http_method_denied'));
		$result=self::consume($_POST,$_COOKIE,$_SERVER); unset($_POST['secret']);
		if(self::ref($_POST['session_ref']??null)&&function_exists('wp_safe_redirect')) {
			$scope=self::scope(); $url=is_wp_error($scope)?$scope:self::endpoint($scope);
			if(!is_wp_error($url)) {
				$ref=$_POST['session_ref']; setcookie(self::cookie_name($ref),'',array('expires'=>1,'path'=>(string)wp_parse_url($url,PHP_URL_PATH),'secure'=>true,'httponly'=>true,'samesite'=>'Strict'));
				wp_safe_redirect(add_query_arg(array('action'=>self::ACTION,'session_ref'=>$ref,'view'=>'status','_wpnonce'=>wp_create_nonce(self::ACTION.':status:'.$ref)),$url),303); exit;
			}
		}
		self::finish($result);
	}

	private static function open( $input, array $server ) {
		if(!is_array($input)||array_diff(array_keys($input),array('action','session_ref','open_token','_wpnonce'))||self::ACTION!==($input['action']??null)||!self::ref($input['session_ref']??null)) return self::error('open_input_invalid');
		if(!empty($server['HTTP_PURPOSE'])||!empty($server['HTTP_SEC_PURPOSE'])||'navigate'!==($server['HTTP_SEC_FETCH_MODE']??null)||'?1'!==($server['HTTP_SEC_FETCH_USER']??null)) return self::error('deliberate_navigation_required');
		$scope=self::scope(); if(is_wp_error($scope)) return $scope;
		$http=self::http_origin($scope,$server,false); if(is_wp_error($http)) return $http;
		$loaded=self::load($input['session_ref']); if(is_wp_error($loaded)) return $loaded;
		$r=$loaded['record']; $bound=self::bound($r,$scope); if(is_wp_error($bound)) return $bound;
		if('PREPARED'!==$r['state']||$r['expires_at']<=time()) return self::error('handoff_replayed_or_expired');
		if(!self::sha($input['open_token']??null)||!hash_equals($r['open_token_sha256'],hash('sha256',$input['open_token']))||!is_string($input['_wpnonce']??null)||!wp_verify_nonce($input['_wpnonce'],self::ACTION.':open:'.$r['session_ref'])) return self::error('open_nonce_invalid');
		$p=self::provider($r,$scope,$r['mode']); if(is_wp_error($p)||!hash_equals($p['binding'],$r['provider_binding'])) return self::error('managed_store_no_longer_admitted');
		$guard=self::authorize($p,'store',self::target($r['field_ref'],'','store'),$scope); if(is_wp_error($guard)) return $guard;
		try {$cookie=bin2hex(random_bytes(32));} catch(Throwable $error) {return self::error('randomness_unavailable');}
		$r['state']='OPENED'; $r['phase']='collect'; $r['browser_cookie_sha256']=hash('sha256',$cookie); unset($r['open_token_sha256']);
		$saved=self::persist($r['session_ref'],$loaded['sealed'],$r); if(is_wp_error($saved)) return $saved;
		return array('record'=>$r,'cookie'=>$cookie,'max_bytes'=>$p['field']['max_bytes']);
	}

	/** The only plaintext ingress; private and never registered as an Ability. */
	private static function consume( $post, array $cookies, array $server ) {
		if(!is_array($post)||array_diff(array_keys($post),array('action','session_ref','_wpnonce','secret'))||self::ACTION!==($post['action']??null)||!self::ref($post['session_ref']??null)||!empty($server['QUERY_STRING'])) return self::error('submit_input_invalid');
		$scope=self::scope(); if(is_wp_error($scope)) return $scope;
		$http=self::http_origin($scope,$server,true); if(is_wp_error($http)) return $http;
		$loaded=self::load($post['session_ref']); if(is_wp_error($loaded)) return $loaded;
		$r=$loaded['record']; $bound=self::bound($r,$scope); if(is_wp_error($bound)) return $bound;
		if('OPENED'!==$r['state']||$r['expires_at']<=time()) return self::error('handoff_replayed_or_expired');
		$cookie=$cookies[self::cookie_name($r['session_ref'])]??null;
		if(!self::sha($cookie)||!hash_equals($r['browser_cookie_sha256'],hash('sha256',$cookie))||!is_string($post['_wpnonce']??null)||!wp_verify_nonce($post['_wpnonce'],self::ACTION.':submit:'.$r['session_ref'])) return self::error('submit_nonce_or_cookie_invalid');
		$p=self::provider($r,$scope,$r['mode']); if(is_wp_error($p)||!hash_equals($p['binding'],$r['provider_binding'])) return self::error('managed_store_no_longer_admitted');
		$guard=self::authorize($p,'store',self::target($r['field_ref'],'','store'),$scope); if(is_wp_error($guard)) return $guard;
		$initial=self::inspect($p,$r['field_ref'],'','current',$scope);
		if(is_wp_error($initial)||$initial['revision']!==$r['initial_revision']||$initial['configured']!==$r['initial_configured']) return self::error('native_state_changed');
		$length=$server['CONTENT_LENGTH']??null;
		if(!is_string($length)||!preg_match('/^[1-9][0-9]{0,5}$/D',$length)||(int)$length>12288||!is_string($server['CONTENT_TYPE']??null)||1!==preg_match('/^application\/x-www-form-urlencoded(?:;[ \t]*charset=utf-8)?$/iD',$server['CONTENT_TYPE'])) return self::error('submit_size_or_type_invalid');
		// No plaintext is read until managed storage and current authority are admitted.
		$plaintext=isset($post['secret'])&&is_string($post['secret'])?wp_unslash($post['secret']):null; unset($post['secret'],$_POST['secret']);
		if(!is_string($plaintext)||''===$plaintext||strlen($plaintext)>$p['field']['max_bytes']||false!==strpos($plaintext,"\0")||1!==preg_match('//u',$plaintext)) return self::error('secret_value_invalid');
		$r['state']='CONSUMED'; $r['phase']='store'; unset($r['browser_cookie_sha256']);
		$sealed=self::persist($r['session_ref'],$loaded['sealed'],$r); if(is_wp_error($sealed)) {unset($plaintext);return $sealed;}
		$context=array_merge($scope,array('expected_revision'=>$r['initial_revision'],'idempotency_ref'=>$r['session_ref']));
		try {
			if($r['expires_at']<=time()) return self::uncertain($r,$sealed,'handoff_expired_before_effect');
			$candidate=self::private_execute($p,self::target($r['field_ref'],'','store'),$scope,$context,$plaintext); unset($plaintext);
			if(!is_string($candidate)||1!==preg_match('/^cso-secret\.[a-f0-9]{32,64}$/D',$candidate)) return self::uncertain($r,$sealed,'store_postcondition_unknown');
			$r['candidate_ref']=$candidate;
			foreach(array('store','verify','cutover','revoke') as $phase) {
				if('revoke'===$phase&&'rotate'!==$r['mode']) continue;
				$r['phase']=$phase;
				if('store'!==$phase) {
					if($r['expires_at']<=time()) return self::uncertain($r,$sealed,'handoff_expired_during_lifecycle');
					$effect=self::private_execute($p,self::target($r['field_ref'],$candidate,$phase),$scope,$context);
					if(is_wp_error($effect)) return self::uncertain($r,$sealed,'native_effect_unconfirmed');
				}
				$observed=self::inspect($p,$r['field_ref'],$candidate,$phase,$scope);
				if(is_wp_error($observed)||!$observed['candidate_stored']||('store'!==$phase&&!$observed['verified'])||(in_array($phase,array('cutover','revoke'),true)&&(!$observed['active_new']||!$observed['configured']))||('revoke'===$phase&&!$observed['old_revoked'])) return self::uncertain($r,$sealed,'independent_postcondition_missing');
				foreach(array('configured','verified','active_new','old_revoked','observed_at') as $key) $r[$key]=$observed[$key];
				$context['expected_revision']=$observed['revision'];
				$r['state']='store'===$phase?'STORED':'PARTIAL'; $next=self::persist($r['session_ref'],$sealed,$r); if(is_wp_error($next)) return self::error('state_uncertain_owner_recovery_required'); $sealed=$next;
			}
			$r['state']='VERIFIED'; $r['phase']='complete'; $r['completed_at']=time();
			$next=self::persist($r['session_ref'],$sealed,$r); return is_wp_error($next)?self::error('state_uncertain_owner_recovery_required'):self::projection($r);
		} catch(Throwable $error) { return self::uncertain($r,$sealed,'native_exception_unconfirmed'); }
		finally {unset($plaintext);self::$pending=null;}
	}

	private static function private_execute( array $p, array $target, array $scope, array $context, $plaintext = '' ) {
		if(null!==self::$pending) return self::error('nested_private_handoff_denied');
		$role=$target['phase']; $guard=self::authorize($p,$role,$target,$scope); if(is_wp_error($guard)) return $guard;
		$name=$p['field'][$role.'_ability'];
		// A previous call's observation must not admit a permission-time callback.
		MAD4B_SCP_Authorization::begin_execution_callback_observation($name);
		self::$pending=array('provider'=>$p,'ability'=>$name,'target'=>$target,'scope'=>$scope,'context'=>$context,'plaintext'=>$plaintext,'used'=>false);
		try {$result=self::execute($name,$target);return self::$pending['used']?$result:self::error('private_callback_not_consumed');}
		finally {self::$pending=null;}
	}
	private static function execute( $name, array $target ) {
		$ability=wp_get_ability($name); $callback=static function() use($ability,$target){return $ability->execute($target);};
		// A local POST enters the actual native wrapper; nested read/Ability calls use its existing child permit.
		return MAD4B_SCP_Execution_Fence::has_active_frame()?MAD4B_SCP_Execution_Fence::with_governed_child($name,$target,$callback,'cso01_private_secret_handoff'):$callback();
	}
	private static function provider( array $input, array $scope, $mode ) {
		if(!class_exists('MAD4B_SCP_Adapter_Registry')||!class_exists('MAD4B_SCP_Provider_Compatibility_Certification')||!class_exists('MAD4B_SCP_Authorization')||!class_exists('MAD4B_SCP_Execution_Fence')) return self::error('certified_managed_store_unavailable');
		$registry=MAD4B_SCP_Adapter_Registry::instance(); $registry->register_defaults(); $all=$registry->all();
		if(!is_array($all)||count($all)>256) return self::error('provider_registry_invalid'); $adapter=null;
		foreach($all as $candidate) {
			if(!$candidate instanceof MAD4B_SCP_CSO_Secret_Provider||!method_exists($candidate,'provider_key')||$input['provider_id']!==$candidate->provider_key()) continue;
			if(null!==$adapter) return self::error('provider_identity_ambiguous'); $adapter=$candidate;
		}
		if(!is_object($adapter)||!method_exists($adapter,'is_available')||!$adapter->is_available()) return self::error('certified_managed_store_unavailable');
		$fields=$adapter->secret_fields(); $field=is_array($fields)?($fields[$input['field_ref']]??null):null;
		if(!is_array($fields)||count($fields)>32||!is_array($field)||array_diff(array_keys($field),array('storage_mode','plaintext_options','max_bytes','store_ability','verify_ability','cutover_ability','revoke_ability','readback_ability'))||!in_array($field['storage_mode']??null,array('managed_encrypted','native_secret_store'),true)||false!==($field['plaintext_options']??null)||!is_int($field['max_bytes']??null)||$field['max_bytes']<1||$field['max_bytes']>8192) return self::error('secret_storage_policy_unsupported');
		$roles=array('store','verify','cutover','readback'); if('rotate'===$mode) $roles[]='revoke'; $certificates=array(); $names=array();
		MAD4B_SCP_Provider_Compatibility_Certification::clear_request_cache();
		foreach($roles as $role) {
			$name=$field[$role.'_ability']??null;
			if(!is_string($name)||strlen($name)>160||!preg_match('/^[a-z0-9._-]+\/[a-z0-9._-]+$/D',$name)||isset($names[$name])) return self::error('separate_native_lifecycle_required'); $names[$name]=true;
			$a=function_exists('wp_get_ability')?wp_get_ability($name):null;
			if(!is_object($a)||!method_exists($a,'execute')||!method_exists($a,'get_meta')||!method_exists($a,'check_permissions')||!MAD4B_SCP_Execution_Fence::final_execution_wrapper_verified($name)) return self::error('native_execution_wrapper_unavailable');
			$meta=$a->get_meta(); $readonly=is_array($meta)&&true===($meta['annotations']['readonly']??null);
			if(('readback'===$role)!==$readonly||true===($meta['public']??null)||true===($meta['mcp']['public']??null)||('readback'!==$role&&(!in_array($meta['mcp']['surface']??null,array('content','admin','write'),true)||!MAD4B_SCP_Authorization::execution_boundary_verified($a)))) return self::error('native_secret_ability_surface_unsafe');
			$status=MAD4B_SCP_Provider_Compatibility_Certification::ability_status($input['provider_id'],$name,$adapter);
			if(!is_array($status)||('readback'===$role?true!==($status['read_eligible']??null):(true!==($status['write_eligible']??null)||true!==($status['artifact_authority_bound']??null)||true!==($status['behavioral_evidence']['behavioral_verified']??null)||'active'!==($status['activation_stage']??null)))) return self::error('native_secret_capability_uncertified');
			$certificates[$role]=array('ability'=>$name,'contract'=>$status['capability_contract_digest']??'','receipt'=>$status['behavioral_evidence']['receipt_sha256']??'','level'=>$status['certification_level']??'');
		}
		$generation=MAD4B_SCP_Provider_Compatibility_Certification::certification_generation_sha256($input['provider_id']); if(!self::sha($generation)) return self::error('certificate_generation_unavailable');
		$binding=MAD4B_SCP_CSO_Scope::digest(array('purpose'=>'mad4b.cso01.secret-provider-binding.v1','provider'=>$input['provider_id'],'field'=>$input['field_ref'],'policy'=>$field,'certificates'=>$certificates,'generation'=>$generation,'scope'=>$scope));
		if(!self::sha($binding)) return self::error('provider_binding_invalid');
		return array('adapter'=>$adapter,'provider_id'=>$input['provider_id'],'field'=>$field,'binding'=>$binding,'mode'=>$mode);
	}
	private static function authorize( array $p, $role, array $target, array $scope ) {
		$current=MAD4B_SCP_CSO_Scope::assert_current($scope); if(is_wp_error($current)) return $current;
		$fresh=self::provider(array('provider_id'=>$p['provider_id'],'field_ref'=>$target['field_ref']),$scope,$p['mode']);
		if(is_wp_error($fresh)||!hash_equals($p['binding'],$fresh['binding'])) return self::error('provider_binding_changed');
		if(!current_user_can('manage_options')||!class_exists('MAD4B_SCP_Policy')||!('readback'===$role?MAD4B_SCP_Policy::can_read():MAD4B_SCP_Policy::can_mutate())) return self::error('current_authority_denied');
		try {
			$a=wp_get_ability($p['field'][$role.'_ability']);
			if('readback'===$role) $allowed=$a->check_permissions($target);
			else {
				// Native permission callbacks run only inside the final Ability execution.
				// This established central probe cannot claim a ticket or budget.
				if(!method_exists('MAD4B_SCP_Authorization','probe_mutation')||!method_exists('MAD4B_SCP_Authorization','permission_result_from_authorization')) return self::error('nonconsuming_authorization_unavailable');
				$meta=$a->get_meta();$surface=$meta['mcp']['surface']??null;
				if(!in_array($surface,array('content','admin','write'),true)) return self::error('native_secret_ability_surface_unsafe');
				$probe=MAD4B_SCP_Authorization::probe_mutation($p['field'][$role.'_ability'],'mad4b-'.$surface,$p['provider_id'],$target);
				$allowed=MAD4B_SCP_Authorization::permission_result_from_authorization($probe);
			}
		} catch(Throwable $error){return self::error('native_permission_denied');}
		return true===$allowed?MAD4B_SCP_CSO_Scope::assert_current($scope):self::error('native_permission_denied');
	}
	private static function inspect( array $p, $field, $candidate, $phase, array $scope ) {
		$target=self::target($field,$candidate,$phase); $guard=self::authorize($p,'readback',$target,$scope); if(is_wp_error($guard)) return $guard;
		$out=self::execute($p['field']['readback_ability'],$target); $current=MAD4B_SCP_CSO_Scope::assert_current($scope); if(is_wp_error($current)) return $current;
		$keys=array('configured','candidate_stored','verified','active_new','old_revoked','candidate_ref','observed_at','revision');
		if(!is_array($out)||array_diff(array_keys($out),$keys)||array_diff($keys,array_keys($out))||$candidate!==$out['candidate_ref']||!is_int($out['revision'])||$out['revision']<0||!is_int($out['observed_at'])||$out['observed_at']<time()-5||$out['observed_at']>time()) return self::error('independent_readback_invalid');
		foreach(array_slice($keys,0,5) as $key) if(!is_bool($out[$key])) return self::error('independent_readback_invalid');
		return $out;
	}
	private static function target($field,$candidate,$phase){return array('field_ref'=>$field,'candidate_ref'=>$candidate,'phase'=>$phase);}
	private static function scope() {
		if(!class_exists('MAD4B_SCP_CSO_Scope')||!MAD4B_SCP_CSO_Scope::enabled('secrets')) return self::error('secrets_disabled');
		$scope=MAD4B_SCP_CSO_Scope::current(); if(is_wp_error($scope)) return $scope;
		if(!is_array($scope)||!in_array($scope['environment']??null,array('staging','development','local'),true)||!is_string($scope['origin']??null)||!preg_match('/^https:\/\/[a-z0-9.-]+(?::[0-9]{1,5})?$/D',$scope['origin'])||!self::sha($scope['actor_sha256']??null)) return self::error('first_party_staging_scope_required');
		return $scope;
	}
	private static function identity(array $scope) {
		$user=get_current_user_id(); $token=function_exists('wp_get_session_token')?wp_get_session_token():'';
		if(!is_int($user)||$user<1||!is_string($token)||strlen($token)<32||!function_exists('is_user_logged_in')||!is_user_logged_in()||!current_user_can('manage_options')) return self::error('first_party_session_required');
		$identity=class_exists('MAD4B_SCP_Identity_Context')?MAD4B_SCP_Identity_Context::current():array(); if(is_wp_error($identity)||!is_array($identity)) return self::error('issuer_identity_invalid');
		return array('user_id'=>$user,'cookie_session_sha256'=>hash('sha256',self::PURPOSE.':'.$token),'actor_sha256'=>$scope['actor_sha256'],'issuer_fingerprint'=>$identity['issuer_fingerprint']??'','session_fingerprint'=>$identity['session_fingerprint']??'','subject_fingerprint'=>$identity['subject_fingerprint']??'','consent_audience'=>$scope['origin']);
	}
	private static function bound(array $record,array $scope) {
		$current=MAD4B_SCP_CSO_Scope::assert_current($record['scope']); if(is_wp_error($current)) return $current;
		if($scope!==$record['scope']) return self::error('scope_changed'); $identity=self::identity($scope); if(is_wp_error($identity)) return $identity;
		return $identity===$record['identity']?true:self::error('actor_issuer_or_browser_session_changed');
	}
	private static function http_origin(array $scope,array $server,$post) {
		if(!function_exists('is_ssl')||!is_ssl()||'off'===($server['HTTPS']??'')) return self::error('https_required');
		$port=wp_parse_url($scope['origin'],PHP_URL_PORT); $host=wp_parse_url($scope['origin'],PHP_URL_HOST).($port&&443!==$port?':'.$port:'');
		if(!is_string($server['HTTP_HOST']??null)||strtolower($server['HTTP_HOST'])!==strtolower($host)) return self::error('host_origin_mismatch');
		if($post&&($scope['origin']!==($server['HTTP_ORIGIN']??null)||(isset($server['HTTP_SEC_FETCH_SITE'])&&'same-origin'!==$server['HTTP_SEC_FETCH_SITE']))) return self::error('cross_origin_submit_denied');
		return true;
	}
	private static function endpoint(array $scope) {
		$url=admin_url('admin-post.php','https'); $p=wp_parse_url($url);
		if(!is_array($p)||'https'!==($p['scheme']??null)||!isset($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])) return self::error('first_party_endpoint_invalid');
		$origin='https://'.$p['host'].(isset($p['port'])&&443!==$p['port']?':'.$p['port']:''); return hash_equals($scope['origin'],$origin)?$url:self::error('first_party_endpoint_foreign');
	}
	private static function metadata($input,array $keys,$selector=true) {
		if(!is_array($input)||array_diff(array_keys($input),$keys)) return self::error('metadata_only_input_required');
		foreach($input as $value) if(is_array($value)||is_object($value)||(is_string($value)&&strlen($value)>160)) return self::error('metadata_input_invalid');
		if($selector) foreach(array('provider_id','field_ref') as $key) if(!is_string($input[$key]??null)||!preg_match('/^[a-z0-9][a-z0-9._:-]{0,95}$/D',$input[$key])) return self::error('selector_invalid');
		return true;
	}
	private static function slot_key($slot){return 'mad4b_cso_handoff_slot_'.sprintf('%02x',$slot);}
	private static function key($ref){return self::slot_key(hexdec(substr($ref,5,2)));}
	/** Fixed partitions make both the global and per-user bounds atomic without a lease. */
	private static function allocation($user) {
		global $wpdb;
		if(!is_object($wpdb)||!isset($wpdb->options)||!is_int($user)||$user<1) return self::error('handoff_store_unavailable');
		$start=($user%intdiv(self::SLOT_COUNT,self::USER_SLOT_COUNT))*self::USER_SLOT_COUNT;
		for($i=0;$i<self::USER_SLOT_COUNT;++$i) {
			$slot=$start+$i;
			$raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",self::slot_key($slot)));
			if(!empty($wpdb->last_error)) return self::error('handoff_store_unavailable');
			if(null===$raw) return $slot;
		}
		// Colliding user IDs share capacity. Unknown native effects retain their slot.
		return self::error('handoff_creation_quota_exhausted');
	}
	/** Creation-only metadata cleanup; read tools never expire or delete persisted state. */
	private static function cleanup(array $scope) {
		global $wpdb;
		$guard=MAD4B_SCP_CSO_Scope::assert_current($scope); if(is_wp_error($guard)) return $guard;
		if(!is_object($wpdb)||!isset($wpdb->options)) return self::error('handoff_store_unavailable');
		$deleted=0;
		for($slot=0;$slot<self::SLOT_COUNT&&$deleted<self::CLEANUP_MAX;++$slot) {
			$key=self::slot_key($slot);
			$raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",$key));
			if(!empty($wpdb->last_error)) return self::error('handoff_store_unavailable');
			if(null===$raw) continue;
			$loaded=self::decode($raw); if(is_wp_error($loaded)) continue;
			$r=$loaded['record'];
			if(self::key($r['session_ref'])!==$key) continue;
			$site_match=true;
			foreach(array('site_uuid','origin','environment','blog_id') as $k) if(($r['scope'][$k]??null)!==($scope[$k]??null)) {$site_match=false;break;}
			if(!$site_match) continue;
			$pending=in_array($r['state'],array('PREPARED','OPENED','EXPIRED'),true)&&$r['expires_at']<=time();
			$completed='VERIFIED'===$r['state']&&is_int($r['completed_at']??null)&&$r['completed_at']<=time()-self::RECEIPT_RETENTION;
			if(!$pending&&!$completed) continue;
			$guard=MAD4B_SCP_CSO_Scope::assert_current($scope); if(is_wp_error($guard)) return $guard;
			// A fresh full-value CAS cannot erase a claim or replacement that won the race.
			$changed=$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = BINARY %s",$key,$raw));
			wp_cache_delete($key,'options');
			if(!empty($wpdb->last_error)) return self::error('handoff_store_unavailable');
			if(1===$changed) ++$deleted;
			$guard=MAD4B_SCP_CSO_Scope::assert_current($scope); if(is_wp_error($guard)) return $guard;
		}
		return true;
	}
	private static function decode($raw) {
		if(!is_string($raw)||strlen($raw)>65536) return self::error('handoff_record_invalid');
		// No database value may instantiate PHP objects before its MAC is verified.
		$sealed=@unserialize($raw,array('allowed_classes'=>false));
		if(!is_array($sealed)) return self::error('handoff_record_invalid');
		$r=MAD4B_SCP_CSO_Scope::unseal($sealed,self::PURPOSE);
		$keys=array('contract','session_ref','state','phase','provider_id','field_ref','mode','scope','identity','provider_binding','expires_at','created_at','initial_revision','initial_configured','configured','verified','active_new','old_revoked','owner_recovery_required');
		if(is_wp_error($r)||!is_array($r)||array_diff($keys,array_keys($r))||self::CONTRACT!==$r['contract']||!self::ref($r['session_ref'])||!is_array($r['scope'])||!is_array($r['identity'])||!self::sha($r['provider_binding'])||!is_int($r['expires_at'])||!is_int($r['created_at'])||$r['created_at']>time()+5||$r['expires_at']<=$r['created_at']||$r['expires_at']>$r['created_at']+self::TTL_MAX||!in_array($r['state'],array('PREPARED','OPENED','CONSUMED','STORED','PARTIAL','UNCERTAIN','VERIFIED','EXPIRED'),true)) return self::error('handoff_record_invalid');
		return array('record'=>$r,'sealed'=>$sealed);
	}
	private static function load($ref) {
		global $wpdb; if(!self::ref($ref)||!is_object($wpdb)||!isset($wpdb->options)) return self::error('handoff_store_unavailable');
		$raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",self::key($ref)));
		if(null===$raw||!empty($wpdb->last_error)) return self::error('handoff_record_unavailable');
		$loaded=self::decode($raw);
		return is_wp_error($loaded)||$ref!==$loaded['record']['session_ref']?self::error('handoff_record_invalid'):$loaded;
	}
	private static function persist($ref,$expected,array $record) {
		global $wpdb; $guard=MAD4B_SCP_CSO_Scope::assert_current($record['scope']); if(is_wp_error($guard)) return $guard;
		if(!self::ref($ref)||$ref!==($record['session_ref']??null)) return self::error('session_ref_invalid');
		$sealed=MAD4B_SCP_CSO_Scope::seal($record,self::PURPOSE); if(is_wp_error($sealed)) return $sealed;
		if(!is_object($wpdb)||!isset($wpdb->options)) return self::error('handoff_store_unavailable'); $key=self::key($ref);
		// WordPress add_option() uses an upsert; a racing creator must never replace
		// another handoff. Only an insert with an untouched unique key may succeed.
		if(null===$expected) $changed=1===$wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",$key,maybe_serialize($sealed),'no'));
		else $changed=1===$wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",maybe_serialize($sealed),$key,maybe_serialize($expected)));
		wp_cache_delete($key,'options');
		if(!$changed||!empty($wpdb->last_error)) return self::error('handoff_compare_exchange_conflict');
		$guard=MAD4B_SCP_CSO_Scope::assert_current($record['scope']); return is_wp_error($guard)?$guard:$sealed;
	}
	private static function uncertain(array $r,$sealed,$reason) {$r['state']=!empty($r['active_new'])?'PARTIAL':'UNCERTAIN';$r['owner_recovery_required']=true;$r['reason']=$reason;$next=self::persist($r['session_ref'],$sealed,$r);return is_wp_error($next)?self::error('state_uncertain_owner_recovery_required'):self::projection($r);}
	private static function projection(array $r) {
		// An interrupted one-use claim needs reconciliation, never a blind replay.
		$state='CONSUMED'===$r['state']?'UNCERTAIN':$r['state'];
		$recovery=(bool)$r['owner_recovery_required']||in_array($r['state'],array('CONSUMED','STORED','PARTIAL','UNCERTAIN'),true);
		$out=array('contract'=>self::CONTRACT,'state'=>$state,'session_ref'=>$r['session_ref'],'site_uuid'=>$r['scope']['site_uuid'],'origin'=>$r['scope']['origin'],'provider_id'=>$r['provider_id'],'field_ref'=>$r['field_ref'],'expires_at'=>gmdate('c',$r['expires_at']),'configured'=>(bool)$r['configured'],'verified'=>(bool)$r['verified'],'phase'=>$r['phase'],'owner_recovery_required'=>$recovery,'plaintext_in_chat'=>false,'authorizing'=>false,'blind_retry_allowed'=>false,'signed_receipt_ref'=>$r['session_ref']);
		if(isset($r['observed_at'])) $out['observed_at']=gmdate('c',$r['observed_at']); if(isset($r['reason'])) $out['reason']=$r['reason']; return $out;
	}
	private static function unsupported(array $input,$reason){return array('contract'=>self::CONTRACT,'state'=>'UNSUPPORTED','reason'=>$reason,'collection_allowed'=>false,'plaintext_in_chat'=>false,'authorizing'=>false);}
	private static function cookie_name($ref){return 'mad4b_csoh_'.substr(hash('sha256',$ref),0,16);}
	private static function sha($v){return is_string($v)&&1===preg_match('/^[a-f0-9]{64}$/D',$v);}
	private static function ref($v){return is_string($v)&&1===preg_match('/^csoh\.[a-f0-9]{64}$/D',$v)&&hexdec(substr($v,5,2))<self::SLOT_COUNT;}
	private static function error($reason){return new WP_Error('mad4b_cso_secret_'.$reason,'Secure handoff requires current first-party identity, certified managed storage and existing authority.',array('reason'=>$reason,'plaintext_in_chat'=>false,'authorizing'=>false,'blind_retry_allowed'=>false));}
	private static function headers() {if(headers_sent()) return;header('Content-Type: text/html; charset=UTF-8');header("Content-Security-Policy: default-src 'none'; script-src 'none'; style-src 'none'; connect-src 'none'; img-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");header('Cache-Control: no-store, private, max-age=0');header('Pragma: no-cache');header('Referrer-Policy: no-referrer');header('X-Frame-Options: DENY');header('X-Content-Type-Options: nosniff');header('Permissions-Policy: camera=(), microphone=(), geolocation=()');}
	private static function finish($result) {$error=is_wp_error($result);status_header($error?403:200);$state=$error?'DENIED':($result['state']??'UNCERTAIN');echo '<!doctype html><html><head><meta charset="utf-8"><meta name="referrer" content="no-referrer"><title>Secure handoff status</title></head><body><main><h1>Secure handoff status</h1><p>'.esc_html($state).'</p><p>'.esc_html('VERIFIED'===$state?'Independent readback verified the credential lifecycle. No credential value is returned.':'Review the handoff state with the provider owner before any retry. An unconfirmed provider effect must be reconciled.').'</p></main></body></html>';exit;}
}
