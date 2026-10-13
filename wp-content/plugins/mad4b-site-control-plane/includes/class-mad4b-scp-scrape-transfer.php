<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Source-faithful scraping handoff to native WordPress links and Media Library.
 *
 * Scraping is evidence capture, not a license, inventory, sale or publication
 * decision. Existing governed media import provides downloaded bytes, original
 * provenance, SHA256, deduplication and attachment metadata readback.
 * This class only builds and applies an in-memory URL mapping: no HTTP, no DB
 * writes, no publication and no invented prices or matching destinations.
 */
final class MAD4B_SCP_Scrape_Transfer {
    const CONTRACT = 'mad4b.scrape-transfer.v1';
    const PLAN_ABILITY = 'mad4b/scrape-transfer-plan';
    const REWRITE_ABILITY = 'mad4b/scrape-transfer-rewrite';
    const MAX_HTML_BYTES = 262144;
    const MAX_LINKS = 160;
    const MAX_IMAGES = 50;

    public static function boot() {
        if ( function_exists( 'add_action' ) )
            add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
    }

    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        foreach ( array( self::PLAN_ABILITY => 'plan', self::REWRITE_ABILITY => 'rewrite' ) as $name => $method ) {
            if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) continue;
            wp_register_ability( $name, array(
                'label' => 'Scraped Source WordPress Link and Media Handoff',
                'description' => 'Read-only scraped page URL remapping against existing published posts and imported WP Media, preserving source/prices and never publishing.',
                'category' => 'mad4b-read',
                'execute_callback' => array( __CLASS__, $method ),
                'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
                'input_schema' => array( 'type' => 'object',
                    'properties' => array(
                        'source_page_url' => array( 'type' => 'string', 'maxLength' => 2048 ),
                        'html' => array( 'type' => 'string', 'maxLength' => self::MAX_HTML_BYTES ),
                        'prices' => array( 'type' => 'array', 'maxItems' => 80,
                            'items' => array( 'type' => 'object', 'additionalProperties' => true ) ),
                        'link_overrides' => array( 'type' => 'array', 'maxItems' => self::MAX_LINKS,
                            'items' => array( 'type' => 'object',
                                'properties' => array(
                                    'source_url' => array( 'type' => 'string' ),
                                    'target_post_id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
                                'required' => array( 'source_url', 'target_post_id' ),
                                'additionalProperties' => false ) ),
                        'media_bindings' => array( 'type' => 'array', 'maxItems' => self::MAX_IMAGES,
                            'items' => array( 'type' => 'object',
                                'properties' => array(
                                    'source_url' => array( 'type' => 'string' ),
                                    'attachment_id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
                                'required' => array( 'source_url', 'attachment_id' ),
                                'additionalProperties' => false ) ),
                        'expected_plan_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                    ), 'required' => array( 'source_page_url', 'html' ),
                    'additionalProperties' => false ),
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array( 'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                    'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
            ) );
        }
    }

    private static function absolute( $raw, $page ) {
        if ( ! is_string( $raw ) || '' === trim( $raw ) || strlen( $raw ) > 2048 ) return '';
        $raw = trim( html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' ) );
        if ( '#' === substr( $raw, 0, 1 ) || 1 === preg_match( '/^(?:mailto|tel|javascript|data):/i', $raw ) ) return '';
        $p = wp_parse_url( $page );
        if ( ! is_array( $p ) || empty( $p['scheme'] ) || empty( $p['host'] ) ) return '';
        $origin = strtolower( $p['scheme'] ) . '://' . $p['host'];
        if ( isset( $p['port'] ) ) $origin .= ':' . (int) $p['port'];
        if ( 0 === strpos( $raw, '//' ) ) $raw = strtolower( $p['scheme'] ) . ':' . $raw;
        elseif ( '/' === substr( $raw, 0, 1 ) ) $raw = $origin . $raw;
        elseif ( false === strpos( $raw, '://' ) ) {
            $dir = isset( $p['path'] ) ? dirname( $p['path'] ) : '/';
            $raw = $origin . rtrim( str_replace( '\\', '/', $dir ), '/' ) . '/' . $raw;
        }
        $u = wp_parse_url( $raw );
        if ( ! is_array( $u ) || ! in_array( strtolower( (string) ( $u['scheme'] ?? '' ) ), array( 'https', 'http' ), true )
            || empty( $u['host'] ) || isset( $u['user'] ) || isset( $u['pass'] ) ) return '';
        return $raw;
    }

    private static function same_origin( $a, $b ) {
        $x = wp_parse_url( $a ); $y = wp_parse_url( $b );
        return is_array( $x ) && is_array( $y )
            && strtolower( (string) ( $x['host'] ?? '' ) ) === strtolower( (string) ( $y['host'] ?? '' ) )
            && (int) ( $x['port'] ?? 0 ) === (int) ( $y['port'] ?? 0 );
    }

    /** Read an actual existing public local WordPress target, never invent it. */
    private static function target( $id ) {
        $post = get_post( (int) $id );
        if ( ! is_object( $post ) || (string) ( $post->post_status ?? '' ) !== 'publish' ) return '';
        $type = get_post_type_object( (string) ( $post->post_type ?? '' ) );
        if ( ! is_object( $type ) || empty( $type->public ) || ! current_user_can( 'read_post', (int) $id ) ) return '';
        $url = get_permalink( $id );
        if ( ! is_string( $url ) || ! self::same_origin( $url, home_url( '/' ) ) ) return '';
        return $url;
    }

    /** Unique existing public post OR public taxonomy archive, never a guess. */
    private static function auto_target( $source_url ) {
        $p = wp_parse_url( $source_url );
        if ( ! is_array( $p ) ) return '';
        $path = trim( (string) ( $p['path'] ?? '' ), '/' );
        if ( '' === $path ) return home_url( '/' );
        $parts = explode( '/', $path );
        $slug = sanitize_title( rawurldecode( end( $parts ) ) );
        if ( '' === $slug || strlen( $slug ) > 180 ) return '';
        $candidates = array();
        $types = get_post_types( array( 'public' => true ), 'names' );
        if ( is_array( $types ) && $types ) {
            $ids = get_posts( array(
                'name' => $slug, 'post_type' => array_values( $types ),
                'post_status' => 'publish', 'posts_per_page' => 2,
                'fields' => 'ids', 'suppress_filters' => false,
                'no_found_rows' => true,
            ) );
            if ( ! is_array( $ids ) || count( $ids ) > 1 ) return '';
            foreach ( $ids as $id ) {
                $target = self::target( (int) $id );
                if ( '' !== $target ) $candidates[] = $target;
            }
        }
        // Public taxonomy archives are also internal pages. A slug that
        // matches both a post and a term (or multiple taxonomies) is NOT
        // automatically linked: the ambiguity requires an exact binding.
        if ( function_exists( 'get_taxonomies' ) && function_exists( 'get_terms' ) &&
            function_exists( 'get_term_link' ) ) {
            $taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
            if ( is_array( $taxonomies ) && $taxonomies ) {
                $terms = get_terms( array(
                    'taxonomy' => array_values( $taxonomies ), 'slug' => $slug,
                    'hide_empty' => false, 'number' => 2,
                ) );
                if ( is_wp_error( $terms ) || ! is_array( $terms ) || count( $terms ) > 1 ) return '';
                foreach ( $terms as $term ) {
                    if ( ! is_object( $term ) || ! isset( $term->term_id ) ) return '';
                    $url = get_term_link( $term );
                    if ( ! is_wp_error( $url ) && is_string( $url ) &&
                        self::same_origin( $url, home_url( '/' ) ) ) $candidates[] = $url;
                }
            }
        }
        return 1 === count( $candidates ) ? $candidates[0] : '';
    }

    /** A previously imported Media Library item must be bound to this source. */
    private static function media_target( $source_url, $id ) {
        $post = get_post( (int) $id );
        if ( ! is_object( $post ) || (string) ( $post->post_type ?? '' ) !== 'attachment'
            || ! wp_attachment_is_image( (int) $id ) || ! current_user_can( 'read_post', (int) $id ) )
            return '';
        $source_hash = hash( 'sha256', $source_url );
        // The native importer can bind multiple source URLs to one content-
        // identical attachment; read *all* verified provenance aliases.
        $recorded = get_post_meta( (int) $id, '_mad4b_remote_media_source_sha256', false );
        $recorded = is_array( $recorded ) ? array_values( array_map( 'strval', $recorded ) ) : array();
        $simple_source = (string) get_post_meta( (int) $id, '_mad4b_import_source_url', true );
        if ( ! in_array( $source_hash, $recorded, true ) && ! hash_equals( $source_url, $simple_source ) )
            return '';
        $url = wp_get_attachment_url( (int) $id );
        return is_string( $url ) && self::same_origin( $url, home_url( '/' ) ) ? $url : '';
    }

    /** Pick one publicly-addressable candidate from native HTML srcset. */
    /** Unicode-safe metadata truncation, even when mbstring is unavailable. */
    private static function safe_text_cap( $text, $limit ) {
        $text = (string) $text;
        if ( function_exists( 'mb_substr' ) )
            return mb_substr( $text, 0, $limit, 'UTF-8' );
        $chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
        return is_array( $chars ) ? implode( '', array_slice( $chars, 0, $limit ) ) : '';
    }

    private static function srcset_candidate( $srcset, $source ) {
        if ( ! is_string( $srcset ) || '' === trim( $srcset ) ||
            strlen( $srcset ) > 4096 ) return '';
        $best = ''; $highest = -1.0;
        foreach ( array_slice( explode( ',', $srcset ), 0, 20 ) as $part ) {
            $pair = preg_split( '/\\s+/', trim( $part ), 2 );
            if ( ! is_array( $pair ) || ! isset( $pair[0] ) ) continue;
            $url = self::absolute( $pair[0], $source );
            if ( '' === $url ) continue;
            $descriptor = isset( $pair[1] ) ? trim( $pair[1] ) : '';
            $score = 1.0;
            if ( preg_match( '/^([1-9][0-9]{0,4})w$/', $descriptor, $m ) )
                $score = (float) $m[1];
            elseif ( preg_match( '/^([1-9](?:\\.[0-9]+)?)x$/', $descriptor, $m ) )
                $score = 1000.0 * (float) $m[1];
            if ( $score > $highest ) { $best = $url; $highest = $score; }
        }
        return $best;
    }

    private static function parse( $input ) {
        if ( ! is_array( $input ) || array_diff( array_keys( $input ), array(
            'source_page_url', 'html', 'prices', 'link_overrides', 'media_bindings', 'expected_plan_sha256' ) ) )
            return new WP_Error( 'mad4b_scrape_transfer_input_invalid', 'Unexpected transfer input.' );
        $source = $input['source_page_url'] ?? '';
        $html = $input['html'] ?? '';
        if ( ! is_string( $source ) || ! is_string( $html ) || strlen( $html ) > self::MAX_HTML_BYTES
            || '' === self::absolute( $source, $source ) )
            return new WP_Error( 'mad4b_scrape_transfer_source_invalid', 'Exact source URL and bounded HTML required.' );
        if ( ! class_exists( 'WP_HTML_Tag_Processor' ) )
            return new WP_Error( 'mad4b_scrape_transfer_html_processor_missing', 'Native WordPress HTML parser required.' );
        $prices = $input['prices'] ?? array();
        $overrides = $input['link_overrides'] ?? array();
        $bindings = $input['media_bindings'] ?? array();
        if ( ! is_array( $prices ) || count( $prices ) > 80 ||
            ! is_array( $overrides ) || count( $overrides ) > self::MAX_LINKS ||
            ! is_array( $bindings ) || count( $bindings ) > self::MAX_IMAGES )
            return new WP_Error( 'mad4b_scrape_transfer_limit', 'Bounded scrape evidence required.' );
        foreach ( $prices as $item ) if ( ! is_array( $item ) )
            return new WP_Error( 'mad4b_scrape_transfer_price_invalid', 'Price records must be source data objects.' );
        $links = array(); $images = array();
        $processor = new WP_HTML_Tag_Processor( $html );
        while ( $processor->next_tag() ) {
            $tag = strtoupper( (string) $processor->get_tag() );
            if ( 'A' === $tag ) {
                $href = $processor->get_attribute( 'href' );
                $url = self::absolute( $href, $source );
                if ( '' !== $url && self::same_origin( $url, $source ) ) $links[$url] = true;
                if ( count( $links ) > self::MAX_LINKS )
                    return new WP_Error( 'mad4b_scrape_transfer_link_limit', 'Too many source links.' );
            }
            if ( 'IMG' === $tag || 'SOURCE' === $tag ) {
                $candidate = $processor->get_attribute( 'data-srcset' );
                if ( ! is_string( $candidate ) || '' === trim( $candidate ) )
                    $candidate = $processor->get_attribute( 'srcset' );
                $url = self::srcset_candidate( $candidate, $source );
                if ( '' === $url ) {
                    $src = $processor->get_attribute( 'data-src' );
                    if ( ! is_string( $src ) || '' === trim( $src ) )
                        $src = $processor->get_attribute( 'src' );
                    $url = self::absolute( $src, $source );
                }
                if ( '' === $url ) continue;
                $original_alt = (string) ( $processor->get_attribute( 'alt' ) ?: '' );
                $original_title = (string) ( $processor->get_attribute( 'title' ) ?: '' );
                $filename = basename( (string) ( wp_parse_url( $url )['path'] ?? '' ) );
                $stem = preg_replace( '/\.(?:jpe?g|png|webp|gif|avif)$/i', '', $filename );
                $fallback_title = trim( preg_replace( '/[-_]+/', ' ', rawurldecode( $stem ) ) );
                $title = '' !== $original_title ? $original_title
                    : ( '' !== $original_alt ? $original_alt : $fallback_title );
                $alt = '' !== $original_alt ? $original_alt : $title;
                $images[$url] = array(
                    'source_url' => $url,
                    'source_page_url' => $source,
                    'title' => self::safe_text_cap( $title, 200 ),
                    'alt' => self::safe_text_cap( $alt, 240 ),
                    'caption' => self::safe_text_cap( $title, 240 ),
                    'description' => self::safe_text_cap( 'Original image source: ' . $url .
                        ' | Collected from: ' . $source, 1000 ),
                    'filename' => self::safe_text_cap( $filename, 150 ),
                    'inspect_ability' => 'media/remote-image-inspect',
                    'inspect_input' => array( 'source_url' => $url ),
                    'content_inspection_required' => true,
                    'import_plan_ability' => 'media/remote-import-plan',
                    'import_apply_ability' => 'media/remote-import-apply',
                );
                if ( count( $images ) > self::MAX_IMAGES )
                    return new WP_Error( 'mad4b_scrape_transfer_media_limit', 'Too many image candidates.' );
            }
        }
        return array( 'source' => $source, 'html' => $html, 'links' => array_keys( $links ),
            'images' => array_values( $images ), 'prices' => $prices,
            'overrides' => $overrides, 'bindings' => $bindings );
    }

    private static function build( $input ) {
        $parsed = self::parse( $input );
        if ( is_wp_error( $parsed ) ) return $parsed;
        $site = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
        if ( ! is_array( $site ) || empty( $site['site_uuid'] )
            || empty( $site['origin_match'] ) || empty( $site['environment_match'] ) )
            return new WP_Error( 'mad4b_scrape_transfer_site_unbound', 'Exact enrolled site required.' );
        $source = $parsed['source'];
        $explicit = array();
        foreach ( $parsed['overrides'] as $row ) {
            if ( ! is_array( $row ) || ! is_int( $row['target_post_id'] ?? null ) ||
                ! is_string( $row['source_url'] ?? null ) )
                return new WP_Error( 'mad4b_scrape_transfer_link_binding_invalid', 'Exact existing post identity required.' );
            $url = self::absolute( $row['source_url'], $source );
            if ( '' === $url || ! self::same_origin( $url, $source ) || isset( $explicit[$url] ) )
                return new WP_Error( 'mad4b_scrape_transfer_link_binding_invalid', 'Duplicate or off-site source link.' );
            $target = self::target( $row['target_post_id'] );
            if ( '' === $target ) return new WP_Error( 'mad4b_scrape_transfer_target_missing',
                'Binding must refer to an existing public readable local post.' );
            $explicit[$url] = $target;
        }
        $attached = array();
        foreach ( $parsed['bindings'] as $row ) {
            if ( ! is_array( $row ) || ! is_int( $row['attachment_id'] ?? null ) ||
                ! is_string( $row['source_url'] ?? null ) )
                return new WP_Error( 'mad4b_scrape_transfer_media_binding_invalid', 'Exact existing attachment required.' );
            $url = self::absolute( $row['source_url'], $source );
            if ( '' === $url || isset( $attached[$url] ) )
                return new WP_Error( 'mad4b_scrape_transfer_media_binding_invalid', 'Invalid duplicate media binding.' );
            $target = self::media_target( $url, $row['attachment_id'] );
            if ( '' === $target )
                return new WP_Error( 'mad4b_scrape_transfer_media_provenance_missing',
                    'Attachment lacks source-hash evidence or a local media URL.' );
            $attached[$url] = $target;
        }
        $mapped = array(); $missing = array();
        foreach ( $parsed['links'] as $href ) {
            $target = isset( $explicit[$href] ) ? $explicit[$href] : self::auto_target( $href );
            if ( '' === $target ) $missing[] = $href;
            else $mapped[$href] = $target;
        }
        $media = array(); $manifest = array();
        foreach ( $parsed['images'] as $item ) {
            $item['local_media_url'] = $attached[$item['source_url']] ?? '';
            $item['import_required'] = '' === $item['local_media_url'];
            if ( $item['import_required'] ) $manifest[] = array(
                'binding_role' => 'gallery',
                'import' => array(
                    'source_url' => $item['source_url'],
                    'source_page_url' => $source,
                    'source_page_sha256' => hash( 'sha256', $parsed['html'] ),
                    'rights_basis' => 'unknown', // No rights decision in scraper.
                    'capture_only' => true,
                    'duplicate_policy' => 'reuse',
                    'filename' => $item['filename'],
                    'title' => $item['title'], 'caption' => $item['caption'],
                    'description' => $item['description'], 'alt' => $item['alt'],
                ),
            );
            $media[] = $item;
        }
        $identity = array( 'site_uuid' => (string) $site['site_uuid'],
            'site_profile_digest' => (string) ( $site['profile_digest'] ?? '' ),
            'source_url' => $source, 'source_html_sha256' => hash( 'sha256', $parsed['html'] ),
            'source_prices_sha256' => hash( 'sha256', wp_json_encode( $parsed['prices'] ) ),
            'links' => $mapped, 'missing_links' => $missing, 'images' => $media );
        return array( 'contract' => self::CONTRACT, 'state' => 'SCRAPED_EVIDENCE_CAPTURED',
            'identity' => $identity, 'plan_sha256' => hash( 'sha256', wp_json_encode( $identity ) ),
            'source_page_url' => $source,
            'source_html_sha256' => $identity['source_html_sha256'],
            'post_import_media_binding' => 'media/get attachment_id → wp_get_attachment_url readback',
            'media_metadata_fields' => array( 'title', 'caption', 'description', 'alt', 'filename' ),
            'locally_hosted_media_required' => true,
            'source_prices' => $parsed['prices'], 'source_prices_unchanged' => true,
            'internal_link_map' => $mapped, 'unmatched_competitor_links' => $missing,
            'image_import_tasks' => $media,
            'remote_media_manifest_items' => $manifest,
            'image_import_remaining' => count( array_filter( $media,
                static function ( $item ) { return $item['import_required']; } ) ),
            'media_receipt_required_before_local_rewrite' => true,
            'next_media_abilities' => array( 'media/remote-image-inspect',
                'media/remote-import-manifest-plan', 'media/remote-import-apply',
                'media/get', 'media/update-metadata' ),
            'source_links_are_remapped_only_to_existing_local_posts' => true,
            'publication_authorized' => false, 'scraping_performed' => false,
            'read_only' => true, 'mutation_performed' => false );
    }

    public static function plan( $input = array() ) {
        if ( ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::can_read() )
            return new WP_Error( 'mad4b_scrape_transfer_read_denied', 'Site read permission required.' );
        $plan = self::build( $input );
        if ( is_wp_error( $plan ) ) return $plan;
        unset( $plan['identity'] );
        return $plan;
    }

    public static function rewrite( $input = array() ) {
        if ( ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::can_read() )
            return new WP_Error( 'mad4b_scrape_transfer_read_denied', 'Site read permission required.' );
        $plan = self::build( $input );
        if ( is_wp_error( $plan ) ) return $plan;
        if ( ! is_string( $input['expected_plan_sha256'] ?? null ) ||
            ! hash_equals( $plan['plan_sha256'], $input['expected_plan_sha256'] ) )
            return new WP_Error( 'mad4b_scrape_transfer_plan_drift', 'Exact current reviewed plan digest required.' );
        $source = $plan['source_page_url'];
        $links = $plan['internal_link_map']; $local = array();
        foreach ( $plan['image_import_tasks'] as $item )
            if ( ! $item['import_required'] ) $local[$item['source_url']] = $item['local_media_url'];
        $processor = new WP_HTML_Tag_Processor( $input['html'] );
        $rewritten_links = 0; $unlinked = 0; $rewritten_media = 0; $pending_media = 0;
        while ( $processor->next_tag() ) {
            $tag = strtoupper( (string) $processor->get_tag() );
            if ( 'A' === $tag ) {
                $old = (string) ( $processor->get_attribute( 'href' ) ?: '' );
                $url = self::absolute( $old, $source );
                if ( '' === $url || ! self::same_origin( $url, $source ) ) continue;
                if ( isset( $links[$url] ) ) {
                    $processor->set_attribute( 'href', $links[$url] ); $rewritten_links++;
                } else {
                    $processor->remove_attribute( 'href' ); $unlinked++;
                }
            } elseif ( 'SOURCE' === $tag ) {
                $set = $processor->get_attribute( 'data-srcset' );
                if ( ! is_string( $set ) || '' === trim( $set ) )
                    $set = $processor->get_attribute( 'srcset' );
                $source_url = self::srcset_candidate( $set, $source );
                if ( '' !== $source_url && isset( $local[$source_url] ) ) {
                    $processor->set_attribute( 'srcset', $local[$source_url] . ' 1x' );
                    $rewritten_media++;
                } else $processor->remove_attribute( 'srcset' );
                $processor->remove_attribute( 'data-srcset' );
                $processor->remove_attribute( 'src' );
                $processor->remove_attribute( 'data-src' );
            } elseif ( 'IMG' === $tag ) {
                $set = $processor->get_attribute( 'data-srcset' );
                if ( ! is_string( $set ) || '' === trim( $set ) )
                    $set = $processor->get_attribute( 'srcset' );
                $url = self::srcset_candidate( $set, $source );
                if ( '' === $url ) {
                    $src = $processor->get_attribute( 'data-src' );
                    if ( ! is_string( $src ) || '' === trim( $src ) )
                        $src = $processor->get_attribute( 'src' );
                    $url = self::absolute( (string) ( $src ?: '' ), $source );
                }
                if ( '' === $url ) continue;
                if ( isset( $local[$url] ) ) {
                    $processor->set_attribute( 'src', $local[$url] );
                    $processor->remove_attribute( 'srcset' );
                    $processor->remove_attribute( 'data-src' );
                    $processor->remove_attribute( 'data-srcset' );
                    $processor->remove_attribute( 'data-original' );
                    $processor->remove_attribute( 'data-lazy-src' );
                    $rewritten_media++;
                } else $pending_media++;
            }
        }
        $out = $processor->get_updated_html();
        return array(
            'contract' => self::CONTRACT . '.rewrite.v1',
            'plan_sha256' => $plan['plan_sha256'], 'source_html_sha256' => $plan['source_html_sha256'],
            'html' => $out, 'html_sha256' => hash( 'sha256', $out ),
            'source_prices' => $plan['source_prices'],
            'rewritten_internal_links' => $rewritten_links,
            'competitor_links_unlinked' => $unlinked,
            'rewritten_to_local_media' => $rewritten_media,
            'pending_media' => $pending_media,
            'source_internal_links_remaining' => false,
            'media_complete' => 0 === $pending_media,
            'ready_for_draft_review' => 0 === $pending_media,
            'publication_authorized' => false, 'mutation_performed' => false, 'read_only' => true,
        );
    }
}
