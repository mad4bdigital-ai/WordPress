<?php
/** Isolated WordPress API contract fixture; no HTTP, DB, publishing or installs. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $v ) { return json_encode( $v, JSON_UNESCAPED_SLASHES ); }
function wp_parse_url( $v ) { return parse_url( $v ); }
function home_url( $path = '/' ) { return 'https://staging.our-site.test' . $path; }
function sanitize_title( $v ) {
    $v = strtolower( trim( $v ) );
    return preg_replace( '/[^a-z0-9-]+/', '-', $v );
}
function get_post_types( $args, $output ) { return array( 'posts', 'products' ); }
function get_taxonomies( $args, $output ) { return array( 'public_taxonomy' ); }
function get_terms( $args ) {
    $terms = array();
    foreach ( $GLOBALS['taxonomy_terms'] as $term ) {
        if ( $term['slug'] === $args['slug'] ) $terms[] = (object) $term;
    }
    return array_slice( $terms, 0, 2 );
}
function get_term_link( $term ) {
    return 'https://staging.our-site.test/collections/' . $term->slug . '/';
}
function get_post( $id ) {
    if ( ! isset( $GLOBALS['posts'][$id] ) ) return null;
    return (object) $GLOBALS['posts'][$id];
}
function get_post_type_object( $type ) { return (object) array( 'public' => true ); }
function current_user_can( $scope, $id ) { return ! empty( $GLOBALS['reader'] ); }
function get_permalink( $id ) { return $GLOBALS['posts'][$id]['permalink']; }
function get_posts( $args ) {
    $found = array();
    foreach ( $GLOBALS['posts'] as $id => $p ) {
        if ( isset( $p['slug'] ) && $p['slug'] === $args['name'] &&
            $p['post_status'] === 'publish' ) $found[] = $id;
    }
    return array_slice( $found, 0, 2 );
}
function wp_attachment_is_image( $id ) { return 'attachment' === ( $GLOBALS['posts'][$id]['post_type'] ?? '' ); }
function get_post_meta( $id, $name, $single ) {
    $value = $GLOBALS['meta'][$id][$name] ?? '';
    return $single ? $value : ( '' === $value ? array() : array( $value ) );
}
function wp_get_attachment_url( $id ) { return $GLOBALS['posts'][$id]['permalink']; }
class MAD4B_SCP_Policy {
    public static function can_read() { return ! empty( $GLOBALS['reader'] ); }
}
class MAD4B_SCP_Site_Profile {
    public static function status() {
        return array( 'site_uuid' => 'site-1', 'profile_digest' => 'site-proof',
            'origin_match' => true, 'environment_match' => true );
    }
}
/** Minimal stand-in for WP_HTML_Tag_Processor; tests simple HTML tags only. */
class WP_HTML_Tag_Processor {
    private $html;
    private $tags;
    private $index = -1;
    private $mods = array();
    public function __construct( $html ) {
        $this->html = $html;
        preg_match_all( '/<\s*(a|img|source)\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE );
        $this->tags = $matches[0];
    }
    public function next_tag() { return isset( $this->tags[++$this->index] ); }
    public function get_tag() {
        preg_match( '/^<\s*([a-z0-9]+)/i', $this->tags[$this->index][0], $m );
        return strtoupper( $m[1] );
    }
    public function get_attribute( $name ) {
        $tag = $this->mods[$this->index] ?? $this->tags[$this->index][0];
        $pattern = '/\b' . preg_quote( $name, '/' ) . '\s*=\s*(["\x27])(.*?)\1/i';
        return preg_match( $pattern, $tag, $m ) ? $m[2] : null;
    }
    public function set_attribute( $name, $value ) {
        $tag = $this->mods[$this->index] ?? $this->tags[$this->index][0];
        $pattern = '/\b' . preg_quote( $name, '/' ) . '\s*=\s*(["\x27])(.*?)\1/i';
        $value = htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
        if ( preg_match( $pattern, $tag ) )
            $tag = preg_replace( $pattern, $name . '="' . $value . '"', $tag, 1 );
        else $tag = substr( $tag, 0, -1 ) . ' ' . $name . '="' . $value . '">';
        $this->mods[$this->index] = $tag;
    }
    public function remove_attribute( $name ) {
        $tag = $this->mods[$this->index] ?? $this->tags[$this->index][0];
        $pattern = '/\s+' . preg_quote( $name, '/' ) . '\s*=\s*(["\x27])(.*?)\1/i';
        $this->mods[$this->index] = preg_replace( $pattern, '', $tag, 1 );
    }
    public function get_updated_html() {
        $out = $this->html;
        for ( $i = count( $this->tags ) - 1; $i >= 0; $i-- ) {
            if ( ! isset( $this->mods[$i] ) ) continue;
            $old = $this->tags[$i][0];
            $off = $this->tags[$i][1];
            $out = substr_replace( $out, $this->mods[$i], $off, strlen( $old ) );
        }
        return $out;
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-scrape-transfer.php';
function check( $v, $why ) {
    if ( ! $v ) { fwrite( STDERR, "FAIL: $why\n" ); exit( 1 ); }
}
$GLOBALS['reader'] = true;
$GLOBALS['taxonomy_terms'] = array(
    array( 'term_id' => 31, 'slug' => 'shared-category', 'taxonomy' => 'public_taxonomy' ),
);
$GLOBALS['posts'] = array(
    7 => array( 'slug' => 'exact-route', 'post_type' => 'products',
        'post_status' => 'publish', 'permalink' => 'https://staging.our-site.test/products/exact-route/' ),
    8 => array( 'slug' => 'hidden-route', 'post_type' => 'products',
        'post_status' => 'draft', 'permalink' => 'https://staging.our-site.test/products/hidden-route/' ),
    19 => array( 'post_type' => 'attachment', 'post_status' => 'inherit',
        'permalink' => 'https://staging.our-site.test/wp-content/uploads/2026/10/import.jpg' ),
);
$img = 'https://example-competitor.test/assets/photo.jpg';
$GLOBALS['meta'][19] = array( '_mad4b_remote_media_source_sha256' => hash( 'sha256', $img ) );
$input = array(
    'source_page_url' => 'https://example-competitor.test/deals/special-ship',
    'html' => '<p><a href="/products/exact-route?affiliate=1">Related</a> <a href="/deals/unknown">Unknown</a> <a href="https://independent.test/source">Outside</a></p><img src="' . $img . '" alt="Deck" srcset="' . $img . ' 2x">',
    'prices' => array( array( 'raw' => 'USD 1,457 per person', 'currency' => 'USD', 'value' => '1457' ) ),
    'media_bindings' => array( array( 'source_url' => $img, 'attachment_id' => 19 ) ),
);
$plan = MAD4B_SCP_Scrape_Transfer::plan( $input );
check( ! is_wp_error( $plan ) && count( $plan['internal_link_map'] ) === 1 &&
    count( $plan['unmatched_competitor_links'] ) === 1 &&
    $plan['image_import_remaining'] === 0 &&
    $plan['source_prices'][0]['raw'] === 'USD 1,457 per person',
    'Exact local link, unresolved competitor link, existing local image and price capture' );
$rewritten = MAD4B_SCP_Scrape_Transfer::rewrite(
    array_merge( $input, array( 'expected_plan_sha256' => $plan['plan_sha256'] ) ) );
check( ! is_wp_error( $rewritten ) && $rewritten['rewritten_internal_links'] === 1 &&
    $rewritten['competitor_links_unlinked'] === 1 && $rewritten['rewritten_to_local_media'] === 1 &&
    strpos( $rewritten['html'], 'https://staging.our-site.test/products/exact-route/' ) !== false &&
    strpos( $rewritten['html'], 'https://staging.our-site.test/wp-content/uploads/2026/10/import.jpg' ) !== false &&
    strpos( $rewritten['html'], 'https://independent.test/source' ) !== false &&
    strpos( $rewritten['html'], 'affiliate=1' ) === false &&
    $rewritten['source_prices'][0]['value'] === '1457',
    'Rewrite keeps source facts while mapping exact site links and verified local media' );
$term_page = $input;
$term_page['html'] = '<p><a href="/collections/shared-category">Browse category</a></p>';
$term_plan = MAD4B_SCP_Scrape_Transfer::plan( $term_page );
check( ! is_wp_error( $term_plan ) &&
    count( $term_plan['internal_link_map'] ) === 1 &&
    array_values( $term_plan['internal_link_map'] )[0] ===
    'https://staging.our-site.test/collections/shared-category/',
    'Known public taxonomy archive must remap to the local term URL.' );
$GLOBALS['taxonomy_terms'][] = array( 'term_id' => 32,
    'slug' => 'exact-route', 'taxonomy' => 'public_taxonomy' );
$ambiguous_term = MAD4B_SCP_Scrape_Transfer::plan( $input );
check( ! is_wp_error( $ambiguous_term ) &&
    count( $ambiguous_term['unmatched_competitor_links'] ) === 2,
    'Post vs public taxonomy slug conflict must require explicit selection.' );
array_pop( $GLOBALS['taxonomy_terms'] );
$none = $input;
unset( $none['media_bindings'] );
$pending = MAD4B_SCP_Scrape_Transfer::plan( $none );
check( ! is_wp_error( $pending ) && $pending['image_import_remaining'] === 1 &&
    $pending['image_import_tasks'][0]['import_apply_ability'] === 'media/remote-import-apply' &&
    $pending['remote_media_manifest_items'][0]['binding_role'] === 'gallery' &&
    $pending['remote_media_manifest_items'][0]['import']['rights_basis'] === 'unknown' &&
    $pending['remote_media_manifest_items'][0]['import']['capture_only'] === true &&
    $pending['remote_media_manifest_items'][0]['import']['duplicate_policy'] === 'reuse' &&
    $pending['remote_media_manifest_items'][0]['import']['alt'] === 'Deck' &&
    strpos( $pending['remote_media_manifest_items'][0]['import']['description'], 'Original image source:' ) === 0,
    'Scraper should prepare full new attachment metadata and exact native importer fields, without rights claims' );
$lazy = $input;
$lazy['html'] = '<img src="data:image/gif;base64,R0lG" data-src="' . $img . '" alt="Deck detail">';
$lazy_plan = MAD4B_SCP_Scrape_Transfer::plan( $lazy );
check( ! is_wp_error( $lazy_plan ) &&
    count( $lazy_plan['image_import_tasks'] ) === 1 &&
    $lazy_plan['image_import_tasks'][0]['source_url'] === $img,
    'Lazy image data-src should be imported instead of a placeholder' );
$picture = $input;
$large = 'https://example-competitor.test/assets/photo-large.jpg';
$picture['html'] = '<picture><source srcset="' . $img . ' 300w, ' . $large .
    ' 1200w"><img src="data:image/gif;base64,R0lG" alt="River deck"></picture>';
$picture_plan = MAD4B_SCP_Scrape_Transfer::plan( $picture );
check( ! is_wp_error( $picture_plan ) &&
    count( $picture_plan['image_import_tasks'] ) === 1 &&
    $picture_plan['image_import_tasks'][0]['source_url'] === $large &&
    $picture_plan['remote_media_manifest_items'][0]['import']['alt'] === 'River deck',
    'Picture srcset should choose actual higher-resolution URL, not placeholder' );
$picture['media_bindings'] = array();
$picture_rewrite = MAD4B_SCP_Scrape_Transfer::rewrite(
    array_merge( $picture, array( 'expected_plan_sha256' => $picture_plan['plan_sha256'] ) ) );
check( ! is_wp_error( $picture_rewrite ) &&
    !$picture_rewrite['ready_for_draft_review'] &&
    $picture_rewrite['pending_media'] > 0,
    'Missing native imported picture image must keep draft review blocked' );
$bad = $input;
$bad['media_bindings'][0]['attachment_id'] = 7;
check( is_wp_error( MAD4B_SCP_Scrape_Transfer::plan( $bad ) ),
    'Foreign attachment or nonimage ID must be rejected' );
$stale = MAD4B_SCP_Scrape_Transfer::rewrite(
    array_merge( $input, array( 'expected_plan_sha256' => str_repeat( 'f', 64 ) ) ) );
check( is_wp_error( $stale ) && $stale->get_error_code() === 'mad4b_scrape_transfer_plan_drift',
    'Rewrite with unreviewed input digest must not proceed' );
$GLOBALS['posts'][20] = array( 'slug' => 'exact-route', 'post_type' => 'products',
    'post_status' => 'publish', 'permalink' => 'https://staging.our-site.test/products/exact-route-2/' );
$ambiguous = MAD4B_SCP_Scrape_Transfer::plan( $input );
check( ! is_wp_error( $ambiguous ) && count( $ambiguous['unmatched_competitor_links'] ) === 2,
    'Ambiguous slug must not link to the wrong product' );
$GLOBALS['reader'] = false;
check( is_wp_error( MAD4B_SCP_Scrape_Transfer::plan( $input ) ),
    'Unauthorized caller must not inspect site links' );
echo "PASS: source-faithful prices, unique existing-site links, failed ambiguity, local Media Library URLs, provenance, no mutation\n";
