<?php
/**
 * Exact content-bundle field semantics: isolated PHP 7.4+ regression test.
 * No live WordPress bootstrap, OAuth connection, release, or mutation.
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'MAD4B_SCP_DIR', dirname( __DIR__ ) . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $v ) ); }
function wp_json_encode( $v, $opts = 0 ) { return json_encode( $v, $opts ); }
function wp_strip_all_tags( $v ) { return strip_tags( $v ); }
class MAD4B_SCP_Approval_Tickets {
    public static $ticket = array();
    public static function get( $id ) { return self::$ticket; }
}
require MAD4B_SCP_DIR . 'includes/class-mad4b-scp-semantic-content-field-contracts.php';
require MAD4B_SCP_DIR . 'includes/class-mad4b-scp-context-preflight.php';
function check_sem( $ok, $message ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); }
}
function has_error( $value, $code ) {
    return is_wp_error( $value ) && $code === $value->get_error_code();
}
$contract = 'MAD4B_SCP_Semantic_Content_Field_Contracts';
$guard = 'MAD4B_SCP_Context_Preflight';
$ability = 'mad4b/content-apply-bundle';
check_sem( in_array( $ability, $contract::declared_abilities(), true ), 'Bundle must be in declared ability catalog' );
$sha = str_repeat( 'a', 64 );
$operational = array(
    'mode' => 'create', 'post_type' => 'tours-and-activities', 'operation_key' => 'are-semantic-test',
    'post' => array( 'post_status' => 'draft', 'post_parent' => 0 ),
    'meta' => array( 'price' => 2500, 'tour_price' => 2000, '_sku' => 'ARE-001' ),
    'meta_entries' => array( 'stock' => array( 'mode' => 'single', 'value' => 4 ) ),
    'taxonomies' => array( 'destinations' => array( array( 'term_id' => 42 ), array( 'slug' => 'luxor' ) ) ),
    'featured_media_id' => 9001, 'expected_bundle_sha256' => $sha,
    'expected_pipeline_settings_sha256' => $sha,
    'evidence' => array( 'review_note' => 'Internal audit context is not public copy.' ),
);
$o = $contract::classify( $ability, $operational );
check_sem( ! empty( $o['field_contract_known'] ) && 'core' === $o['provider_id'], 'Exact registered core provider contract required' );
check_sem( empty( $o['required'] ) && empty( $o['review_required'] ) && ! empty( $o['mutation_classification_ready'] ), 'Safe operational fields should not require Brand Core' );
check_sem( true === $guard::mutation_context_guard( $ability, $operational ), 'Direct operational bundle should pass only Context preflight' );
$plan = array( 'ability' => $ability, 'input' => $operational, 'reason' => 'Exact staged draft operation' );
check_sem( true === $guard::mutation_context_guard( 'mad4b/approval-plan', $plan ), 'Planner must reuse target bundle semantics' );
$ticket_id = '11111111-1111-4111-8111-111111111111';
MAD4B_SCP_Approval_Tickets::$ticket = array(
    'status' => 'pending', 'ticket_class' => 'mutation', 'ability_name' => $ability,
    'provider' => 'core', 'server_id' => 'mad4b-write', 'payload_sha256' => $sha,
);
$decision = array( 'ticket_id' => $ticket_id, 'decision' => 'approve',
    'expected_payload_sha256' => $sha, 'expected_classification_sha256' => str_repeat( 'b', 64 ),
    'operation_input' => $operational );
$d = $contract::classify( 'mad4b/approval-ai-decide', $decision );
check_sem( ! empty( $d['field_contract_known'] ) && empty( $d['required'] ) && empty( $d['review_required'] ), 'AI decision must resolve exact operational target ticket' );
check_sem( true === $guard::mutation_context_guard( 'mad4b/approval-ai-decide', $decision ), 'Operational target approval Context gate' );

$brand = $operational;
$brand['post']['post_title'] = 'Royal Egypt Nile Cruise';
$brand['post']['post_content'] = 'Proposed tour description.';
$brand['meta']['tour_description'] = 'Public itinerary description.';
$brand['meta_entries']['seo_title'] = array( 'mode' => 'delete' );
$b = $contract::classify( $ability, $brand );
check_sem( ! empty( $b['required'] ) && empty( $b['review_required'] ), 'Named brand fields require exact receipt but not ambiguous review' );
foreach ( array( 'post.post_title', 'post.post_content', 'meta.tour_description', 'meta_entries.seo_title' ) as $path )
    check_sem( in_array( $path, $b['matched_fields'], true ), 'Brand match missing: ' . $path );
check_sem( has_error( $guard::mutation_context_guard( $ability, $brand ), 'mad4b_content_context_receipt_required' ), 'Brand bundle must require receipt at execution gate' );
$plan['input'] = $brand;
check_sem( has_error( $guard::mutation_context_guard( 'mad4b/approval-plan', $plan ), 'mad4b_content_context_receipt_required' ), 'Planner cannot grant brand mutation without receipt' );
$decision['operation_input'] = $brand;
check_sem( has_error( $guard::mutation_context_guard( 'mad4b/approval-ai-decide', $decision ), 'mad4b_content_context_receipt_required' ), 'Approval cannot waive brand receipt' );

$unknown = $operational;
$unknown['meta']['unregistered_field'] = 3; // short, numeric: must still fail closed
$x = $contract::classify( $ability, $unknown );
check_sem( ! empty( $x['review_required'] ) && empty( $x['mutation_classification_ready'] ), 'Unknown short Meta key must fail closed' );
check_sem( has_error( $guard::mutation_context_guard( $ability, $unknown ), 'mad4b_content_field_classification_required' ), 'Unknown metadata cannot bypass classification' );
$unknown = $operational;
$unknown['meta']['price'] = array( 'caption' => 'Buy this itinerary now' );
$x = $contract::classify( $ability, $unknown );
check_sem( ! empty( $x['review_required'] ), 'Editorial nested in operational price must be reviewed' );
$unknown = $operational;
$unknown['post']['unsafe_key'] = 'x';
check_sem( ! empty( $contract::classify( $ability, $unknown )['review_required'] ), 'Unknown post field must be reviewed' );
$unknown = $operational;
$unknown['unrecognized_root'] = 0;
check_sem( ! empty( $contract::classify( $ability, $unknown )['review_required'] ), 'Unknown root field must be reviewed' );
$taxonomy = $operational;
$taxonomy['taxonomies']['destinations'][] = array( 'create_slug' => 'new-location', 'name' => 'New Royal Stop', 'description' => 'New public destination content.' );
$t = $contract::classify( $ability, $taxonomy );
check_sem( ! empty( $t['required'] ) && empty( $t['review_required'] ), 'New taxonomy term text is brand-bearing' );
check_sem( in_array( 'taxonomies.destinations.2.description', $t['matched_fields'], true ), 'New term description missing' );
$decision['operation_input'] = $operational;
MAD4B_SCP_Approval_Tickets::$ticket['payload_sha256'] = str_repeat( 'c', 64 );
check_sem( ! empty( $contract::classify( 'mad4b/approval-ai-decide', $decision )['review_required'] ), 'Stale ticket is not reusable' );
MAD4B_SCP_Approval_Tickets::$ticket['payload_sha256'] = $sha;
MAD4B_SCP_Approval_Tickets::$ticket['provider'] = 'other';
check_sem( ! empty( $contract::classify( 'mad4b/approval-ai-decide', $decision )['review_required'] ), 'Provider mismatch must fail closed' );
echo "mad4b.content-bundle-semantic-contract: PASS\n";
