<?php
/** Redacted, expiring, actor/site/source-bound drafts. Own native options only. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_CSO_Drafts {
	const PURPOSE='mad4b.cso01.draft-record.v1';
	const MAX_REVISIONS=64;
	const HOOK='mad4b_cso_draft_expire';
	private static $booted=false;
	public static function boot() { if ( ! self::$booted && function_exists( 'add_action' ) ) { add_action( self::HOOK,array( __CLASS__,'expire' ),10,3 ); self::$booted=true; } }
	public static function create( $sealed,$values=array() ) {
		if ( ! self::available() ) return self::deny( 'draft_unavailable' );
		$f=MAD4B_SCP_CSO_Forms::inspect( $sealed ); if ( is_wp_error( $f ) ) return $f;
		$v=MAD4B_SCP_CSO_Forms::validate_draft( $sealed,$values ); if ( is_wp_error( $v ) ) return $v;
		try { $id=bin2hex( random_bytes( 16 ) ); } catch ( Throwable $e ) { return self::deny( 'draft_unavailable' ); }
		$owner=MAD4B_SCP_CSO_Scope::digest( $f['scope'] ); if ( is_wp_error( $owner ) ) return $owner; $quota=null;
		for ( $slot=0;$slot<10;$slot++ ) { $key='_mad4b_cso_dq_'.$owner.'_'.(int)floor( self::now()/MAD4B_SCP_CSO_Forms::TTL ).'_'.$slot; if ( add_option( $key,$id,'',false ) ) { $quota=$key; break; } }
		if ( null===$quota ) return self::deny( 'draft_quota' );
		$redacted=array(); $safe=self::project( $values,$f['descriptor']['input_schema'],$f['descriptor'],'',$redacted );
		$r=array( 'draft_id'=>$id,'revision'=>1,'previous_revision'=>0,'state'=>'DRAFT','scope'=>$f['scope'],'expires_at'=>$f['expires_at'],'sealed_form'=>$sealed,'values'=>$safe,'redacted_fields'=>array_values( array_unique( $redacted ) ),'quota_key'=>$quota );
		$token=MAD4B_SCP_CSO_Scope::seal( $r,self::PURPOSE );
		if ( is_wp_error( $token ) || ! add_option( self::key( $owner,$id,1 ),$token,'',false ) ) { delete_option( $quota ); return self::deny( 'draft_unavailable' ); }
		self::boot(); $scheduled=wp_schedule_single_event( $r['expires_at'],self::HOOK,array( $owner,$id,$r['expires_at'] ),true );
		if ( is_wp_error( $scheduled ) || false===$scheduled ) { delete_option( self::key( $owner,$id,1 ) ); delete_option( $quota ); return self::deny( 'draft_unavailable' ); }
		return self::view( $r );
	}
	public static function load( $id ) { $r=self::latest( $id ); if ( is_wp_error( $r ) ) return $r; $v=MAD4B_SCP_CSO_Forms::validate_draft( $r['sealed_form'],$r['values'] ); return is_wp_error( $v ) ? self::deny( 'stale_draft' ) : self::view( $r ); }
	public static function save( $id,$expected,$sealed,$values ) {
		$r=self::latest( $id ); if ( is_wp_error( $r ) ) return $r;
		// Reserve the last immutable revision for a deletion tombstone.
		if ( ! is_int( $expected ) || $expected!==$r['revision'] || $expected>=self::MAX_REVISIONS-1 ) return self::deny( 'draft_conflict' );
		if ( MAD4B_SCP_CSO_Scope::digest( $sealed )!==MAD4B_SCP_CSO_Scope::digest( $r['sealed_form'] ) ) return self::deny( 'draft_conflict' );
		$f=MAD4B_SCP_CSO_Forms::inspect( $sealed ); if ( is_wp_error( $f ) ) return $f;
		$v=MAD4B_SCP_CSO_Forms::validate_draft( $sealed,$values ); if ( is_wp_error( $v ) ) return $v;
		$redacted=array(); $r['values']=self::project( $values,$f['descriptor']['input_schema'],$f['descriptor'],'',$redacted ); $r['redacted_fields']=array_values( array_unique( $redacted ) ); $r['previous_revision']=$expected; $r['revision']=$expected+1;
		$v=self::append( $r ); return is_wp_error( $v ) ? $v : self::view( $r );
	}
	public static function delete( $id,$expected ) { $r=self::latest( $id ); if ( is_wp_error( $r ) ) return $r; if ( ! is_int( $expected ) || $expected!==$r['revision'] || $expected>=self::MAX_REVISIONS ) return self::deny( 'draft_conflict' ); $r['previous_revision']=$expected; $r['revision']=$expected+1; $r['state']='DELETED'; $r['values']=array(); $r['redacted_fields']=array(); $v=self::append( $r ); if ( is_wp_error( $v ) ) return $v; return array( 'status'=>'DELETED','draft_id'=>$id,'revision'=>$r['revision'],'non_authorizing'=>true,'execution_allowed'=>false ); }
	private static function append( $r ) { $current=MAD4B_SCP_CSO_Scope::assert_current( $r['scope'] ); if ( is_wp_error( $current ) || false===$current || self::now()>=$r['expires_at'] ) return self::deny( 'stale_draft' ); $sealed=MAD4B_SCP_CSO_Scope::seal( $r,self::PURPOSE ); if ( is_wp_error( $sealed ) ) return $sealed; return add_option( self::key( MAD4B_SCP_CSO_Scope::digest( $r['scope'] ),$r['draft_id'],$r['revision'] ),$sealed,'',false ) ? true : self::deny( 'draft_conflict' ); }
	private static function latest( $id ) {
		if ( ! self::available() || ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{32}$/D',$id ) ) return self::deny( 'draft_unavailable' );
		$scope=MAD4B_SCP_CSO_Scope::current(); if ( is_wp_error( $scope ) ) return $scope; $owner=MAD4B_SCP_CSO_Scope::digest( $scope ); if ( is_wp_error( $owner ) ) return $owner; $latest=null; $first=null;
		for ( $rev=1;$rev<=self::MAX_REVISIONS;$rev++ ) {
			$value=get_option( self::key( $owner,$id,$rev ),null ); if ( null===$value ) break; if ( ! is_array( $value ) ) return self::deny( 'draft_unavailable' );
			$r=MAD4B_SCP_CSO_Scope::unseal( $value,self::PURPOSE );
			if ( is_wp_error( $r ) || ! is_array( $r ) || ! isset( $r['draft_id'],$r['revision'],$r['previous_revision'],$r['scope'],$r['expires_at'],$r['state'],$r['sealed_form'],$r['values'] ) || $r['draft_id']!==$id || $r['revision']!==$rev || $r['previous_revision']!==$rev-1 || ! is_int( $r['expires_at'] ) || MAD4B_SCP_CSO_Scope::digest( $r['scope'] )!==$owner ) return self::deny( 'draft_unavailable' );
			if ( null===$first ) $first=$r; else if ( 'DRAFT'!==$latest['state'] || $r['expires_at']!==$first['expires_at'] || MAD4B_SCP_CSO_Scope::digest( $r['sealed_form'] )!==MAD4B_SCP_CSO_Scope::digest( $first['sealed_form'] ) || ( $r['quota_key']??null )!==( $first['quota_key']??null ) ) return self::deny( 'draft_unavailable' );
			$latest=$r;
		}
		if ( null===$latest || 'DRAFT'!==$latest['state'] || self::now()>=$latest['expires_at'] ) return self::deny( 'draft_unavailable' ); $current=MAD4B_SCP_CSO_Scope::assert_current( $latest['scope'] ); return is_wp_error( $current ) || false===$current ? self::deny( 'stale_draft' ) : $latest;
	}
	/** Detached MAC verification permits Cron to purge only owned expired records, never disclose them. */
	public static function expire( $owner,$id,$expires ) {
		if ( ! is_string( $owner ) || ! preg_match( '/^[a-f0-9]{64}$/D',$owner ) || ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{32}$/D',$id ) || ! is_int( $expires ) || self::now()<$expires || ! function_exists( 'get_option' ) || ! function_exists( 'delete_option' ) ) return;
		$value=get_option( self::key( $owner,$id,1 ),null ); if ( ! is_array( $value ) ) return; $r=MAD4B_SCP_CSO_Scope::unseal( $value,self::PURPOSE );
		if ( is_wp_error( $r ) || ! is_array( $r ) || ! isset( $r['scope'],$r['expires_at'],$r['draft_id'],$r['quota_key'] ) || $r['expires_at']!==$expires || $r['draft_id']!==$id || MAD4B_SCP_CSO_Scope::digest( $r['scope'] )!==$owner ) return;
		for ( $rev=1;$rev<=self::MAX_REVISIONS;$rev++ ) delete_option( self::key( $owner,$id,$rev ) );
		if ( is_string( $r['quota_key'] ) && preg_match( '/^_mad4b_cso_dq_'.$owner.'_[0-9]+_[0-9]$/D',$r['quota_key'] ) && get_option( $r['quota_key'] )===$id ) delete_option( $r['quota_key'] );
	}
	private static function project( $v,$s,$d,$path,&$redacted ) { if ( ''!==$path && ! in_array( MAD4B_SCP_CSO_Forms::sensitivity( $d,$path ),array( 'PUBLIC','SITE_INTERNAL' ),true ) ) { $redacted[]=$path; return null; } if ( 'object'===$s['type'] ) { $out=array(); foreach ( $v as $key=>$child ) { $cp=''===$path ? $key : $path.'.'.$key; if ( ! in_array( MAD4B_SCP_CSO_Forms::sensitivity( $d,$cp ),array( 'PUBLIC','SITE_INTERNAL' ),true ) ) { $redacted[]=$cp; continue; } $out[ $key ]=self::project( $child,$s['properties'][ $key ],$d,$cp,$redacted ); } return $out; } if ( 'array'===$s['type'] ) { $out=array(); foreach ( $v as $child ) $out[]=self::project( $child,$s['items'],$d,$path.'.*',$redacted ); return $out; } return $v; }
	private static function view( $r ) { return array( 'status'=>'DRAFT','draft_id'=>$r['draft_id'],'revision'=>$r['revision'],'expires_at'=>gmdate( 'c',$r['expires_at'] ),'sealed_form'=>$r['sealed_form'],'values'=>$r['values'],'redacted_fields'=>$r['redacted_fields'],'confirmation_required'=>true,'non_authorizing'=>true,'execution_allowed'=>false ); }
	private static function available() { return MAD4B_SCP_CSO_Scope::enabled( 'forms' ) && function_exists( 'get_option' ) && function_exists( 'add_option' ) && function_exists( 'delete_option' ) && function_exists( 'wp_schedule_single_event' ); }
	private static function key( $owner,$id,$rev ) { return '_mad4b_cso_d_'.$owner.'_'.$id.'_'.$rev; }
	private static function now() { return class_exists( 'MAD4B_SCP_Time_Policy' ) ? (int)MAD4B_SCP_Time_Policy::now_epoch() : time(); }
	private static function deny( $reason ) { return MAD4B_SCP_CSO_Scope::error( $reason ); }
}
