<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Time_Policy {
	const CONTRACT = 'mad4b.time-policy.v1';
	private static $catalog = null;
	private static $test_wall = null;
	private static $test_monotonic_ms = null;

	public static function clear_cache() { self::$catalog = null; }
	public static function now_epoch() { return null !== self::$test_wall ? (int) self::$test_wall : time(); }
	public static function monotonic_ms() {
		if ( null !== self::$test_monotonic_ms ) return (int) self::$test_monotonic_ms;
		if ( function_exists( 'hrtime' ) ) { $value = hrtime( true ); if ( is_int( $value ) || is_float( $value ) ) return (int) floor( (float) $value / 1000000 ); }
		return (int) floor( microtime( true ) * 1000 );
	}
	public static function set_test_clock( $wall_epoch, $monotonic_ms ) {
		if ( ! defined( 'MAD4B_SCP_TEST_RUNTIME' ) || true !== MAD4B_SCP_TEST_RUNTIME ) return new WP_Error( 'mad4b_time_test_clock_forbidden', 'Clock injection is available only in the explicit test runtime.' );
		$wall_epoch=(int)$wall_epoch;$monotonic_ms=(int)$monotonic_ms;
		if($wall_epoch<1||$monotonic_ms<0)return new WP_Error('mad4b_time_test_clock_invalid','Injected wall/monotonic clock is invalid.');
		self::$test_wall=$wall_epoch;self::$test_monotonic_ms=$monotonic_ms;return self::snapshot();
	}
	public static function advance_test_clock( $wall_seconds, $monotonic_delta_ms ) {
		if(null===self::$test_wall||null===self::$test_monotonic_ms)return new WP_Error('mad4b_time_test_clock_missing','Test clock has not been initialized.');
		self::$test_wall+=(int)$wall_seconds;self::$test_monotonic_ms=max(0,self::$test_monotonic_ms+(int)$monotonic_delta_ms);return self::snapshot();
	}
	public static function reset_test_clock(){self::$test_wall=null;self::$test_monotonic_ms=null;}
	public static function snapshot(){return array('contract'=>self::CONTRACT,'wall_epoch'=>self::now_epoch(),'monotonic_ms'=>self::monotonic_ms(),'test_clock_active'=>null!==self::$test_wall,'authorizing'=>false);}

	public static function bounded_ttl( $purpose, $requested = 0 ) {
		$policy=self::purpose($purpose);if(is_wp_error($policy))return$policy;$requested=(int)$requested;
		if($requested<=0)$requested=(int)$policy['default_ttl_seconds'];
		$min=max(0,(int)$policy['min_ttl_seconds']);$max=max($min,(int)$policy['max_ttl_seconds']);
		if(0===$max)return 0;return max($min,min($max,$requested));
	}
	public static function deadline_epoch($purpose,$requested_ttl=0){$ttl=self::bounded_ttl($purpose,$requested_ttl);return is_wp_error($ttl)?$ttl:self::now_epoch()+(int)$ttl;}
	public static function future_skew_seconds($purpose){$p=self::purpose($purpose);return is_wp_error($p)?0:max(0,(int)$p['max_future_skew_seconds']);}
	public static function assert_timestamp($purpose,$observed_epoch,$max_age_override=null){
		$p=self::purpose($purpose);if(is_wp_error($p))return$p;$observed=(int)$observed_epoch;$now=self::now_epoch();
		if($observed<1)return new WP_Error('mad4b_time_timestamp_invalid','Observed timestamp is invalid.',array('purpose'=>sanitize_key((string)$purpose)));
		$skew=max(0,(int)$p['max_future_skew_seconds']);
		if($observed>$now+$skew)return new WP_Error('mad4b_time_future_skew_exceeded','Observed timestamp exceeds the allowed future clock skew.',array('purpose'=>sanitize_key((string)$purpose),'future_seconds'=>$observed-$now,'max_future_skew_seconds'=>$skew));
		$age=max(0,$now-$observed);$max_age=null===$max_age_override?max(0,(int)$p['max_age_seconds']):max(0,(int)$max_age_override);
		if($max_age>0&&$age>$max_age)return new WP_Error('mad4b_time_observation_stale','Observed timestamp exceeds the allowed age window.',array('purpose'=>sanitize_key((string)$purpose),'age_seconds'=>$age,'max_age_seconds'=>$max_age));
		return array('contract'=>self::CONTRACT,'purpose'=>sanitize_key((string)$purpose),'observed_epoch'=>$observed,'now_epoch'=>$now,'age_seconds'=>$age,'max_future_skew_seconds'=>$skew,'max_age_seconds'=>$max_age,'authorizing'=>false);
	}
	public static function offline_window($risk_class,array $dependencies=array()){
		$c=self::catalog();if(is_wp_error($c))return$c;$risk=sanitize_key((string)$risk_class);
		if(empty($c['offline_authorization'][$risk])||!is_array($c['offline_authorization'][$risk]))return new WP_Error('mad4b_time_offline_risk_unknown','Offline authorization risk class is not registered.');
		$p=$c['offline_authorization'][$risk];$policy_max=max(0,(int)$p['max_age_seconds']);$effective=$policy_max;$age_max=0;$count=0;
		foreach($dependencies as $d){if(!is_array($d))return new WP_Error('mad4b_time_offline_dependency_invalid','Offline authorization dependency window is invalid.');if(isset($d['required'])&&false===(bool)$d['required'])continue;$age=max(0,(int)($d['age_seconds']??0));$ttl=max(0,(int)($d['ttl_seconds']??0));$age_max=max($age_max,$age);$count++;if($ttl>0)$effective=0===$effective?0:min($effective,$ttl);if($ttl>0&&$age>$ttl)return array('contract'=>self::CONTRACT,'risk_class'=>$risk,'allowed'=>false,'online_required'=>!empty($p['online_required']),'reason_code'=>'dependency_ttl_expired','policy_max_age_seconds'=>$policy_max,'effective_max_age_seconds'=>$effective,'observed_age_seconds'=>$age_max,'dependency_count'=>$count,'policy_sha256'=>self::policy_sha256(),'authorizing'=>false);}
		$online=!empty($p['online_required']);$allowed=!$online&&$effective>0&&$age_max<=$effective;
		return array('contract'=>self::CONTRACT,'risk_class'=>$risk,'allowed'=>$allowed,'online_required'=>$online,'reason_code'=>$online?'online_validation_required':($allowed?'within_offline_window':'offline_window_expired'),'policy_max_age_seconds'=>$policy_max,'effective_max_age_seconds'=>$effective,'observed_age_seconds'=>$age_max,'dependency_count'=>$count,'policy_sha256'=>self::policy_sha256(),'authorizing'=>false);
	}
	public static function policy_sha256(){$c=self::catalog();if(is_wp_error($c))return'';$json=function_exists('wp_json_encode')?wp_json_encode(self::canonicalize($c),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):json_encode(self::canonicalize($c),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);return is_string($json)?hash('sha256',$json):'';}
	private static function purpose($purpose){$c=self::catalog();if(is_wp_error($c))return$c;$purpose=sanitize_key((string)$purpose);if(''===$purpose||empty($c['purposes'][$purpose])||!is_array($c['purposes'][$purpose]))return new WP_Error('mad4b_time_purpose_unknown','Time policy purpose is not registered.');return$c['purposes'][$purpose];}
	private static function catalog(){if(is_array(self::$catalog))return self::$catalog;$path=defined('MAD4B_SCP_DIR')?MAD4B_SCP_DIR.'config/time-policy.json':dirname(__DIR__).'/config/time-policy.json';if(!is_readable($path))return new WP_Error('mad4b_time_policy_missing','Time policy catalog is unavailable.');$data=json_decode((string)file_get_contents($path),true);if(!is_array($data)||self::CONTRACT!==($data['contract']??'')||empty($data['purposes'])||!is_array($data['purposes'])||empty($data['offline_authorization'])||!is_array($data['offline_authorization']))return new WP_Error('mad4b_time_policy_invalid','Time policy catalog is invalid.');return self::$catalog=$data;}
	private static function canonicalize($v){if(!is_array($v))return$v;$list=empty($v)||array_keys($v)===range(0,count($v)-1);if($list)return array_map(array(__CLASS__,'canonicalize'),$v);ksort($v,SORT_STRING);foreach($v as$k=>$x)$v[$k]=self::canonicalize($x);return$v;}
}
