<?php
/** Independent HTTP proof of the advertised local cookie compatibility path. */
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress must be loaded.' );
$base = getenv( 'MAD4B_PROJECTION_NETWORK_BASE' );
if ( ! $base ) throw new RuntimeException( 'Independent HTTP server is required.' );
$expires = time() + 300;
$manager = WP_Session_Tokens::get_instance( 1 );
$token = $manager->create( $expires );
$original_cookie = $_COOKIE[LOGGED_IN_COOKIE] ?? null;
$original_user = get_current_user_id();
try {
 $cookie = wp_generate_auth_cookie( 1, $expires, 'logged_in', $token );
 $_COOKIE[LOGGED_IN_COOKIE] = $cookie;
 wp_set_current_user( 1 );
 $nonce = wp_create_nonce( 'wp_rest' );
 $url = rtrim( $base, '/' ) . '/wp-json/mad4b/v1/capability-gateway';
 $headers = array( 'Content-Type' => 'application/json', 'Cookie' => LOGGED_IN_COOKIE . '=' . $cookie );
 if ( getenv( 'MAD4B_PROJECTION_NETWORK_HOST' ) ) $headers['Host'] = getenv( 'MAD4B_PROJECTION_NETWORK_HOST' );
 $send = static function ( array $request_headers ) use ( $url ) {
  $response = wp_remote_request( $url, array( 'method' => 'POST', 'headers' => $request_headers, 'body' => wp_json_encode( array( 'action' => 'negotiate' ) ), 'timeout' => 10, 'redirection' => 0 ) );
  if ( is_wp_error( $response ) ) throw new RuntimeException( 'Cookie HTTP fixture failed: ' . $response->get_error_code() );
  return $response;
 };
 $ok = $send( $headers + array( 'X-WP-Nonce' => $nonce ) );
 $data = json_decode( wp_remote_retrieve_body( $ok ), true );
 if ( 200 !== wp_remote_retrieve_response_code( $ok ) || ! in_array( 'authenticated_wordpress_session', $data['rest_auth_modes'] ?? array(), true ) ) throw new RuntimeException( 'Advertised cookie+nonce auth did not reach gateway.' );
 $without_nonce = $send( $headers );
 if ( wp_remote_retrieve_response_code( $without_nonce ) < 400 ) throw new RuntimeException( 'Cookie without REST nonce retained read authority.' );
 $bad_bearer = $send( $headers + array( 'X-WP-Nonce' => $nonce, 'Authorization' => 'Bearer invalid' ) );
 if ( wp_remote_retrieve_response_code( $bad_bearer ) < 400 ) throw new RuntimeException( 'Invalid bearer fell back to a valid cookie session.' );
 echo "PASS independent gateway auth: cookie+nonce, nonce denial and invalid-bearer fallback denial\n";
} finally {
 $manager->destroy( $token );
 if ( null === $original_cookie ) unset( $_COOKIE[LOGGED_IN_COOKIE] ); else $_COOKIE[LOGGED_IN_COOKIE] = $original_cookie;
 wp_set_current_user( $original_user );
}
