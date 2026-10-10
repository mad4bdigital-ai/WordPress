<?php
/** Inert signed field recipes: no values, defaults, credentials or grants. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_CSO_Templates {
	const PURPOSE='mad4b.cso01.template.v1';
	const TTL=86400;
	public static function create( $sealed,$recipe=array() ) {
		$f=MAD4B_SCP_CSO_Forms::inspect( $sealed ); if ( is_wp_error( $f ) ) return $f;
		if ( ! is_array( $recipe ) || array_diff( array_keys( $recipe ),array( 'version','fields' ) ) ) return self::deny( 'unsafe_template' );
		$version=$recipe['version']??1; if ( ! is_int( $version ) || $version<1 || $version>9999 ) return self::deny( 'unsafe_template' );
		$fields=$recipe['fields']??array(); if ( ! isset( $recipe['fields'] ) ) foreach ( $f['fields'] as $field ) if ( empty( $field['read_only'] ) && ! in_array( $field['sensitivity'],array( 'SECRET','PERSONAL','REGULATED' ),true ) ) $fields[]=array( 'field_id'=>$field['field_id'] );
		$v=self::fields( $f,$fields ); if ( is_wp_error( $v ) ) return $v;
		$m=array( 'contract'=>'mad4b.cso01.template.v1','version'=>$version,'ability_name'=>$f['ability_name'],'schema_sha256'=>$f['schema_sha256'],'field_metadata_sha256'=>self::metadata_digest( $f ),'fields'=>$fields,'expires_at'=>self::now()+self::TTL );
		$token=MAD4B_SCP_CSO_Scope::seal( $m,self::PURPOSE ); if ( is_wp_error( $token ) ) return $token; return array( 'status'=>'TEMPLATE_PROPOSED','template'=>$m,'sealed_template'=>$token,'non_authorizing'=>true,'execution_allowed'=>false );
	}
	public static function plan( $sealed,$recipe=array() ) { return self::create( $sealed,$recipe ); }
	public static function apply( $template,$sealed ) {
		$f=MAD4B_SCP_CSO_Forms::inspect( $sealed ); if ( is_wp_error( $f ) ) return $f; if ( ! is_array( $template ) ) return self::deny( 'unsafe_template' ); $m=MAD4B_SCP_CSO_Scope::unseal( $template,self::PURPOSE ); if ( is_wp_error( $m ) ) return $m;
		if ( ! is_array( $m ) || array_diff( array_keys( $m ),array( 'contract','version','ability_name','schema_sha256','field_metadata_sha256','fields','expires_at' ) ) || ! isset( $m['contract'],$m['version'],$m['ability_name'],$m['schema_sha256'],$m['field_metadata_sha256'],$m['fields'],$m['expires_at'] ) || 'mad4b.cso01.template.v1'!==$m['contract'] || ! is_int( $m['version'] ) || $m['version']<1 || $m['version']>9999 || ! is_int( $m['expires_at'] ) || self::now()>=$m['expires_at'] || $m['expires_at']>self::now()+self::TTL ) return self::deny( 'unsafe_template' );
		if ( $m['ability_name']!==$f['ability_name'] || $m['schema_sha256']!==$f['schema_sha256'] || $m['field_metadata_sha256']!==self::metadata_digest( $f ) ) return self::deny( 'template_incompatible' );
		$fields=self::fields( $f,$m['fields'] ); if ( is_wp_error( $fields ) ) return $fields; return array( 'status'=>'TEMPLATE_PROPOSED','version'=>$m['version'],'fields'=>$fields,'sealed_form'=>$sealed,'values'=>array(),'confirmation_required'=>true,'non_authorizing'=>true,'execution_allowed'=>false );
	}
	private static function fields( $f,$recipes ) {
		if ( ! is_array( $recipes ) || array_values( $recipes )!==$recipes || ! $recipes || count( $recipes )>MAD4B_SCP_CSO_Forms::MAX_FIELDS ) return self::deny( 'unsafe_template' ); $available=array(); foreach ( $f['fields'] as $field ) $available[ $field['field_id'] ]=$field;
		$out=array(); $seen=array(); foreach ( $recipes as $r ) { if ( ! is_array( $r ) || array( 'field_id' )!==array_keys( $r ) || ! is_string( $r['field_id'] ) || ! isset( $available[ $r['field_id'] ] ) || isset( $seen[ $r['field_id'] ] ) ) return self::deny( 'unsafe_template' ); $field=$available[ $r['field_id'] ]; if ( ! empty( $field['read_only'] ) || in_array( $field['sensitivity'],array( 'SECRET','PERSONAL','REGULATED' ),true ) ) return self::deny( 'unsafe_template' ); $seen[ $r['field_id'] ]=true; $out[]=$field; } return $out;
	}
	private static function metadata_digest( $f ) { return MAD4B_SCP_CSO_Scope::digest( ! empty( $f['descriptor']['metadata_trusted'] ) ? ( $f['descriptor']['field_metadata']??array() ) : array() ); }
	private static function now() { return class_exists( 'MAD4B_SCP_Time_Policy' ) ? (int)MAD4B_SCP_Time_Policy::now_epoch() : time(); }
	private static function deny( $reason ) { return MAD4B_SCP_CSO_Scope::error( $reason ); }
}
