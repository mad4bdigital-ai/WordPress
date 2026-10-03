<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bounded, non-authorizing distributed observability for governed execution.
 *
 * Trace/metric failure is intentionally optional for safe reads. This layer is
 * never a substitute for mandatory audit, approval, journal or execution
 * evidence; governed writes keep their existing fail-closed evidence semantics.
 */
final class MAD4B_SCP_Observability {
	const CONTRACT = 'mad4b.observability.v1';
	const TRACE_CONTRACT = 'mad4b.trace-context.v1';
	const PROFILE_CONTRACT = 'mad4b.observability-slo-profile.v1';
	const TRACE_VERSION = '00';
	const MAX_ATTRIBUTES = 16;
	const MAX_DEPTH = 6;
	const MAX_ITEMS = 50;
	const MAX_STRING_BYTES = 512;
	private static $profile = null;
	private static $current = null;

	public static function reset_request() { self::$current = null; }

	public static function begin_trace( $tenant_scope = '', $incoming_traceparent = '' ) {
		$tenant = self::tenant_hash( $tenant_scope );
		$parsed = self::parse_traceparent( $incoming_traceparent );
		if ( is_wp_error( $parsed ) ) $parsed = array();
		$trace_id = isset( $parsed['trace_id'] ) ? (string) $parsed['trace_id'] : self::random_hex( 16 );
		$parent_span_id = isset( $parsed['span_id'] ) ? (string) $parsed['span_id'] : '';
		$sampled = isset( $parsed['sampled'] ) ? (bool) $parsed['sampled'] : self::sampled( $trace_id );
		$span_id = self::random_hex( 8 );
		$context = array(
			'contract'=>self::TRACE_CONTRACT,
			'trace_id'=>$trace_id,
			'span_id'=>$span_id,
			'parent_span_id'=>$parent_span_id,
			'trace_flags'=>$sampled?'01':'00',
			'sampled'=>$sampled,
			'tenant_scope_sha256'=>$tenant,
			'traceparent'=>self::traceparent( $trace_id, $span_id, $sampled ),
			'causal_link_sha256'=>'',
			'authorizing'=>false,
		);
		self::$current = $context;
		return $context;
	}

	public static function current( $tenant_scope = '' ) {
		if ( ! is_array( self::$current ) ) return self::begin_trace( $tenant_scope );
		if ( '' === trim( (string) $tenant_scope ) ) return self::$current;
		$tenant = self::tenant_hash( $tenant_scope );
		if ( ! hash_equals( (string)self::$current['tenant_scope_sha256'], $tenant ) ) {
			return self::fork_for_tenant( self::$current, $tenant_scope );
		}
		return self::$current;
	}

	public static function child( $stage, $tenant_scope = '', array $attributes = array() ) {
		$stage = sanitize_key( (string)$stage );
		$profile = self::profile();
		if ( is_wp_error( $profile ) ) return $profile;
		if ( ! isset( $profile['stages'][ $stage ] ) ) return new WP_Error( 'mad4b_observability_stage_unknown', 'Observability stage is not registered.' );
		$parent = self::current( $tenant_scope );
		if ( is_wp_error( $parent ) ) return $parent;
		$span_id = self::random_hex( 8 );
		return array(
			'contract'=>self::TRACE_CONTRACT,
			'stage'=>$stage,
			'trace_id'=>(string)$parent['trace_id'],
			'span_id'=>$span_id,
			'parent_span_id'=>(string)$parent['span_id'],
			'trace_flags'=>(string)$parent['trace_flags'],
			'sampled'=>!empty($parent['sampled']),
			'tenant_scope_sha256'=>(string)$parent['tenant_scope_sha256'],
			'traceparent'=>self::traceparent( (string)$parent['trace_id'], $span_id, !empty($parent['sampled']) ),
			'causal_link_sha256'=>isset($parent['causal_link_sha256'])?(string)$parent['causal_link_sha256']:'',
			'attributes'=>self::bounded_attributes( $attributes ),
			'authorizing'=>false,
		);
	}

	public static function fork_for_tenant( array $parent, $tenant_scope ) {
		$tenant = self::tenant_hash( $tenant_scope );
		if ( '' === $tenant ) return new WP_Error( 'mad4b_observability_tenant_scope_required', 'Cross-tenant trace fork requires a bounded tenant scope.' );
		$parent_trace = isset($parent['trace_id']) ? strtolower((string)$parent['trace_id']) : '';
		$parent_span = isset($parent['span_id']) ? strtolower((string)$parent['span_id']) : '';
		if ( ! self::valid_hex( $parent_trace, 32 ) || ! self::valid_hex( $parent_span, 16 ) ) return new WP_Error( 'mad4b_observability_parent_trace_invalid', 'Parent trace identity is invalid.' );
		$trace_id = substr( hash( 'sha256', 'mad4b.trace-tenant-fork.v1|' . $parent_trace . '|' . $tenant ), 0, 32 );
		if ( str_repeat('0',32) === $trace_id ) $trace_id = self::random_hex(16);
		$span_id = self::random_hex(8);
		$sampled = !empty($parent['sampled']);
		$link = hash( 'sha256', 'mad4b.trace-causal-link.v1|' . $parent_trace . '|' . $parent_span . '|' . $trace_id . '|' . $span_id );
		$child = array(
			'contract'=>self::TRACE_CONTRACT,
			'trace_id'=>$trace_id,
			'span_id'=>$span_id,
			'parent_span_id'=>'',
			'trace_flags'=>$sampled?'01':'00',
			'sampled'=>$sampled,
			'tenant_scope_sha256'=>$tenant,
			'traceparent'=>self::traceparent($trace_id,$span_id,$sampled),
			'causal_link_sha256'=>$link,
			'cross_tenant_trace_id_reused'=>false,
			'authorizing'=>false,
		);
		// A cross-tenant fork is returned to the caller but never becomes the
		// request-global current context. Fan-out children therefore cannot
		// contaminate the origin site's subsequent spans.
		return $child;
	}

	public static function propagation_headers( array $context, $tenant_scope ) {
		$tenant = self::tenant_hash( $tenant_scope );
		if ( '' === $tenant || empty($context['tenant_scope_sha256']) || ! hash_equals((string)$context['tenant_scope_sha256'],$tenant) ) {
			return new WP_Error( 'mad4b_observability_cross_tenant_propagation_denied', 'Trace context cannot be exported across a different tenant scope.' );
		}
		$traceparent = isset($context['traceparent'])?(string)$context['traceparent']:'';
		$parsed = self::parse_traceparent($traceparent);
		if ( is_wp_error($parsed) ) return $parsed;
		return array('traceparent'=>$traceparent,'authority_effect'=>'none','authorizing'=>false);
	}

	public static function start_clock() {
		return function_exists('hrtime') ? hrtime(true) : microtime(true);
	}

	public static function run_stage( $stage, $callback, $tenant_scope = '', array $attributes = array() ) {
		if ( ! is_callable( $callback ) ) return new WP_Error( 'mad4b_observability_callback_invalid', 'Observed stage requires a callable.' );
		$span = self::child( $stage, $tenant_scope, $attributes );
		// child() resolves/creates the parent request context but does not mutate
		// it. Capture that parent and make the child current only for the callback
		// scope so nested governed stages inherit an exact causal parent span.
		$parent = self::$current;
		if ( ! is_wp_error( $span ) ) self::$current = $span;
		$started = self::start_clock();
		try {
			$result = call_user_func( $callback );
			if ( ! is_wp_error( $span ) ) {
				// Telemetry is intentionally best-effort: its result never
				// replaces or upgrades the authoritative callback result.
				self::finish_span( $span, $started, ! is_wp_error( $result ), array(
					'result_class' => is_wp_error( $result ) ? 'wp_error' : 'success',
					'error_code' => is_wp_error( $result ) ? sanitize_key( (string) $result->get_error_code() ) : '',
				) );
			}
			return $result;
		} catch ( Throwable $error ) {
			if ( ! is_wp_error( $span ) ) self::finish_span( $span, $started, false, array(
				'result_class' => 'exception',
				'error_class' => get_class( $error ),
			) );
			throw $error;
		} finally {
			self::$current = $parent;
		}
	}

	public static function finish_span( $span, $started, $success = true, array $attributes = array() ) {
		if ( is_wp_error($span) || !is_array($span) ) return array('contract'=>self::CONTRACT,'recorded'=>false,'telemetry_error_code'=>'invalid_span','authorizing'=>false);
		$now = self::start_clock();
		$duration_ms = is_int($started) && is_int($now) ? max(0,(int)round(($now-$started)/1000000)) : max(0,(int)round(((float)$now-(float)$started)*1000));
		$stage = isset($span['stage']) ? (string)$span['stage'] : '';
		$attrs = array_merge(isset($span['attributes'])&&is_array($span['attributes'])?$span['attributes']:array(),$attributes);
		return self::record_stage($stage,$duration_ms,$success,$attrs);
	}

	public static function record_stage( $stage, $duration_ms, $success = true, array $attributes = array() ) {
		$stage = sanitize_key((string)$stage);
		$profile = self::profile();
		if ( is_wp_error($profile) || !isset($profile['stages'][$stage]) ) {
			return array('contract'=>self::CONTRACT,'stage'=>$stage,'recorded'=>false,'telemetry_error_code'=>is_wp_error($profile)?$profile->get_error_code():'stage_unknown','authorizing'=>false);
		}
		$duration_ms=max(0,min(600000,(int)$duration_ms));
		$attrs=self::bounded_attributes($attributes);
		if ( ! class_exists('MAD4B_SCP_Runtime_Metrics') ) return self::optional_failure($stage,'runtime_metrics_unavailable',$attrs);
		$metric_base='obs.stage.'.$stage;
		$names=array(
			array($metric_base.'.count',1),
			array($metric_base.'.'.($success?'success':'error'),1),
			array($metric_base.'.duration_ms',$duration_ms),
		);
		foreach($profile['histogram_ms'] as $boundary){
			if($duration_ms<=(int)$boundary)$names[]=array($metric_base.'.le'.str_pad((string)(int)$boundary,6,'0',STR_PAD_LEFT),1);
		}
		foreach($names as $metric){
			$r=MAD4B_SCP_Runtime_Metrics::record($metric[0],$metric[1]);
			if(is_wp_error($r)) return self::optional_failure($stage,$r->get_error_code(),$attrs);
		}
		return array(
			'contract'=>self::CONTRACT,'stage'=>$stage,'duration_ms'=>$duration_ms,'success'=>(bool)$success,
			'recorded'=>true,'attributes'=>$attrs,'optional_telemetry'=>true,'authority_effect'=>'none','authorizing'=>false
		);
	}

	public static function slo_status( $hours = 24 ) {
		$profile=self::profile();
		if(is_wp_error($profile))return $profile;
		$hours=max(1,min(720,(int)$hours));
		$stages=array();
		foreach($profile['stages'] as $stage=>$policy){
			$base='obs.stage.'.$stage;
			$names=array($base.'.count',$base.'.error');
			foreach($profile['histogram_ms'] as $boundary)$names[]=$base.'.le'.str_pad((string)(int)$boundary,6,'0',STR_PAD_LEFT);
			$summary=class_exists('MAD4B_SCP_Runtime_Metrics')?MAD4B_SCP_Runtime_Metrics::summary($names,$hours):new WP_Error('mad4b_metric_storage_unavailable','Metric storage unavailable.');
			if(is_wp_error($summary)){ $stages[$stage]=array('ready'=>false,'reason_code'=>$summary->get_error_code(),'authority_effect'=>'none'); continue; }
			$map=array();
			foreach(isset($summary['metrics'])&&is_array($summary['metrics'])?$summary['metrics']:array() as $row)$map[(string)$row['name']]=(int)$row['count'];
			$total=isset($map[$base.'.count'])?(int)$map[$base.'.count']:0;
			$errors=isset($map[$base.'.error'])?(int)$map[$base.'.error']:0;
			$buckets=array();
			foreach($profile['histogram_ms'] as $boundary){
				$name=$base.'.le'.str_pad((string)(int)$boundary,6,'0',STR_PAD_LEFT);
				$buckets[(int)$boundary]=isset($map[$name])?(int)$map[$name]:0;
			}
			$q=self::quantiles($buckets,$total);
			$objective=(float)$policy['availability_objective'];
			$error_ratio=$total>0?$errors/$total:0.0;
			$allowed=max(0.000001,1.0-$objective);
			$burn=$error_ratio/$allowed;
			$warning=max(0.0,(float)$profile['burn_rate']['warning_multiplier']);
			$critical=max($warning,(float)$profile['burn_rate']['critical_multiplier']);
			$burn_state=$burn >= $critical ? 'critical' : ( $burn >= $warning ? 'warning' : 'healthy' );
			$operator_action='none';
			if('critical'===$burn_state)$operator_action='investigate_and_mitigate';
			elseif('warning'===$burn_state)$operator_action='review_error_budget';
			$stages[$stage]=array(
				'ready'=>true,'samples'=>$total,'errors'=>$errors,'error_ratio'=>round($error_ratio,6),
				'p50_ms'=>$q['p50_ms'],'p95_ms'=>$q['p95_ms'],'p99_ms'=>$q['p99_ms'],
				'p95_objective_ms'=>(int)$policy['p95_ms'],'availability_objective'=>$objective,
				'burn_rate'=>round($burn,3),'burn_state'=>$burn_state,
				'burn_warning_multiplier'=>$warning,'burn_critical_multiplier'=>$critical,
				'operator_action'=>$operator_action,'operator_action_authorizing'=>false,
				'within_p95_objective'=>0===$total||$q['p95_ms']<=(int)$policy['p95_ms'],
				'authority_effect'=>'none'
			);
		}
		return array(
			'contract'=>self::CONTRACT,'hours'=>$hours,'stages'=>$stages,
			'error_budget_policy'=>$profile['burn_rate'],'operator_evidence_only'=>true,
			'telemetry_grants_authority'=>false,'read_only'=>true,'mutation_performed'=>false,'authorizing'=>false
		);
	}

	public static function error_budget_status() {
		$profile=self::profile();
		if(is_wp_error($profile))return $profile;
		$short=max(1,(int)$profile['burn_rate']['short_window_hours']);
		$long=max($short,(int)$profile['burn_rate']['long_window_hours']);
		$short_report=self::slo_status($short);
		$long_report=self::slo_status($long);
		if(is_wp_error($short_report))return $short_report;
		if(is_wp_error($long_report))return $long_report;
		$stages=array();
		foreach(array_keys($profile['stages']) as $stage){
			$s=isset($short_report['stages'][$stage])?$short_report['stages'][$stage]:array();
			$l=isset($long_report['stages'][$stage])?$long_report['stages'][$stage]:array();
			$states=array(isset($s['burn_state'])?$s['burn_state']:'unknown',isset($l['burn_state'])?$l['burn_state']:'unknown');
			$severity=in_array('critical',$states,true)?'critical':(in_array('warning',$states,true)?'warning':(in_array('unknown',$states,true)?'unknown':'healthy'));
			$stages[$stage]=array(
				'short_window_hours'=>$short,'long_window_hours'=>$long,
				'short_burn_rate'=>isset($s['burn_rate'])?$s['burn_rate']:null,
				'long_burn_rate'=>isset($l['burn_rate'])?$l['burn_rate']:null,
				'combined_burn_state'=>$severity,
				'operator_evidence_only'=>true,'authority_effect'=>'none','authorizing'=>false
			);
		}
		return array(
			'contract'=>self::CONTRACT,'stages'=>$stages,'burn_rate_policy'=>$profile['burn_rate'],
			'operator_evidence_only'=>true,'telemetry_grants_authority'=>false,
			'read_only'=>true,'mutation_performed'=>false,'authorizing'=>false
		);
	}

	public static function quantiles( array $cumulative_buckets, $total ) {
		$total=max(0,(int)$total); ksort($cumulative_buckets,SORT_NUMERIC);
		$out=array();
		foreach(array('p50_ms'=>0.50,'p95_ms'=>0.95,'p99_ms'=>0.99) as $key=>$fraction){
			$value=0;
			if($total>0){
				$need=(int)ceil($total*$fraction);
				foreach($cumulative_buckets as $boundary=>$count){if((int)$count>=$need){$value=(int)$boundary;break;}}
				if(0===$value&&!empty($cumulative_buckets))$value=(int)max(array_keys($cumulative_buckets));
			}
			$out[$key]=$value;
		}
		return $out;
	}

	public static function bounded_attributes( array $attributes ) {
		$out=array(); $count=0;
		foreach($attributes as $key=>$value){
			if($count>=self::MAX_ATTRIBUTES)break;
			$key=sanitize_key((string)$key);
			if(''===$key)continue;
			if(self::sensitive_key($key)){ $out[$key]='[REDACTED]'; ++$count; continue; }
			if(in_array($key,array('site_uuid','tenant_id','user_id','subject_id','client_id','provider_account_id'),true)){
				$out[$key.'_sha256']=hash('sha256','mad4b.obs-label.v1|'.self::scalar_string($value)); ++$count; continue;
			}
			$out[$key]=self::redact($value,0); ++$count;
		}
		return $out;
	}

	public static function redact( $value, $depth = 0 ) {
		if ( 0 === (int)$depth && class_exists( 'MAD4B_SCP_Structural_Redaction' ) ) return MAD4B_SCP_Structural_Redaction::redact( $value, 'observability' );
		if($depth>=self::MAX_DEPTH)return '[TRUNCATED]';
		if(is_object($value))$value=get_object_vars($value);
		if(is_array($value)){
			$out=array();$count=0;
			foreach($value as $key=>$item){
				if($count>=self::MAX_ITEMS){$out['_truncated']=true;break;}
				$key_string=is_string($key)?$key:(string)$key;
				$out[$key]=self::sensitive_key($key_string)?'[REDACTED]':self::redact($item,$depth+1);
				++$count;
			}
			return $out;
		}
		if(is_string($value)){
			if(strlen($value)<=self::MAX_STRING_BYTES)return $value;
			return substr($value,0,self::MAX_STRING_BYTES).'…[TRUNCATED]';
		}
		if(is_int($value)||is_float($value)||is_bool($value)||null===$value)return $value;
		return '[REDACTED]';
	}

	public static function failure_semantics() {
		return array(
			'contract'=>self::CONTRACT,
			'optional_telemetry_failure_blocks_safe_reads'=>false,
			'optional_telemetry_failure_grants_authority'=>false,
			'mandatory_audit_or_execution_evidence_failure_blocks_governed_write'=>true,
			'observability_is_not_audit_authority'=>true,
			'authorizing'=>false
		);
	}

	public static function parse_traceparent( $value ) {
		$value=strtolower(trim((string)$value));
		if(''===$value)return new WP_Error('mad4b_traceparent_missing','Traceparent is absent.');
		if(1!==preg_match('/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/D',$value,$m))return new WP_Error('mad4b_traceparent_invalid','Traceparent format is invalid.');
		if('00'!==$m[1]||str_repeat('0',32)===$m[2]||str_repeat('0',16)===$m[3])return new WP_Error('mad4b_traceparent_invalid','Traceparent identity is invalid.');
		return array('version'=>$m[1],'trace_id'=>$m[2],'span_id'=>$m[3],'flags'=>$m[4],'sampled'=>(1&(hexdec($m[4])))===1);
	}

	private static function profile() {
		if(null!==self::$profile)return self::$profile;
		$path=dirname(__DIR__).'/config/observability-slo-profiles.json';
		if(!is_readable($path))return self::$profile=new WP_Error('mad4b_observability_profile_missing','Observability SLO profile is unavailable.');
		$data=json_decode((string)file_get_contents($path),true);
		if(!is_array($data)||self::PROFILE_CONTRACT!==(isset($data['contract'])?(string)$data['contract']:'')||empty($data['stages'])||!is_array($data['stages'])||empty($data['histogram_ms'])||!is_array($data['histogram_ms']))return self::$profile=new WP_Error('mad4b_observability_profile_invalid','Observability SLO profile is invalid.');
		foreach($data['stages'] as $stage=>$policy){
			if(sanitize_key((string)$stage)!==(string)$stage||!is_array($policy)||empty($policy['p95_ms'])||!isset($policy['availability_objective']))return self::$profile=new WP_Error('mad4b_observability_profile_invalid','Observability stage policy is invalid.');
		}
		return self::$profile=$data;
	}

	private static function optional_failure( $stage, $code, array $attrs ) {
		return array(
			'contract'=>self::CONTRACT,'stage'=>$stage,'recorded'=>false,'telemetry_error_code'=>sanitize_key((string)$code),
			'attributes'=>$attrs,'safe_read_blocked'=>false,'authority_effect'=>'none','authorizing'=>false
		);
	}

	private static function tenant_hash( $scope ) {
		$scope=trim((string)$scope);
		return ''===$scope ? hash('sha256','mad4b.obs-tenant.v1|site-default') : hash('sha256','mad4b.obs-tenant.v1|'.$scope);
	}
	private static function traceparent( $trace_id, $span_id, $sampled ) { return self::TRACE_VERSION.'-'.$trace_id.'-'.$span_id.'-'.($sampled?'01':'00'); }
	private static function sampled( $trace_id ) {
		$profile=self::profile(); $bps=is_wp_error($profile)?0:max(0,min(10000,(int)$profile['sampling_basis_points']));
		return (hexdec(substr((string)$trace_id,0,8))%10000)<$bps;
	}
	private static function random_hex( $bytes ) {
		try{$hex=bin2hex(random_bytes((int)$bytes));}catch(Throwable $e){$hex=substr(hash('sha256',microtime(true).'|'.wp_generate_uuid4().'|'.mt_rand()),0,$bytes*2);}
		return str_repeat('0',$bytes*2)===$hex ? substr(hash('sha256',$hex.'|nonzero'),0,$bytes*2) : $hex;
	}
	private static function valid_hex( $value, $length ) { return strlen((string)$value)===(int)$length && 1===preg_match('/^[a-f0-9]+$/D',(string)$value) && str_repeat('0',$length)!==(string)$value; }
	private static function sensitive_key( $key ) { return class_exists( 'MAD4B_SCP_Structural_Redaction' ) ? MAD4B_SCP_Structural_Redaction::sensitive_key( $key ) : 1===preg_match('/(?:authorization|bearer|token|secret|password|passwd|cookie|credential|api[_-]?key|client[_-]?secret|raw(?:_|$)|payload|prompt|post_content|request_body|response_body)/i',(string)$key); }
	private static function scalar_string( $value ) { return is_scalar($value)?(string)$value:wp_json_encode(self::redact($value)); }
}
