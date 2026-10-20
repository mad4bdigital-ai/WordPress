<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Encrypted, bounded diagnostic progress. Never writes Context assets or grants. */
final class MAD4B_SCP_Drive_Scan_Continuation {
 const CONTRACT = 'mad4b.drive-scan-continuation.v1';
 const TTL = 900;
 const MAX_BYTES = 8388608;
 private static function error( $suffix ) { return new WP_Error( 'mad4b_scan_' . $suffix, 'Scan continuation stopped: ' . $suffix . '. Reinspect the exact source; no Context registry write was performed.' ); }
 private static function digest( $data ) { return hash( 'sha256', wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); }
 private static function key() { return hash( 'sha256', wp_salt( 'auth' ) . '|' . self::CONTRACT, true ); }
 public static function option_name( array $scope ) { return 'mad4b_scan_checkpoint_' . self::digest( $scope['checkpoint_owner'] ?? $scope ); }
 private static function cursor( array $state, $scope_sha ) {
  $body = $state['generation'] . ':' . $state['revision'];
  return $body . ':' . hash_hmac( 'sha256', $scope_sha . '|' . $body, self::key() );
 }
 private static function seal( array $state, $scope_sha ) {
  $plain = wp_json_encode( $state );
  if ( ! is_string( $plain ) || strlen( $plain ) > self::MAX_BYTES ) return self::error( 'checkpoint_size_limit' );
  $iv = random_bytes( 12 ); $tag = '';
  $cipher = openssl_encrypt( $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, $scope_sha );
  return false === $cipher ? self::error( 'checkpoint_encrypt_failed' ) : $scope_sha . ':' . base64_encode( $iv . $tag . $cipher );
 }
 private static function open( $raw, $scope_sha ) {
  if ( ! is_string( $raw ) || strlen( $raw ) > self::MAX_BYTES * 2 ) return self::error( 'checkpoint_unavailable' );
  if ( substr( $raw, 0, 65 ) !== $scope_sha . ':' ) return self::error( 'checkpoint_scope_changed' );
  $bytes = base64_decode( substr( $raw, 65 ), true );
  if ( false === $bytes || strlen( $bytes ) < 29 ) return self::error( 'checkpoint_corrupt' );
  $plain = openssl_decrypt( substr( $bytes, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $bytes, 0, 12 ), substr( $bytes, 12, 16 ), $scope_sha );
  $state = is_string( $plain ) ? json_decode( $plain, true ) : null;
  return is_array( $state ) && self::CONTRACT === ( $state['contract'] ?? '' ) ? $state : self::error( 'checkpoint_corrupt' );
 }
 private static function read_raw( $name ) {
  wp_cache_delete( $name, 'options' ); wp_cache_delete( 'notoptions', 'options' );
  return get_option( $name, null );
 }
 private static function save( $name, $expected, $next ) {
  global $wpdb;
  if ( null === $expected ) $saved = add_option( $name, $next, '', false );
  else $saved = 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", maybe_serialize( $next ), $name, maybe_serialize( $expected ) ) );
  wp_cache_delete( $name, 'options' ); wp_cache_delete( 'notoptions', 'options' );
  return $saved && $next === self::read_raw( $name ) ? true : self::error( 'checkpoint_cas_failed' );
 }
 /** Provider is private server code, never a callback or scope supplied by MCP. */
 public static function run( array $scope, array $root, $recursive, $cursor, $final_only, $provider, $restart = false ) {
  if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'wp_salt' ) || ! class_exists( 'MAD4B_SCP_Distributed_Lock' ) ) return self::error( 'checkpoint_runtime_unavailable' );
  if ( ! is_string( $cursor ) || strlen( $cursor ) > 120 || ! is_callable( $provider ) ) return self::error( 'cursor_invalid' );
  $name = self::option_name( $scope ); $scope_sha = self::digest( $scope );
  $lock = MAD4B_SCP_Distributed_Lock::catalog_name( $name );
  $held = MAD4B_SCP_Distributed_Lock::acquire( $lock ); if ( is_wp_error( $held ) ) return $held;
  try {
   $raw = self::read_raw( $name ); $state = null === $raw ? null : self::open( $raw, $scope_sha );
   if ( is_wp_error( $state ) ) {
    if ( '' === $cursor && ! $final_only && 'mad4b_scan_checkpoint_scope_changed' === $state->get_error_code() ) $state = null;
    else return $state;
   }
   if ( is_array( $state ) && $state['expires_at'] <= time() ) {
    if ( '' !== $cursor ) return self::error( 'checkpoint_expired' );
    $state = null;
   }
   if ( $restart ) {
    if ( $final_only || ! is_array( $state ) || ! hash_equals( self::cursor( $state, $scope_sha ), $cursor ) ) return self::error( 'restart_requires_current_cursor' );
    $state = null; $cursor = '';
   }
   if ( '' === $cursor && is_array( $state ) ) return self::readback( $state, $scope_sha, $provider );
   if ( null === $state ) {
    if ( '' !== $cursor || $final_only ) return self::error( 'checkpoint_missing' );
    $token = call_user_func( $provider, 'begin', $root ); if ( is_wp_error( $token ) ) return $token;
    if ( ! is_string( $token ) || '' === $token ) return self::error( 'provider_change_token_missing' );
    $state = array( 'contract'=>self::CONTRACT, 'generation'=>bin2hex( random_bytes( 16 ) ),
     'revision'=>0, 'expires_at'=>time()+self::TTL, 'started_at'=>gmdate('c'), 'completed_at'=>'', 'change_token'=>$token,
     'root'=>$root, 'queue'=>array( array('id'=>$root['id'],'path'=>$root['name'],'depth'=>0,'token'=>'') ),
     'pending'=>array(), 'seen'=>array($root['id']=>true), 'page_tokens'=>array(), 'assets'=>array(),
     'folders'=>1, 'complete'=>false, 'last_request'=>'', 'blocked'=>'' );
   } else {
    if ( '' !== $state['blocked'] ) return self::error( $state['blocked'] );
    // Replayed page is readback only. Never append duplicates or rerun provider work.
    if ( hash_equals( $state['last_request'], hash( 'sha256', $cursor ) ) && ! $final_only ) return self::readback( $state, $scope_sha, $provider );
    if ( ! hash_equals( self::cursor( $state, $scope_sha ), $cursor ) ) return self::error( 'cursor_stale_or_foreign' );
   }
   if ( $state['revision'] >= 1024 ) return self::error( 'page_limit' );
   if ( $state['complete'] ) return self::readback( $state, $scope_sha, $provider );
   if ( $final_only ) return self::error( 'inventory_incomplete' );
   $next = self::advance( $state, $recursive, $provider ); if ( is_wp_error( $next ) ) return $next;
   $check = call_user_func( $provider, 'revalidate', $scope );
   if ( true !== $check ) return is_wp_error( $check ) ? $check : self::error( 'scope_changed' );
   if ( $next['expires_at'] <= time() ) return self::error( 'checkpoint_expired' );
   $next['revision']++; $next['last_request'] = hash( 'sha256', $cursor );
   $sealed = self::seal( $next, $scope_sha ); if ( is_wp_error( $sealed ) ) return $sealed;
   // Append-only intent precedes exact CAS. A failed CAS never claims completion.
   if ( ! class_exists( 'MAD4B_SCP_Audit' ) ) return self::error( 'audit_unavailable' );
   $audit = MAD4B_SCP_Audit::record( 'context/source-scan-plan', array( 'event'=>'diagnostic_checkpoint_intent',
    'scope_sha256'=>$scope_sha, 'generation'=>$next['generation'], 'revision'=>$next['revision'],
    'checkpoint_sha256'=>hash('sha256',$sealed), 'registry_mutation'=>false ) );
   if ( is_wp_error( $audit ) || ! $audit ) return self::error( 'audit_failed' );
   if ( ! MAD4B_SCP_Distributed_Lock::owns( $lock ) ) return self::error( 'checkpoint_lock_lost' );
   $saved = self::save( $name, $raw, $sealed );
   return is_wp_error( $saved ) ? $saved : self::result( $next, $scope_sha );
  } finally { MAD4B_SCP_Distributed_Lock::release( $lock ); }
 }
 private static function advance( array $state, $recursive, $provider ) {
  if ( $state['pending'] ) {
   $file = $state['pending'][0];
   $asset = call_user_func( $provider, 'normalize', $file );
   if ( is_wp_error( $asset ) ) return $asset;
   if ( ! is_array( $asset ) || true !== ( $asset['content_complete'] ?? false )
    || ! preg_match('/^[a-f0-9]{64}$/D', (string)($asset['content_hash']??'')) ) return self::error( 'file_normalization_incomplete' );
   if ( $file['id'] !== ($asset['file_id']??'') ) return self::error( 'file_identity_changed' );
   $state['assets'][] = $asset; array_shift( $state['pending'] );
  } elseif ( $state['queue'] ) {
   $folder = $state['queue'][0];
   $page = call_user_func( $provider, 'list', $folder ); if ( is_wp_error( $page ) ) return $page;
   if ( ! is_array( $page ) || ! is_array( $page['files']??null ) || count( $page['files'] ) > 25
    || ! is_string( $page['nextPageToken']??'' ) || ! empty( $page['incompleteSearch'] ) ) return self::error( 'provider_page_invalid' );
   $token = $page['nextPageToken']??'';
   if ( '' !== $token && ( strlen($token)>2048 || isset($state['page_tokens'][$folder['id'].'|'.$token]) ) ) return self::error( 'page_token_repeated' );
   if ( '' !== $token ) $state['page_tokens'][$folder['id'].'|'.$token]=true;
   foreach ( $page['files'] as $file ) {
    $id = is_array($file) ? ($file['id']??'') : '';
    if ( ! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{1,255}$/D',$id) || isset($state['seen'][$id])
     || ! in_array($folder['id'], $file['parents']??array(), true) ) return self::error( 'duplicate_or_foreign_file' );
    $state['seen'][$id]=true; $file['path']=$folder['path'].'/'.($file['name']??$id);
    if ( 'application/vnd.google-apps.folder' === ($file['mimeType']??'') ) {
     if ( ! $recursive ) continue;
     if ( $folder['depth'] >= 8 || ++$state['folders'] > 120 ) return self::error( 'folder_limit' );
     $state['queue'][]=array('id'=>$id,'path'=>$file['path'],'depth'=>$folder['depth']+1,'token'=>'');
    } else {
     if ( 'application/vnd.google-apps.shortcut' === ($file['mimeType']??'') ) return self::error( 'shortcut_scope_review_required' );
     if ( count($state['assets'])+count($state['pending']) >= 500 ) return self::error( 'file_limit' );
     $state['pending'][]=$file;
    }
   }
   if ( '' === $token ) array_shift($state['queue']); else $state['queue'][0]['token']=$token;
  }
  if ( ! $state['pending'] && ! $state['queue'] ) {
   $verified=call_user_func($provider,'verify',$state);
   if ( true !== $verified ) return is_wp_error($verified)?$verified:self::error('provider_changed');
   $state['complete']=true; $state['completed_at']=gmdate('c');
  }
  return $state;
 }
 private static function readback( array $state, $scope_sha, $provider ) {
  if ( $state['complete'] ) {
   $verified=call_user_func($provider,'verify',$state);
   if ( true !== $verified ) return is_wp_error($verified)?$verified:self::error('provider_changed');
  }
  $valid=call_user_func($provider,'revalidate',array());
  if ( true !== $valid ) return is_wp_error($valid)?$valid:self::error('scope_changed');
  return self::result($state,$scope_sha);
 }
 private static function result( array $state, $scope_sha ) {
  return array( 'contract'=>self::CONTRACT, 'scan_generation'=>hash('sha256',self::CONTRACT.'|'.$state['generation']),
   'started_at'=>$state['started_at'], 'completed_at'=>$state['completed_at'],
   'complete'=>$state['complete'], 'truncated'=>!$state['complete'],
   'partial_scan_requires_complete_rescan'=>!$state['complete'],
   'truncation_reasons'=>$state['complete']?array():array('scan_page_pending'),
   'assets'=>$state['assets'], 'asset_count'=>count($state['assets']),
   'scan_cursor'=>self::cursor($state,$scope_sha), 'expires_at'=>$state['expires_at'],
   'checkpoint_revision'=>$state['revision'], 'registry_mutation_performed'=>false,
   'next_action'=>$state['complete']?'prepare_exact_source_scan_apply':'continue_with_scan_cursor' );
 }
}
