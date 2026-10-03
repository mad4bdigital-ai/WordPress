<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'MAD4B_SCP_Schema' ) ) require_once __DIR__ . '/class-mad4b-scp-schema.php';
if ( ! class_exists( 'MAD4B_SCP_Database_Transaction_Guard' ) ) require_once __DIR__ . '/class-mad4b-scp-database-transaction-guard.php';
if ( ! class_exists( 'MAD4B_SCP_Identifiers' ) ) require_once __DIR__ . '/class-mad4b-scp-identifiers.php';

/**
 * Durable, non-authorizing multisite/network orchestration journal.
 *
 * It owns only orchestration evidence. Credentials, catalog objects, approvals,
 * context and mutation authority remain site-local and are represented here by
 * exact per-site binding digests.
 */
final class MAD4B_SCP_Network_Operation_Journal {
	const CONTRACT = 'mad4b.network-operation-journal.v1';
	const EVENT_CONTRACT = 'mad4b.network-operation-event.v1';
	const TARGET_BINDING_DOMAIN = 'mad4b.network-operation-target-binding.v1';
	const TARGET_IDEMPOTENCY_DOMAIN = 'mad4b.network-operation-target-idempotency.v1';
	const MAX_TARGETS = 100;

	public static function create( $network_operation_id, array $input, array $targets ) {
		global $wpdb;
		$network_operation_id = self::canonical_operation_id( $network_operation_id );
		if ( '' === $network_operation_id ) return self::error( 'mad4b_network_operation_id_invalid', 'NetworkOperation id must be canonical UUIDv4.' );
		$origin_site_uuid = self::uuidv4( isset($input['origin_site_uuid']) ? $input['origin_site_uuid'] : '' );
		$origin_blog_id = isset($input['origin_blog_id']) ? absint($input['origin_blog_id']) : 0;
		$authority = self::digest_value( isset($input['authority_scope_sha256']) ? $input['authority_scope_sha256'] : '' );
		$plan = self::digest_value( isset($input['plan_sha256']) ? $input['plan_sha256'] : '' );
		$preparation = self::digest_value( isset($input['preparation_sha256']) ? $input['preparation_sha256'] : '' );
		$idempotency = self::digest_value( isset($input['idempotency_key']) ? $input['idempotency_key'] : '' );
		if ( '' === $origin_site_uuid || $origin_blog_id < 1 || '' === $authority || '' === $plan || '' === $preparation || '' === $idempotency ) {
			return self::error( 'mad4b_network_operation_input_invalid', 'NetworkOperation origin/authority/plan/preparation/idempotency identity is incomplete.' );
		}
		if ( empty($targets) || count($targets) > self::MAX_TARGETS ) return self::error( 'mad4b_network_operation_target_budget_invalid', 'NetworkOperation requires a bounded non-empty target set.' );
		$normalized_targets = array(); $seen_blogs=array(); $seen_sites=array();
		foreach ( $targets as $target ) {
			if ( ! is_array($target) ) return self::error( 'mad4b_network_target_invalid', 'Network target must be an object.' );
			$row = self::normalize_target( $target, $idempotency );
			if ( is_wp_error($row) ) return $row;
			if ( isset($seen_blogs[$row['target_blog_id']]) || isset($seen_sites[$row['target_site_uuid']]) ) return self::error( 'mad4b_network_target_duplicate', 'Network target blog/site identity must be unique.' );
			$seen_blogs[$row['target_blog_id']]=true; $seen_sites[$row['target_site_uuid']]=true; $normalized_targets[]=$row;
		}
		$tx = MAD4B_SCP_Database_Transaction_Guard::begin( 'network_operation_create', array('network_operations','network_operation_targets','network_operation_events'), false );
		if ( is_wp_error($tx) ) return $tx;
		$t = MAD4B_SCP_Schema::tables();
		try {
			$existing = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$t['network_operations']} WHERE BINARY origin_site_uuid=BINARY %s AND BINARY idempotency_key=BINARY %s FOR UPDATE",
				$origin_site_uuid, $idempotency
			), ARRAY_A );
			if ( is_array($existing) ) {
				foreach ( array('origin_blog_id'=>$origin_blog_id,'authority_scope_sha256'=>$authority,'plan_sha256'=>$plan,'preparation_sha256'=>$preparation) as $field=>$expected ) {
					if ( (string)$existing[$field] !== (string)$expected ) throw new RuntimeException('network_operation_idempotency_conflict');
				}
				$committed=MAD4B_SCP_Database_Transaction_Guard::commit($tx); if(is_wp_error($committed))return $committed;
				$result=self::reconstruct((string)$existing['network_operation_id']);
				if(is_array($result))$result['deduplicated']=true;
				return $result;
			}
			$inserted=$wpdb->query($wpdb->prepare(
				"INSERT INTO {$t['network_operations']}
				(network_operation_id,origin_site_uuid,origin_blog_id,authority_scope_sha256,plan_sha256,preparation_sha256,idempotency_key,state,paused,revision,latest_event_sha256,created_at,updated_at)
				VALUES (%s,%s,%d,%s,%s,%s,%s,'pending',0,0,%s,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
				$network_operation_id,$origin_site_uuid,$origin_blog_id,$authority,$plan,$preparation,$idempotency,str_repeat('0',64)
			));
			if(1!==(int)$inserted)throw new RuntimeException('network_operation_insert_failed');
			foreach($normalized_targets as $target){
				$ok=$wpdb->query($wpdb->prepare(
					"INSERT INTO {$t['network_operation_targets']}
					(network_operation_id,target_blog_id,target_site_uuid,origin_sha256,authority_scope_sha256,catalog_sha256,plan_sha256,preparation_sha256,approval_ticket_id,context_sha256,credential_binding_sha256,target_binding_sha256,idempotency_key,state,claim_epoch,worker_id,evidence_ref,receipt_sha256,receipt_binding_sha256,last_error_code,created_at,updated_at)
					VALUES (%s,%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,'pending',0,'','','',%s,'',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
					$network_operation_id,$target['target_blog_id'],$target['target_site_uuid'],$target['origin_sha256'],$target['authority_scope_sha256'],$target['catalog_sha256'],$target['plan_sha256'],$target['preparation_sha256'],$target['approval_ticket_id'],$target['context_sha256'],$target['credential_binding_sha256'],$target['target_binding_sha256'],$target['idempotency_key'],$target['receipt_binding_sha256']
				));
				if(1!==(int)$ok)throw new RuntimeException('network_target_insert_failed');
			}
			$event=self::append_event_locked($network_operation_id,0,'operation_created','pending','',array('target_count'=>count($normalized_targets)));
			if(is_wp_error($event))throw new RuntimeException($event->get_error_code());
			$committed=MAD4B_SCP_Database_Transaction_Guard::commit($tx); if(is_wp_error($committed))return $committed;
		}catch(Throwable $error){
			MAD4B_SCP_Database_Transaction_Guard::rollback($tx);
			return self::error('mad4b_network_operation_create_failed','NetworkOperation creation failed closed.',array('reason_code'=>sanitize_key($error->getMessage())));
		}
		$result=self::reconstruct($network_operation_id); if(is_array($result))$result['deduplicated']=false; return $result;
	}

	public static function claim_target( $network_operation_id, $target_blog_id, $worker_id, array $observed_context ) {
		global $wpdb;
		$network_operation_id=self::canonical_operation_id($network_operation_id); $target_blog_id=absint($target_blog_id); $worker_id=self::bounded_token($worker_id,191);
		if(''===$network_operation_id||$target_blog_id<1||''===$worker_id)return self::error('mad4b_network_claim_identity_invalid','Network target claim identity is invalid.');
		$context=self::verify_target_context($network_operation_id,$target_blog_id,$observed_context); if(is_wp_error($context))return $context;
		$tx=MAD4B_SCP_Database_Transaction_Guard::begin('network_target_claim',array('network_operations','network_operation_targets','network_operation_events'),false); if(is_wp_error($tx))return $tx;
		$t=MAD4B_SCP_Schema::tables();
		try{
			$op=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['network_operations']} WHERE BINARY network_operation_id=BINARY %s FOR UPDATE",$network_operation_id),ARRAY_A);
			$target=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['network_operation_targets']} WHERE BINARY network_operation_id=BINARY %s AND target_blog_id=%d FOR UPDATE",$network_operation_id,$target_blog_id),ARRAY_A);
			if(!is_array($op)||!is_array($target))throw new RuntimeException('network_target_unknown');
			if(!empty($op['paused']))throw new RuntimeException('network_operation_paused');
			if(in_array((string)$target['state'],array('committed','no_effect'),true)){
				$committed=MAD4B_SCP_Database_Transaction_Guard::commit($tx); if(is_wp_error($committed))return $committed;
				return self::target_receipt($target,true);
			}
			if('claimed'===(string)$target['state']){
				if(hash_equals((string)$target['worker_id'],$worker_id)){ $committed=MAD4B_SCP_Database_Transaction_Guard::commit($tx); if(is_wp_error($committed))return $committed; return self::target_receipt($target,false); }
				throw new RuntimeException('network_target_already_claimed');
			}
			if('pending'!==(string)$target['state'])throw new RuntimeException('network_target_reconciliation_required');
			$epoch=(int)$target['claim_epoch']+1;
			$ok=$wpdb->query($wpdb->prepare(
				"UPDATE {$t['network_operation_targets']} SET state='claimed',claim_epoch=%d,worker_id=%s,updated_at=UTC_TIMESTAMP()
				WHERE id=%d AND state='pending' AND claim_epoch=%d",
				$epoch,$worker_id,(int)$target['id'],(int)$target['claim_epoch']
			));
			if(1!==(int)$ok)throw new RuntimeException('network_target_claim_cas_conflict');
			$state=self::derive_operation_state_locked($network_operation_id,false);
			$event=self::append_event_locked($network_operation_id,$target_blog_id,'target_claimed',$state,'',array('claim_epoch'=>$epoch,'worker_id_sha256'=>hash('sha256',$worker_id)));
			if(is_wp_error($event))throw new RuntimeException($event->get_error_code());
			$committed=MAD4B_SCP_Database_Transaction_Guard::commit($tx); if(is_wp_error($committed))return $committed;
			$target['state']='claimed';$target['claim_epoch']=$epoch;$target['worker_id']=$worker_id;return self::target_receipt($target,false);
		}catch(Throwable $error){
			MAD4B_SCP_Database_Transaction_Guard::rollback($tx);
			return self::error('mad4b_network_target_claim_denied','Network target claim failed closed.',array('reason_code'=>sanitize_key($error->getMessage())));
		}
	}

	public static function record_target_outcome( $network_operation_id, $target_blog_id, $claim_epoch, $outcome, $evidence_ref, $receipt_sha256 = '', $receipt_binding_sha256 = '' ) {
		global $wpdb;
		$network_operation_id=self::canonical_operation_id($network_operation_id);$target_blog_id=absint($target_blog_id);$claim_epoch=(int)$claim_epoch;
		$outcome=sanitize_key((string)$outcome);$evidence_ref=self::bounded_token($evidence_ref,191);
		$receipt_sha256=''===(string)$receipt_sha256?'':self::digest_value($receipt_sha256);$receipt_binding_sha256=self::digest_value($receipt_binding_sha256);
		if(''===$network_operation_id||$target_blog_id<1||$claim_epoch<1||!in_array($outcome,array('committed','no_effect','reconciling','failed'),true)||''===$evidence_ref||''===$receipt_binding_sha256)return self::error('mad4b_network_target_outcome_invalid','Network target outcome evidence is incomplete.');
		if('committed'===$outcome&&''===$receipt_sha256)return self::error('mad4b_network_target_receipt_required','Committed target requires an exact receipt digest.');
		$tx=MAD4B_SCP_Database_Transaction_Guard::begin('network_target_outcome',array('network_operations','network_operation_targets','network_operation_events'),false);if(is_wp_error($tx))return $tx;
		$t=MAD4B_SCP_Schema::tables();
		try{
			$op=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['network_operations']} WHERE BINARY network_operation_id=BINARY %s FOR UPDATE",$network_operation_id),ARRAY_A);
			$target=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['network_operation_targets']} WHERE BINARY network_operation_id=BINARY %s AND target_blog_id=%d FOR UPDATE",$network_operation_id,$target_blog_id),ARRAY_A);
			if(!is_array($op)||!is_array($target))throw new RuntimeException('network_target_unknown');
			if(!hash_equals((string)$target['receipt_binding_sha256'],$receipt_binding_sha256))throw new RuntimeException('network_target_receipt_binding_mismatch');
			if(in_array((string)$target['state'],array('committed','no_effect'),true)){
				$receipt_match=hash_equals((string)$target['receipt_sha256'],(string)$receipt_sha256);
				if((string)$target['state']===$outcome&&(int)$target['claim_epoch']===$claim_epoch&&hash_equals((string)$target['evidence_ref'],$evidence_ref)&&$receipt_match){
					$committed=MAD4B_SCP_Database_Transaction_Guard::commit($tx);if(is_wp_error($committed))return $committed;$r=self::target_receipt($target,true);$r['outcome_idempotent']=true;return $r;
				}
				throw new RuntimeException('network_target_terminal_immutable');
			}
			if('claimed'!==(string)$target['state'])throw new RuntimeException('network_target_not_claimed');
			if((int)$target['claim_epoch']!==$claim_epoch)throw new RuntimeException('network_target_claim_epoch_stale');
			$last_error='failed'===$outcome?'provider_failed':('');
			$ok=$wpdb->query($wpdb->prepare(
				"UPDATE {$t['network_operation_targets']} SET state=%s,evidence_ref=%s,receipt_sha256=%s,last_error_code=%s,updated_at=UTC_TIMESTAMP()
				WHERE id=%d AND state='claimed' AND claim_epoch=%d",
				$outcome,$evidence_ref,$receipt_sha256,$last_error,(int)$target['id'],$claim_epoch
			));
			if(1!==(int)$ok)throw new RuntimeException('network_target_outcome_cas_conflict');
			$state=self::derive_operation_state_locked($network_operation_id,!empty($op['paused']));
			$event=self::append_event_locked($network_operation_id,$target_blog_id,'target_'.$outcome,$state,$evidence_ref,array('claim_epoch'=>$claim_epoch,'receipt_sha256'=>$receipt_sha256));
			if(is_wp_error($event))throw new RuntimeException($event->get_error_code());
			$committed=MAD4B_SCP_Database_Transaction_Guard::commit($tx);if(is_wp_error($committed))return $committed;
			$target['state']=$outcome;$target['evidence_ref']=$evidence_ref;$target['receipt_sha256']=$receipt_sha256;$target['last_error_code']=$last_error;return self::target_receipt($target,false);
		}catch(Throwable $error){
			MAD4B_SCP_Database_Transaction_Guard::rollback($tx);
			return self::error('mad4b_network_target_outcome_denied','Network target outcome failed closed.',array('reason_code'=>sanitize_key($error->getMessage())));
		}
	}

	public static function pause( $network_operation_id ) { return self::set_paused($network_operation_id,true); }
	public static function resume( $network_operation_id ) { return self::set_paused($network_operation_id,false); }

	public static function verify_target_context( $network_operation_id, $target_blog_id, array $observed ) {
		global $wpdb;
		$network_operation_id=self::canonical_operation_id($network_operation_id);$target_blog_id=absint($target_blog_id);
		if(''===$network_operation_id||$target_blog_id<1)return self::error('mad4b_network_target_context_identity_invalid','Network target context identity is invalid.');
		$t=MAD4B_SCP_Schema::tables();
		$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['network_operation_targets']} WHERE BINARY network_operation_id=BINARY %s AND target_blog_id=%d LIMIT 1",$network_operation_id,$target_blog_id),ARRAY_A);
		if(!is_array($row))return self::error('mad4b_network_target_unknown','Network target is unknown.');
		$normalized=self::normalize_observed_context($observed);
		if(is_wp_error($normalized))return $normalized;
		foreach(array('target_site_uuid','origin_sha256','authority_scope_sha256','catalog_sha256','plan_sha256','preparation_sha256','approval_ticket_id','context_sha256','credential_binding_sha256','receipt_binding_sha256') as $field){
			if(!hash_equals((string)$row[$field],(string)$normalized[$field]))return self::error('mad4b_network_target_context_mismatch','Observed target context belongs to a different site or evidence generation.',array('field'=>$field,'target_blog_id'=>$target_blog_id));
		}
		$binding=self::target_binding($normalized);
		if(!hash_equals((string)$row['target_binding_sha256'],$binding))return self::error('mad4b_network_target_binding_mismatch','Observed target binding digest does not match durable planning evidence.');
		return array('contract'=>self::CONTRACT,'network_operation_id'=>$network_operation_id,'target_blog_id'=>$target_blog_id,'target_binding_sha256'=>$binding,'site_bound'=>true,'authorizing'=>false);
	}

	public static function reconstruct( $network_operation_id ) {
		global $wpdb;
		$network_operation_id=self::canonical_operation_id($network_operation_id);if(''===$network_operation_id)return self::error('mad4b_network_operation_id_invalid','NetworkOperation id is invalid.');
		$t=MAD4B_SCP_Schema::tables();
		$op=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['network_operations']} WHERE BINARY network_operation_id=BINARY %s LIMIT 1",$network_operation_id),ARRAY_A);
		if(!is_array($op))return self::error('mad4b_network_operation_unknown','NetworkOperation is unknown.');
		$targets=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['network_operation_targets']} WHERE BINARY network_operation_id=BINARY %s ORDER BY target_blog_id ASC",$network_operation_id),ARRAY_A);
		$events=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['network_operation_events']} WHERE BINARY network_operation_id=BINARY %s ORDER BY sequence ASC",$network_operation_id),ARRAY_A);
		$chain=self::verify_event_chain($op,is_array($events)?$events:array());if(is_wp_error($chain))return $chain;
		$targets=is_array($targets)?$targets:array();$derived=self::derive_state_from_rows($targets,!empty($op['paused']));
		if(!hash_equals((string)$op['state'],$derived))return self::error('mad4b_network_operation_state_drift','Stored NetworkOperation state disagrees with durable target evidence.',array('stored'=>(string)$op['state'],'derived'=>$derived));
		$sets=array('completed'=>array(),'pending'=>array(),'reconciling'=>array(),'claimed'=>array(),'failed'=>array());
		$target_rows=array();
		foreach($targets as $row){
			$blog=(int)$row['target_blog_id'];$state=(string)$row['state'];
			if(in_array($state,array('committed','no_effect'),true))$sets['completed'][]=$blog;
			elseif('pending'===$state)$sets['pending'][]=$blog;
			elseif('reconciling'===$state)$sets['reconciling'][]=$blog;
			elseif('claimed'===$state)$sets['claimed'][]=$blog;
			elseif('failed'===$state)$sets['failed'][]=$blog;
			$target_rows[(string)$blog]=self::public_target($row);
		}
		foreach($sets as &$values){sort($values,SORT_NUMERIC);}unset($values);
		return array(
			'contract'=>self::CONTRACT,'network_operation_id'=>$network_operation_id,'state'=>(string)$op['state'],'paused'=>!empty($op['paused']),
			'revision'=>(int)$op['revision'],'latest_event_sha256'=>(string)$op['latest_event_sha256'],
			'origin_site_uuid'=>(string)$op['origin_site_uuid'],'origin_blog_id'=>(int)$op['origin_blog_id'],'authority_scope_sha256'=>(string)$op['authority_scope_sha256'],
			'plan_sha256'=>(string)$op['plan_sha256'],'preparation_sha256'=>(string)$op['preparation_sha256'],'idempotency_key'=>(string)$op['idempotency_key'],
			'sets'=>$sets,'resume_candidates'=>$sets['pending'],'targets'=>$target_rows,'event_count'=>count($events),'event_chain_valid'=>true,
			'replay_committed_allowed'=>false,'read_only'=>true,'mutation_performed'=>false,'authorizing'=>false
		);
	}

	private static function set_paused( $network_operation_id, $paused ) {
		global $wpdb;
		$network_operation_id=self::canonical_operation_id($network_operation_id);if(''===$network_operation_id)return self::error('mad4b_network_operation_id_invalid','NetworkOperation id is invalid.');
		$tx=MAD4B_SCP_Database_Transaction_Guard::begin($paused?'network_operation_pause':'network_operation_resume',array('network_operations','network_operation_targets','network_operation_events'),false);if(is_wp_error($tx))return $tx;
		$t=MAD4B_SCP_Schema::tables();
		try{
			$op=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['network_operations']} WHERE BINARY network_operation_id=BINARY %s FOR UPDATE",$network_operation_id),ARRAY_A);if(!is_array($op))throw new RuntimeException('network_operation_unknown');
			if('completed'===(string)$op['state']){ $committed=MAD4B_SCP_Database_Transaction_Guard::commit($tx);if(is_wp_error($committed))return $committed;return self::reconstruct($network_operation_id); }
			if((bool)$op['paused']===(bool)$paused){$committed=MAD4B_SCP_Database_Transaction_Guard::commit($tx);if(is_wp_error($committed))return $committed;return self::reconstruct($network_operation_id);}
			$ok=$wpdb->query($wpdb->prepare("UPDATE {$t['network_operations']} SET paused=%d,updated_at=UTC_TIMESTAMP() WHERE BINARY network_operation_id=BINARY %s AND revision=%d",$paused?1:0,$network_operation_id,(int)$op['revision']));
			if(1!==(int)$ok)throw new RuntimeException('network_operation_pause_cas_conflict');
			$state=self::derive_operation_state_locked($network_operation_id,$paused);
			$event=self::append_event_locked($network_operation_id,0,$paused?'operation_paused':'operation_resumed',$state,'',array());
			if(is_wp_error($event))throw new RuntimeException($event->get_error_code());
			$committed=MAD4B_SCP_Database_Transaction_Guard::commit($tx);if(is_wp_error($committed))return $committed;
		}catch(Throwable $error){MAD4B_SCP_Database_Transaction_Guard::rollback($tx);return self::error('mad4b_network_operation_pause_resume_failed','NetworkOperation pause/resume failed closed.',array('reason_code'=>sanitize_key($error->getMessage())));}
		return self::reconstruct($network_operation_id);
	}

	private static function append_event_locked( $network_operation_id, $target_blog_id, $event_type, $operation_state, $evidence_ref, array $metadata ) {
		global $wpdb;$t=MAD4B_SCP_Schema::tables();
		$op=$wpdb->get_row($wpdb->prepare("SELECT revision,latest_event_sha256 FROM {$t['network_operations']} WHERE BINARY network_operation_id=BINARY %s FOR UPDATE",$network_operation_id),ARRAY_A);
		if(!is_array($op))return self::error('mad4b_network_event_operation_missing','NetworkOperation event head is missing.');
		$sequence=(int)$op['revision']+1;$previous=(string)$op['latest_event_sha256'];$metadata_json=self::canonical_json($metadata);
		$payload=array('contract'=>self::EVENT_CONTRACT,'network_operation_id'=>$network_operation_id,'sequence'=>$sequence,'target_blog_id'=>(int)$target_blog_id,'event_type'=>sanitize_key($event_type),'state'=>sanitize_key($operation_state),'evidence_ref'=>(string)$evidence_ref,'safe_metadata'=>$metadata,'previous_event_sha256'=>$previous);
		$event_sha=hash('sha256',self::canonical_json($payload));
		$insert=$wpdb->query($wpdb->prepare(
			"INSERT INTO {$t['network_operation_events']} (network_operation_id,sequence,target_blog_id,event_type,state,evidence_ref,safe_metadata_json,previous_event_sha256,event_sha256,created_at)
			VALUES (%s,%d,%d,%s,%s,%s,%s,%s,%s,UTC_TIMESTAMP())",
			$network_operation_id,$sequence,(int)$target_blog_id,sanitize_key($event_type),sanitize_key($operation_state),(string)$evidence_ref,$metadata_json,$previous,$event_sha
		));
		if(1!==(int)$insert)return self::error('mad4b_network_event_append_failed','NetworkOperation event append failed.');
		$updated=$wpdb->query($wpdb->prepare(
			"UPDATE {$t['network_operations']} SET state=%s,revision=%d,latest_event_sha256=%s,updated_at=UTC_TIMESTAMP()
			WHERE BINARY network_operation_id=BINARY %s AND revision=%d AND BINARY latest_event_sha256=BINARY %s",
			sanitize_key($operation_state),$sequence,$event_sha,$network_operation_id,(int)$op['revision'],$previous
		));
		if(1!==(int)$updated)return self::error('mad4b_network_event_head_cas_failed','NetworkOperation event head CAS failed.');
		return array('sequence'=>$sequence,'event_sha256'=>$event_sha);
	}

	private static function verify_event_chain( array $op, array $events ) {
		$expected_sequence=1;$previous=str_repeat('0',64);
		foreach($events as $row){
			if((int)$row['sequence']!==$expected_sequence||!hash_equals($previous,(string)$row['previous_event_sha256']))return self::error('mad4b_network_event_chain_invalid','NetworkOperation event sequence/hash chain is invalid.');
			$metadata=json_decode((string)$row['safe_metadata_json'],true);if(!is_array($metadata))$metadata=array();
			$payload=array('contract'=>self::EVENT_CONTRACT,'network_operation_id'=>(string)$row['network_operation_id'],'sequence'=>(int)$row['sequence'],'target_blog_id'=>(int)$row['target_blog_id'],'event_type'=>(string)$row['event_type'],'state'=>(string)$row['state'],'evidence_ref'=>(string)$row['evidence_ref'],'safe_metadata'=>$metadata,'previous_event_sha256'=>(string)$row['previous_event_sha256']);
			$sha=hash('sha256',self::canonical_json($payload));if(!hash_equals($sha,(string)$row['event_sha256']))return self::error('mad4b_network_event_hash_invalid','NetworkOperation event content hash is invalid.');
			$previous=$sha;$expected_sequence++;
		}
		if((int)$op['revision']!==count($events)||!hash_equals((string)$op['latest_event_sha256'],$previous))return self::error('mad4b_network_event_head_invalid','NetworkOperation durable head disagrees with append-only events.');
		return true;
	}

	private static function normalize_target( array $target, $network_idempotency ) {
		$context=self::normalize_observed_context($target);if(is_wp_error($context))return $context;
		$blog=isset($target['target_blog_id'])?absint($target['target_blog_id']):0;if($blog<1)return self::error('mad4b_network_target_blog_invalid','Target blog id is invalid.');
		$context['target_blog_id']=$blog;$binding=self::target_binding($context);
		return array_merge($context,array('target_binding_sha256'=>$binding,'idempotency_key'=>hash('sha256',self::TARGET_IDEMPOTENCY_DOMAIN.'|'.$network_idempotency.'|'.$binding)));
	}

	private static function normalize_observed_context( array $target ) {
		$site=self::uuidv4(isset($target['target_site_uuid'])?$target['target_site_uuid']:'');
		$approval=trim((string)(isset($target['approval_ticket_id'])?$target['approval_ticket_id']:''));
		if(''!==$approval){$approval=MAD4B_SCP_Identifiers::approval_ticket_id($approval);if(''===$approval)return self::error('mad4b_network_target_approval_invalid','Target approval ticket id is not canonical UUIDv4.');}
		$out=array('target_site_uuid'=>$site,'approval_ticket_id'=>$approval);
		foreach(array('origin_sha256','authority_scope_sha256','catalog_sha256','plan_sha256','preparation_sha256','context_sha256','credential_binding_sha256','receipt_binding_sha256') as $field)$out[$field]=self::digest_value(isset($target[$field])?$target[$field]:'');
		foreach($out as $field=>$value)if('target_site_uuid'!==$field&&'approval_ticket_id'!==$field&&''===$value)return self::error('mad4b_network_target_context_invalid','Target context digest is incomplete.',array('field'=>$field));
		if(''===$site)return self::error('mad4b_network_target_site_invalid','Target Site UUID is invalid.');return $out;
	}

	private static function target_binding( array $context ) {
		$material=$context;unset($material['target_blog_id']);ksort($material,SORT_STRING);
		return hash('sha256',self::TARGET_BINDING_DOMAIN.'|'.self::canonical_json($material));
	}

	private static function derive_operation_state_locked( $network_operation_id, $paused ) {
		global $wpdb;$t=MAD4B_SCP_Schema::tables();
		$rows=$wpdb->get_results($wpdb->prepare("SELECT state FROM {$t['network_operation_targets']} WHERE BINARY network_operation_id=BINARY %s",$network_operation_id),ARRAY_A);
		return self::derive_state_from_rows(is_array($rows)?$rows:array(),$paused);
	}
	private static function derive_state_from_rows( array $rows, $paused ) {
		$states=array();foreach($rows as $row)$states[]=(string)$row['state'];
		if(!empty($states)&&count(array_filter($states,static function($s){return in_array($s,array('committed','no_effect'),true);}))===count($states))return 'completed';
		if($paused)return 'paused';
		if(in_array('reconciling',$states,true))return 'reconciling';
		if(in_array('failed',$states,true))return 'partial_failed';
		if(in_array('claimed',$states,true))return 'running';
		return 'pending';
	}

	private static function public_target( array $row ) {
		return array(
			'target_blog_id'=>(int)$row['target_blog_id'],'target_site_uuid'=>(string)$row['target_site_uuid'],'origin_sha256'=>(string)$row['origin_sha256'],
			'authority_scope_sha256'=>(string)$row['authority_scope_sha256'],'catalog_sha256'=>(string)$row['catalog_sha256'],'plan_sha256'=>(string)$row['plan_sha256'],
			'preparation_sha256'=>(string)$row['preparation_sha256'],'approval_ticket_id'=>(string)$row['approval_ticket_id'],'context_sha256'=>(string)$row['context_sha256'],
			'credential_binding_sha256'=>(string)$row['credential_binding_sha256'],'receipt_binding_sha256'=>(string)$row['receipt_binding_sha256'],
			'target_binding_sha256'=>(string)$row['target_binding_sha256'],'idempotency_key'=>(string)$row['idempotency_key'],'state'=>(string)$row['state'],
			'claim_epoch'=>(int)$row['claim_epoch'],'worker_id'=>(string)$row['worker_id'],'evidence_ref'=>(string)$row['evidence_ref'],'receipt_sha256'=>(string)$row['receipt_sha256'],
			'last_error_code'=>(string)$row['last_error_code']
		);
	}
	private static function target_receipt( array $row, $already_completed ) { $out=self::public_target($row);$out['contract']=self::CONTRACT;$out['already_completed']=(bool)$already_completed;$out['authorizing']=false;return $out; }

	private static function canonical_operation_id( $value ) { return class_exists('MAD4B_SCP_Identifiers')?MAD4B_SCP_Identifiers::operation_id_for_write($value):''; }
	private static function uuidv4( $value ) { $v=strtolower(trim((string)$value));return 1===preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$v)?$v:''; }
	private static function digest_value( $value ) { $v=strtolower(trim((string)$value));return 1===preg_match('/^[a-f0-9]{64}$/D',$v)?$v:''; }
	private static function bounded_token( $value, $max ) { $v=trim((string)$value);return ''!==$v&&strlen($v)<=$max?$v:''; }
	private static function canonical_json( $value ) { return wp_json_encode(self::canonicalize($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); }
	private static function canonicalize( $value ) { if(!is_array($value))return $value;$list=empty($value)||array_keys($value)===range(0,count($value)-1);if($list)return array_map(array(__CLASS__,'canonicalize'),$value);ksort($value,SORT_STRING);foreach($value as $k=>$v)$value[$k]=self::canonicalize($v);return $value; }
	private static function error( $code, $message, array $data=array() ) { $data['contract']=self::CONTRACT;$data['authorizing']=false;return new WP_Error($code,$message,$data); }
}
