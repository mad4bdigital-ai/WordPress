<?php

define( 'ABSPATH', '/tmp/mad4b-context-parent-lineage/' );

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code, $message = '', $data = null ) { $this->code = (string) $code; $this->message = (string) $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\\-]/i', '', (string) $value ) ); }
function esc_url_raw( $value ) { return (string) $value; }

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-google-drive-context.php';

function mad4b_parent_assert( $condition, $message, $context = null ) {
	if ( $condition ) return;
	fwrite( STDERR, "FAIL: {$message}\n" );
	if ( null !== $context ) fwrite( STDERR, json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	exit( 1 );
}

$ref = new ReflectionClass( 'MAD4B_SCP_Google_Drive_Context' );
$candidate = $ref->getMethod( 'recreate_parent_candidate' );
$candidate->setAccessible( true );
$payload = $ref->getMethod( 'provider_asset_payload' );
$payload->setAccessible( true );

$source = array( 'external_root_id' => 'folder-root', 'label' => 'Brand Core', 'recursive' => true );
$flat_source = array( 'external_root_id' => 'folder-root', 'label' => 'Brand Core', 'recursive' => false );

$nested = $candidate->invoke( null, array( 'parent_folder_id' => 'folder-nested' ), $source );
mad4b_parent_assert( is_array( $nested ) && 'folder-nested' === $nested['parent_folder_id'] && empty( $nested['is_source_root'] ), 'Nested recreate must retain exact parent candidate.', $nested );

$root = $candidate->invoke( null, array( 'parent_folder_id' => 'folder-root' ), $source );
mad4b_parent_assert( is_array( $root ) && ! empty( $root['is_source_root'] ), 'Top-level asset may recreate in the selected source root.', $root );

$flat_root = $candidate->invoke( null, array( 'parent_folder_id' => 'folder-root' ), $flat_source );
mad4b_parent_assert( is_array( $flat_root ) && ! empty( $flat_root['is_source_root'] ), 'Non-recursive source must still allow recreation directly in its selected root.', $flat_root );

$flat_nested = $candidate->invoke( null, array( 'parent_folder_id' => 'folder-nested' ), $flat_source );
mad4b_parent_assert( is_wp_error( $flat_nested ) && 'mad4b_google_drive_recreate_parent_outside_source' === $flat_nested->get_error_code(), 'Non-recursive source must reject recreation into nested folders.', $flat_nested );
$flat_nested_data = $flat_nested->get_error_data();
mad4b_parent_assert( is_array( $flat_nested_data ) && 'non_recursive_source_boundary' === ( isset( $flat_nested_data['reason'] ) ? $flat_nested_data['reason'] : '' ), 'Non-recursive recreate denial must expose bounded source-boundary reason.', $flat_nested_data );

$missing = $candidate->invoke( null, array(), $source );
mad4b_parent_assert( is_wp_error( $missing ) && 'mad4b_google_drive_recreate_parent_unavailable' === $missing->get_error_code(), 'Legacy/missing parent lineage must fail closed instead of falling back to root.', $missing );

$provider = array(
	'id' => 'replacement-file',
	'name' => 'Tone of Voice.md',
	'mimeType' => 'text/markdown',
	'parents' => array( 'folder-nested' ),
	'modifiedTime' => '2026-09-19T17:00:00Z',
	'webViewLink' => 'https://drive.google.test/replacement-file',
);
$preserve = array(
	'parent_folder_id' => 'folder-nested',
	'path' => 'Brand Core/Editorial/Tone of Voice.md',
);
$asset = $payload->invoke( null, $source, $provider, 'replacement content', $preserve );
mad4b_parent_assert( 'folder-nested' === $asset['parent_folder_id'], 'Provider payload must persist exact immediate parent folder.', $asset );
mad4b_parent_assert( 'Brand Core/Editorial/Tone of Voice.md' === $asset['path'], 'Recreated asset in the same parent must preserve governed path lineage.', $asset );

$moved = $provider;
$moved['parents'] = array( 'folder-other' );
$moved_payload = $payload->invoke( null, $source, $moved, 'replacement content', $preserve );
mad4b_parent_assert( 'folder-other' === $moved_payload['parent_folder_id'], 'Provider-observed parent must win over stale preserved parent.', $moved_payload );
mad4b_parent_assert( 'Brand Core/Tone of Voice.md' === $moved_payload['path'], 'Path must not be preserved when provider parent differs.', $moved_payload );

echo "mad4b.site-control-plane.context-parent-lineage.runtime.v2: PASS\n";
