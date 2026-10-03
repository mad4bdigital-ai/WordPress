<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'MAD4B_SCP_Schema' ) ) require_once __DIR__ . '/class-mad4b-scp-schema.php';
if ( ! class_exists( 'MAD4B_SCP_Database_Transaction_Guard' ) ) require_once __DIR__ . '/class-mad4b-scp-database-transaction-guard.php';

/**
 * Durable transport circuit breaker keyed by provider + site + certification generation.
 *
 * The breaker controls transport eligibility only. It never grants provider
 * certification, WordPress authority, approval, release-ring activation or
 * Production eligibility.
 */
final class MAD4B_SCP_Provider_Circuit_Breaker {
	const CONTRACT = 'mad4b.provider-circuit-breaker.v1';
	const EVENT_CONTRACT = 'mad4b.provider-circuit-breaker-event.v1';
	const STATE_CLOSED = 'closed';
	const STATE_OPEN = 'open';
	const STATE_HALF_OPEN = 'half_open';
	const FAILURE_THRESHOLD = 3;
	const BASE_OPEN_SECONDS = 60;
	const MAX_OPEN_SECONDS = 900;
	const HALF_OPEN_LEASE_SECONDS = 45;

	public static function begin_for_target( $surface, $target ) {
		$context = self::target_context( $surface, $target );
		if ( is_wp_error( $context ) ) return $context;
		if ( empty( $context['applicable'] ) ) return $context;
		return self::begin_attempt( $context['provider_id'], $context['site_uuid'], $context['certification_generation_sha256'], 'read' === sanitize_key( (string) $surface ) );
	}

	public static function begin_attempt( $provider_id, $site_uuid, $generation_sha256, $allow_half_open_probe = true ) {
		global $wpdb;
		$identity = self::identity( $provider_id, $site_uuid, $generation_sha256 );
		if ( is_wp_error( $identity ) ) return $identity;
		$tx = MAD4B_SCP_Database_Transaction_Guard::begin( 'provider_breaker_begin', array( 'provider_breakers', 'provider_breaker_events' ), false );
		if ( is_wp_error( $tx ) ) return $tx;
		$t = MAD4B_SCP_Schema::tables();
		try {
			$wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$t['provider_breakers']}
				(breaker_key_sha256,provider_id,site_uuid,certification_generation_sha256,state,failure_count,open_count,open_until,probe_token_sha256,probe_expires_at,revision,latest_event_sha256,created_at,updated_at)
				VALUES (%s,%s,%s,%s,%s,0,0,NULL,'',NULL,0,%s,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
				$identity['breaker_key_sha256'], $identity['provider_id'], $identity['site_uuid'], $identity['certification_generation_sha256'], self::STATE_CLOSED, str_repeat( '0', 64 )
			) );
			$row = self::locked_row( $identity['breaker_key_sha256'] );
			if ( ! is_array( $row ) ) throw new RuntimeException( 'provider_breaker_row_missing' );
			$now = self::now_epoch();
			$probe_token = '';
			$probe = false;
			if ( self::STATE_OPEN === (string) $row['state'] ) {
				$open_until = self::mysql_epoch( $row['open_until'] );
				if ( $open_until <= $now && ! $allow_half_open_probe ) {
					$committed = MAD4B_SCP_Database_Transaction_Guard::commit( $tx ); if ( is_wp_error( $committed ) ) return $committed;
					return new WP_Error( 'mad4b_provider_circuit_breaker_read_probe_required', 'Provider breaker recovery requires a non-mutating read/health probe before governed mutation transport can resume.', array(
						'contract'=>self::CONTRACT,'provider_id'=>$identity['provider_id'],'state'=>self::STATE_OPEN,'transport_eligible'=>false,'authority_effect'=>'none'
					) );
				}
				if ( $open_until > $now ) {
					$committed = MAD4B_SCP_Database_Transaction_Guard::commit( $tx ); if ( is_wp_error( $committed ) ) return $committed;
					return new WP_Error( 'mad4b_provider_circuit_breaker_open', 'Provider transport circuit breaker is open.', array(
						'contract'=>self::CONTRACT,'provider_id'=>$identity['provider_id'],'state'=>self::STATE_OPEN,
						'retry_after_seconds'=>max(1,$open_until-$now),'transport_eligible'=>false,'authority_effect'=>'none'
					) );
				}
				$probe_token = self::new_probe_token( $identity['breaker_key_sha256'], (int) $row['revision'] );
				$updated = self::transition_locked( $row, self::STATE_HALF_OPEN, 'open_window_elapsed', '', array(
					'failure_count'=>(int)$row['failure_count'],'open_count'=>(int)$row['open_count'],
					'open_until'=>null,'probe_token_sha256'=>hash('sha256',$probe_token),'probe_expires_at'=>$now+self::HALF_OPEN_LEASE_SECONDS,
				) );
				if ( is_wp_error( $updated ) ) throw new RuntimeException( $updated->get_error_code() );
				$row = $updated; $probe = true;
			} elseif ( self::STATE_HALF_OPEN === (string) $row['state'] ) {
				$probe_expires = self::mysql_epoch( $row['probe_expires_at'] );
				if ( ! $allow_half_open_probe ) {
					$committed = MAD4B_SCP_Database_Transaction_Guard::commit( $tx ); if ( is_wp_error( $committed ) ) return $committed;
					return new WP_Error( 'mad4b_provider_circuit_breaker_read_probe_required', 'HALF_OPEN provider recovery never uses mutation as a transport probe.', array(
						'contract'=>self::CONTRACT,'provider_id'=>$identity['provider_id'],'state'=>self::STATE_HALF_OPEN,'transport_eligible'=>false,'authority_effect'=>'none'
					) );
				}
				if ( '' !== (string)$row['probe_token_sha256'] && $probe_expires > $now ) {
					$committed = MAD4B_SCP_Database_Transaction_Guard::commit( $tx ); if ( is_wp_error( $committed ) ) return $committed;
					return new WP_Error( 'mad4b_provider_circuit_breaker_probe_in_flight', 'A single HALF_OPEN provider probe is already in flight.', array(
						'contract'=>self::CONTRACT,'provider_id'=>$identity['provider_id'],'state'=>self::STATE_HALF_OPEN,
						'retry_after_seconds'=>max(1,$probe_expires-$now),'transport_eligible'=>false,'authority_effect'=>'none'
					) );
				}
				$probe_token = self::new_probe_token( $identity['breaker_key_sha256'], (int) $row['revision'] );
				$updated = self::transition_locked( $row, self::STATE_HALF_OPEN, 'expired_probe_reissued', '', array(
					'failure_count'=>(int)$row['failure_count'],'open_count'=>(int)$row['open_count'],
					'open_until'=>null,'probe_token_sha256'=>hash('sha256',$probe_token),'probe_expires_at'=>$now+self::HALF_OPEN_LEASE_SECONDS,
				) );
				if ( is_wp_error( $updated ) ) throw new RuntimeException( $updated->get_error_code() );
				$row = $updated; $probe = true;
			}
			$committed = MAD4B_SCP_Database_Transaction_Guard::commit( $tx ); if ( is_wp_error( $committed ) ) return $committed;
			return self::admission_receipt( $identity, $row, $probe, $probe_token );
		} catch ( Throwable $error ) {
			MAD4B_SCP_Database_Transaction_Guard::rollback( $tx );
			return new WP_Error( 'mad4b_provider_circuit_breaker_begin_failed', 'Provider circuit breaker admission failed closed.', array( 'reason_code'=>sanitize_key($error->getMessage()),'authority_effect'=>'none' ) );
		}
	}

	public static function record_result( array $admission, $transport_ok, $failure_class = '' ) {
		global $wpdb;
		if ( empty( $admission['applicable'] ) ) return array( 'contract'=>self::CONTRACT,'recorded'=>false,'not_applicable'=>true,'authority_effect'=>'none','authorizing'=>false );
		$identity = self::identity(
			isset($admission['provider_id'])?$admission['provider_id']:'',
			isset($admission['site_uuid'])?$admission['site_uuid']:'',
			isset($admission['certification_generation_sha256'])?$admission['certification_generation_sha256']:''
		);
		if ( is_wp_error( $identity ) ) return $identity;
		$failure_class = sanitize_key( (string) $failure_class );
		$breaker_failure = ! $transport_ok && self::is_breaker_failure( $failure_class );
		$tx = MAD4B_SCP_Database_Transaction_Guard::begin( 'provider_breaker_result', array( 'provider_breakers', 'provider_breaker_events' ), false );
		if ( is_wp_error( $tx ) ) return $tx;
		try {
			$row = self::locked_row( $identity['breaker_key_sha256'] );
			if ( ! is_array( $row ) ) throw new RuntimeException( 'provider_breaker_row_missing' );
			$state = (string) $row['state'];
			$now = self::now_epoch();
			if ( self::STATE_OPEN === $state ) {
				$committed=MAD4B_SCP_Database_Transaction_Guard::commit($tx); if(is_wp_error($committed))return $committed;
				return array('contract'=>self::CONTRACT,'recorded'=>false,'late_result_ignored'=>true,'state'=>$state,'authority_effect'=>'none','authorizing'=>false);
			}
			if ( self::STATE_HALF_OPEN === $state ) {
				$raw_probe = isset( $admission['probe_token'] ) ? (string) $admission['probe_token'] : '';
				$expected_probe = (string) $row['probe_token_sha256'];
				if ( '' === $raw_probe || '' === $expected_probe || ! hash_equals( $expected_probe, hash('sha256',$raw_probe) ) ) throw new RuntimeException( 'provider_breaker_probe_token_mismatch' );
				if ( self::mysql_epoch( $row['probe_expires_at'] ) < $now ) throw new RuntimeException( 'provider_breaker_probe_result_stale' );
				if ( $transport_ok ) {
					$row = self::transition_locked( $row, self::STATE_CLOSED, 'half_open_probe_succeeded', '', array(
						'failure_count'=>0,'open_count'=>0,'open_until'=>null,'probe_token_sha256'=>'','probe_expires_at'=>null
					) );
				} else {
					$open_count = (int)$row['open_count'] + 1;
					$seconds = self::open_seconds( $open_count );
					$row = self::transition_locked( $row, self::STATE_OPEN, $breaker_failure ? 'half_open_probe_failed' : 'half_open_probe_inconclusive', $failure_class, array(
						'failure_count'=>self::FAILURE_THRESHOLD,'open_count'=>$open_count,'open_until'=>$now+$seconds,'probe_token_sha256'=>'','probe_expires_at'=>null
					) );
				}
				if ( is_wp_error($row) ) throw new RuntimeException($row->get_error_code());
			} elseif ( self::STATE_CLOSED === $state ) {
				if ( $breaker_failure ) {
					$failures=(int)$row['failure_count']+1;
					$next=$failures>=self::FAILURE_THRESHOLD?self::STATE_OPEN:self::STATE_CLOSED;
					$open_count=(int)$row['open_count']+(self::STATE_OPEN===$next?1:0);
					$open_until=self::STATE_OPEN===$next?$now+self::open_seconds($open_count):null;
					$row=self::transition_locked($row,$next,self::STATE_OPEN===$next?'failure_threshold_opened':'transport_failure_recorded',$failure_class,array(
						'failure_count'=>$failures,'open_count'=>$open_count,'open_until'=>$open_until,'probe_token_sha256'=>'','probe_expires_at'=>null
					));
					if(is_wp_error($row))throw new RuntimeException($row->get_error_code());
				} elseif ( $transport_ok && (int)$row['failure_count'] > 0 ) {
					$row=self::transition_locked($row,self::STATE_CLOSED,'transport_health_restored','',array(
						'failure_count'=>0,'open_count'=>(int)$row['open_count'],'open_until'=>null,'probe_token_sha256'=>'','probe_expires_at'=>null
					));
					if(is_wp_error($row))throw new RuntimeException($row->get_error_code());
				}
			}
			$committed=MAD4B_SCP_Database_Transaction_Guard::commit($tx); if(is_wp_error($committed))return $committed;
			$status=self::status($identity['provider_id'],$identity['site_uuid'],$identity['certification_generation_sha256']);
			if(is_array($status))$status['recorded']=true;
			return $status;
		} catch(Throwable $error) {
			MAD4B_SCP_Database_Transaction_Guard::rollback($tx);
			return new WP_Error('mad4b_provider_circuit_breaker_result_failed','Provider circuit breaker result persistence failed closed.',array('reason_code'=>sanitize_key($error->getMessage()),'authority_effect'=>'none'));
		}
	}

	public static function status( $provider_id, $site_uuid, $generation_sha256 ) {
		global $wpdb;
		$identity=self::identity($provider_id,$site_uuid,$generation_sha256); if(is_wp_error($identity))return $identity;
		$t=MAD4B_SCP_Schema::tables();
		$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['provider_breakers']} WHERE BINARY breaker_key_sha256=BINARY %s LIMIT 1",$identity['breaker_key_sha256']),ARRAY_A);
		if(!is_array($row)) return array_merge($identity,array(
			'contract'=>self::CONTRACT,'state'=>self::STATE_CLOSED,'failure_count'=>0,'open_count'=>0,'revision'=>0,
			'event_count'=>0,'event_chain_valid'=>true,'transport_eligible'=>true,'probe_required'=>false,
			'probe_restores_transport_only'=>true,'certification_granted'=>false,'authority_granted'=>false,'approval_granted'=>false,
			'production_eligibility_granted'=>false,'authority_effect'=>'none','read_only'=>true,'mutation_performed'=>false,'authorizing'=>false
		));
		$events=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['provider_breaker_events']} WHERE BINARY breaker_key_sha256=BINARY %s ORDER BY sequence ASC",$identity['breaker_key_sha256']),ARRAY_A);
		$chain=self::verify_chain($row,is_array($events)?$events:array()); if(is_wp_error($chain))return $chain;
		$state=(string)$row['state'];
		return array_merge($identity,array(
			'contract'=>self::CONTRACT,'state'=>$state,'failure_count'=>(int)$row['failure_count'],'open_count'=>(int)$row['open_count'],
			'open_until_epoch'=>self::mysql_epoch($row['open_until']),'probe_expires_at_epoch'=>self::mysql_epoch($row['probe_expires_at']),
			'revision'=>(int)$row['revision'],'event_count'=>count($events),'event_chain_valid'=>true,
			'transport_eligible'=>self::STATE_CLOSED===$state,'probe_required'=>self::STATE_HALF_OPEN===$state,
			'probe_restores_transport_only'=>true,'certification_granted'=>false,'authority_granted'=>false,'approval_granted'=>false,
			'production_eligibility_granted'=>false,'authority_effect'=>'none','read_only'=>true,'mutation_performed'=>false,'authorizing'=>false
		));
	}

	public static function target_context( $surface, $target ) {
		$surface=sanitize_key((string)$surface);$target=trim((string)$target);
		if(''===$target||!class_exists('MAD4B_SCP_Servers')||!class_exists('MAD4B_SCP_Provider_Compatibility_Certification')){
			return array('contract'=>self::CONTRACT,'applicable'=>false,'transport_eligible'=>true,'authority_effect'=>'none','authorizing'=>false);
		}
		$servers=array_values(array_unique(array_filter(array('mad4b-'.$surface,'mad4b-read','mad4b-content','mad4b-write','mad4b-admin'))));
		$provider='';
		foreach($servers as $server_id){$candidate=MAD4B_SCP_Servers::provider_for_ability($server_id,$target);if(is_string($candidate)&&''!==$candidate&&'core'!==$candidate&&'dynamic_projection'!==$candidate){$provider=sanitize_key($candidate);break;}}
		if(''===$provider||!MAD4B_SCP_Provider_Compatibility_Certification::supports_provider($provider)){
			return array('contract'=>self::CONTRACT,'applicable'=>false,'transport_eligible'=>true,'authority_effect'=>'none','authorizing'=>false);
		}
		$site=class_exists('MAD4B_SCP_Site_Profile')?(string)MAD4B_SCP_Site_Profile::site_uuid():'';
		$generation=MAD4B_SCP_Provider_Compatibility_Certification::certification_generation_sha256($provider);
		$identity=self::identity($provider,$site,$generation);
		if(is_wp_error($identity))return new WP_Error('mad4b_provider_circuit_breaker_context_unavailable','Provider breaker context is not bound to exact site/certification generation.',array('provider_id'=>$provider,'authority_effect'=>'none'));
		$identity['contract']=self::CONTRACT;$identity['applicable']=true;$identity['transport_eligible']=true;$identity['authority_effect']='none';$identity['authorizing']=false;
		return $identity;
	}

	private static function identity( $provider_id, $site_uuid, $generation_sha256 ) {
		$provider_id=sanitize_key((string)$provider_id);$site_uuid=strtolower(trim((string)$site_uuid));$generation_sha256=strtolower(trim((string)$generation_sha256));
		if(''===$provider_id||1!==preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$site_uuid)||1!==preg_match('/^[a-f0-9]{64}$/D',$generation_sha256))return new WP_Error('mad4b_provider_circuit_breaker_identity_invalid','Provider breaker identity must bind provider, canonical Site UUID and certification generation.');
		return array('provider_id'=>$provider_id,'site_uuid'=>$site_uuid,'certification_generation_sha256'=>$generation_sha256,'breaker_key_sha256'=>hash('sha256','mad4b.provider-breaker-key.v1|'.$provider_id.'|'.$site_uuid.'|'.$generation_sha256));
	}
	private static function locked_row($key){global $wpdb;$t=MAD4B_SCP_Schema::tables();return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['provider_breakers']} WHERE BINARY breaker_key_sha256=BINARY %s FOR UPDATE",$key),ARRAY_A);}
	private static function transition_locked(array $row,$next_state,$event_type,$failure_class,array $fields){
		global $wpdb;$t=MAD4B_SCP_Schema::tables();$sequence=(int)$row['revision']+1;$previous=(string)$row['latest_event_sha256'];
		$metadata=array('failure_count'=>(int)$fields['failure_count'],'open_count'=>(int)$fields['open_count'],'open_until_epoch'=>null===$fields['open_until']?0:(int)$fields['open_until'],'probe_expires_at_epoch'=>null===$fields['probe_expires_at']?0:(int)$fields['probe_expires_at']);
		$payload=array('contract'=>self::EVENT_CONTRACT,'breaker_key_sha256'=>(string)$row['breaker_key_sha256'],'sequence'=>$sequence,'event_type'=>sanitize_key($event_type),'from_state'=>(string)$row['state'],'to_state'=>(string)$next_state,'failure_class'=>sanitize_key($failure_class),'safe_metadata'=>$metadata,'previous_event_sha256'=>$previous);
		$event_sha=hash('sha256',self::canonical_json($payload));$metadata_json=self::canonical_json($metadata);
		$insert=$wpdb->query($wpdb->prepare("INSERT INTO {$t['provider_breaker_events']} (breaker_key_sha256,sequence,event_type,from_state,to_state,failure_class,safe_metadata_json,previous_event_sha256,event_sha256,created_at) VALUES (%s,%d,%s,%s,%s,%s,%s,%s,%s,UTC_TIMESTAMP())",$row['breaker_key_sha256'],$sequence,sanitize_key($event_type),$row['state'],$next_state,sanitize_key($failure_class),$metadata_json,$previous,$event_sha));
		if(1!==(int)$insert)return new WP_Error('mad4b_provider_breaker_event_append_failed','Provider breaker transition evidence append failed.');
		$open_epoch=null===$fields['open_until']?0:max(0,(int)$fields['open_until']);$probe_epoch=null===$fields['probe_expires_at']?0:max(0,(int)$fields['probe_expires_at']);
		$updated=$wpdb->query($wpdb->prepare("UPDATE {$t['provider_breakers']} SET state=%s,failure_count=%d,open_count=%d,open_until=IF(%d>0,FROM_UNIXTIME(%d),NULL),probe_token_sha256=%s,probe_expires_at=IF(%d>0,FROM_UNIXTIME(%d),NULL),revision=%d,latest_event_sha256=%s,updated_at=UTC_TIMESTAMP() WHERE BINARY breaker_key_sha256=BINARY %s AND revision=%d AND BINARY latest_event_sha256=BINARY %s",$next_state,(int)$fields['failure_count'],(int)$fields['open_count'],$open_epoch,$open_epoch,(string)$fields['probe_token_sha256'],$probe_epoch,$probe_epoch,$sequence,$event_sha,$row['breaker_key_sha256'],(int)$row['revision'],$previous));
		if(1!==(int)$updated)return new WP_Error('mad4b_provider_breaker_head_cas_failed','Provider breaker head CAS failed.');
		$row['state']=$next_state;$row['failure_count']=(int)$fields['failure_count'];$row['open_count']=(int)$fields['open_count'];$row['open_until']=$open_epoch>0?gmdate('Y-m-d H:i:s',$open_epoch):null;$row['probe_token_sha256']=(string)$fields['probe_token_sha256'];$row['probe_expires_at']=$probe_epoch>0?gmdate('Y-m-d H:i:s',$probe_epoch):null;$row['revision']=$sequence;$row['latest_event_sha256']=$event_sha;return $row;
	}
	private static function verify_chain(array $row,array $events){$sequence=1;$previous=str_repeat('0',64);foreach($events as $event){if((int)$event['sequence']!==$sequence||!hash_equals($previous,(string)$event['previous_event_sha256']))return new WP_Error('mad4b_provider_breaker_event_chain_invalid','Provider breaker event sequence/hash chain is invalid.');$metadata=json_decode((string)$event['safe_metadata_json'],true);if(!is_array($metadata))$metadata=array();$payload=array('contract'=>self::EVENT_CONTRACT,'breaker_key_sha256'=>(string)$event['breaker_key_sha256'],'sequence'=>(int)$event['sequence'],'event_type'=>(string)$event['event_type'],'from_state'=>(string)$event['from_state'],'to_state'=>(string)$event['to_state'],'failure_class'=>(string)$event['failure_class'],'safe_metadata'=>$metadata,'previous_event_sha256'=>(string)$event['previous_event_sha256']);$sha=hash('sha256',self::canonical_json($payload));if(!hash_equals($sha,(string)$event['event_sha256']))return new WP_Error('mad4b_provider_breaker_event_hash_invalid','Provider breaker event hash is invalid.');$previous=$sha;$sequence++;}if((int)$row['revision']!==count($events)||!hash_equals((string)$row['latest_event_sha256'],$previous))return new WP_Error('mad4b_provider_breaker_event_head_invalid','Provider breaker head disagrees with transition evidence.');return true;}
	private static function admission_receipt(array $identity,array $row,$probe,$probe_token){return array_merge($identity,array('contract'=>self::CONTRACT,'applicable'=>true,'state'=>(string)$row['state'],'transport_eligible'=>true,'half_open_probe'=>(bool)$probe,'probe_token'=>(string)$probe_token,'breaker_revision'=>(int)$row['revision'],'max_read_attempts'=>$probe?1:2,'probe_restores_transport_only'=>true,'certification_granted'=>false,'authority_granted'=>false,'approval_granted'=>false,'production_eligibility_granted'=>false,'authority_effect'=>'none','authorizing'=>false));}
	private static function is_breaker_failure($failure_class){return in_array(sanitize_key((string)$failure_class),array('timeout','transport','upstream_unavailable','session_terminated','rate_limit'),true);}
	private static function open_seconds($open_count){$power=max(0,min(4,(int)$open_count-1));$requested=min(self::MAX_OPEN_SECONDS,self::BASE_OPEN_SECONDS*(int)pow(2,$power));if(class_exists('MAD4B_SCP_Time_Policy')){$v=MAD4B_SCP_Time_Policy::bounded_ttl('provider_breaker',$requested);if(!is_wp_error($v))return(int)$v;}return$requested;}
	private static function new_probe_token($key,$revision){$entropy=class_exists('MAD4B_SCP_Entropy')?MAD4B_SCP_Entropy::hex('provider_breaker_probe',16):'';if(is_wp_error($entropy)||''===$entropy){try{$entropy=bin2hex(random_bytes(16));}catch(Throwable $error){$entropy=hash('sha256',$key.'|'.(int)$revision.'|'.self::monotonic_ms());}}return hash('sha256','mad4b.provider-breaker-probe.v1|'.$key.'|'.(int)$revision.'|'.$entropy);}
	private static function mysql_epoch($value){if(null===$value||''===$value)return 0;$v=strtotime((string)$value.' UTC');return false===$v?0:(int)$v;}
	private static function canonical_json($value){return wp_json_encode(self::canonicalize($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
	private static function canonicalize($value){if(!is_array($value))return $value;$list=empty($value)||array_keys($value)===range(0,count($value)-1);if($list)return array_map(array(__CLASS__,'canonicalize'),$value);ksort($value,SORT_STRING);foreach($value as $k=>$v)$value[$k]=self::canonicalize($v);return $value;}

	private static function now_epoch(){return class_exists('MAD4B_SCP_Time_Policy')?MAD4B_SCP_Time_Policy::now_epoch():time();}
	private static function monotonic_ms(){return class_exists('MAD4B_SCP_Time_Policy')?MAD4B_SCP_Time_Policy::monotonic_ms():(int)floor(microtime(true)*1000);}
}
