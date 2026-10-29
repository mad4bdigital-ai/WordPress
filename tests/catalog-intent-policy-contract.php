<?php
declare( strict_types=1 );
define( 'ABSPATH', '/tmp' );
$GLOBALS['read_ok'] = false;
$GLOBALS['registered'] = array();
$GLOBALS['hook'] = null;
class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
class MAD4B_SCP_Policy {
    public static function can_read() { return $GLOBALS['read_ok']; }
}
function add_action( $name, $callback, $priority ) { $GLOBALS['hook'] = $callback; }
function wp_register_ability( $name, $config ) { $GLOBALS['registered'][ $name ] = $config; }
function wp_has_ability( $name ) { return isset( $GLOBALS['registered'][ $name ] ); }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
require dirname( __DIR__ ) . '/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-catalog-intent-policy.php';
$cls = 'MAD4B_SCP_Catalog_Intent_Policy';
call_user_func( $GLOBALS['hook'] );
assert( isset( $GLOBALS['registered'][ $cls::ABILITY ] ) );
assert( 'mad4b-read' === $GLOBALS['registered'][ $cls::ABILITY ]['category'] );
assert( true === $GLOBALS['registered'][ $cls::ABILITY ]['meta']['annotations']['readonly'] );
$catalog = 'https://www.memphistours.com/egypt/cruises/river-nile-cruises';
$primary_url = 'https://www.memphistours.com/egypt/movenpick-royal-lotus-nile-cruise';
$primary = array( 'url' => $primary_url, 'title' => 'Movenpick Royal Lotus', 'entity_type' => 'individual_tour',
    'discovery_context' => 'listing_card', 'intent_relation' => 'direct', 'entity_key' => 'ship:royal-lotus' );
$secondary = array( 'url' => 'https://www.memphistours.com/egypt/philae-temple', 'title' => 'Philae Temple',
    'entity_type' => 'attraction', 'discovery_context' => 'inline_reference',
    'intent_relation' => 'supporting', 'related_primary_urls' => array( $primary_url ) );
$filter = array( 'url' => 'https://www.memphistours.com/egypt/cruises/river-nile-cruises?duration=4&sort=popular',
    'title' => 'Four-night sailings', 'entity_type' => 'filter',
    'discovery_context' => 'filter_control', 'intent_relation' => 'exploration' );
$unknown = array( 'url' => 'https://www.memphistours.com/egypt/random-article', 'title' => 'Maybe useful',
    'entity_type' => 'unknown', 'discovery_context' => 'unknown', 'intent_relation' => 'unknown' );
$alias = array( 'url' => 'https://www.memphistours.com/Egypt/Cruises/River-Nile-Cruises/royal-lotus',
    'title' => 'Royal Lotus alias', 'entity_type' => 'individual_tour',
    'discovery_context' => 'listing_card', 'intent_relation' => 'direct', 'entity_key' => 'ship:royal-lotus' );
$dupe = array( 'url' => $primary_url . '?utm_source=menu', 'title' => 'Royal Lotus menu entry',
    'entity_type' => 'individual_tour', 'discovery_context' => 'navigation', 'intent_relation' => 'supporting' );
$input = array( 'catalog_url' => $catalog, 'intent' => 'tour_catalog',
    'links' => array( $secondary, $filter, $unknown, $alias, $dupe, $primary ),
    'existing_urls' => array( $primary_url ) );
assert( is_wp_error( $cls::plan( $input ) ) );
$GLOBALS['read_ok'] = true;
$plan = $cls::plan( $input );
assert( ! is_wp_error( $plan ) );
assert( $plan['counts']['primary'] === 1 );
assert( $plan['counts']['complementary'] === 1 );
assert( $plan['counts']['discovery_expansion'] === 1 );
assert( $plan['counts']['review'] === 1 );
assert( $plan['duplicate_count'] === 2 );
assert( $plan['tasks']['primary'][0]['existing_local_url_match'] === true );
assert( $plan['tasks']['primary'][0]['required_next_steps'] === array( 'review_existing_local_entity', 'assess_update_not_create' ) );
assert( $plan['tasks']['complementary'][0]['related_primary_task_ids'] === array( $plan['tasks']['primary'][0]['task_id'] ) );
assert( $plan['tasks']['complementary'][0]['relationship_status'] === 'mapped_from_explicit_catalog_evidence' );
assert( $plan['tasks']['discovery_expansion'][0]['source_url'] === $filter['url'] );
assert( $plan['tasks']['review'][0]['decision_reason'] === 'intent_relevance_not_proven' );
assert( $plan['task_creation_performed'] === false && $plan['wordpress_mutation_performed'] === false );
assert( $plan['network_requests_performed'] === 0 && $plan['publication_authorized'] === false );
assert( $plan === $cls::plan( $input ) );
assert( $cls::canonical_url( $primary_url . '?gclid=one&utm_campaign=abc' ) === $primary_url );
assert( $cls::canonical_url( $filter['url'] ) !== $cls::canonical_url( $catalog ) );
assert( $cls::canonical_url( 'https://127.0.0.1/private' ) === '' );
assert( $cls::canonical_url( 'https://user:password@example.com/x' ) === '' );
assert( $cls::canonical_url( 'http://example.com/x' ) === '' );
assert( $cls::canonical_url( 'https://example.com:8080/x' ) === '' );
assert( $cls::canonical_url( 'https://localhost/x' ) === '' );
assert( is_wp_error( $cls::plan( array( 'catalog_url' => $catalog, 'intent' => 'tour_catalog',
    'links' => array( array_merge( $primary, array( 'unknown_field' => 1 ) ) ) ) ) ) );
assert( is_wp_error( $cls::plan( array( 'catalog_url' => $catalog, 'intent' => 'tour_catalog',
    'links' => array_fill( 0, 201, $primary ) ) ) ) );
$dst = $cls::plan( array( 'catalog_url' => $catalog, 'intent' => 'destination_guide',
    'links' => array( array( 'url' => $secondary['url'], 'title' => 'Philae Temple',
        'entity_type' => 'attraction', 'discovery_context' => 'listing_card', 'intent_relation' => 'direct' ) ) ) );
assert( $dst['counts']['primary'] === 1 );
$no_parent = $secondary;
$no_parent['related_primary_urls'] = array( 'https://example.com/not-in-plan' );
$unlinked = $cls::plan( array( 'catalog_url' => $catalog, 'intent' => 'tour_catalog',
    'links' => array( $primary, $no_parent ) ) );
assert( $unlinked['unresolved_relationships'] === 1 );
assert( $unlinked['tasks']['complementary'][0]['related_primary_task_ids'] === array() );
// Single catalog with 13 real listing cards and 2 side routes:
$slugs = array( 'movenpick-royal-lotus-nile-cruise', 'moevenpick-royal-lily-nile-cruise',
    'dwa-cruise', 'minerva-nile-cruise', 'ms-salacia-nile-cruise', 'jaz-jubilee-nile-cruise',
    'the-oberoi-philae-nile-cruise', 'historia-nile-cruise', 'the-oberoi-zahra-nile-cruise',
    'sonesta-st-george-nile-cruise', 'sonesta-moon-goddess-nile-cruise',
    'sonesta-nile-goddess-nile-cruise', 'iberotel-crown-empress-nile-cruise' );
$links = array();
foreach ( $slugs as $s ) {
    $links[] = array( 'url' => 'https://www.memphistours.com/egypt/' . $s,
        'title' => $s, 'entity_type' => 'individual_tour',
        'discovery_context' => 'listing_card', 'intent_relation' => 'direct' );
}
$links[] = $secondary;
$links[] = $filter;
$out = $cls::plan( array( 'catalog_url' => $catalog, 'intent' => 'tour_catalog', 'links' => $links ) );
assert( $out['counts']['primary'] === 13 );
assert( $out['counts']['complementary'] === 1 && $out['counts']['discovery_expansion'] === 1 );
assert( $out['duplicate_count'] === 0 );
echo "catalog-intent-policy-contract: PASS\n";
