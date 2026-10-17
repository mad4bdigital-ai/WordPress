<?php
/* Pure provider/registry-set acceptance: no WordPress, network, DB or writes. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
class WP_Error {
	private $code;
	public function __construct( $code, $message = '' ) { $this->code = (string) $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $options = 0 ) { return json_encode( $value, $options ); }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-context-authority.php';
function legacy_proof_check( $ok, $reason ) {
	if ( ! $ok ) { fwrite( STDERR, "FAIL: " . $reason . "\n" ); exit( 1 ); }
}
function legacy_proof( $plan, $records, $scan, $recursive = true ) {
	return MAD4B_SCP_Context_Authority::legacy_owner_provider_proof( $plan, $records, $scan, $recursive );
}
function legacy_proof_denies( $result, $code, $reason ) {
	legacy_proof_check( is_wp_error( $result ) && $code === $result->get_error_code(), $reason );
}
$id = str_repeat( 'a', 64 );
$plan = array(
	'source_id' => $id, 'plan_sha256' => str_repeat( 'b', 64 ),
	'external_root_id' => 'original-folder-id', 'asset_count' => 12,
);
$records = array();
$provider_files = array();
for ( $i = 1; $i <= 12; ++$i ) {
	$file_id = 'original-file-' . $i;
	$asset_id = hash( 'sha256', $id . '|' . $file_id );
	$records[ $asset_id ] = array(
		'source_id' => $id, 'file_id' => $file_id, 'asset_id' => $asset_id,
	);
	$provider_files[] = array(
		'file_id' => $file_id, 'parent_folder_id' => 'original-folder-id',
		'mimeType' => 'application/vnd.google-apps.document',
		'modifiedTime' => '2026-10-11T00:00:00Z',
		'content_hash' => hash( 'sha256', 'document-' . $i ),
	);
}
$provider_files[] = array(
	'file_id' => 'unregistered-extra-file',
	'parent_folder_id' => 'original-folder-id',
	'mimeType' => 'text/plain', 'modifiedTime' => '2026-10-11T00:00:00Z',
);
$scan = array(
	'folder' => array( 'id' => 'original-folder-id', 'mimeType' => 'application/vnd.google-apps.folder' ),
	'assets' => $provider_files, 'complete' => true, 'truncated' => false, 'recursive' => true,
);
$ok = legacy_proof( $plan, $records, $scan );
legacy_proof_check( ! is_wp_error( $ok ) && ! empty( $ok['folder_membership_verified'] )
	&& 12 === $ok['matched_file_count'] && 13 === $ok['provider_file_count']
	&& ! $ok['migration_authorized'] && ! $ok['legal_owner_or_rights_verified']
	&& ! $ok['mutation_performed'], 'complete original inventory should prove membership only' );
$reverse = $scan;
$reverse['assets'] = array_reverse( $reverse['assets'] );
$stable = legacy_proof( $plan, $records, $reverse );
legacy_proof_check( ! is_wp_error( $stable ) && hash_equals( $ok['provider_proof_sha256'], $stable['provider_proof_sha256'] ),
	'provider list iteration order must not alter exact approval proof' );
$modified = $scan;
$modified['assets'][0]['modifiedTime'] = '2026-10-11T00:01:00Z';
$changed = legacy_proof( $plan, $records, $modified );
legacy_proof_check( ! is_wp_error( $changed ) && ! hash_equals( $ok['provider_proof_sha256'], $changed['provider_proof_sha256'] ),
	'in-place Drive file version change must invalidate prior proof' );
$missing = $scan;
array_shift( $missing['assets'] );
legacy_proof_denies( legacy_proof( $plan, $records, $missing ), 'mad4b_legacy_proof_file_missing',
	'missing one of the twelve original file IDs must deny adoption' );
$partial = $scan;
$partial['complete'] = false;
legacy_proof_denies( legacy_proof( $plan, $records, $partial ), 'mad4b_legacy_proof_incomplete_or_wrong_folder',
	'partial paginated/timeout scan cannot prove source ownership' );
$wrong_root = $scan;
$wrong_root['folder']['id'] = 'lookalike-folder';
legacy_proof_denies( legacy_proof( $plan, $records, $wrong_root ), 'mad4b_legacy_proof_incomplete_or_wrong_folder',
	'same-name different-ID folder cannot replace original' );
$wrong_scope = $scan;
$wrong_scope['recursive'] = false;
legacy_proof_denies( legacy_proof( $plan, $records, $wrong_scope ), 'mad4b_legacy_proof_incomplete_or_wrong_folder',
	'nonrecursive listing cannot prove a recursive source' );
$duplicate = $scan;
$duplicate['assets'][] = $duplicate['assets'][0];
legacy_proof_denies( legacy_proof( $plan, $records, $duplicate ), 'mad4b_legacy_proof_duplicate_provider_identity',
	'provider duplicate aliases must not become evidence' );
$shortcut = $scan;
$shortcut['assets'][0]['mimeType'] = 'application/vnd.google-apps.shortcut';
legacy_proof_denies( legacy_proof( $plan, $records, $shortcut ), 'mad4b_legacy_proof_file_indirect',
	'shortcut cannot establish original file membership' );
$no_parent = $scan;
$no_parent['assets'][0]['parent_folder_id'] = '';
legacy_proof_denies( legacy_proof( $plan, $records, $no_parent ), 'mad4b_legacy_proof_file_indirect',
	'file with no proven parent folder cannot pass' );
$extra = $records;
$extra[ hash( 'sha256', $id . '|new-file' ) ] = array(
	'source_id' => $id, 'file_id' => 'new-file',
	'asset_id' => hash( 'sha256', $id . '|new-file' ),
);
legacy_proof_denies( legacy_proof( $plan, $extra, $scan ), 'mad4b_legacy_proof_registry_count_changed',
	'thirteenth previously unknown legacy asset invalidates approved plan count' );
$invalid_key = $records;
$first = array_key_first( $invalid_key );
$invalid_key[ $first ]['asset_id'] = str_repeat( 'f', 64 );
legacy_proof_denies( legacy_proof( $plan, $invalid_key, $scan ), 'mad4b_legacy_proof_registry_ambiguous',
	'noncanonical asset key or fingerprint must deny proof' );
echo "mad4b.site-control-plane.context-legacy-owner-provider-proof.runtime.v1: PASS\n";
