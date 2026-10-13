<?php
/**
 * Isolated domain-neutral semantic graph acceptance and negative cases.
 * No WordPress boot, database, provider access, mutation or production.
 */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-semantic-structure-intelligence.php';
function insist( $ok, $message ) {
    if ( ! $ok ) { fwrite( STDERR, "FAIL: " . $message . PHP_EOL ); exit( 1 ); }
}
function graph( $source, $targets, $edges, $fields = array() ) {
    $nodes = array( array( 'id' => $source, 'kind' => 'post_type' ) );
    foreach ( $targets as $to ) $nodes[] = array( 'id' => $to, 'kind' => 'relation_target' );
    return array( 'nodes' => $nodes, 'edges' => $edges, 'fields' => $fields );
}
function edge( $from, $to, $kind = 'taxonomy_assignment', $left = 'many', $right = 'many',
    $schema = true, $linked = false ) {
    return array( 'from' => $from, 'to' => $to, 'relation' => $kind,
        'from_to' => $left, 'to_from' => $right,
        'verified_schema' => $schema, 'verified_record_links' => $linked,
        'evidence' => 'fixture_registered_schema' );
}
function kinds( $result ) {
    return array_map( static function( $row ) { return $row['kind']; }, $result['patterns'] );
}

// A travel product can reference multiple reusable itinerary terms. The
// interpreter knows only graph shape; never any "cruise" or "program" key.
$a = 'post_type:product_x'; $b = 'taxonomy:option_x'; $c = 'taxonomy:region_y';
$g = graph( $a, array( $b, $c ), array( edge( $a, $b ), edge( $a, $c ) ) );
$r = MAD4B_SCP_Semantic_Structure_Intelligence::assess( $g, 'compose_content' );
insist( ! is_wp_error( $r ) && in_array( 'shared_reusable_reference', kinds( $r ), true ) &&
    in_array( 'multi_source_composition', kinds( $r ), true ) &&
    ! $r['publication_authorized'] && ! $r['mutation_performed'],
    'Multiple reusable relations require readback, never content copies/permission' );
$sha = $r['graph_sha256'];
insist( preg_match( '/^[a-f0-9]{64}$/', $sha ), 'Stable graph digest required.' );

// Independent domain: courses reusing skill modules across classes.
$courses = graph( 'post_type:course', array( 'taxonomy:skill', 'post_type:instructor' ),
    array( edge( 'post_type:course', 'taxonomy:skill' ),
        edge( 'post_type:course', 'post_type:instructor',
            'native_relation', 'many', 'one' ) ) );
$course = MAD4B_SCP_Semantic_Structure_Intelligence::assess( $courses );
insist( in_array( 'multi_item_composition', kinds( $course ), true ) &&
    in_array( 'shared_reusable_reference', kinds( $course ), true ),
    'Mixed cardinality should infer collections and reusable references, no vocabulary dependence.' );

// Independent domain: service plan with nested repeaters, not reusable records.
$service = graph( 'post_type:service', array( 'post_type:component' ),
    array( edge( 'post_type:service', 'post_type:component',
        'native_relation', 'many', 'one' ) ),
    array( array( 'key' => 'offer_steps', 'type' => 'array', 'single' => true ) ) );
$svc = MAD4B_SCP_Semantic_Structure_Intelligence::assess( $service );
insist( in_array( 'multi_item_composition', kinds( $svc ), true ) &&
    in_array( 'structured_collection_candidate', kinds( $svc ), true ) &&
    ! in_array( 'shared_reusable_reference', kinds( $svc ), true ),
    'Repeater/one-way child collections must not be mistaken for reusable resources.' );

// Provider did not certify relationship: hypothesize and request evidence.
$hyp = graph( 'post_type:object', array( 'post_type:shared' ),
    array( edge( 'post_type:object', 'post_type:shared',
        'native_relation', 'many', 'many', false ) ) );
$h = MAD4B_SCP_Semantic_Structure_Intelligence::assess( $hyp );
insist( in_array( 'shared_reference_hypothesis', kinds( $h ), true ) &&
    $h['patterns'][0]['confidence'] < 0.5 &&
    !$h['patterns'][0]['semantic_role_confirmed'],
    'Unverified provider relations must never become verified facts.' );

// A taxonomy and a native relation between same types may represent storage
// drift, not two independent business objects: require reconciliation.
$conflict = graph( $a, array( $b ), array(
    edge( $a, $b, 'taxonomy_assignment' ),
    edge( $a, $b, 'native_relation' ),
) );
$d = MAD4B_SCP_Semantic_Structure_Intelligence::assess( $conflict );
insist( count( $d['ambiguities'] ) === 1 &&
    !$d['ambiguities'][0]['automatic_reconciliation_allowed'],
    'Parallel representations must be diagnosed, not silently merged.' );
insist( $sha === MAD4B_SCP_Semantic_Structure_Intelligence::assess( $g, 'compose_content' )['graph_sha256'],
    'Same evidence must produce identical graph identity.' );
$variants = $g;
$variants['edges'][0]['to_from'] = 'one';
insist( $sha !== MAD4B_SCP_Semantic_Structure_Intelligence::assess( $variants, 'compose_content' )['graph_sha256'],
    'Cardinality drift must invalidate the old plan fingerprint.' );

// Unknown/foreign endpoints, duplicate evidence, overflow and content-name
// guessing are never accepted as a viable content mutation plan.
$invalid = $g; $invalid['edges'][0]['to'] = 'taxonomy:foreign';
insist( is_wp_error( MAD4B_SCP_Semantic_Structure_Intelligence::assess( $invalid ) ),
    'Foreign relation target must fail closed.' );
$dup = $g; $dup['edges'][] = $dup['edges'][0];
insist( is_wp_error( MAD4B_SCP_Semantic_Structure_Intelligence::assess( $dup ) ),
    'Duplicate relation observation must not inflate confidence.' );
$unknown = $g; $unknown['edges'][0]['from_to'] = 'guessed';
insist( is_wp_error( MAD4B_SCP_Semantic_Structure_Intelligence::assess( $unknown ) ),
    'Unsupported cardinality must not be guessed.' );
$too_big = $g;
for ( $i = 0; $i < 130; $i++ )
    $too_big['nodes'][] = array( 'id' => 'taxonomy:generated' . $i, 'kind' => 'taxonomy_term_set' );
insist( is_wp_error( MAD4B_SCP_Semantic_Structure_Intelligence::assess( $too_big ) ),
    'Schema inventory must be bounded.' );
insist( ! $r['authorization_created'] && $r['graph_is_not_execution_plan'] &&
    $r['layout_not_verified'] && $r['business_intent_not_inferred_as_fact'],
    'Reasoning output cannot replace live template proof, source rights or governance.' );
echo "PASS: domain-neutral graph reuse, composition, candidate collections, provider uncertainty, drift, input bounds, no authorization\n";
