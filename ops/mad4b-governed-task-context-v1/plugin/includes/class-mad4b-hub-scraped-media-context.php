<?php
/**
 * MAD4B scraped image contextual matching and governed reuse.
 * Standalone class to be loaded inside MAD4B Content Hub on authorized Staging.
 * Inputs from a scraper are ALWAYS untrusted. Neither image pixels nor usage
 * rights are certified by titles, alt text or scraping itself.
 */
defined( 'ABSPATH' ) || exit;
final class MAD4B_Hub_Scraped_Media_Context {
    const CONTRACT = 'mad4b.hub.scraped-media-context.v1';
    const META_KEY = '_mad4b_hub_scraped_media_context_v1';
    const MAX_HTML = 262144;
    const MAX_CANDIDATES = 50;

    public static function boot() {
        add_action( 'wp_abilities_api_init', [ __CLASS__, 'abilities' ], 68 );
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ], 68 );
    }

    private static function error( $code, $status = 409 ) {
        return new WP_Error( 'mad4b_scraped_media_' . $code, $code, [ 'status' => $status ] );
    }

    private static function target( $post_id, $for_write = false ) {
        $id = absint( $post_id );
        $post = $id ? get_post( $id ) : null;
        if ( ! $post || 'post' !== $post->post_type ) return self::error( 'article_missing', 404 );
        if ( ! current_user_can( 'edit_post', $id ) ) return self::error( 'article_denied', 403 );
        if ( $for_write && ! in_array( $post->post_status, [ 'draft', 'pending', 'auto-draft' ], true ) )
            return self::error( 'draft_only' );
        return $post;
    }

    /** Searchable word overlap, explicitly not a visual image interpretation. */
    private static function terms( $text ) {
        $text = mb_strtolower( wp_strip_all_tags( (string) $text ), 'UTF-8' );
        $raw = preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
        $stop = [ 'the', 'and', 'for', 'with', 'from', 'this', 'that', 'your',
            'are', 'into', 'of', 'in', 'to', 'at', 'on', 'its', 'is', 'or',
            'jpg', 'png', 'webp', 'image', 'photo', 'www', 'com',
            'من', 'في', 'على', 'إلى', 'الى', 'هذا', 'هذه' ];
        $out = [];
        foreach ( array_slice( is_array( $raw ) ? $raw : [], 0, 800 ) as $word ) {
            if ( mb_strlen( $word, 'UTF-8' ) >= 3 && ! in_array( $word, $stop, true ) )
                $out[ $word ] = true;
        }
        return array_keys( $out );
    }

    private static function image_context( $image, $post ) {
        $article = self::terms( $post->post_title . ' ' . $post->post_excerpt . ' ' .
            mb_substr( $post->post_content, 0, 3500 ) );
        $image_words = self::terms( ( $image['title'] ?? '' ) . ' ' .
            ( $image['alt'] ?? '' ) . ' ' . ( $image['filename'] ?? '' ) );
        $overlap = array_slice( array_values( array_intersect( $article, $image_words ) ), 0, 15 );
        return [ 'score' => min( 100, count( $overlap ) * 17 ),
            'matched_terms' => $overlap, 'method' => 'source_text_metadata_only',
            'pixel_vision_performed' => false, 'rights_inferred' => false ];
    }

    private static function image_rights( $attachment_id, $source_url ) {
        $simple = (string) get_post_meta( $attachment_id, '_mad4b_import_source_url', true );
        if ( $simple !== '' ) {
            if ( ! hash_equals( $source_url, $simple ) ) return self::error( 'source_identity_changed' );
            $evidence = get_post_meta( $attachment_id, '_mad4b_media_operator_responsibility_v1', true );
            if ( ! is_array( $evidence ) || ( $evidence['rights_status'] ?? '' ) !== 'verified' ||
                empty( $evidence['reuse_permitted'] ) || empty( $evidence['rights_basis'] ) )
                return self::error( 'rights_not_verified' );
            $basis = $evidence['rights_basis'];
            if ( ! in_array( $basis, [ 'owned', 'licensed', 'permission', 'public_domain', 'creative_commons' ], true ) )
                return self::error( 'rights_basis_invalid' );
            if ( $basis !== 'owned' && empty( $evidence['rights_reference'] ) )
                return self::error( 'rights_evidence_missing' );
            if ( empty( $evidence['scope_site_uuid'] ) ||
                ! class_exists( 'MAD4B_SCP_Site_Profile' ) )
                return self::error( 'site_usage_scope_unverified' );
            $site = MAD4B_SCP_Site_Profile::status();
            if ( ( $evidence['scope_site_uuid'] ?? '' ) !== ( $site['site_uuid'] ?? '' ) )
                return self::error( 'site_usage_scope_mismatch' );
            $expiry = (string) ( $evidence['license_expires_on'] ?? '' );
            if ( $expiry !== '' && ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $expiry ) ||
                $expiry < gmdate( 'Y-m-d' ) ) ) return self::error( 'rights_expired' );
        } else {
            // Hash-only native remote imports must have the actual source alias.
            $hashes = (array) get_post_meta( $attachment_id, '_mad4b_remote_media_source_sha256', false );
            if ( ! in_array( hash( 'sha256', $source_url ), $hashes, true ) )
                return self::error( 'remote_source_alias_missing' );
        }
        if ( ! class_exists( 'MAD4B_SCP_Remote_Media_Rights' ) )
            return self::error( 'rights_guard_unavailable' );
        $remote = MAD4B_SCP_Remote_Media_Rights::publish_guard( $attachment_id, [], [] );
        if ( is_wp_error( $remote ) ) return $remote;
        return [ 'valid' => true, 'checked_at' => gmdate( 'c' ) ];
    }

    private static function imported( $source_url ) {
        $by_url = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit',
            'fields' => 'ids', 'posts_per_page' => 3, 'suppress_filters' => true,
            'meta_key' => '_mad4b_import_source_url', 'meta_value' => $source_url ] );
        $by_hash = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit',
            'fields' => 'ids', 'posts_per_page' => 3, 'suppress_filters' => true,
            'meta_key' => '_mad4b_remote_media_source_sha256',
            'meta_value' => hash( 'sha256', $source_url ) ] );
        $ids = array_values( array_unique( array_map( 'intval',
            array_merge( is_array( $by_url ) ? $by_url : [], is_array( $by_hash ) ? $by_hash : [] ) ) ) );
        if ( count( $ids ) !== 1 ) return [ 'attachment_id' => 0,
            'status' => $ids ? 'ambiguous_import' : 'not_imported', 'reuse_ready' => false ];
        $id = $ids[0];
        $image = class_exists( 'MAD4B_Editorial_Featured_Image_Gate' )
            ? MAD4B_Editorial_Featured_Image_Gate::verify_image( $id )
            : [ 'valid' => false, 'reason' => 'image_guard_unavailable' ];
        $rights = self::image_rights( $id, $source_url );
        return [ 'attachment_id' => $id, 'image_valid' => ! empty( $image['valid'] ),
            'image_blocker' => $image['reason'] ?? '',
            'rights_ready' => ! is_wp_error( $rights ),
            'rights_blocker' => is_wp_error( $rights ) ? $rights->get_error_code() : '',
            'reuse_ready' => ! empty( $image['valid'] ) && ! is_wp_error( $rights ) ];
    }

    public static function plan( $input = [] ) {
        if ( ! is_array( $input ) ) return self::error( 'invalid_input', 400 );
        $post = self::target( $input['target_post_id'] ?? 0 );
        if ( is_wp_error( $post ) ) return $post;
        if ( ! class_exists( 'MAD4B_SCP_Scrape_Transfer' ) )
            return self::error( 'native_scraper_unavailable', 503 );
        $source = (string) ( $input['source_page_url'] ?? '' );
        $html = (string) ( $input['html'] ?? '' );
        if ( $html === '' || strlen( $html ) > self::MAX_HTML )
            return self::error( 'bounded_scrape_evidence_required', 400 );
        $request = [ 'source_page_url' => $source, 'html' => $html ];
        if ( isset( $input['scrape_receipt'] ) ) $request['scrape_receipt'] = $input['scrape_receipt'];
        $scrape = MAD4B_SCP_Scrape_Transfer::plan( $request );
        if ( is_wp_error( $scrape ) ) return $scrape;
        $images = [];
        foreach ( array_slice( (array) ( $scrape['image_import_tasks'] ?? [] ), 0, self::MAX_CANDIDATES ) as $item ) {
            $url = (string) ( $item['source_url'] ?? '' );
            if ( $url === '' ) continue;
            $context = self::image_context( $item, $post );
            $image = self::imported( $url );
            $images[] = [
                'source_url' => $url,
                'original_alt' => $item['source_metadata']['alt'] ?? $item['alt'] ?? '',
                'original_title' => $item['source_metadata']['title'] ?? $item['title'] ?? '',
                'context' => $context,
                'import' => $image,
                'recommended_for_visual_review' => $context['score'] >= 17,
                'requires_visual_observation' => true,
                'requires_verified_reuse_rights' => true,
                'candidate_sha256' => hash( 'sha256', $url . '|' . $scrape['source_html_sha256'] ),
            ];
        }
        usort( $images, static fn( $a, $b ) => $b['context']['score'] <=> $a['context']['score'] );
        $version = hash( 'sha256', wp_json_encode( [ $post->ID, $post->post_modified_gmt,
            $scrape['source_html_sha256'], array_column( $images, 'candidate_sha256' ) ] ) );
        return [ 'contract' => self::CONTRACT,
            'target_post_id' => (int) $post->ID,
            'source_page_url' => $source,
            'source_html_sha256' => $scrape['source_html_sha256'],
            'capture_receipt_status' => $scrape['scrape_provenance_status'],
            'capture_is_server_verified' => false,
            'binding_requires_server_verified_capture' => true,
            'capture_verifier_registered' => ( false !== has_filter( 'mad4b_hub_scraped_media_verify_capture' ) ),
            'next_capture_step' => false !== has_filter( 'mad4b_hub_scraped_media_verify_capture' )
                ? 'get_and_verify_provider_stored_capture_receipt'
                : 'connect_governed_provider_capture_verifier',
            'remaining_review_requirements' => [
                'server_verified_capture_receipt',
                'visual_observation_of_exact_image',
                'scope_bound_reuse_rights',
                'editorial_approval_before_publication',
            ],
            'plan_sha256' => $version,
            'candidates' => $images, 'candidate_count' => count( $images ),
            'analysis_method' => 'scraped_image_metadata_vs_article',
            'vision_analysis_next' => 'chatgpt_mcp_visual_review_of_exact_image',
            'next_native_abilities' => [ 'media/remote-image-inspect',
                'media/remote-import-plan', 'media/remote-import-apply' ],
            'auto_publish' => false, 'mutation_performed' => false ];
    }

    /** Never turn a submitted HTML/receipt hash into evidence of an actual
     * provider capture. A trusted server-side provider adapter must locate an
     * independently stored capture and return the exact attested binding.
     * Filters are private PHP integration points; no request flag can pass.
     */
    private static function verify_capture( $plan, $input ) {
        $submitted = $input['scrape_receipt'] ?? null;
        if ( ! is_array( $submitted ) || empty( $submitted['record_id'] ) )
            return self::error( 'server_verified_capture_required' );
        $proof = apply_filters( 'mad4b_hub_scraped_media_verify_capture', null,
            [ 'record_id' => (string) $submitted['record_id'],
              'source_page_url' => (string) $plan['source_page_url'],
              'source_html_sha256' => (string) $plan['source_html_sha256'],
              'target_post_id' => (int) $plan['target_post_id'] ] );
        if ( ! is_array( $proof ) || ( $proof['verified'] ?? null ) !== true ||
            ! is_string( $proof['provider_id'] ?? null ) ||
            '' === trim( $proof['provider_id'] ) ||
            ! hash_equals( (string) $submitted['record_id'], (string) ( $proof['record_id'] ?? '' ) ) ||
            ! hash_equals( (string) $plan['source_page_url'], (string) ( $proof['source_page_url'] ?? '' ) ) ||
            ! hash_equals( (string) $plan['source_html_sha256'], (string) ( $proof['source_html_sha256'] ?? '' ) ) )
            return self::error( 'server_verified_capture_required' );
        return true;
    }

    private static function can_bind( $post_id ) {
        if ( wp_get_environment_type() !== 'staging' || ! get_current_user_id() ||
            ! current_user_can( 'upload_files' ) ||
            ! current_user_can( 'edit_post', $post_id ) ||
            ! ( current_user_can( 'manage_options' ) ||
                current_user_can( 'mad4b_manage_company_operations' ) ) ) return false;
        return class_exists( 'MAD4B_SCP_Site_Profile' ) &&
            MAD4B_SCP_Site_Profile::user_is_enrolled( get_current_user_id() );
    }

    public static function bind( $input = [] ) {
        if ( ! is_array( $input ) ) return self::error( 'invalid_input', 400 );
        $id = absint( $input['target_post_id'] ?? 0 );
        if ( ! self::can_bind( $id ) ) return self::error( 'site_editor_authority_missing', 403 );
        $post = self::target( $id, true );
        if ( is_wp_error( $post ) ) return $post;
        if ( ( $input['visual_context_reviewed'] ?? null ) !== true )
            return self::error( 'actual_visual_review_required' );
        $observed = $input['observed_subjects'] ?? null;
        if ( ! is_array( $observed ) || ! $observed || count( $observed ) > 12 )
            return self::error( 'visual_observations_required' );
        foreach ( $observed as $tag ) {
            if ( ! is_string( $tag ) || mb_strlen( $tag ) < 3 || mb_strlen( $tag ) > 90 )
                return self::error( 'visual_observation_invalid' );
        }
        $plan = self::plan( $input );
        if ( is_wp_error( $plan ) ) return $plan;
        $capture = self::verify_capture( $plan, $input );
        if ( is_wp_error( $capture ) ) return $capture;
        $expected_plan = (string) ( $input['expected_plan_sha256'] ?? '' );
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', $expected_plan ) ||
            ! hash_equals( $plan['plan_sha256'], $expected_plan ) )
            return self::error( 'plan_stale' );
        $source = (string) ( $input['source_url'] ?? '' );
        $candidate = null;
        foreach ( $plan['candidates'] as $entry ) {
            if ( hash_equals( $entry['source_url'], $source ) ) { $candidate = $entry; break; }
        }
        if ( ! $candidate || ! hash_equals( $candidate['candidate_sha256'],
            (string) ( $input['expected_candidate_sha256'] ?? '' ) ) )
            return self::error( 'image_identity_stale' );
        $visual = self::image_context( [ 'title' => implode( ' ', $observed ) ], $post );
        if ( $visual['score'] < 17 ) return self::error( 'observed_image_subject_unrelated' );
        // Re-read both the actual local file and rights immediately before use.
        $import = self::imported( $source );
        if ( empty( $import['reuse_ready'] ) ) return self::error( 'image_or_rights_not_ready' );
        $attachment = (int) $import['attachment_id'];
        $old = (int) get_post_thumbnail_id( $id );
        if ( $old === $attachment ) return [ 'contract' => self::CONTRACT,
            'state' => 'already_bound', 'attachment_id' => $attachment,
            'mutation_performed' => false, 'publish_authorized' => false ];
        if ( ! set_post_thumbnail( $id, $attachment ) ) return self::error( 'binding_failed', 500 );
        $record = [ 'contract' => self::CONTRACT,
            'scraped_source_page_url' => $plan['source_page_url'],
            'scraped_html_sha256' => $plan['source_html_sha256'],
            'candidate_sha256' => $candidate['candidate_sha256'],
            'source_image_url' => $source,
            'attachment_id' => $attachment,
            'observed_subjects' => array_values( $observed ),
            'context_match_score' => $visual['score'],
            'editor_user_id' => get_current_user_id(),
            'reviewed_at' => gmdate( 'c' ),
            'rights_checked_at_binding' => true,
            'requires_editorial_review' => true,
            'publication_authorized' => false ];
        if ( ! update_post_meta( $id, self::META_KEY, $record ) ) {
            if ( $old ) set_post_thumbnail( $id, $old );
            else delete_post_thumbnail( $id );
            return self::error( 'context_record_write_failed', 500 );
        }
        $back = get_post_meta( $id, self::META_KEY, true );
        if ( (int) get_post_thumbnail_id( $id ) !== $attachment ||
            ! is_array( $back ) ||
            ( $back['candidate_sha256'] ?? '' ) !== $candidate['candidate_sha256'] ) {
            if ( $old ) set_post_thumbnail( $id, $old );
            else delete_post_thumbnail( $id );
            return self::error( 'binding_readback_failed', 500 );
        }
        // Only after the source capture, rights, visual observations, local
        // file and exact binding have all passed. No SEO meta is touched.
        $proposal=MAD4B_Hub_Image_Descriptive_Metadata::propose($candidate,$observed);
        $metadata_result=MAD4B_Hub_Image_Descriptive_Metadata::fill_missing_after_verified_bind(
            $attachment,$proposal
        );
        return [ 'contract' => self::CONTRACT,
            'metadata_fill'=>is_wp_error($metadata_result)
                ?[ 'status'=>'needs_manual_review','error_code'=>$metadata_result->get_error_code() ]
                :$metadata_result,
            'state' => 'draft_image_context_bound',
            'post_id' => $id,
            'attachment_id' => $attachment,
            'context_match_score' => $visual['score'],
            'rights_checked' => true,
            'requires_editorial_review' => true,
            'publish_authorized' => false, 'mutation_performed' => true ];
    }

    public static function abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        foreach ( [ 'plan' => false, 'bind' => true ] as $mode => $write ) {
            $name = 'mad4b/hub-scraped-media-context-' . $mode;
            if ( wp_has_ability( $name ) ) continue;
            wp_register_ability( $name, [
                'label' => 'Scraped image context ' . ucfirst( $mode ),
                'description' => 'Contextual image recommendations from real scraped page images with rights-gated draft reuse.',
                'category' => $write ? 'mad4b-write' : 'mad4b-read',
                'execute_callback' => [ __CLASS__, $mode ],
                'permission_callback' => static fn() => get_current_user_id() > 0 &&
                    ( current_user_can( 'edit_posts' ) || current_user_can( 'manage_options' ) ),
                'input_schema' => [ 'type' => 'object', 'properties' => [
                    'source_page_url' => [ 'type' => 'string' ],
                    'html' => [ 'type' => 'string', 'maxLength' => self::MAX_HTML ],
                    'target_post_id' => [ 'type' => 'integer' ],
                    'scrape_receipt' => [ 'type' => 'object', 'additionalProperties' => true ],
                    'source_url' => [ 'type' => 'string' ],
                    'expected_plan_sha256' => [ 'type' => 'string' ],
                    'expected_candidate_sha256' => [ 'type' => 'string' ],
                    'visual_context_reviewed' => [ 'type' => 'boolean' ],
                    'observed_subjects' => [ 'type' => 'array',
                        'items' => [ 'type' => 'string' ] ],
                ], 'required' => [ 'source_page_url', 'html', 'target_post_id' ],
                    'additionalProperties' => false ],
                'output_schema' => [ 'type' => 'object', 'additionalProperties' => true ],
                'meta' => [ 'public' => false, 'show_in_rest' => false,
                    'mcp' => [ 'public' => false, 'type' => 'tool',
                        'surface' => $write ? 'write' : 'read' ],
                    'annotations' => [ 'readonly' => ! $write,
                        'destructive' => false, 'idempotent' => ! $write ] ],
            ] );
        }
    }

    public static function routes() {
        register_rest_route( 'mad4b-hub/v1', '/control-workflow/scraped-media-context/plan', [
            'methods' => 'POST',
            'permission_callback' => static fn() => get_current_user_id() > 0 &&
                current_user_can( 'edit_posts' ),
            'callback' => static fn( $request ) => self::plan( $request->get_params() ),
        ] );
        register_rest_route( 'mad4b-hub/v1', '/control-workflow/scraped-media-context/bind', [
            'methods' => 'POST',
            'permission_callback' => static fn( $request ) =>
                self::can_bind( absint( $request->get_param( 'target_post_id' ) ) ),
            'callback' => static fn( $request ) => self::bind( $request->get_params() ),
        ] );
    }
}
